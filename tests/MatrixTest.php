<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Controller\MatrixController;
use OCA\TalkAi\Listener\BotEnabledListener;
use OCA\TalkAi\Listener\BotInvokeListener;
use OCA\TalkAi\Matrix\Event as MatrixEvent;
use OCA\TalkAi\Matrix\Message;
use OCA\TalkAi\Matrix\Room;
use OCA\TalkAi\Matrix\Store;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Requests;
use OCA\TalkAi\Service\Switches;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The Matrix tables in memory. */
class FakeStore extends Store {
	public array $rooms = [];
	public array $members = [];
	/** @var array<int, Message> */
	public array $messages = [];
	public array $tasks = [];
	public int $now = 1_800_000_000;

	public function __construct() {
	}

	public function saveRoom(string $roomId, string $name, int $people): Room {
		$token = self::token($roomId);
		return $this->rooms[$token] = new Room($token, $roomId, $name, $people);
	}

	public function room(string $token): ?Room {
		return $this->rooms[$token] ?? null;
	}

	public function saveMember(string $token, string $userId, bool $moderator): void {
		$this->members[$token][$userId] = $moderator;
	}

	public function isModerator(string $token, string $userId): bool {
		return $this->members[$token][$userId] ?? false;
	}

	public function addMessage(string $token, string $eventId, string $userId, string $actor, bool $bot, string $text, array $files, int $replyTo): Message {
		$id = count($this->messages) + 1;
		return $this->messages[$id] = new Message($id, $token, $eventId, $userId, $actor, $bot, $text, $files, $replyTo, $this->now, false);
	}

	public function message(string $token, int $id): ?Message {
		$m = $this->messages[$id] ?? null;
		return $m !== null && $m->token === $token ? $m : null;
	}

	public function byEvent(string $token, string $eventId): ?Message {
		foreach ($this->messages as $m) {
			if ($eventId !== '' && $m->token === $token && $m->eventId === $eventId) {
				return $m;
			}
		}
		return null;
	}

	public function recent(string $token, int $limit): array {
		$out = array_filter($this->messages, fn (Message $m) => $m->token === $token && !$m->deleted);
		return array_slice(array_reverse(array_values($out)), 0, $limit);
	}

	public function setText(string $token, int $id, string $text): void {
		$this->change($id, ['text' => $text]);
	}

	public function setEvent(string $token, int $id, string $eventId): void {
		$this->change($id, ['eventId' => $eventId]);
	}

	public function markDeleted(string $token, int $id): void {
		$this->change($id, ['deleted' => true]);
	}

	public function botReplies(string $token, int $messageId): array {
		return array_values(array_filter($this->messages, fn (Message $m) => $m->token === $token && $m->bot && !$m->deleted && $m->replyTo === $messageId));
	}

	public function push(string $token, string $type, array $payload): void {
		$this->tasks[] = ['id' => count($this->tasks) + 1, 'type' => $type, 'room_id' => $this->rooms[$token]->roomId(), 'payload' => $payload];
	}

	public function outbox(int $after, int $limit = 100): array {
		return array_values(array_filter($this->tasks, fn ($t) => $t['id'] > $after));
	}

	public function acknowledge(int $upTo): void {
		$this->tasks = array_values(array_filter($this->tasks, fn ($t) => $t['id'] > $upTo));
	}

	public function clean(int $before): void {
	}

	private function change(int $id, array $fields): void {
		$m = $this->messages[$id];
		$v = ['id' => $m->id, 'token' => $m->token, 'eventId' => $m->eventId, 'userId' => $m->userId, 'actor' => $m->actor, 'bot' => $m->bot,
			'text' => $m->text, 'files' => $m->files, 'replyTo' => $m->replyTo, 'time' => $m->time, 'deleted' => $m->deleted];
		$this->messages[$id] = new Message(...array_merge($v, $fields));
	}
}

class MatrixTest extends TestCase {
	private const ROOM = '!family:ciepiela.org';

	private FakeStore $store;
	private Conversation $conversation;
	private array $questions = [];
	private array $dispatched = [];
	private array $answered = [];
	private array $released = [];
	private array $cancelled = [];
	private array $options = [];
	private ?array $job = null;
	private array $config = ['matrix_service_user' => 'matrix-assistant'];

	protected function setUp(): void {
		$this->store = new FakeStore();
		$this->conversation = new Conversation($this->createMock(IRootFolder::class), $this->createMock(IShareManager::class),
			$this->createMock(IFactory::class), new NullLogger(), $this->store);
	}

	private function controller(?string $userId): MatrixController {
		$listener = $this->createMock(BotInvokeListener::class);
		$listener->method('question')->willReturnCallback(function ($event, $actor, $token, $id, $text, $repliesToBot) {
			$this->questions[] = [$actor, $token, $id, $text, $repliesToBot];
			$event->addReaction('👀');
		});
		$enabled = $this->createMock(BotEnabledListener::class);
		$enabled->method('notice')->willReturn('An AI answers here.');
		$dispatcher = $this->createMock(Dispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(function ($token, $user, $id, $text) {
			$this->dispatched[] = [$user, $id, $text];
		});
		$requests = $this->createMock(Requests::class);
		$requests->method('answeredAt')->willReturnCallback(fn ($token, $id) => in_array($id, $this->answered, true) ? 1 : 0);
		$requests->method('release')->willReturnCallback(function ($token, $id) {
			$this->released[] = $id;
		});
		$jobs = $this->createMock(Jobs::class);
		$jobs->method('byMessage')->willReturnCallback(fn () => $this->job);
		$jobs->method('cancel')->willReturnCallback(function ($id) {
			$this->cancelled[] = $id;
		});
		$jobs->method('setOption')->willReturnCallback(function ($id, $key, $value) {
			$this->options[$key] = $value;
		});
		$switches = $this->createMock(Switches::class);
		$switches->method('off')->willReturn('');
		$bot = $this->createMock(Bot::class);
		$bot->method('name')->willReturn('Claude');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->config[$key] ?? $default);
		return new MatrixController('talk_ai', $this->createMock(IRequest::class), $this->store, $this->conversation, $listener, $enabled,
			$dispatcher, $requests, $jobs, $switches, $bot, $appConfig, $userId);
	}

	public function testTokensAreMatrixOnesAndStable(): void {
		$token = Store::token(self::ROOM);
		$this->assertTrue(Store::isMatrix($token));
		$this->assertSame($token, Store::token(self::ROOM));
		$this->assertNotSame($token, Store::token('!other:ciepiela.org'));
		$this->assertLessThanOrEqual(32, strlen($token));
		$this->assertFalse(Store::isMatrix('abcd1234'));
	}

	public function testOffWithoutServiceUser(): void {
		$this->config = [];
		$this->assertSame(404, $this->controller('matrix-assistant')->info()->getStatus());
		$this->assertSame(400, $this->controller('ewa')->message(self::ROOM, '$1', 'hi')->getStatus());
		$this->assertSame(403, $this->controller('matrix-assistant')->outbox()->getStatus());
	}

	public function testInfoTellsTheServiceAccount(): void {
		$this->assertSame(['name' => 'Claude', 'service' => true], $this->controller('matrix-assistant')->info()->getData());
		$this->assertSame(['name' => 'Claude', 'service' => false], $this->controller('ewa')->info()->getData());
	}

	public function testMessageIsStoredAndAskedAsThePerson(): void {
		$data = $this->controller('ewa')->message(self::ROOM, '$1', 'Claude, what is on today?', 'Family', 3, true, 'Ewa')->getData();
		$this->assertSame(1, $data['message_id']);
		$token = Store::token(self::ROOM);
		$this->assertSame([['users/ewa', $token, 1, 'Claude, what is on today?', false]], $this->questions);
		$this->assertTrue($this->store->isModerator($token, 'ewa'));
		$this->assertSame(3, $this->conversation->numberOfPeople($token));
		// The listener's reaction goes to the outbox, on the person's event.
		$this->assertSame(['message_id' => 1, 'event_id' => '$1', 'key' => '👀'], $this->store->tasks[0]['payload']);
		$this->assertSame(self::ROOM, $this->store->tasks[0]['room_id']);
	}

	public function testRetriedMessageIsNotAskedTwice(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'hello');
		$data = $this->controller('ewa')->message(self::ROOM, '$1', 'hello')->getData();
		$this->assertSame(1, $data['message_id']);
		$this->assertCount(1, $this->questions);
		$this->assertCount(1, $this->store->messages);
	}

	public function testFilesAloneAreNoQuestionButVoiceIs(): void {
		$file = ['id' => 42, 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'size' => 1000];
		$this->controller('ewa')->message(self::ROOM, '$1', '', files: [$file]);
		$this->assertSame([], $this->questions);
		$this->assertSame('[file "photo.jpg", id 42]', $this->store->messages[1]->text);
		$this->controller('ewa')->message(self::ROOM, '$2', '', files: [['id' => 43, 'name' => 'voice.ogg', 'mime' => 'audio/ogg']], voice: true);
		$this->assertSame('{file}', $this->questions[0][3]);
		$this->controller('ewa')->message(self::ROOM, '$3', 'what is this?', files: [$file, ['id' => 0, 'name' => 'bad']]);
		$this->assertSame('what is this? [file "photo.jpg", id 42]', $this->store->messages[3]->text);
		$this->assertCount(1, $this->store->messages[3]->files);
	}

	public function testReplyToTheBotIsMarked(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'Claude, hi');
		$answer = $this->conversation->postText($this->store->room(Store::token(self::ROOM)), 'Hello Ewa!', 1);
		$this->controller('matrix-assistant')->posted(self::ROOM, (int)$answer->getId(), '$bot1');
		$this->controller('ewa')->message(self::ROOM, '$2', 'and tomorrow?', reply_to_event: '$bot1');
		$this->assertTrue($this->questions[1][4]);
		$this->assertSame(2, $this->store->messages[3]->replyTo);
		// The answer went out as a reply to the person's event.
		$text = array_values(array_filter($this->store->tasks, fn ($t) => $t['type'] === 'text'))[0];
		$this->assertSame(['message_id' => 2, 'text' => 'Hello Ewa!', 'reply_to' => 1, 'reply_event' => '$1'], $text['payload']);
	}

	public function testHistoryIsOldestFirstWithOthersAndTheBot(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'Claude, hi', sender_name: 'Ewa');
		$this->assertSame(403, $this->controller('ewa')->observe(self::ROOM, '$2', 'hi from afar')->getStatus());
		$this->controller('matrix-assistant')->observe(self::ROOM, '$2', 'hi from afar', sender_name: 'Jan (matrix.org)');
		$room = $this->store->room(Store::token(self::ROOM));
		$this->conversation->postText($room, 'Hello!', 1);
		$history = $this->conversation->history($room, 'ewa');
		// The bot's own name comes from the server (a placeholder here).
		$this->assertSame(['Ewa', 'Jan (matrix.org)'], array_slice(array_column($history, 'actor'), 0, 2));
		$this->assertSame([false, false, true], array_column($history, 'bot'));
	}

	public function testEditIsAnsweredAnew(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'Claude, wht time?');
		$room = $this->store->room(Store::token(self::ROOM));
		$this->conversation->postText($room, 'Pardon?', 1);
		$this->answered = [1];
		$this->controller('ewa')->message(self::ROOM, '$1e', 'Claude, what time?', edit_of: '$1');
		$this->assertSame('Claude, what time?', $this->store->messages[1]->text);
		$this->assertTrue($this->store->messages[2]->deleted);
		$this->assertSame([1], $this->released);
		$this->assertSame([['ewa', 1, 'Claude, what time?']], $this->dispatched);
		$this->assertContains('redact', array_column($this->store->tasks, 'type'));
	}

	public function testOthersEditIsNotAnswered(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'Claude, hi');
		$this->answered = [1];
		$this->controller('jan')->message(self::ROOM, '$1e', 'Claude, bye', edit_of: '$1');
		$this->assertSame([], $this->dispatched);
	}

	public function testRedactionRemovesTheAnswer(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'Claude, hi');
		$room = $this->store->room(Store::token(self::ROOM));
		$this->conversation->postText($room, 'Hello!', 1);
		$this->answered = [1];
		$this->controller('ewa')->redaction(self::ROOM, '$1');
		$this->assertTrue($this->store->messages[1]->deleted);
		$this->assertTrue($this->store->messages[2]->deleted);
		$this->assertSame([], $this->conversation->history($room, 'ewa'));
	}

	public function testReactionsCancelAndRate(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'Claude, make a report');
		$room = $this->store->room(Store::token(self::ROOM));
		$status = $this->conversation->postText($room, '⏳ Working on it', 1);
		$this->controller('matrix-assistant')->posted(self::ROOM, (int)$status->getId(), '$status');
		$this->job = ['id' => 7, 'user_id' => 'ewa', 'options' => []];
		$this->controller('jan')->reaction(self::ROOM, '$status', '❌');
		$this->assertSame([], $this->cancelled);
		$this->controller('ewa')->reaction(self::ROOM, '$status', '❌');
		$this->assertSame([7], $this->cancelled);
		$this->controller('ewa')->reaction(self::ROOM, '$1', '❌'); // not the bot's
		$this->assertSame([7], $this->cancelled);
	}

	public function testJoinedPostsTheNotice(): void {
		$this->assertSame(403, $this->controller('ewa')->joined(self::ROOM)->getStatus());
		$this->controller('matrix-assistant')->joined(self::ROOM, 'Family', 4);
		$this->assertSame('text', $this->store->tasks[0]['type']);
		$this->assertSame('An AI answers here.', $this->store->tasks[0]['payload']['text']);
	}

	public function testOutboxAndAcknowledge(): void {
		$this->controller('matrix-assistant')->joined(self::ROOM);
		$this->controller('ewa')->message(self::ROOM, '$1', 'hi');
		$this->assertSame(403, $this->controller('ewa')->outbox()->getStatus());
		$service = $this->controller('matrix-assistant');
		$this->assertCount(2, $service->outbox()->getData()['tasks']);
		$this->assertCount(1, $service->outbox(1)->getData()['tasks']);
		$service->acknowledge(1);
		$this->assertSame([2], array_column($service->outbox()->getData()['tasks'], 'id'));
	}

	public function testPollsAndModeratorsAreMatrixOnes(): void {
		$this->controller('ewa')->message(self::ROOM, '$1', 'hi', moderator: false);
		$room = $this->store->room(Store::token(self::ROOM));
		$this->assertFalse($this->conversation->isModerator($room, 'ewa'));
		$this->conversation->postPoll($room, 'Claude', 'Pizza or pasta?', ['Pizza', 'Pasta'], false, 1);
		$poll = array_values(array_filter($this->store->tasks, fn ($t) => $t['type'] === 'poll'))[0];
		$this->assertSame('Pizza or pasta?', $poll['payload']['question']);
		$this->assertSame(['Pizza', 'Pasta'], $poll['payload']['options']);
	}

	public function testEventAnswerReplies(): void {
		$room = $this->store->saveRoom(self::ROOM, 'Family', 2);
		$message = $this->store->addMessage($room->getToken(), '$1', 'ewa', 'Ewa', false, 'hi', [], 0);
		(new MatrixEvent($this->store, $this->conversation, $room, $message))->addAnswer('ℹ️ off here', $message->id);
		$this->assertSame(1, $this->store->tasks[0]['payload']['reply_to']);
		$this->assertSame('$1', $this->store->tasks[0]['payload']['reply_event']);
	}
}
