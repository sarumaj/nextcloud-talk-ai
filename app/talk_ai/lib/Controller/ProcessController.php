<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Reply;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Works out an answer the Dispatcher handed over. Public, as the hand-off comes
 * from the server itself without a login, so it takes only a request signed with
 * the bot's secret and at most Dispatcher::MAX_AGE old.
 */
class ProcessController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Dispatcher $dispatcher,
		private Reply $reply,
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
		// The caller stops waiting after a second or two; the answer takes longer.
		ignore_user_abort(true);
		set_time_limit(0);
		$this->reply->answer((string)$job['token'], (string)$job['userId'], (int)$job['messageId'], (string)$job['text']);
		// The Dispatcher's sign that the question was taken here.
		return new DataResponse(['answered' => true]);
	}
}
