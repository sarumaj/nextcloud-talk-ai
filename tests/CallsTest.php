<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Listener\CallListener;
use OCA\TalkAi\Service\Calls;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Calls the bot answers by voice: which calls it joins, and that the listener hands
 * Talk's call events to the service and nothing else.
 */
class CallsTest extends TestCase {
	public static function calls(): array {
		return [
			'a person alone with the bot' => [true, true, true, 1, 'users', ''],
			'switched off (or no internal secret)' => [false, false, true, 1, 'users', ''],
			'the bot not enabled here' => [false, true, false, 1, 'users', ''],
			'more people in the conversation' => [false, true, true, 2, 'users', ''],
			'a guest calls' => [false, true, true, 1, 'guests', ''],
			'the bot off for the person' => [false, true, true, 1, 'users', 'user'],
			'the bot off in the conversation' => [false, true, true, 1, 'users', 'room'],
		];
	}

	#[DataProvider('calls')]
	public function testTheBotJoinsOnlyACallWithAPersonAloneWithIt(bool $joins, bool $on, bool $here, int $people, string $actorType, string $off): void {
		$this->assertSame($joins, Calls::wanted($on, $here, $people, $actorType, $off));
	}

	public function testTheScenarioAsksForSpokenAnswers(): void {
		foreach (['read aloud', 'speak', 'no', 'lists', 'links', 'language the person speaks', 'speech recognition'] as $part) {
			$this->assertStringContainsString($part, Calls::SYSTEM);
		}
	}

	public function testTheListenerIgnoresOtherEvents(): void {
		$calls = $this->createMock(Calls::class);
		$calls->expects($this->never())->method('started');
		$calls->expects($this->never())->method('ended');
		$listener = new CallListener($calls, new NullLogger());
		$listener->handle(new Event());
		$listener->handle(new class extends Event {
			public function getRoom(): object {
				return new \stdClass();
			}
		});
	}

	public function testTheListenerKnowsTalksCallEvents(): void {
		$this->assertSame('OCA\Talk\Events\CallStartedEvent', CallListener::STARTED);
		$this->assertSame(['OCA\Talk\Events\CallEndedEvent', 'OCA\Talk\Events\CallEndedForEveryoneEvent'], CallListener::ENDED);
	}
}
