<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * Nextcloud's HTTP client replaying recorded answers, in order, and keeping the
 * requests. An answer is [status, body] (a body string; with 'stream' => true the
 * client hands it out as a stream, as Guzzle does) or a \Throwable to throw.
 */
class FakeHttp implements IClientService {
	/** @var list<array{method: string, url: string, options: array, json: ?array}> */
	public array $requests = [];

	/** @param list<array{0: int, 1: string}|\Throwable> $answers */
	public function __construct(public array $answers = []) {
	}

	/** A JSON body. */
	public static function json(int $status, array $body): array {
		return [$status, (string)json_encode($body)];
	}

	/** Server-sent events: [event name or '', data], as a body. */
	public static function events(array $events): array {
		$out = '';
		foreach ($events as [$name, $data]) {
			$out .= ($name !== '' ? 'event: ' . $name . "\n" : '') . 'data: ' . (is_string($data) ? $data : json_encode($data)) . "\n\n";
		}
		return [200, $out];
	}

	/** The last request's JSON body. */
	public function sent(int $index = -1): array {
		return $this->requests[$index < 0 ? count($this->requests) + $index : $index]['json'] ?? [];
	}

	/** What every verb of the client comes to. */
	public function request(string $method, string $uri, array $options = []): IResponse {
		$this->requests[] = ['method' => $method, 'url' => $uri, 'options' => $options, 'json' => isset($options['body']) ? json_decode((string)$options['body'], true) : null];
		$answer = array_shift($this->answers) ?? [500, '{"error": {"message": "no answer recorded"}}'];
		if ($answer instanceof \Throwable) {
			throw $answer;
		}
		[$status, $body] = $answer;
		if ($options['stream'] ?? false) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $body);
			rewind($stream);
			$body = $stream;
		}
		return new class($status, $body) implements IResponse {
			public function __construct(private int $status, private mixed $body) {
			}

			public function getBody() {
				return $this->body;
			}

			public function getStatusCode(): int {
				return $this->status;
			}

			public function getHeader(string $key): string {
				return '';
			}

			public function getHeaders(): array {
				return [];
			}
		};
	}

	/** $handler: Nextcloud 35 added it; an optional parameter fits the older interface too. */
	public function newClient(?callable $handler = null): IClient {
		return self::client($this);
	}

	/**
	 * A client of the installed IClient, built from its methods: Nextcloud adds some
	 * (sendRequest in 33), and a class written against one version fails on the next.
	 * The verbs go to request(); the rest is not used by the engines.
	 */
	private static function client(self $http): IClient {
		if (!class_exists(__NAMESPACE__ . '\\FakeHttpClient', false)) {
			$methods = [];
			foreach ((new \ReflectionClass(IClient::class))->getMethods() as $method) {
				$params = [];
				foreach ($method->getParameters() as $p) {
					$params[] = ($p->hasType() ? self::type($p->getType()) . ' ' : '') . '$' . $p->getName()
						. ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
				}
				$verb = in_array($method->getName(), ['get', 'head', 'post', 'put', 'patch', 'delete', 'options'], true);
				$body = match (true) {
					$method->getName() === 'request' => 'return $this->http->request($method, $uri, $options);',
					$verb => 'return $this->http->request(' . var_export(strtoupper($method->getName()), true) . ', $uri, $options);',
					default => 'throw new \\LogicException("not used");',
				};
				$methods[] = 'public function ' . $method->getName() . '(' . implode(', ', $params) . ')'
					. ($method->hasReturnType() ? ': ' . self::type($method->getReturnType()) : '') . ' { ' . $body . ' }';
			}
			eval('namespace ' . __NAMESPACE__ . '; class FakeHttpClient implements \\' . IClient::class . ' { public function __construct(private FakeHttp $http) {} '
				. implode("\n", $methods) . ' }');
		}
		$class = __NAMESPACE__ . '\\FakeHttpClient';
		return new $class($http);
	}

	private static function type(\ReflectionType $type): string {
		if ($type instanceof \ReflectionNamedType) {
			return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '') . ($type->isBuiltin() ? '' : '\\') . $type->getName();
		}
		return implode($type instanceof \ReflectionUnionType ? '|' : '&', array_map(fn ($t) => self::type($t), $type->getTypes()));
	}
}
