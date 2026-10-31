<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\Http\Client\IClientService;

/**
 * The engines' HTTP: Nextcloud's client with timeouts, JSON in and out, and
 * server-sent events for streaming. Nothing is thrown: a failure comes back as
 * an error word (Errors), without the request's headers (they hold the key).
 */
class Http {
	public function __construct(
		private IClientService $clients,
	) {
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, json: ?array, body: string, error: string} error '' for a 2xx with a body
	 */
	public function json(string $method, string $url, array $headers, ?array $body, int $timeout): array {
		try {
			$response = $this->clients->newClient()->request($method, $url, $this->options($headers, $body, $timeout));
			$text = (string)$response->getBody();
			return self::result($response->getStatusCode(), $text);
		} catch (\Throwable $e) {
			return ['status' => 0, 'json' => null, 'body' => '', 'error' => self::thrown($e)];
		}
	}

	/**
	 * A POST whose answer streams as server-sent events; $onEvent gets each event's name
	 * and decoded data. A provider that answers with an error answers with JSON instead.
	 *
	 * @param callable(string $event, array $data): void $onEvent
	 * @return array{status: int, json: ?array, body: string, error: string}
	 */
	public function events(string $url, array $headers, array $body, int $timeout, callable $onEvent): array {
		try {
			// A stream that stalls ends after two minutes of silence, not after the whole timeout.
			$response = $this->clients->newClient()->post($url, ['stream' => true, 'read_timeout' => min($timeout, 120)]
				+ $this->options($headers + ['Accept' => 'text/event-stream'], $body, $timeout));
			$status = $response->getStatusCode();
			$stream = $response->getBody();
			if ($status < 200 || $status >= 300 || !is_resource($stream)) {
				return self::result($status, is_resource($stream) ? (string)stream_get_contents($stream) : (string)$stream);
			}
			self::readEvents($stream, $onEvent);
			return ['status' => $status, 'json' => null, 'body' => '', 'error' => ''];
		} catch (\Throwable $e) {
			return ['status' => 0, 'json' => null, 'body' => '', 'error' => self::thrown($e)];
		}
	}

	/** Server-sent events from a stream: "event:" and "data:" lines, an empty line ends one. */
	public static function readEvents($stream, callable $onEvent): void {
		$event = 'message';
		$data = [];
		$emit = function () use (&$event, &$data, $onEvent): void {
			$joined = implode("\n", $data);
			if ($data !== [] && $joined !== '[DONE]') {
				$decoded = json_decode($joined, true);
				$onEvent($event, is_array($decoded) ? $decoded : ['text' => $joined]);
			}
			$event = 'message';
			$data = [];
		};
		while (($line = fgets($stream)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '') {
				$emit();
			} elseif (str_starts_with($line, 'event:')) {
				$event = trim(substr($line, 6));
			} elseif (str_starts_with($line, 'data:')) {
				$data[] = ltrim(substr($line, 5));
			}
		}
		$emit();
	}

	private function options(array $headers, ?array $body, int $timeout): array {
		return [
			'headers' => $headers + ['Content-Type' => 'application/json'],
			'timeout' => $timeout,
			'connect_timeout' => 15,
			// The status is ours to read: a 429 or 529 is an answer, not an exception.
			'http_errors' => false,
			// A local model server (Ollama, vLLM) is on the same host or network.
			'nextcloud' => ['allow_local_address' => true],
		] + ($body !== null ? ['body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)] : []);
	}

	private static function result(int $status, string $text): array {
		$json = json_decode($text, true);
		$json = is_array($json) ? $json : null;
		if ($status >= 200 && $status < 300) {
			return ['status' => $status, 'json' => $json, 'body' => $text, 'error' => $json === null ? Errors::provider('the answer is not JSON') : ''];
		}
		$message = (string)($json['error']['message'] ?? $json['error'] ?? $json['message'] ?? $json['detail'] ?? '');
		return ['status' => $status, 'json' => $json, 'body' => $text, 'error' => Errors::http($status, is_string($message) ? $message : '')];
	}

	private static function thrown(\Throwable $e): string {
		$message = $e->getMessage();
		return preg_match('/timed out|timeout/i', $message) === 1 ? Errors::TIMEOUT : Errors::provider('connection: ' . preg_replace('/\s*\(see .*$/', '', $message));
	}
}
