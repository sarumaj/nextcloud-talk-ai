<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Controller\TickerController;
use OCA\TalkAi\Service\Progress;
use OCA\TalkAi\Service\Reply;
use PHPUnit\Framework\TestCase;

/** The steps the model announces with its tool calls (the argument "why"), on their way to the status message. */
class StepsTest extends TestCase {
	public function testEveryToolTakesWhyBesideItsOwnArguments(): void {
		$input = Reply::withWhy(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]);
		$this->assertSame(['id', Reply::WHY], array_keys($input['properties']));
		$this->assertSame(['id'], $input['required']);
		$this->assertSame('string', $input['properties'][Reply::WHY]['type']);
		// A tool without arguments gets it too.
		$this->assertArrayHasKey(Reply::WHY, Reply::withWhy(['type' => 'object'])['properties']);
	}

	public function testOnlyTheLatestShortenedStepsAreKept(): void {
		$progress = new FakeProgress();
		$progress->start('k', 'room', 5, '⏳ Thinking …');
		for ($i = 1; $i <= Progress::STEPS + 2; $i++) {
			$progress->note('k', "Step  $i\n");
		}
		$progress->note('k', '   ');
		$progress->note('k', str_repeat('x', Progress::STEP_LENGTH + 40));
		$state = $progress->get('k');
		$this->assertCount(Progress::STEPS, $state['steps']);
		$this->assertSame('Step 4', $state['steps'][0]);
		$this->assertSame(Progress::STEP_LENGTH, mb_strlen(end($state['steps'])));
		$this->assertStringEndsWith('…', end($state['steps']));
		// Blank ones are not counted as new.
		$this->assertSame(Progress::STEPS + 3, $state['revision']);
	}

	public function testNoStepIsNotedForWorkThatIsNotTracked(): void {
		$progress = new FakeProgress();
		$progress->note('unknown', 'Something');
		$this->assertNull($progress->get('unknown'));
	}

	public function testTheStepsStandAsQuotedLines(): void {
		$this->assertSame('', TickerController::steps([]));
		$this->assertSame("> One\n> Two\n\n", TickerController::steps(['One', 'Two']));
	}
}
