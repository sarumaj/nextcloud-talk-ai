<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Listener\BotEnabledListener;
use OCA\TalkAi\Listener\BotInvokeListener;
use OCA\TalkAi\Matrix\Event as MatrixEvent;
use OCA\TalkAi\Matrix\Store;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Requests;
use OCA\TalkAi\Service\Switches;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * The bot in Matrix (OCS API, /ocs/v2.php/apps/talk_ai/api/v1/matrix/…): a Matrix
 * adapter hands in the rooms' messages, edits, removals and reactions, and carries out
 * what the bot does there (the outbox), so that the bot answers in Matrix as it does in
 * Talk, with every tool.
 *
 * A message, edit or reaction comes with the login of the person who wrote it (their app
 * password, which the adapter holds), so the bot answers with that person's rights, as in
 * Talk. What is not a person's (the outbox, the posted events, others' messages for the
 * history, the bot joining a room) takes the login of the adapter's own account, the
 * setting matrix_service_user; with that setting empty, the API is off.
 */
class MatrixController extends OCSController {
	private const CANCEL_REACTIONS = ['❌', '✋', '🛑', '⛔', '✖️'];

	public function __construct(
		string $appName,
		IRequest $request,
		private Store $store,
		private Conversation $conversation,
		private BotInvokeListener $listener,
		private BotEnabledListener $enabled,
		private Dispatcher $dispatcher,
		private Requests $requests,
		private Jobs $jobs,
		private Switches $switches,
		private Bot $bot,
		private IAppConfig $appConfig,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** The bot's name (the adapter's display name follows it), and whether the caller is the adapter's account. */
	#[NoAdminRequired]
	public function info(): DataResponse {
		if ($this->serviceUser() === '') {
			return new DataResponse(['error' => 'Matrix is off'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['name' => $this->bot->name(), 'service' => $this->isService()]);
	}

	/**
	 * A person's message in a Matrix room (their own login): kept for the history, and
	 * answered when it is meant for the bot, as in Talk. An edit (edit_of) of a message the
	 * bot answered within the hour is answered anew, its old answer removed.
	 */
	#[NoAdminRequired]
	public function message(string $room_id, string $event_id, string $text = '', string $room_name = '', int $people = 2, bool $moderator = false,
		string $sender_name = '', array $files = [], string $reply_to_event = '', string $edit_of = '', bool $voice = false): DataResponse {
		$uid = (string)$this->userId;
		if ($this->serviceUser() === '' || $uid === '' || $room_id === '' || $event_id === '') {
			return new DataResponse(['error' => 'room_id and event_id needed'], Http::STATUS_BAD_REQUEST);
		}
		$room = $this->store->saveRoom($room_id, $room_name, $people);
		$token = $room->getToken();
		$this->store->saveMember($token, $uid, $moderator);
		if ($edit_of !== '') {
			return $this->edited($room, $uid, $edit_of, $text);
		}
		$known = $this->store->byEvent($token, $event_id);
		if ($known !== null) {
			return new DataResponse(['message_id' => $known->id]); // the adapter retried
		}
		$parent = $this->store->byEvent($token, $reply_to_event);
		$files = self::files($files);
		$message = $this->store->addMessage($token, $event_id, $uid, $sender_name !== '' ? $sender_name : $uid, false,
			self::withFiles($text, $files), $files, $parent?->id ?? 0);
		// A file alone is no question; a voice message or a file with a caption is (as in Talk).
		$question = trim($text);
		if ($question === '' && $files !== [] && $voice) {
			$question = '{file}';
		}
		if ($question !== '') {
			$this->listener->question(new MatrixEvent($this->store, $this->conversation, $room, $message), 'users/' . $uid, $token, $message->id,
				$question, $parent !== null && $parent->bot);
		}
		return new DataResponse(['message_id' => $message->id]);
	}

	/** Someone else's message (another server's people, other bots), for the history only: the adapter's login. */
	#[NoAdminRequired]
	public function observe(string $room_id, string $event_id, string $text = '', string $room_name = '', int $people = 2, string $sender_name = '',
		string $reply_to_event = ''): DataResponse {
		if (!$this->isService()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$room = $this->store->saveRoom($room_id, $room_name, $people);
		if ($this->store->byEvent($room->getToken(), $event_id) === null) {
			$parent = $this->store->byEvent($room->getToken(), $reply_to_event);
			$this->store->addMessage($room->getToken(), $event_id, '', $sender_name, false, $text, [], $parent?->id ?? 0);
		}
		return new DataResponse([]);
	}

	/** A message was removed in Matrix: gone from the history, and the bot's answer to it too. */
	#[NoAdminRequired]
	public function redaction(string $room_id, string $event_id): DataResponse {
		if ($this->serviceUser() === '' || (string)$this->userId === '') {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$room = $this->conversation->room(Store::token($room_id));
		$target = $this->store->byEvent($room->getToken(), $event_id);
		if ($target !== null && !$target->bot) {
			$this->store->markDeleted($room->getToken(), $target->id);
			if ($this->requests->answeredAt($room->getToken(), $target->id) !== 0) {
				$this->conversation->removeBotReplies($room, $target->id);
			}
		}
		return new DataResponse([]);
	}

	/** A reaction (the person's login): ❌ ✋ 🛑 on a job's status cancel it, 👍 👎 on a delivered document rate it. */
	#[NoAdminRequired]
	public function reaction(string $room_id, string $event_id, string $key): DataResponse {
		$uid = (string)$this->userId;
		if ($this->serviceUser() === '' || $uid === '') {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$token = Store::token($room_id);
		$target = $this->store->byEvent($token, $event_id);
		if ($target === null || !$target->bot) {
			return new DataResponse([]);
		}
		$fileId = (int)($target->files[0]['id'] ?? 0);
		$job = $this->jobs->byMessage($token, $fileId, $target->id);
		if ($job === null || $job['user_id'] !== $uid) {
			return new DataResponse([]);
		}
		if (in_array($key, self::CANCEL_REACTIONS, true)) {
			$this->jobs->cancel($job['id']);
		} elseif (in_array($key, ['👍', '👎'], true) && $fileId > 0) {
			$this->jobs->setOption($job['id'], 'rating', ['value' => $key === '👍' ? 1 : -1, 'by' => $uid, 'file' => $fileId, 'time' => time()]);
		}
		return new DataResponse([]);
	}

	/** The bot joined a room (the adapter's login): the notice that an AI answers here, as when it is enabled in Talk. */
	#[NoAdminRequired]
	public function joined(string $room_id, string $room_name = '', int $people = 2): DataResponse {
		if (!$this->isService()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$room = $this->store->saveRoom($room_id, $room_name, $people);
		$this->conversation->postText($room, $this->enabled->notice(), 0);
		return new DataResponse([]);
	}

	/** What the adapter is to do in Matrix, after the task it did last (the adapter's login). */
	#[NoAdminRequired]
	public function outbox(int $after = 0): DataResponse {
		if (!$this->isService()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		return new DataResponse(['tasks' => $this->store->outbox($after)]);
	}

	/** The adapter did the tasks up to this one: they are forgotten. */
	#[NoAdminRequired]
	public function acknowledge(int $up_to): DataResponse {
		if (!$this->isService()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$this->store->acknowledge($up_to);
		return new DataResponse([]);
	}

	/** The Matrix event a message of the bot became (to edit it, and to tell replies to it). */
	#[NoAdminRequired]
	public function posted(string $room_id, int $message_id, string $event_id): DataResponse {
		if (!$this->isService()) {
			return new DataResponse([], Http::STATUS_FORBIDDEN);
		}
		$this->store->setEvent(Store::token($room_id), $message_id, $event_id);
		return new DataResponse([]);
	}

	private function edited(object $room, string $uid, string $eventId, string $text): DataResponse {
		$token = $room->getToken();
		$target = $this->store->byEvent($token, $eventId);
		if ($target === null || $target->bot) {
			return new DataResponse([]);
		}
		$this->store->setText($token, $target->id, self::withFiles($text, $target->files));
		if ($this->requests->answeredAt($token, $target->id) === 0 || $target->userId !== $uid || time() - $target->time > 3600
			|| trim($text) === '' || $this->switches->off($token, $uid) !== '') {
			return new DataResponse(['message_id' => $target->id]);
		}
		$this->conversation->removeBotReplies($room, $target->id);
		$this->requests->release($token, $target->id);
		$this->dispatcher->dispatch($token, $uid, $target->id, $text);
		return new DataResponse(['message_id' => $target->id]);
	}

	private function serviceUser(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, 'matrix_service_user', ''));
	}

	private function isService(): bool {
		$service = $this->serviceUser();
		return $service !== '' && $this->userId === $service;
	}

	/**
	 * The files the adapter put into the person's folder for the message.
	 *
	 * @return list<array{id: int, name: string, mime: string, size: int}>
	 */
	private static function files(array $files): array {
		$out = [];
		foreach (array_slice($files, 0, 20) as $file) {
			if (is_array($file) && (int)($file['id'] ?? 0) > 0) {
				$out[] = ['id' => (int)$file['id'], 'name' => mb_substr((string)($file['name'] ?? ''), 0, 255),
					'mime' => mb_substr((string)($file['mime'] ?? ''), 0, 127), 'size' => (int)($file['size'] ?? 0)];
			}
		}
		return $out;
	}

	/** The message's text as the history shows it, with its files as Talk's are written: [file "name", id N]. */
	public static function withFiles(string $text, array $files): string {
		$named = array_map(fn (array $f) => '[file "' . $f['name'] . '", id ' . $f['id'] . ']', $files);
		return trim($text . ($named === [] ? '' : ' ' . implode(' ', $named)));
	}
}
