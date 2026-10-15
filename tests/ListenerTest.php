<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Listener\BotInvokeListener;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Switches;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Talk's BotInvokeEvent as the bot sees it (the fixture of Talk's events). */
class FakeBotEvent extends Event {
	public array $reactions = [];
	public array $answers = [];

	public function __construct(private array $message, private string $url = Application::BOT_URL) {
		parent::__construct();
	}

	public function getBotUrl(): string {
		return $this->url;
	}

	public function getMessage(): array {
		return $this->message;
	}

	public function addReaction(string $emoji): void {
		$this->reactions[] = $emoji;
	}

	public function addAnswer(string $message, bool|int $reply = false): void {
		$this->answers[] = $message;
	}
}

class ListenerTest extends TestCase {
	private array $dispatched = [];
	private array $config = [];
	private ?array $active = null;
	private array $cancelled = [];
	private array $feedback = [];
	private int $people = 3;

	private function listener(): BotInvokeListener {
		$dispatcher = $this->createMock(Dispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(function ($token, $user, $id, $text) {
			$this->dispatched[] = [$token, $user, $id, $text];
		});
		$conversation = $this->createMock(Conversation::class);
		$conversation->method('numberOfPeople')->willReturnCallback(fn () => $this->people);
		$bot = $this->createMock(Bot::class);
		$bot->method('name')->willReturn('Claude');
		$jobs = $this->createMock(Jobs::class);
		$jobs->method('active')->willReturnCallback(fn () => $this->active);
		$jobs->method('cancel')->willReturnCallback(function ($id) {
			$this->cancelled[] = $id;
		});
		$jobs->method('setFeedback')->willReturnCallback(function ($id, $text) {
			$this->feedback[] = $text;
		});
		$jobs->method('byMessage')->willReturnCallback(fn ($token, $file, $message) => $message === 500 ? ['id' => 9, 'user_id' => 'ewa', 'options' => []] : null);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->config[$key] ?? $default);
		$switches = new Switches($appConfig, $jobs);
		return new BotInvokeListener($dispatcher, $conversation, $bot, $jobs, $switches, $appConfig, new EnglishTexts(), new NullLogger());
	}

	private static function message(string $text, string $actor = 'users/ewa', int $id = 100, array $extra = []): array {
		return ['type' => 'Create', 'actor' => ['type' => 'Person', 'id' => $actor, 'name' => 'Ewa'],
			'object' => ['type' => 'Note', 'id' => (string)$id, 'name' => 'message', 'content' => json_encode(['message' => $text, 'parameters' => []]), 'mediaType' => 'text/markdown'] + $extra,
			'target' => ['type' => 'Collection', 'id' => 'room1', 'name' => 'Family']];
	}

	public function testAnswersWhenNamedInAGroupAndIgnoresOtherwise(): void {
		$listener = $this->listener();
		$listener->handle($event = new FakeBotEvent(self::message('what is this?')));
		$this->assertSame([], $this->dispatched);
		$listener->handle($event = new FakeBotEvent(self::message('@Claude what is this?')));
		$this->assertSame(['👀'], $event->reactions);
		$this->assertSame('ewa', $this->dispatched[0][1]);
		$this->people = 1;
		$listener->handle(new FakeBotEvent(self::message('and this?', id: 101)));
		$this->assertCount(2, $this->dispatched);
	}

	public function testOtherBotsEventsAreNotOurs(): void {
		$this->people = 1;
		$this->listener()->handle(new FakeBotEvent(self::message('hi'), 'nextcloudapp://other'));
		$this->assertSame([], $this->dispatched);
	}

	public function testStopCancelsTheRunningJobWithoutNamingTheBot(): void {
		$this->active = ['id' => 4, 'feedback' => null];
		$this->listener()->handle($event = new FakeBotEvent(self::message('stop')));
		$this->assertSame([4], $this->cancelled);
		$this->assertSame(['✋'], $event->reactions);
		$this->assertSame([], $this->dispatched);
	}

	public function testAWaitingPreviewTakesTheNextMessageAsItsAnswer(): void {
		$this->active = ['id' => 4, 'feedback' => ''];
		$this->listener()->handle($event = new FakeBotEvent(self::message('Die Tabelle bitte breiter')));
		$this->assertSame(['Die Tabelle bitte breiter'], $this->feedback);
		$this->assertSame(['✍️'], $event->reactions);
		$this->listener()->handle($event = new FakeBotEvent(self::message('passt')));
		$this->assertSame(['👍'], $event->reactions);
		$this->assertSame([], $this->dispatched);
	}

	public function testAnOpenConfirmationIsAnsweredWithoutNamingTheBot(): void {
		$this->config['confirm_' . sha1('room1|ewa')] = '{"tool":"calendar"}';
		$this->listener()->handle(new FakeBotEvent(self::message('ja')));
		$this->assertCount(1, $this->dispatched);
	}

	public function testGuestsOnlyWhenAllowed(): void {
		$this->people = 1;
		$this->listener()->handle($event = new FakeBotEvent(self::message('hello', 'guests/abc')));
		$this->assertSame([], $this->dispatched);
		$this->assertStringContainsString('only members', $event->answers[0]);
		$this->config['allow_guests'] = 'yes';
		$this->listener()->handle(new FakeBotEvent(self::message('hello', 'guests/abc', 102)));
		$this->assertSame('guest:abc', $this->dispatched[0][1]);
	}

	public function testTheEmergencyStopAnswersWithANote(): void {
		$this->people = 1;
		$this->config['enabled'] = 'no';
		$this->listener()->handle($event = new FakeBotEvent(self::message('hello')));
		$this->assertSame([], $this->dispatched);
		$this->assertStringContainsString('switched off', $event->answers[0]);
		$this->config['enabled'] = 'yes';
		$this->config['rooms_off'] = '["room1"]';
		$this->listener()->handle($event = new FakeBotEvent(self::message('hello')));
		$this->assertStringContainsString('in this conversation', $event->answers[0]);
	}

	public function testAReactionOnTheStatusMessageCancelsTheJob(): void {
		$reaction = ['type' => 'Like', 'actor' => ['type' => 'Person', 'id' => 'users/ewa', 'name' => 'Ewa'], 'content' => '❌',
			'object' => ['type' => 'Note', 'id' => '500', 'name' => 'message', 'content' => '{"message":"⏳ Building …","parameters":[]}', 'mediaType' => 'text/markdown'],
			'target' => ['type' => 'Collection', 'id' => 'room1', 'name' => 'Family']];
		$this->listener()->handle(new FakeBotEvent($reaction));
		$this->assertSame([9], $this->cancelled);
	}
}
