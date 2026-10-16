<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Progress;
use OCA\TalkAi\Service\Texts;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * The ticker beside a slow answer or document job (handed over by the Dispatcher,
 * signed like the process route): from ten seconds on it shows the current step
 * and the time it runs in a status message, changed every twenty seconds, until
 * the work is done (then the answer takes the message's place) or an hour passed.
 * The steps the model announced stand above it, one per line, and a new one is
 * shown at the next poll rather than after the twenty seconds.
 */
class TickerController extends Controller {
	public const FIRST = 10;
	public const EVERY = 20;
	private const POLL = 3;
	private const LIMIT = 3600;

	public function __construct(
		string $appName,
		IRequest $request,
		private Dispatcher $dispatcher,
		private Progress $progress,
		private Conversation $conversation,
		private Texts $texts,
		private ITimeFactory $time,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function run(): DataResponse {
		$body = (string)file_get_contents('php://input');
		if (!$this->dispatcher->verify($body, (string)$this->request->getHeader('X-Talk-AI-Signature'))) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$job = json_decode($body, true);
		if (!is_array($job) || abs($this->time->getTime() - (int)($job['time'] ?? 0)) > Dispatcher::MAX_AGE) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		ignore_user_abort(true);
		set_time_limit(0);
		$this->tick((string)$job['key']);
		return new DataResponse(['answered' => true]);
	}

	/** The loop; public for the tests. */
	public function tick(string $key, ?callable $sleep = null): void {
		$sleep ??= fn (int $s) => sleep($s);
		$shown = 0;
		$revision = 0;
		$room = null;
		for ($waited = 0; $waited < self::LIMIT; $waited += self::POLL) {
			$sleep(self::POLL);
			$state = $this->progress->get($key);
			if ($state === null || $state['done']) {
				return;
			}
			$elapsed = $this->time->getTime() - (int)$state['since'];
			$announced = (int)($state['revision'] ?? 0) !== $revision;
			if ($elapsed < self::FIRST || ($shown > 0 && !$announced && $this->time->getTime() - $shown < self::EVERY)) {
				continue;
			}
			$room ??= $this->conversation->room((string)$state['token']);
			$revision = (int)($state['revision'] ?? 0);
			$text = self::steps($state['steps'] ?? []) . $state['step'] . ' · ' . self::duration($elapsed) . ' '
				. $this->texts->t((string)$state['user'], '(“stop” cancels)');
			if ((int)$state['status'] > 0) {
				$comment = $this->conversation->comment($room, (int)$state['status']);
				if ($comment !== null) {
					$this->conversation->editText($room, $comment, $text);
				}
			} else {
				$comment = $this->conversation->postText($room, $text, (int)$state['replyTo']);
				$this->progress->setStatus($key, (int)$comment->getId());
				// Finished in the meantime: the answer did not see this message, so it goes.
				if ($this->progress->get($key)['done'] ?? true) {
					$this->conversation->removeMessage($room, $comment);
					return;
				}
			}
			$shown = $this->time->getTime();
		}
	}

	/** The announced steps as lines above the current one (quoted, so they read as the bot's notes). */
	public static function steps(array $steps): string {
		return implode('', array_map(fn ($step) => '> ' . $step . "\n", $steps)) . ($steps === [] ? '' : "\n");
	}

	public static function duration(int $seconds): string {
		return $seconds < 60 ? $seconds . ' s' : intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . ' min';
	}
}
