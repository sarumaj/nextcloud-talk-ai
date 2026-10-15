<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\OdpWriter;
use OCA\TalkAi\Document\OdsWriter;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\TextExtractor;
use OCA\TalkAi\Service\Actions;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Calendar;
use OCA\TalkAi\Service\Capabilities;
use OCA\TalkAi\Service\Confirmations;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Office;
use OCA\TalkAi\Service\Policy;
use OCA\TalkAi\Service\Reply;
use OCA\TalkAi\Service\Requests;
use OCA\TalkAi\Service\Sandbox;
use OCA\TalkAi\Service\Web;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Idempotency (B-01): a message is answered once, also when Talk delivers it twice. */
class ReplyTest extends TestCase {
	public function testAMessageTakenBeforeIsNotAnsweredAgain(): void {
		$conversation = $this->createMock(Conversation::class);
		$conversation->method('room')->willReturn(new \stdClass());
		$conversation->expects($this->never())->method('postText');
		$requests = $this->createMock(Requests::class);
		$requests->expects($this->once())->method('take')->with('room1', 100, 'ewa')->willReturn(null);
		$requests->expects($this->never())->method('done');
		$this->reply($conversation, $requests)->answer('room1', 'ewa', 100, '@Claude hi');
	}

	public function testATakenMessageIsRecordedWithItsOutcome(): void {
		$room = new class {
			public function getToken(): string {
				return 'room1';
			}
		};
		$conversation = $this->createMock(Conversation::class);
		$conversation->method('room')->willReturn($room);
		$requests = $this->createMock(Requests::class);
		$requests->method('take')->willReturn('abc123');
		// Without AI-Hub (not installed in tests) the answer is a note, and the request is closed.
		$conversation->expects($this->once())->method('postText')->with($room, $this->stringContains('AI-Hub'));
		$requests->expects($this->once())->method('done')->with('abc123', 'answered');
		$this->reply($conversation, $requests)->answer('room1', 'ewa', 100, '@Claude hi');
	}

	private function reply(Conversation $conversation, Requests $requests): Reply {
		return new Reply($conversation, $this->createMock(TextExtractor::class), $this->createMock(OdtWriter::class), $this->createMock(OdsWriter::class),
			$this->createMock(OdpWriter::class), $this->createMock(Office::class), $this->createMock(Bot::class), $this->createMock(Web::class),
			$this->createMock(Calendar::class), $requests, $this->createMock(Actions::class), $this->createMock(Capabilities::class),
			$this->createMock(Sandbox::class), $this->createMock(Dispatcher::class), $this->createMock(Jobs::class), $this->createMock(Policy::class),
			$this->createMock(Confirmations::class), new FakeProgress(), $this->createMock(\OCP\IConfig::class), $this->createMock(\OCP\IAppConfig::class), new EnglishTexts(), new NullLogger());
	}
}
