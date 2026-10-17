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

/** Which tools go with a question: all that are allowed, always the same, for the prompt cache. */
class ToolChoiceTest extends TestCase {
	private function tools(array $features = []): array {
		$reply = new Reply($this->createMock(Conversation::class), $this->createMock(TextExtractor::class), $this->createMock(OdtWriter::class),
			$this->createMock(OdsWriter::class), $this->createMock(OdpWriter::class), $this->createMock(Office::class), $this->createMock(Bot::class),
			$this->createMock(Web::class), $this->createMock(Calendar::class), $this->createMock(Requests::class), $this->createMock(Actions::class),
			$this->createMock(Capabilities::class), $this->createMock(Sandbox::class), $this->createMock(Dispatcher::class), $this->createMock(Jobs::class),
			$this->createMock(Policy::class), $this->createMock(Confirmations::class), new FakeProgress(), $this->createMock(\OCP\IConfig::class),
			$this->createMock(\OCP\IAppConfig::class), new EnglishTexts(), new NullLogger());
		$set = function (string $name, mixed $value) use ($reply): void {
			$p = new \ReflectionProperty(Reply::class, $name);
			$p->setValue($reply, $value);
		};
		$set('features', $features + ['documents' => true, 'sandbox' => true, 'web' => true, 'calendar' => true]);
		$set('canBuild', true);
		$set('room', new class {
			public function getToken(): string {
				return 'room1';
			}
		});
		return (new \ReflectionMethod(Reply::class, 'tools'))->invoke($reply);
	}

	/** What the model reads of the tools: their names, descriptions and arguments, in order. */
	private static function listing(array $tools): string {
		return implode("\n", array_map(fn ($name, $t) => $name . ': ' . $t['description'] . json_encode($t['input']), array_keys($tools), $tools));
	}

	public function testEveryQuestionGetsTheSameTools(): void {
		$tools = $this->tools();
		$this->assertSame(Reply::CORE_TOOLS, array_slice(array_keys($tools), 0, count(Reply::CORE_TOOLS)));
		foreach (['chemistry', 'create_poll', 'finance', 'find_images', 'redo_document', 'my_profile', 'exam', 'formula', 'grades', 'measurement', 'numerics', 'law'] as $name) {
			$this->assertArrayHasKey($name, $tools);
		}
		$this->assertArrayNotHasKey('more_tools', $tools);
		// Built twice, the same to the byte: the system prompt stays cacheable.
		$this->assertSame(self::listing($tools), self::listing($this->tools()));
	}

	public function testSwitchedOffFeaturesTakeTheirTools(): void {
		$tools = $this->tools(['web' => false, 'calendar' => false]);
		foreach (['read_url', 'lookup', 'cite', 'calendar'] as $name) {
			$this->assertArrayNotHasKey($name, $tools);
		}
		$this->assertArrayHasKey('read_file', $tools);
		$this->assertArrayNotHasKey('formula', $this->tools(['sandbox' => false]), 'formula images need the sandbox');
	}

	public function testTheWorkshopBuildsImagesToo(): void {
		$create = $this->tools()['create_document'];
		$this->assertContains('png', $create['input']['properties']['format']['enum']);
		$this->assertContains('svg', $create['input']['properties']['format']['enum']);
		$this->assertStringContainsString('Unknown format', ($create['run'])('ewa', ['task' => 'x', 'format' => 'bmp', 'name' => 'x']));
		$this->assertStringContainsString('"Water cycle.jpg"', ($create['run'])('ewa', ['task' => 'A diagram', 'format' => 'JPEG', 'name' => 'Water cycle.jpeg']));
	}

	public function testAToolRuns(): void {
		$out = ($this->tools()['convert_units']['run'])('ewa', ['value' => 5, 'from' => 'km', 'to' => 'mi']);
		$this->assertStringContainsString('3.1', is_string($out) ? $out : json_encode($out));
		$points = ($this->tools()['exam']['run'])('ewa', ['action' => 'points', 'achieved' => 57, 'max' => 80, 'why' => 'I convert the marks']);
		$this->assertSame(10, $points['result']);
	}
}
