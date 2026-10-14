<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\BackgroundJob\ReplyJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Hands a question off the request that posted it: a signed request to the
 * process route, which the server keeps working on after this side stops waiting
 * (a second or two); when that cannot be sent, a background job (minutes later,
 * with the next cron run).
 */
class Dispatcher {
	private const HANDOFF_TIMEOUT = 2;
	/** A hand-off older than this is refused (seconds). */
	public const MAX_AGE = 300;

	public function __construct(
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	public function dispatch(string $token, string $userId, int $messageId, string $text): void {
		$body = json_encode([
			'token' => $token, 'userId' => $userId, 'messageId' => $messageId, 'text' => $text,
			'time' => $this->time->getTime(), 'nonce' => $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		try {
			$this->clientService->newClient()->post($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.process.run'), [
				'headers' => ['Content-Type' => 'application/json', 'X-Talk-AI-Signature' => $this->sign($body)],
				'body' => $body,
				'timeout' => self::HANDOFF_TIMEOUT,
				'connect_timeout' => 10,
				'nextcloud' => ['allow_local_address' => true],
			]);
		} catch (\Throwable $e) {
			if (self::stillWorking($e)) {
				return;
			}
			$this->logger->warning('AI bot: no hand-off over HTTP, answering in a background job: ' . $e->getMessage());
			// The job's arguments are limited to 4000 bytes: the text waits in the app config.
			$ref = $this->time->getTime() . '_' . $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, 'pending_' . $ref, $text, lazy: true);
			$this->jobList->add(ReplyJob::class, ['token' => $token, 'userId' => $userId, 'messageId' => $messageId, 'ref' => $ref]);
		}
	}

	public function sign(string $body): string {
		return hash_hmac('sha256', $body, $this->appConfig->getValueString(Application::APP_ID, 'secret', '', lazy: true));
	}

	public function verify(string $body, string $signature): bool {
		$secret = $this->appConfig->getValueString(Application::APP_ID, 'secret', '', lazy: true);
		return $secret !== '' && $signature !== '' && hash_equals($this->sign($body), strtolower($signature));
	}

	/** Whether the hand-off arrived and is being worked on: the wait timed out, not the connection. */
	private static function stillWorking(\Throwable $e): bool {
		$message = strtolower($e->getMessage());
		return str_contains($message, 'operation timed out after') && !str_contains($message, 'connection timed out');
	}
}
