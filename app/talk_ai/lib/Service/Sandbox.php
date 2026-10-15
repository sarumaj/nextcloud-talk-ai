<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The sandbox runner (sandbox/runner.py in the repository): one JSON request per
 * connection over its Unix socket, with the shared token. It runs each document job
 * in its own container (no network, read-only, writable only /work).
 *
 * Without a socket, the runner of the External App talk_ai_sandbox (AppAPI,
 * sandbox/exapp/) when it is installed and enabled: the same requests over AppAPI.
 *
 * App settings: sandbox_socket (path), sandbox_token; sandbox_exapp (the ExApp's id,
 * default talk_ai_sandbox; empty: not used).
 */
class Sandbox {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/** Whether a runner answers; with its state (jobs, max_jobs, image). */
	public function health(): ?array {
		if ($this->socket() === '' && $this->exApp() === '') {
			return null;
		}
		try {
			return $this->call('health', [], 10);
		} catch (\Throwable) {
			return null;
		}
	}

	/** A new container; limits are capped by the runner's own. */
	public function create(array $limits): string {
		return (string)$this->call('create', $limits, 120)['job'];
	}

	/**
	 * Runs a command; it goes on as long as it makes progress (output or CPU time) and is
	 * stopped after the runner's idle time without (stalled) or after $timeout seconds in
	 * all. Every few seconds $waiting(seconds so far, last output line) is called while it
	 * runs; false stops it (a cancelled job). A runner older than 0.5.5 answers only at the end.
	 *
	 * @param ?callable(int, string): bool $waiting
	 * @return array{exit_code: int, stdout: string, stderr: string, truncated: bool, timed_out: bool, stalled?: bool}
	 */
	public function exec(string $job, string $command, int $timeout = 120, ?callable $waiting = null): array {
		$r = $this->call('exec', ['job' => $job, 'command' => $command, 'timeout' => $timeout, 'wait' => self::WAIT],
			max(self::WAIT, min($timeout, 600)) + 30);
		while (!empty($r['running'])) {
			if ($waiting !== null && $waiting((int)($r['seconds'] ?? 0), (string)($r['last'] ?? '')) === false) {
				return $this->call('exec_stop', ['job' => $job, 'run' => $r['run']], 60);
			}
			$r = $this->call('exec_wait', ['job' => $job, 'run' => $r['run'], 'wait' => self::WAIT], self::WAIT + 30);
		}
		return $r;
	}

	/** Seconds the runner waits on a command before it reports that it still runs. */
	private const WAIT = 10;

	public function put(string $job, string $path, string $data): void {
		$this->call('put', ['job' => $job, 'path' => $path, 'data' => base64_encode($data)], 120);
	}

	public function get(string $job, string $path): string {
		return (string)base64_decode((string)$this->call('get', ['job' => $job, 'path' => $path], 120)['data'], true);
	}

	/** @return list<array{path: string, size: int}> */
	public function list(string $job, string $path = '.'): array {
		return $this->call('list', ['job' => $job, 'path' => $path], 60)['files'] ?? [];
	}

	public function delete(string $job): void {
		try {
			$this->call('delete', ['job' => $job], 60);
		} catch (\Throwable) {
			// The runner's reaper removes it once it was left alone for half an hour.
		}
	}

	private function socket(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'sandbox_socket', '');
	}

	/** The ExApp to use ('' when a socket is set, or it is not installed and enabled). */
	private function exApp(): string {
		if ($this->socket() !== '' || !class_exists('OCA\AppAPI\PublicFunctions')) {
			return '';
		}
		$id = $this->appConfig->getValueString(Application::APP_ID, 'sandbox_exapp', 'talk_ai_sandbox');
		try {
			$exApp = $id === '' ? null : \OCP\Server::get('OCA\AppAPI\PublicFunctions')->getExApp($id);
		} catch (\Throwable) {
			return '';
		}
		return is_array($exApp) && !empty($exApp['enabled']) ? $id : '';
	}

	protected function call(string $op, array $params, int $timeout): array {
		$exApp = $this->exApp();
		if ($exApp !== '') {
			return $this->callExApp($exApp, $op, $params, $timeout);
		}
		$socket = $this->socket();
		$connection = @stream_socket_client('unix://' . $socket, $errno, $error, 10);
		if ($connection === false) {
			throw new \RuntimeException('the document sandbox is not reachable (' . $error . ')');
		}
		try {
			stream_set_timeout($connection, $timeout);
			$request = json_encode(['op' => $op, 'token' => $this->appConfig->getValueString(Application::APP_ID, 'sandbox_token', '', lazy: true)] + $params, JSON_THROW_ON_ERROR) . "\n";
			fwrite($connection, $request);
			$line = fgets($connection);
			if ($line === false) {
				throw new \RuntimeException('the document sandbox did not answer' . (stream_get_meta_data($connection)['timed_out'] ? ' in time' : ''));
			}
			$answer = json_decode($line, true);
			if (!is_array($answer)) {
				throw new \RuntimeException('the document sandbox answered something unreadable');
			}
			if (isset($answer['error'])) {
				throw new \RuntimeException('sandbox: ' . $answer['error']);
			}
			return $answer;
		} finally {
			fclose($connection);
		}
	}

	/** One request to the ExApp's runner through AppAPI (which signs it with the ExApp's secret). */
	private function callExApp(string $exApp, string $op, array $params, int $timeout): array {
		$response = \OCP\Server::get('OCA\AppAPI\PublicFunctions')->exAppRequest($exApp, '/op', null, 'POST', ['op' => $op] + $params,
			['timeout' => $timeout, 'connect_timeout' => 10]);
		if (is_array($response)) {
			throw new \RuntimeException('the document sandbox (External App) is not reachable (' . ($response['error'] ?? 'unknown') . ')');
		}
		$answer = json_decode((string)$response->getBody(), true);
		if ($response->getStatusCode() !== 200 || !is_array($answer)) {
			throw new \RuntimeException('the document sandbox (External App) answered HTTP ' . $response->getStatusCode());
		}
		if (isset($answer['error'])) {
			throw new \RuntimeException('sandbox: ' . $answer['error']);
		}
		return $answer;
	}
}
