<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\TextProtocol;
use OCA\TalkAi\Ai\Turn;
use OCA\TalkAi\Ai\Usage;

/**
 * Claude Code on the server, for a subscription instead of an API key. It runs
 * as `claude -p` with stream-json in and out, no saved sessions and no built-in
 * tools (WebSearch and WebFetch only for search), HOME set to the directory with
 * its credentials and nothing else of the server's environment.
 *
 * Tools: the question's tools are an MCP server (Bridge/mcp.php) that hands each
 * call back to this process over a private Unix socket. turn() returns the calls
 * the model made while the process waits, Loop runs them, and the next turn()
 * writes the results to the socket: the process lives between the turns in
 * Turn::$session, and a later question of the same run (a document job's
 * reminder) goes to it too. With MCP off (setting), each turn is a new process
 * and tools go by TextProtocol.
 *
 * Every flag is checked against `claude --help` of the installed version first;
 * a missing one switches its feature off, or the engine is not ready.
 *
 * Settings: binary (default claude), home (the credentials' directory; required),
 * model ('' for the CLI's default), timeout (seconds of waiting for the model per
 * turn, 600), php (the PHP command line for the bridge), maxBudgetUsd (per process),
 * path (PATH for the CLI), flags {mcp, search, streaming} (default on).
 */
final class ClaudeCli implements Engine {
	private const REQUIRED = ['--print', '--input-format', '--output-format', '--verbose', '--no-session-persistence', '--tools', '--system-prompt'];
	private const PREFIX = 'mcp__talkai__';
	/** Images with one message: the Messages API's limit for many images. */
	private const MAX_IMAGES = 20;

	private ?string $help = null;

	public function __construct(
		private array $settings,
	) {
	}

	public function status(): Status {
		$binary = $this->binary();
		if ($binary === '') {
			return Status::notReady(Errors::NO_CLI, 'claude-cli', 'anthropic');
		}
		$home = (string)($this->settings['home'] ?? '');
		if ($home === '' || !is_dir($home)) {
			// Without its credentials the CLI asks to log in.
			return Status::notReady(Errors::NO_KEY, 'claude-cli', 'anthropic');
		}
		if (array_diff(self::REQUIRED, $this->flags()) !== []) {
			return Status::notReady(Errors::NO_CLI, 'claude-cli', 'anthropic');
		}
		return new Status(true, '', 'anthropic', (string)($this->settings['model'] ?? '') ?: 'default', true, self::MAX_IMAGES,
			$this->searchOn(), true, $this->has('--json-schema'), $this->streamingOn(), true, 'claude-cli');
	}

	/** Which of the flags the engine would use the installed CLI lacks (for occ talk_ai:check). */
	public function missingFlags(): array {
		return array_values(array_diff([...self::REQUIRED, '--mcp-config', '--strict-mcp-config', '--allowedTools', '--include-partial-messages', '--json-schema', '--model'], $this->flags()));
	}

	/** Whether tools go to the model as real tool calls (MCP), not as text. */
	public function mcpOn(): bool {
		return ($this->settings['flags']['mcp'] ?? true) && $this->has('--mcp-config') && $this->has('--strict-mcp-config') && $this->has('--allowedTools')
			&& is_file($this->php()) && is_executable($this->php());
	}

	public function models(): array {
		// The CLI lists no models; these are its aliases for the latest of each kind.
		return ['default', 'fable', 'opus', 'sonnet', 'haiku'];
	}

	public function turn(Request $request): Turn {
		if (!$this->status()->ready) {
			return Turn::failed($this->status()->reason);
		}
		try {
			return $this->mcpOn() ? $this->live($request) : $this->textTurn($request);
		} catch (\Throwable $e) {
			return Turn::failed(Errors::provider($e->getMessage()));
		}
	}

	/** A turn with the process that lives between turns, its tools over MCP. */
	private function live(Request $request): Turn {
		$messages = $request->messages;
		$last = end($messages) ?: ['role' => 'user', 'parts' => []];
		$fingerprint = sha1($request->system . json_encode([$request->tools, $request->search, $request->answerSchema, $request->onDelta !== null]));
		$session = $request->session instanceof ClaudeSession && $request->session->alive() && $request->session->fingerprint === $fingerprint ? $request->session : null;
		if ($session !== null && $last['role'] === 'tool' && $session->waiting !== []) {
			$this->answer($session, $last);
		} elseif ($session !== null && $session->idle && $last['role'] === 'user') {
			$session->idle = false;
			$session->process->write(self::userLine($last['parts']));
		} else {
			$session = $this->start($request, $fingerprint, true);
			$session->process->write(self::userLine(self::firstParts($messages)));
		}
		return $this->read($session, $request, true);
	}

	/** A turn as a new process that answers once: tools and answer by TextProtocol. */
	private function textTurn(Request $request): Turn {
		$session = $this->start($request, '', false);
		$session->process->write(self::userLine(self::firstParts($request->messages)));
		$session->process->closeInput();
		$turn = $this->read($session, $request, false);
		if (!$turn->ok()) {
			return $turn;
		}
		$parsed = TextProtocol::parse($turn->text, array_keys($request->tools));
		return new Turn($parsed['text'], $parsed['calls'], $turn->answer ?? $parsed['answer'], $turn->usage, null, $parsed['calls'] !== [] ? 'tools' : 'end');
	}

	private function start(Request $request, string $fingerprint, bool $mcp): ClaudeSession {
		$dir = CliProcess::tempDir();
		$system = $request->system;
		if (!$mcp) {
			$system = trim($system . "\n\n" . TextProtocol::instructions($request->tools, $this->has('--json-schema') ? null : $request->answerSchema));
		}
		$search = $request->search && $this->searchOn();
		$argv = [$this->binary(), '--print', '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose', '--no-session-persistence',
			'--tools', $search ? 'WebSearch,WebFetch' : ''];
		// The argument list takes about 128 kB per argument; a longer prompt goes as a file where the CLI takes one.
		if (strlen($system) > 100000 && str_contains($this->helpText(), 'system-prompt-file')) {
			file_put_contents($dir . '/system.txt', $system);
			array_push($argv, '--system-prompt-file', $dir . '/system.txt');
		} else {
			array_push($argv, '--system-prompt', $system);
		}
		$model = (string)($this->settings['model'] ?? '');
		if ($model !== '' && $model !== 'default' && $this->has('--model')) {
			array_push($argv, '--model', $model);
		}
		if ($this->streamingOn()) {
			$argv[] = '--include-partial-messages';
		}
		if ($request->answerSchema !== null && $this->has('--json-schema')) {
			array_push($argv, '--json-schema', (string)json_encode($request->answerSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}
		if ((float)($this->settings['maxBudgetUsd'] ?? 0) > 0 && $this->has('--max-budget-usd')) {
			array_push($argv, '--max-budget-usd', (string)(float)$this->settings['maxBudgetUsd']);
		}
		$allowed = $search ? ['WebSearch', 'WebFetch'] : [];
		$server = null;
		$token = bin2hex(random_bytes(16));
		if ($mcp) {
			$socket = $dir . '/b.sock';
			$server = @stream_socket_server('unix://' . $socket, $errno, $errstr);
			if ($server === false) {
				throw new \RuntimeException('no socket for the tools: ' . $errstr);
			}
			chmod($socket, 0600);
			stream_set_blocking($server, false);
			file_put_contents($dir . '/tools.json', json_encode((object)$request->tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			file_put_contents($dir . '/mcp.json', json_encode(['mcpServers' => ['talkai' => [
				'command' => $this->php(), 'args' => [dirname(__DIR__) . '/Bridge/mcp.php'],
				'env' => ['TALKAI_BRIDGE_SOCKET' => $socket, 'TALKAI_BRIDGE_TOKEN' => $token, 'TALKAI_BRIDGE_TOOLS' => $dir . '/tools.json'],
			]]], JSON_UNESCAPED_SLASHES));
			array_push($argv, '--strict-mcp-config', '--mcp-config', $dir . '/mcp.json');
			$allowed[] = 'mcp__talkai';
		}
		if ($allowed !== [] && $this->has('--allowedTools')) {
			array_push($argv, '--allowedTools', implode(',', $allowed));
		}
		$process = new CliProcess($argv, $this->env(), $dir);
		return new ClaudeSession($process, $server, $token, $fingerprint);
	}

	/** Loop's results to the calls waiting at the bridge; what else the tool message holds goes with the first. */
	private function answer(ClaudeSession $session, array $message): void {
		$extraText = Request::textOf($message);
		$images = array_map(fn (array $p) => ['type' => $p['type'], 'data' => $p['data']], Request::parts($message, 'image'));
		foreach (Request::parts($message, 'tool_result') as $result) {
			$conn = $session->waiting[$result['id']] ?? null;
			if ($conn === null) {
				continue;
			}
			$content = (string)$result['content'] . ($extraText !== '' ? "\n\n" . $extraText : '');
			@fwrite($conn, json_encode(['content' => $content, 'error' => (bool)$result['error'], 'images' => $images], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
			@fclose($conn);
			unset($session->waiting[$result['id']]);
			[$extraText, $images] = ['', []];
		}
		// A call Loop gave no result for must not hang the model.
		foreach ($session->waiting as $conn) {
			@fwrite($conn, '{"content": "Not run.", "error": true}' . "\n");
			@fclose($conn);
		}
		$session->waiting = [];
		$session->calls = [];
	}

	/** Reads the process until it waits for tool results, answers, fails or takes too long. */
	private function read(ClaudeSession $session, Request $request, bool $keep): Turn {
		$timeout = max(1, (int)($this->settings['timeout'] ?? 600));
		$deadline = microtime(true) + $timeout;
		$usage = new Usage();
		$announced = [];
		$stopForTools = false;
		$partial = $this->streamingOn();
		$seen = [];
		// Whether stream events came: a watcher around the CLI may pass on only whole messages.
		$streamed = false;
		// Calls Loop has not had yet: some may have come before this turn's reading began.
		$quietSince = $session->waiting !== [] ? microtime(true) : null;
		while (true) {
			// Loop's turn: every call of the model's message is at the bridge, or some are and nothing
			// more came for a moment (the CLI runs a call before the message ends, and may wait for its
			// result before it makes the next).
			$all = $announced !== [] && array_diff($announced, array_keys($session->waiting)) === [] && $stopForTools;
			if ($session->waiting !== [] && ($all || ($quietSince !== null && microtime(true) - $quietSince > 0.5))) {
				$calls = [];
				foreach ($session->calls as $id => $call) {
					$calls[] = ['id' => (string)$id] + $call;
				}
				return new Turn('', $calls, null, $usage, $session, 'tools');
			}
			if (microtime(true) > $deadline) {
				$session->process->kill();
				return Turn::failed(Errors::TIMEOUT, $usage);
			}
			$read = [$session->process->stdout()];
			if (is_resource($session->server)) {
				$read[] = $session->server;
			}
			$write = $except = null;
			$ready = @stream_select($read, $write, $except, 0, 200000);
			if ($ready === false) {
				return Turn::failed(Errors::provider('the command line tool stopped'), $usage);
			}
			if (is_resource($session->server) && in_array($session->server, $read, true)) {
				$this->accept($session, $announced);
				$quietSince = microtime(true);
			}
			$lines = $session->process->lines();
			if ($lines !== [] && $quietSince !== null) {
				$quietSince = microtime(true);
			}
			foreach ($lines as $line) {
				$event = json_decode($line, true);
				if (!is_array($event)) {
					continue;
				}
				$type = (string)($event['type'] ?? '');
				if ($type === 'stream_event') {
					$streamed = true;
					$e = (array)($event['event'] ?? []);
					match ($e['type'] ?? '') {
						'message_start' => [$announced, $stopForTools] = [[], false],
						'content_block_start' => ($e['content_block']['type'] ?? '') === 'tool_use' && str_starts_with((string)$e['content_block']['name'], self::PREFIX)
							? $announced[] = (string)$e['content_block']['id'] : null,
						'content_block_delta' => ($e['delta']['type'] ?? '') === 'text_delta' && is_callable($request->onDelta) ? ($request->onDelta)((string)$e['delta']['text']) : null,
						'message_delta' => [$usage, $stopForTools] = [$usage->add(self::usage((array)($e['usage'] ?? []))), ($e['delta']['stop_reason'] ?? '') === 'tool_use'],
						default => null,
					};
				} elseif ($type === 'assistant') {
					$message = (array)($event['message'] ?? []);
					$id = (string)($message['id'] ?? '');
					if ((!$partial || !$streamed) && !isset($seen[$id])) {
						// Without partial messages each block comes as an event of its own, all with the message's usage.
						$seen[$id] = true;
						$usage = $usage->add(self::usage((array)($message['usage'] ?? [])));
						$announced = [];
					}
					foreach ((array)($message['content'] ?? []) as $block) {
						if (($block['type'] ?? '') === 'tool_use' && str_starts_with((string)$block['name'], self::PREFIX) && !in_array($block['id'], $announced, true)) {
							$announced[] = (string)$block['id'];
						}
					}
				} elseif ($type === 'result') {
					return $this->result($session, $event, $usage, $keep);
				}
			}
			if (!$session->process->running() && $lines === []) {
				$tail = $session->process->stderrTail();
				$session->process->kill();
				return Turn::failed(self::error($tail !== '' ? $tail : 'the command line tool ended without an answer'), $usage);
			}
		}
	}

	/** A call from the bridge: kept with its connection until Loop has run it. */
	private function accept(ClaudeSession $session, array $announced): void {
		while (($conn = @stream_socket_accept($session->server, 0)) !== false) {
			stream_set_blocking($conn, true);
			stream_set_timeout($conn, 5);
			$call = json_decode((string)fgets($conn), true);
			if (!is_array($call) || !hash_equals($session->token, (string)($call['token'] ?? ''))) {
				fclose($conn);
				continue;
			}
			$id = (string)($call['id'] ?? '');
			if ($id === '' || isset($session->waiting[$id])) {
				// An older CLI without the tool_use id: the first announced call of that name without one.
				$id = '';
				foreach ($announced as $candidate) {
					if (!isset($session->waiting[$candidate])) {
						$id = $candidate;
						break;
					}
				}
				$id = $id !== '' ? $id : 'bridge-' . bin2hex(random_bytes(4));
			}
			$session->waiting[$id] = $conn;
			$session->calls[$id] = ['name' => (string)($call['name'] ?? ''), 'args' => (array)($call['args'] ?? [])];
		}
	}

	private function result(ClaudeSession $session, array $event, Usage $usage, bool $keep): Turn {
		$cost = (float)($event['total_cost_usd'] ?? 0);
		$usage = $usage->add(new Usage(cost: max(0.0, $cost - $session->cost), reported: true));
		$session->cost = $cost;
		if (($event['is_error'] ?? false) || !str_starts_with((string)($event['subtype'] ?? 'success'), 'success')) {
			$session->process->kill();
			return Turn::failed(self::error((string)($event['result'] ?? $event['subtype'] ?? 'error')), $usage);
		}
		$session->idle = true;
		$stop = match ((string)($event['stop_reason'] ?? 'end_turn')) {
			'max_tokens' => 'length',
			'refusal' => 'refusal',
			default => 'end',
		};
		return new Turn(trim((string)($event['result'] ?? '')), [], $event['structured_output'] ?? null, $usage, $keep ? $session : null, $stop);
	}

	/** The first message of a new process: what came before as a transcript, then the question. */
	private static function firstParts(array $messages): array {
		$last = array_pop($messages) ?? ['role' => 'user', 'parts' => []];
		$parts = $last['parts'];
		if ($last['role'] === 'tool') {
			// The process that made these calls is gone: the results go as words.
			$parts = [Request::text(TextProtocol::results(Request::parts($last, 'tool_result'))), ...array_filter($parts, fn ($p) => $p['kind'] !== 'tool_result')];
		}
		if ($messages !== []) {
			// A block per turn: the turns of the last run come again unchanged, so the prompt
			// cache serves them and only what is new is paid in full (one block was new each time).
			$earlier = [Request::text('Earlier in this conversation:')];
			foreach (TextProtocol::flatten($messages) as $t) {
				$earlier[] = Request::text('[' . $t['role'] . "]\n" . $t['text'] . ($t['images'] !== [] ? "\n[" . count($t['images']) . ' image(s), no longer attached]' : ''));
			}
			$parts = [...$earlier, Request::text('Now:'), ...$parts];
		}
		return $parts;
	}

	/** A user message for stream-json input: the Messages API's content blocks. */
	private static function userLine(array $parts): string {
		$content = [];
		foreach ($parts as $part) {
			$block = match ($part['kind']) {
				'text' => trim((string)$part['text']) !== '' ? ['type' => 'text', 'text' => (string)$part['text']] : null,
				'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part['type'], 'data' => $part['data']]],
				'document' => ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $part['type'], 'data' => $part['data']], 'title' => (string)($part['name'] ?? '')],
				'tool_result' => ['type' => 'text', 'text' => TextProtocol::results([$part])],
				default => null,
			};
			if ($block !== null) {
				$content[] = $block;
			}
		}
		$content = $content !== [] ? $content : [['type' => 'text', 'text' => '…']];
		return (string)json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => $content]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function usage(array $u): Usage {
		$cached = (int)($u['cache_read_input_tokens'] ?? 0);
		return new Usage((int)($u['input_tokens'] ?? 0) + $cached + (int)($u['cache_creation_input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), $cached, null, true);
	}

	/** The CLI's words for what went wrong as an error word. */
	private static function error(string $text): string {
		return match (true) {
			preg_match('/log ?in|logged in|invalid api key|authenticat|credit balance|oauth/i', $text) === 1 => Errors::NO_KEY,
			preg_match('/rate.?limit|usage limit|limit reached|\b429\b/i', $text) === 1 => Errors::RATE_LIMITED,
			preg_match('/overloaded|\b529\b/i', $text) === 1 => Errors::BUSY,
			// "stalled": a watcher around the CLI stopped it when nothing came for a while.
			preg_match('/time(d)? ?out|stalled/i', $text) === 1 => Errors::TIMEOUT,
			default => Errors::provider($text),
		};
	}

	private function searchOn(): bool {
		return (bool)($this->settings['flags']['search'] ?? true) && $this->has('--allowedTools');
	}

	private function streamingOn(): bool {
		return (bool)($this->settings['flags']['streaming'] ?? true) && $this->has('--include-partial-messages');
	}

	private function env(): array {
		return [
			'HOME' => (string)$this->settings['home'],
			'PATH' => (string)($this->settings['path'] ?? '/usr/local/bin:/usr/bin:/bin'),
			'LANG' => 'C.UTF-8',
			// A tool runs as long as Loop takes; the engine watches the model's time itself.
			'MCP_TOOL_TIMEOUT' => '86400000',
			'DISABLE_AUTOUPDATER' => '1',
			// Even without saved sessions it made a memory folder in its home for every run (2026-10-06).
			'CLAUDE_CODE_DISABLE_AUTO_MEMORY' => '1',
		];
	}

	private function php(): string {
		$php = (string)($this->settings['php'] ?? '');
		// PHP_BINARY is php-fpm under a web server; the command line PHP sits beside it in PHP_BINDIR.
		return $php !== '' ? $php : PHP_BINDIR . '/php';
	}

	/** The binary's full path, or '' when there is none to run. */
	private function binary(): string {
		$binary = (string)($this->settings['binary'] ?? 'claude');
		if (!str_contains($binary, '/')) {
			foreach (explode(':', (string)($this->settings['path'] ?? '/usr/local/bin:/usr/bin:/bin')) as $dir) {
				if (is_file($dir . '/' . $binary) && is_executable($dir . '/' . $binary)) {
					return $dir . '/' . $binary;
				}
			}
			return '';
		}
		return is_file($binary) && is_executable($binary) ? $binary : '';
	}

	private function has(string $flag): bool {
		return in_array($flag, $this->flags(), true);
	}

	/** @return list<string> the long flags `--help` names */
	private function flags(): array {
		preg_match_all('/(?<![\w-])--[a-zA-Z][\w-]*/', $this->helpText(), $m);
		return array_values(array_unique($m[0]));
	}

	/** `claude --help` of the installed version. */
	private function helpText(): string {
		$binary = $this->binary();
		return $this->help ??= $binary === '' ? '' : CliProcess::help($binary, $this->env(), (string)($this->settings['home'] ?? ''));
	}
}
