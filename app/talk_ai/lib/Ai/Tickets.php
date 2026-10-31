<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * Questions for callers that must not wait (the OCS API): ask() gives a ticket at
 * once, the question is worked out apart, and result() gives its state. It is
 * handed off as a signed request to our own run route, which the server keeps
 * working on after this side stops waiting; when that cannot be sent, a
 * background job takes it (with the next cron run). Questions and results are
 * kept in the app config (lazy), the results for KEEP seconds.
 */
class Tickets {
	public const KEEP = 1800;
	/** A hand-off older than this is refused (seconds). */
	public const MAX_AGE = 300;
	private const HANDOFF_TIMEOUT = 2;

	public function __construct(
		private Hub $hub,
		private IAppConfig $appConfig,
		private IJobList $jobList,
		private IClientService $clients,
		private IURLGenerator $urls,
		private ISecureRandom $random,
		private Config $config,
	) {
	}

	/**
	 * @param array{search?: bool, images?: list<array>, conversation?: string} $options what can be kept: no callbacks
	 * @return array{id?: string, error?: string}
	 */
	public function ask(string $userId, string $scenario, array $messages, array $options = []): array {
		$refused = $this->hub->refused($userId, $scenario, $options);
		if ($refused !== '') {
			return ['error' => $refused];
		}
		$this->clean();
		$id = $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
		$options = array_intersect_key($options, ['search' => 0, 'images' => 0, 'conversation' => 0, 'thinking' => 0]);
		$this->put($id, ['user' => $userId, 'scenario' => $scenario, 'messages' => $messages, 'options' => $options,
			'state' => 'queued', 'expires' => time() + self::KEEP]);
		$this->handOff($id);
		return ['id' => $id];
	}

	/** @return array{state: string, text?: string, answer?: mixed, usage?: array, error?: string} state queued, running, done or error */
	public function result(string $userId, string $id): array {
		$ticket = $this->get($id);
		// Another person's ticket is as unknown as a missing one.
		if ($ticket === null || $ticket['user'] !== $userId) {
			return ['state' => 'error', 'error' => 'no-ticket'];
		}
		return array_intersect_key($ticket, ['state' => 0, 'text' => 0, 'answer' => 0, 'usage' => 0, 'error' => 0, 'tools' => 0]);
	}

	/** Works a ticket out (the run route or the background job); once, whoever comes first. */
	public function run(string $id): void {
		$ticket = $this->get($id);
		if ($ticket === null || $ticket['state'] !== 'queued') {
			return;
		}
		$this->put($id, ['state' => 'running'] + $ticket);
		try {
			$result = $this->hub->ask($ticket['user'], $ticket['scenario'], $ticket['messages'], $ticket['options']);
		} catch (\Throwable $e) {
			$result = ['state' => 'error', 'error' => Errors::provider($e->getMessage())];
		}
		$this->put($id, ['state' => $result['state'], 'text' => (string)($result['text'] ?? ''), 'answer' => $result['answer'] ?? null,
			'usage' => $result['usage'] ?? [], 'tools' => $result['tools'] ?? [], 'error' => (string)($result['error'] ?? ''),
			'messages' => [], 'expires' => time() + self::KEEP] + $ticket);
	}

	public function sign(string $body): string {
		return hash_hmac('sha256', $body, $this->secret());
	}

	public function verify(string $body, string $signature): bool {
		return $signature !== '' && hash_equals($this->sign($body), strtolower($signature));
	}

	/** Tickets past their time. */
	public function clean(): void {
		foreach ($this->appConfig->getKeys(Engines::APP) as $key) {
			if (str_starts_with($key, 'ai_ticket_')) {
				$ticket = json_decode($this->appConfig->getValueString(Engines::APP, $key, '', lazy: true), true);
				if (!is_array($ticket) || (int)($ticket['expires'] ?? 0) < time()) {
					$this->appConfig->deleteKey(Engines::APP, $key);
				}
			}
		}
	}

	private function handOff(string $id): void {
		$body = (string)json_encode(['id' => $id, 'time' => time()]);
		try {
			$response = $this->clients->newClient()->post($this->urls->getAbsoluteURL('/index.php/apps/' . Engines::APP . '/api/v1/run'), [
				'headers' => ['Content-Type' => 'application/json', 'X-Talk-AI-Signature' => $this->sign($body)],
				'body' => $body,
				'timeout' => self::HANDOFF_TIMEOUT,
				'connect_timeout' => 10,
				'nextcloud' => ['allow_local_address' => true],
			]);
			if (str_contains((string)$response->getBody(), '"taken":true')) {
				return;
			}
		} catch (\Throwable $e) {
			// The wait timed out, not the connection: the route has it.
			$message = strtolower($e->getMessage());
			if (str_contains($message, 'operation timed out after') && !str_contains($message, 'connection timed out')) {
				return;
			}
		}
		$this->jobList->add(TicketJob::class, ['id' => $id]);
	}

	private function secret(): string {
		$secret = $this->config->secret('hub', 'loopback');
		if ($secret === '') {
			$secret = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->config->setSecret('hub', 'loopback', $secret);
		}
		return $secret;
	}

	private function get(string $id): ?array {
		if (preg_match('/^[A-Za-z0-9]{24}$/', $id) !== 1) {
			return null;
		}
		$ticket = json_decode($this->appConfig->getValueString(Engines::APP, 'ai_ticket_' . $id, '', lazy: true), true);
		return is_array($ticket) && (int)($ticket['expires'] ?? 0) >= time() ? $ticket : null;
	}

	private function put(string $id, array $ticket): void {
		$this->appConfig->setValueString(Engines::APP, 'ai_ticket_' . $id, (string)json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), lazy: true);
	}
}
