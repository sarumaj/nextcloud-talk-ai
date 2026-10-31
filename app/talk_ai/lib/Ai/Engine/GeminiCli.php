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
 * Gemini CLI on the server, for a Google subscription. Each turn is one run: the
 * prompt (system prompt, TextProtocol's tools, the conversation as a transcript)
 * on stdin, which it reads as its prompt, never as an argument (the kernel's
 * limit, and the process list). Its own tools are denied by an admin policy,
 * which outranks the rules that let read_file, glob and grep_search run unasked:
 * they could read the credentials in its home (AI-Hub's review T2). Without
 * --admin-policy the engine is not ready.
 *
 * It takes no images (it reads one only through a file tool) and searches
 * nothing; the status says so, and Hub refuses images before asking.
 *
 * Settings: binary (default gemini), home (its credentials; required), model
 * (default auto), timeout (600), path.
 */
final class GeminiCli implements Engine {
	private ?string $help = null;

	public function __construct(
		private array $settings,
	) {
	}

	public function status(): Status {
		if ($this->binary() === '') {
			return Status::notReady(Errors::NO_CLI, 'gemini-cli', 'google');
		}
		$home = (string)($this->settings['home'] ?? '');
		if ($home === '' || !is_dir($home)) {
			return Status::notReady(Errors::NO_KEY, 'gemini-cli', 'google');
		}
		if (!str_contains($this->helpText(), '--admin-policy')) {
			return Status::notReady(Errors::NO_CLI, 'gemini-cli', 'google');
		}
		return new Status(true, '', 'google', $this->model(), engine: 'gemini-cli');
	}

	/** What the installed CLI lacks of what the engine would use (for occ talk_ai:check). */
	public function missingFlags(): array {
		return array_values(array_filter(['--admin-policy', '--output-format', '--model'], fn ($f) => !str_contains($this->helpText(), $f)));
	}

	public function models(): array {
		// The CLI's own aliases: it picks the model behind them.
		return ['auto', 'pro', 'flash', 'flash-lite'];
	}

	public function turn(Request $request): Turn {
		$status = $this->status();
		if (!$status->ready) {
			return Turn::failed($status->reason);
		}
		try {
			$dir = CliProcess::tempDir();
			file_put_contents($dir . '/no-tools.toml', "[[rule]]\ntoolName = \"*\"\ndecision = \"deny\"\npriority = 999\n");
			$json = str_contains($this->helpText(), '--output-format');
			$argv = [$this->binary(), '--model', $this->model(), '--admin-policy', $dir . '/no-tools.toml', ...($json ? ['--output-format', 'json'] : [])];
			$process = new CliProcess($argv, $this->env(), $dir);
			$process->write(self::prompt($request));
			$process->closeInput();
			$out = $process->collect(microtime(true) + max(1, (int)($this->settings['timeout'] ?? 600)));
		} catch (\Throwable $e) {
			return Turn::failed(Errors::provider($e->getMessage()));
		}
		if ($out === null) {
			return Turn::failed(Errors::TIMEOUT);
		}
		$text = trim($out);
		$usage = new Usage();
		if ($json) {
			// Some versions write notes before the JSON object.
			$decoded = json_decode(substr($text, max(0, (int)strpos($text, '{'))), true);
			if (!is_array($decoded)) {
				return Turn::failed(self::error($process->stderrTail() ?: 'the answer is not JSON'));
			}
			if (isset($decoded['error'])) {
				return Turn::failed(self::error((string)($decoded['error']['message'] ?? json_encode($decoded['error']))));
			}
			$text = trim((string)($decoded['response'] ?? ''));
			$usage = self::usage((array)($decoded['stats'] ?? []));
		}
		if ($text === '') {
			return Turn::failed(self::error($process->stderrTail() ?: 'no answer'));
		}
		$parsed = TextProtocol::parse($text, array_keys($request->tools));
		return new Turn($parsed['text'], $parsed['calls'], $parsed['answer'], $usage, null, $parsed['calls'] !== [] ? 'tools' : 'end');
	}

	/** Everything in one text: the instructions, the conversation so far, the question. */
	private static function prompt(Request $request): string {
		$turns = TextProtocol::flatten($request->messages);
		$last = array_pop($turns);
		$parts = [trim($request->system . "\n\n" . TextProtocol::instructions($request->tools, $request->answerSchema))];
		if ($turns !== []) {
			$parts[] = "Earlier in this conversation:\n\n" . implode("\n\n", array_map(fn (array $t) => '[' . $t['role'] . "]\n" . $t['text'], $turns));
		}
		$parts[] = ($turns !== [] ? "Now:\n" : '') . (string)($last['text'] ?? '');
		return implode("\n\n---\n\n", $parts);
	}

	/** Tokens from stats.models.<model>.tokens, summed over the models a run used. */
	private static function usage(array $stats): Usage {
		$usage = new Usage();
		foreach ((array)($stats['models'] ?? []) as $model) {
			$t = (array)($model['tokens'] ?? []);
			$usage = $usage->add(new Usage((int)($t['prompt'] ?? 0), (int)($t['candidates'] ?? 0) + (int)($t['thoughts'] ?? 0), (int)($t['cached'] ?? 0), null, true));
		}
		return $usage;
	}

	private static function error(string $text): string {
		return match (true) {
			preg_match('/log ?in|auth|credential|api key/i', $text) === 1 => Errors::NO_KEY,
			preg_match('/quota|rate.?limit|\b429\b|resource.?exhausted/i', $text) === 1 => Errors::RATE_LIMITED,
			preg_match('/overloaded|unavailable|\b503\b/i', $text) === 1 => Errors::BUSY,
			default => Errors::provider($text),
		};
	}

	private function model(): string {
		return (string)($this->settings['model'] ?? '') ?: 'auto';
	}

	private function env(): array {
		return ['HOME' => (string)$this->settings['home'], 'PATH' => (string)($this->settings['path'] ?? '/usr/local/bin:/usr/bin:/bin'), 'LANG' => 'C.UTF-8'];
	}

	private function binary(): string {
		$binary = (string)($this->settings['binary'] ?? 'gemini');
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

	private function helpText(): string {
		$binary = $this->binary();
		return $this->help ??= $binary === '' ? '' : CliProcess::help($binary, $this->env(), (string)($this->settings['home'] ?? ''));
	}
}
