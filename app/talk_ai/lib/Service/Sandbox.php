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
 * App settings: sandbox_socket (path), sandbox_token.
 */
class Sandbox {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/** Whether a runner answers; with its state (jobs, max_jobs, image). */
	public function health(): ?array {
		if ($this->socket() === '') {
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

	/** @return array{exit_code: int, stdout: string, stderr: string, truncated: bool, timed_out: bool} */
	public function exec(string $job, string $command, int $timeout = 120): array {
		return $this->call('exec', ['job' => $job, 'command' => $command, 'timeout' => $timeout], $timeout + 30);
	}

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
			// The runner's reaper removes it at the latest when its time is up.
		}
	}

	private function socket(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'sandbox_socket', '');
	}

	private function call(string $op, array $params, int $timeout): array {
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
}
