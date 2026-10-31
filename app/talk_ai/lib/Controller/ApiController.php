<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\Tickets;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The AI layer for other clients (docs/developer.md, "OCS API"): the status, a
 * question as a ticket, its result, and forgetting a remembered conversation.
 * With the person's login (session or app password); only the scenarios
 * registered with 'ocs' => true.
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private Hub $hub,
		private Tickets $tickets,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function status(): DataResponse {
		$status = $this->hub->status();
		// The engine's limits and abilities; no setting or key.
		return new DataResponse(['ready' => $status->ready, 'reason' => $status->reason, 'provider' => $status->provider, 'model' => $status->model,
			'images' => $status->images, 'maxImages' => $status->maxImages, 'search' => $status->search, 'documents' => $status->documents,
			'scenarios' => $this->hub->scenarios()]);
	}

	/**
	 * @param string $scenario a scenario open to the OCS API
	 * @param list<array{role: string, text: string}> $messages the question last
	 * @param string $conversation a token of the client's: the question goes on from the remembered turns
	 * @param list<array{type: string, data: string}> $images base64, with the question
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function ask(string $scenario, array $messages = [], string $conversation = '', array $images = [], bool $search = true): DataResponse {
		$userId = (string)$this->session->getUser()?->getUID();
		if (!isset($this->hub->scenarios()[$scenario])) {
			return new DataResponse(['error' => 'no-scenario'], Http::STATUS_NOT_FOUND);
		}
		$messages = array_values(array_map(fn ($m) => ['role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'text' => (string)($m['text'] ?? '')],
			array_filter($messages, 'is_array')));
		$ticket = $this->tickets->ask($userId, $scenario, $messages, array_filter(['conversation' => $conversation, 'images' => $images, 'search' => $search],
			fn ($v) => $v !== '' && $v !== []));
		return isset($ticket['error'])
			? new DataResponse(['error' => $ticket['error']], $ticket['error'] === 'rate-limited' || $ticket['error'] === 'busy' ? Http::STATUS_TOO_MANY_REQUESTS : Http::STATUS_BAD_REQUEST)
			: new DataResponse(['id' => $ticket['id']], Http::STATUS_ACCEPTED);
	}

	#[NoAdminRequired]
	public function result(string $id): DataResponse {
		$result = $this->tickets->result((string)$this->session->getUser()?->getUID(), $id);
		return new DataResponse($result, ($result['error'] ?? '') === 'no-ticket' ? Http::STATUS_NOT_FOUND : Http::STATUS_OK);
	}

	/** Forgets a remembered conversation, or saves it to the person's files first (save: true). */
	#[NoAdminRequired]
	public function forget(string $conversation, bool $save = false, bool $summary = false): DataResponse {
		$userId = (string)$this->session->getUser()?->getUID();
		$path = null;
		if ($save) {
			try {
				$path = $this->hub->saveConversation($userId, $conversation, $summary);
			} catch (\Throwable $e) {
				return new DataResponse(['error' => 'not-saved'], Http::STATUS_NOT_FOUND);
			}
		}
		$this->hub->forget($userId, $conversation);
		return new DataResponse(['saved' => $path]);
	}
}
