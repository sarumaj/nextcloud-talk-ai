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

/** Which tools go with a question, and how much smaller the prompt gets. */
class ToolChoiceTest extends TestCase {
	private function tools(string $text): array {
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
		$set('features', ['documents' => true, 'sandbox' => true, 'web' => true, 'calendar' => true]);
		$set('canBuild', true);
		$set('recentText', $text);
		$set('room', new class {
			public function getToken(): string {
				return 'room1';
			}
		});
		return (new \ReflectionMethod(Reply::class, 'tools'))->invoke($reply);
	}

	private static function chars(array $tools): int {
		return array_sum(array_map(fn ($t) => mb_strlen($t['description']) + mb_strlen((string)json_encode($t['input'])), $tools));
	}

	public function testAPlainQuestionGetsTheCoreAndMoreTools(): void {
		$tools = $this->tools('What is the capital of France?');
		$this->assertSame([...Reply::CORE_TOOLS, 'more_tools'], array_keys($tools));
		$this->assertContains('chemistry', $tools['more_tools']['input']['properties']['name']['enum']);
	}

	public function testWordsBringTheirTools(): void {
		$this->assertArrayHasKey('chemistry', $this->tools('Gleiche die Reaktion Fe + O2 aus'));
		$this->assertArrayHasKey('create_poll', $this->tools('Zrób ankietę: pizza czy makaron?'));
		$this->assertArrayHasKey('finance', $this->tools('How much interest on a loan of 10,000 EUR?'));
		$tools = $this->tools('Mach eine Präsentation über den Wasserkreislauf');
		$this->assertArrayHasKey('find_images', $tools);
		// The core keeps its place in front.
		$this->assertSame(Reply::CORE_TOOLS, array_slice(array_keys($tools), 0, count(Reply::CORE_TOOLS)));
	}

	public function testThePromptsToolsShrink(): void {
		$all = self::chars($this->tools(implode(' ', ['chem', 'dna', 'sport', 'binary', 'colour', 'painting', 'function', 'matrix', 'music',
			'loan', 'map', 'presentation', 'umfrage', 'undo', 'teile', 'lösch', 'flashcard', 'plot', 'statistik', 'einheit', 'nochmal', 'status', 'einstellung', 'merk dir'])));
		$plain = self::chars($this->tools('What is the capital of France?'));
		fwrite(STDERR, sprintf("\ntools: %d characters with all, %d for a plain question (%d %%)\n", $all, $plain, round(100 * $plain / $all)));
		$this->assertLessThan($all * 0.7, $plain);
	}

	public function testMoreToolsRunsATool(): void {
		$tools = $this->tools('hello');
		$out = ($tools['more_tools']['run'])('ewa', ['name' => 'convert_units', 'args' => ['value' => 5, 'from' => 'km', 'to' => 'mi']]);
		$this->assertStringContainsString('3.1', is_string($out) ? $out : json_encode($out));
		$this->assertStringContainsString('Missing', ($tools['more_tools']['run'])('ewa', ['name' => 'convert_units', 'args' => []]));
	}
}
