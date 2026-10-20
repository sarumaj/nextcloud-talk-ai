<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Listener;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Confirmations;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Requests;
use OCA\TalkAi\Service\Switches;
use OCA\TalkAi\Service\Texts;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Talk's call for each message and reaction in a conversation where the bot is
 * enabled (BotInvokeEvent), while it is still being posted: nothing slow happens
 * here.
 *
 * A message the bot answers gets 👀 at once and goes to the Dispatcher. It answers
 * people only, never bots, and in a conversation with one person every message;
 * with more, a message that names it ("@Claude …", "Claude, …"), replies to it, or
 * answers its open question (a confirmation, a preview). Without being named it
 * also takes "stop" for the person's running document job.
 *
 * Reactions: ❌ ✋ 🛑 on a job's status message cancel the job; 👍 👎 on a delivered
 * document are kept as feedback. Edits and deletions of a message it answered: see
 * changed().
 *
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {
	private const STOP = '/^\s*(@\S+\s+)?(stop+|halt|abbrechen|abbruch|cancel|przerwij|anuluj|stój)\s*[!.]*\s*$/iu';
	private const CANCEL_REACTIONS = ['❌', '✋', '🛑', '⛔', '✖️'];

	public function __construct(
		private Dispatcher $dispatcher,
		private Conversation $conversation,
		private Bot $bot,
		private Jobs $jobs,
		private Switches $switches,
		private IAppConfig $appConfig,
		private Texts $texts,
		private Requests $requests,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getBotUrl') || $event->getBotUrl() !== Application::BOT_URL) {
			return;
		}
		$activity = $event->getMessage();
		// Nothing may escape into Talk's posting of the message.
		try {
			match ($activity['type'] ?? '') {
				'Create' => $this->message($event, $activity),
				'Like' => $this->reaction($activity),
				'Activity' => $this->changed($event, $activity),
				default => null,
			};
		} catch (\Throwable $e) {
			$this->logger->error('AI bot: the message could not be handed on: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function message(Event $event, array $activity): void {
		$object = $activity['object'] ?? [];
		$actorId = (string)($activity['actor']['id'] ?? '');
		if (!is_array($object) || ($object['name'] ?? '') !== 'message') {
			return;
		}
		$repliesToBot = str_starts_with((string)($activity['object']['inReplyTo']['actor']['id'] ?? $activity['inReplyTo']['actor']['id'] ?? ''), 'bots/');
		$this->question($event, $actorId, (string)($activity['target']['id'] ?? ''), (int)($object['id'] ?? 0),
			self::plainText((string)($object['content'] ?? '')), $repliesToBot);
	}

	/**
	 * A file shared with a caption ("explain this picture" under a photo): Talk tells bots
	 * of it as a system message (an Activity), not as a message, so it went unanswered
	 * until the person wrote again (2026-10-15). Its caption is the question; the file is
	 * the newest in the conversation, which the answer takes along.
	 */
	private function captioned(Event $event, array $activity): void {
		$actorId = (string)($activity['actor']['id'] ?? '');
		$token = (string)($activity['target']['id'] ?? '');
		$messageId = (int)($activity['object']['id'] ?? 0);
		if (!str_starts_with($actorId, 'users/') && !str_starts_with($actorId, 'guests/') && !str_starts_with($actorId, 'federated_users/')) {
			return;
		}
		if ($token === '' || $messageId === 0) {
			return;
		}
		$room = $this->conversation->room($token);
		$comment = $this->conversation->comment($room, $messageId);
		$data = $comment === null ? null : json_decode((string)$comment->getMessage(), true);
		if (!is_array($data) || ($data['message'] ?? '') !== 'file_shared') {
			return;
		}
		$caption = trim((string)($data['parameters']['metaData']['caption'] ?? ''));
		// A voice message is a question too (transcribed by Reply): in a group, as a reply to the bot.
		$voice = ($data['parameters']['metaData']['messageType'] ?? '') === 'voice-message';
		if ($caption === '' && !$voice) {
			return; // a file alone is no question
		}
		// Talk's parsed text of it has the mentions as names ("@Claude"); the stored caption has their ids.
		$parsed = trim(str_replace('{file}', '', self::plainText((string)($activity['object']['content'] ?? ''))));
		$parent = (int)$comment->getParentId() > 0 ? $this->conversation->comment($room, (int)$comment->getParentId()) : null;
		$text = $parsed !== '' && !str_starts_with($parsed, '{') ? $parsed : $caption;
		$this->question($event, $actorId, $token, $messageId, $text !== '' ? $text : '{file}', $parent !== null && $parent->getActorType() === 'bots');
	}

	/**
	 * A message to answer, or not: the bot named, a reply to it, a confirmation open, or a conversation with it alone.
	 * $event is Talk's BotInvokeEvent, or anything with its addReaction() and addAnswer() (a Matrix room's, MatrixEvent).
	 */
	public function question(object $event, string $actorId, string $token, int $messageId, string $text, bool $repliesToBot): void {
		$userId = self::userOf($actorId);
		if ($token === '' || $userId === '' || $messageId === 0 || trim($text) === '') {
			return;
		}
		$named = self::names($text, $this->bot->name());
		$guest = !str_starts_with($actorId, 'users/');
		if (!$guest) {
			// The person's running job: "stop" cancels it, and a waiting preview takes this as its answer.
			$job = $this->jobs->active($token, $userId);
			if ($job !== null && preg_match(self::STOP, $text) === 1) {
				$this->jobs->cancel($job['id']);
				$event->addReaction('✋');
				return;
			}
			if ($job !== null && ($job['feedback'] ?? null) === '') {
				$this->jobs->setFeedback($job['id'], self::withoutName($text, $this->bot->name()));
				$event->addReaction(Confirmations::answer($text) === 'yes' ? '👍' : '✍️');
				return;
			}
		}
		$open = !$guest && $this->confirmationOpen($token, $userId);
		if (!$repliesToBot && !$named && !$open && $this->conversation->numberOfPeople($token) > 1) {
			return;
		}
		if ($guest && !$this->switches->guestsAllowed()) {
			$event->addAnswer('ℹ️ ' . $this->texts->t('', '%s answers only members of this Nextcloud; an administrator can allow guests.', [$this->bot->name()]), $messageId);
			return;
		}
		$off = $this->switches->off($token, $userId);
		if ($off !== '') {
			$event->addAnswer('⏸️ ' . $this->texts->t($userId, match ($off) {
				'room' => '%s is switched off in this conversation by the administrator.',
				'user' => '%s is switched off for you by the administrator.',
				default => '%s is switched off on this server for now by the administrator.',
			}, [$this->bot->name()]), $messageId);
			return;
		}
		$event->addReaction('👀');
		$this->dispatcher->dispatch($token, $userId, $messageId, $text);
	}

	/**
	 * A message the bot answered was edited (within an hour, by its author): the
	 * answer goes and the new text is answered. Deleted: the answer goes too.
	 */
	private function changed(Event $event, array $activity): void {
		$kind = (string)($activity['object']['name'] ?? '');
		// A shared file comes as an Activity named after Talk's raw message: "message" with a
		// caption, "file_shared" without (a voice message; checked on Talk 23, 2026-10-15).
		if ($kind === 'message' || $kind === 'file_shared') {
			$this->captioned($event, $activity);
			return;
		}
		if (!in_array($kind, ['message_edited', 'message_deleted'], true)) {
			return;
		}
		$token = (string)($activity['target']['id'] ?? '');
		$actor = (string)($activity['actor']['id'] ?? '');
		$room = $this->conversation->room($token);
		$target = $this->conversation->editedMessage($room, (int)($activity['object']['id'] ?? 0));
		if ($target === null || $this->requests->answeredAt($token, $target['id']) === 0) {
			return;
		}
		if ($kind === 'message_deleted') {
			$this->conversation->removeBotReplies($room, $target['id']);
			return;
		}
		if ($target['author'] !== $actor || time() - $target['time'] > 3600) {
			return;
		}
		$userId = self::userOf($actor);
		$text = self::plainText($target['text']);
		if ($userId === '' || trim($text) === '' || $this->switches->off($token, $userId) !== '') {
			return;
		}
		$this->conversation->removeBotReplies($room, $target['id']);
		$this->requests->release($token, $target['id']);
		$this->dispatcher->dispatch($token, $userId, $target['id'], $text);
	}

	/** A reaction: cancel by ❌ on a status message, feedback by 👍 or 👎 on a delivered document. */
	private function reaction(array $activity): void {
		$userId = self::userOf((string)($activity['actor']['id'] ?? ''));
		$token = (string)($activity['target']['id'] ?? '');
		$messageId = (int)($activity['object']['id'] ?? 0);
		$emoji = (string)($activity['content'] ?? '');
		if ($userId === '' || $token === '' || $messageId === 0) {
			return;
		}
		$content = json_decode((string)($activity['object']['content'] ?? ''), true);
		$fileId = (int)($content['parameters']['file']['id'] ?? 0);
		$job = $this->jobs->byMessage($token, $fileId, $messageId);
		if ($job === null || $job['user_id'] !== $userId) {
			return;
		}
		if (in_array($emoji, self::CANCEL_REACTIONS, true)) {
			$this->jobs->cancel($job['id']);
		} elseif (in_array($emoji, ['👍', '👎'], true) && $fileId > 0) {
			// Feedback: the job (task, model, outcome) is the example, this the rating.
			$this->jobs->setOption($job['id'], 'rating', ['value' => $emoji === '👍' ? 1 : -1, 'by' => $userId, 'file' => $fileId, 'time' => time()]);
		}
	}

	private function confirmationOpen(string $token, string $userId): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'confirm_' . sha1($token . '|' . $userId), '', lazy: true) !== '';
	}

	/** The asking person's id: a user's id; guests and federated participants get one marked as such ('' for others). */
	public static function userOf(string $actorId): string {
		return match (true) {
			str_starts_with($actorId, 'users/') => substr($actorId, 6),
			str_starts_with($actorId, 'guests/') => 'guest:' . substr($actorId, 7),
			str_starts_with($actorId, 'federated_users/') => 'federated:' . substr($actorId, 16),
			default => '',
		};
	}

	/** Whether the text addresses the bot by name: "@AI …", or "AI, …" / "AI: …" at its start. */
	public static function names(string $text, string $name): bool {
		$quoted = preg_quote($name, '/');
		return preg_match('/(^|\s)@"?' . $quoted . '"?(?![\p{L}\p{N}])|^\s*' . $quoted . '\s*[,:]/iu', $text) === 1;
	}

	public static function withoutName(string $text, string $name): string {
		return trim(preg_replace('/^\s*@?"?' . preg_quote($name, '/') . '"?\s*[,:]?\s*/iu', '', $text) ?? $text);
	}

	/** The message's text: Talk sends a JSON document with the text and its mentions ({mention-user1}). */
	public static function plainText(string $content): string {
		$decoded = json_decode($content, true);
		if (!is_array($decoded) || !is_string($decoded['message'] ?? null)) {
			return $content;
		}
		$parameters = is_array($decoded['parameters'] ?? null) ? $decoded['parameters'] : [];
		return preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($parameters): string {
			$parameter = $parameters[trim($m[1])] ?? null;
			return is_array($parameter) && is_string($parameter['name'] ?? null) ? '@' . $parameter['name'] : $m[0];
		}, $decoded['message']) ?? $decoded['message'];
	}
}
