<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Ai\Tickets;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Works out a ticket of the OCS API (Ai\Tickets). Public, as the hand-off comes
 * from the server itself without a login, so it takes only a request signed with
 * the tickets' secret and at most Tickets::MAX_AGE old.
 */
class TicketController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Tickets $tickets,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function run(): DataResponse {
		$body = (string)file_get_contents('php://input');
		if (!$this->tickets->verify($body, (string)$this->request->getHeader('X-Talk-AI-Signature'))) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$job = json_decode($body, true);
		if (!is_array($job) || abs(time() - (int)($job['time'] ?? 0)) > Tickets::MAX_AGE) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		// The caller stops waiting after a second or two; the answer takes longer.
		ignore_user_abort(true);
		set_time_limit(0);
		$this->tickets->run((string)$job['id']);
		// The sign that the ticket was taken here (else a background job takes it).
		return new DataResponse(['taken' => true]);
	}
}
