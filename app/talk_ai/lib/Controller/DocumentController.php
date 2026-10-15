<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\DocumentJob;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/** Runs a document job the Dispatcher handed over; signed like the process route. */
class DocumentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Dispatcher $dispatcher,
		private DocumentJob $documents,
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
		$this->documents->run((int)($job['job'] ?? 0));
		return new DataResponse(['answered' => true]);
	}
}
