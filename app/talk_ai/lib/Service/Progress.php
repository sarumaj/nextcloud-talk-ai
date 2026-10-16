<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The progress of an answer or a document job, shared between the request that
 * works on it and the ticker (TickerController) that shows it in the chat: the
 * current step, the steps the model announced, since when it runs, the status
 * message, and whether it is done.
 * Kept in Nextcloud's distributed cache (Redis, or the local cache) for an hour.
 */
class Progress {
	private const TTL = 3600;
	/** Announced steps shown at most, and their length. */
	public const STEPS = 6;
	public const STEP_LENGTH = 160;
	private ICache $cache;

	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '-progress');
	}

	public function start(string $key, string $token, int $replyTo, string $step, int $statusId = 0, string $userId = '', ?int $since = null): void {
		$this->put($key, ['token' => $token, 'replyTo' => $replyTo, 'step' => $step, 'since' => $since ?? time(), 'status' => $statusId,
			'user' => $userId, 'done' => false]);
	}

	public function step(string $key, string $step): void {
		$state = $this->get($key);
		if ($state !== null && $state['step'] !== $step) {
			$this->put($key, ['step' => $step] + $state);
		}
	}

	/**
	 * A step the model announced (a tool call's "why"), for the ticker to show above the
	 * current step at once: the latest STEPS of them, each cut to STEP_LENGTH characters.
	 * The revision tells the ticker that something new came.
	 */
	public function note(string $key, string $text): void {
		$state = $this->get($key);
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
		if ($state === null || $text === '') {
			return;
		}
		if (mb_strlen($text) > self::STEP_LENGTH) {
			$text = rtrim(mb_substr($text, 0, self::STEP_LENGTH - 1)) . '…';
		}
		$steps = [...($state['steps'] ?? []), $text];
		$this->put($key, ['steps' => array_slice($steps, -self::STEPS), 'revision' => (int)($state['revision'] ?? 0) + 1] + $state);
	}

	/** The status message the ticker posted (or the job's own), to edit or replace. */
	public function setStatus(string $key, int $id): void {
		$state = $this->get($key);
		if ($state !== null) {
			$this->put($key, ['status' => $id] + $state);
		}
	}

	public function done(string $key): void {
		$state = $this->get($key);
		if ($state !== null) {
			$this->put($key, ['done' => true] + $state);
		}
	}

	public function get(string $key): ?array {
		$value = $this->cache->get($key);
		return is_array($value) ? $value : null;
	}

	public function forget(string $key): void {
		$this->cache->remove($key);
	}

	private function put(string $key, array $state): void {
		$this->cache->set($key, $state, self::TTL);
	}
}
