<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Controller\TickerController;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class TickerTest extends TestCase {
	private int $now = 1000;
	private array $posted = [];
	private array $edited = [];

	private function ticker(FakeProgress $progress): TickerController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$conversation = $this->createMock(Conversation::class);
		$conversation->method('room')->willReturn(new \stdClass());
		$conversation->method('postText')->willReturnCallback(function ($room, $text) {
			$this->posted[] = $text;
			return new class {
				public function getId(): int {
					return 77;
				}
			};
		});
		$conversation->method('comment')->willReturn(new \stdClass());
		$conversation->method('editText')->willReturnCallback(function ($room, $c, $text) {
			$this->edited[] = $text;
			return true;
		});
		return new TickerController('talk_ai', $this->createMock(IRequest::class), $this->createMock(Dispatcher::class), $progress,
			$conversation, new EnglishTexts(), $time);
	}

	public function testShowsTheStepFromTenSecondsAndUpdatesItUntilDone(): void {
		$progress = new FakeProgress();
		$progress->start('k', 'room', 5, '⏳ Thinking …', 0, 'ewa', $this->now);
		$sleeps = 0;
		$this->ticker($progress)->tick('k', function (int $s) use (&$sleeps, $progress) {
			$this->now += $s;
			$sleeps++;
			if ($sleeps === 6) {
				$progress->step('k', '⏳ Writing …');
			}
			if ($sleeps === 30) {
				$progress->done('k');
			}
		});
		// Posted once (at 12 s, the first poll after 10 s), then edited every 20 s or more.
		$this->assertCount(1, $this->posted);
		$this->assertStringStartsWith('⏳ Thinking … · 12 s', $this->posted[0]);
		$this->assertStringContainsString('“stop” cancels', $this->posted[0]);
		$this->assertSame(77, $progress->get('k')['status']);
		$this->assertNotEmpty($this->edited);
		$this->assertStringStartsWith('⏳ Writing … · 33 s', $this->edited[0]);
		$this->assertStringContainsString('1:', end($this->edited));
	}

	public function testAnAnnouncedStepShowsAtTheNextPoll(): void {
		$progress = new FakeProgress();
		$progress->start('k', 'room', 5, '⏳ Thinking …', 0, 'ewa', $this->now);
		$sleeps = 0;
		$this->ticker($progress)->tick('k', function (int $s) use (&$sleeps, $progress) {
			$this->now += $s;
			$sleeps++;
			if ($sleeps === 5) {
				$progress->note('k', 'I read the timetable to find the exam dates.');
			}
			if ($sleeps === 7) {
				$progress->done('k');
			}
		});
		// Posted at 12 s; the step came at 15 s and was shown at that poll, not twenty seconds later.
		$this->assertCount(1, $this->posted);
		$this->assertCount(1, $this->edited);
		$this->assertStringStartsWith("> I read the timetable to find the exam dates.\n\n⏳ Thinking … · 15 s", $this->edited[0]);
	}

	public function testAQuickAnswerShowsNothing(): void {
		$progress = new FakeProgress();
		$progress->start('k', 'room', 5, '⏳ Thinking …', 0, '', $this->now);
		$this->ticker($progress)->tick('k', function (int $s) use ($progress) {
			$this->now += $s;
			$progress->done('k');
		});
		$this->assertSame([], $this->posted);
	}

	public function testAJobKeepsItsOwnStatusMessage(): void {
		$progress = new FakeProgress();
		$progress->start('job-1', 'room', 5, '⏳ Building “Report” …', 55, 'ewa', $this->now);
		$n = 0;
		$this->ticker($progress)->tick('job-1', function (int $s) use (&$n, $progress) {
			$this->now += $s;
			if (++$n === 5) {
				$progress->done('job-1');
			}
		});
		$this->assertSame([], $this->posted);
		$this->assertStringStartsWith('⏳ Building “Report” … · 12 s', $this->edited[0]);
	}
}
