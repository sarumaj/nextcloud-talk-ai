<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Speech;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The spoken reply to a voice message: what of the answer is read out, in which
 * language, and when there is one at all.
 */
class SpeechTest extends TestCase {
	public function testMarkdownCodeLinksAndCitationsAreNotSpoken(): void {
		$answer = "## Pierogi\n\nYou need **flour**, `water` and eggs [1].\n\n"
			. "```python\nprint('dough')\n```\n\n"
			. "- Knead for ten minutes\n- Rest it\n\n"
			. "See [the recipe](https://example.org/pierogi) or https://example.com/x.\n\n"
			. "| a | b |\n|---|---|\n| 1 | 2 |\n\n"
			. 'The area is $\\pi r^2$ here [2, 3].';
		$spoken = Speech::spokenText($answer, 'en');
		$this->assertSame('Pierogi. You need flour, and eggs. Knead for ten minutes. Rest it. See the recipe or. The area is here.', $spoken);
		foreach (['**', '`', 'print', 'http', '[1]', '|', '$', '##'] as $absent) {
			$this->assertStringNotContainsString($absent, $spoken);
		}
	}

	public function testALongAnswerIsCutAfterASentenceAndPointsToTheChat(): void {
		$answer = str_repeat('Das ist ein ganzer Satz über Pierogi. ', 60);
		$spoken = Speech::spokenText($answer, 'de', 200);
		$this->assertLessThanOrEqual(200 + 40, mb_strlen($spoken));
		$this->assertStringEndsWith('Pierogi. Den Rest findest du im Chat.', $spoken);
		$this->assertStringEndsWith('Resztę znajdziesz w czacie.', Speech::spokenText($answer, 'pl', 200));
		$this->assertStringEndsWith('The rest is in the chat.', Speech::spokenText($answer, 'fr', 200));
	}

	public function testNothingToSayIsEmpty(): void {
		$this->assertSame('', Speech::spokenText("```\ncode only\n```", 'en'));
		$this->assertSame('', Speech::spokenText('https://example.org/x [1]', 'en'));
	}

	/** @return array<string, array{string, string, string}> */
	public static function languages(): array {
		return [
			'German' => ['Ich weiß nicht, ob das für dich und deine Schule passt, aber es ist einfach.', 'en', 'de'],
			'Polish' => ['Nie wiem, czy to jest dobre dla ciebie, ale możesz spróbować jeszcze raz.', 'de', 'pl'],
			'English' => ['I do not know if this is right for you, but it is what the teacher said.', 'de', 'en'],
			'unclear: the person\'s' => ['OK 42.', 'pl', 'pl'],
			'unknown person language' => ['OK.', 'fr', 'en'],
		];
	}

	#[DataProvider('languages')]
	public function testTheLanguageIsTheTextsOrThePersons(string $text, string $fallback, string $expected): void {
		$this->assertSame($expected, Speech::language($text, $fallback));
	}

	public function testOnlyAVoiceMessageOfAPersonGetsASpokenReplyWhereSwitchedOnAndPossible(): void {
		$this->assertTrue(Speech::wanted(true, true, false, true));
		$this->assertFalse(Speech::wanted(false, true, false, true), 'switched off');
		$this->assertFalse(Speech::wanted(true, false, false, true), 'a written question');
		$this->assertFalse(Speech::wanted(true, true, true, true), 'a guest');
		$this->assertFalse(Speech::wanted(true, true, false, false), 'no text-to-speech provider');
	}

	public function testTheAudioTypeComesFromItsBytes(): void {
		$this->assertSame('audio/mpeg', Speech::mime('ID3' . "\x04\x00", 'application/octet-stream'));
		$this->assertSame('audio/mpeg', Speech::mime("\xFF\xFB\x90\x00"));
		$this->assertSame('audio/ogg', Speech::mime('OggS' . "\x00"));
		$this->assertSame('audio/wav', Speech::mime('RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt '));
		$this->assertSame('audio/webm', Speech::mime('????', 'audio/webm'));
		$this->assertSame('mp3', Speech::extension('audio/mpeg'));
		$this->assertSame('ogg', Speech::extension('audio/ogg'));
		$this->assertSame('mp3', Speech::extension('application/octet-stream'));
	}
}
