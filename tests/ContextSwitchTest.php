<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Reply;
use PHPUnit\Framework\TestCase;

/**
 * A new file with a new question is what that question is about, not the subject of
 * an earlier task (a Lernzettel was being made; then a new document came with "make
 * one for this": the bot went on with the old one, 2026-10-17).
 */
class ContextSwitchTest extends TestCase {
	private static function message(int $id, string $actor, string $text, array $files = [], bool $bot = false): array {
		return ['id' => $id, 'actor' => $actor, 'time' => '2026-10-17 15:0' . ($id % 10), 'bot' => $bot, 'text' => $text,
			'files' => array_map(fn ($f) => ['id' => $f[0], 'name' => $f[1], 'mime' => $f[2] ?? 'application/pdf', 'size' => 1], $files),
			'replyTo' => 0];
	}

	private static function history(): array {
		return [
			self::message(1, 'Maciej', '@Claude Lernzettel Informatik', [[11, 'Informatik.pdf']]),
			self::message(2, 'Claude', 'Der Lernzettel wird erstellt …', [], true),
			self::message(3, 'Maciej', '[file "Ethik Q3.docx", id 21]', [[21, 'Ethik Q3.docx']]),
			self::message(4, 'Ewa', '[file "Rezept.pdf", id 31]', [[31, 'Rezept.pdf']]),
			self::message(5, 'Maciej', '@Claude mach mir dazu einen Lernzettel'),
			self::message(6, 'Maciej', '[file "Später.pdf", id 41]', [[41, 'Später.pdf']]),
		];
	}

	public function testTheFilesOfTheAuthorSinceTheLastAnswerBelongToTheQuestion(): void {
		$files = Reply::requestFiles(self::history(), 5);
		// Maciej's new document; not the earlier task's file (before the answer), not Ewa's,
		// not one shared after the question.
		$this->assertSame([21], array_column($files, 'id'));
	}

	public function testAFileSharedWithACaptionIsTheQuestionsOwn(): void {
		$history = [...array_slice(self::history(), 0, 2), self::message(3, 'Maciej', '[file "Ethik Q3.docx", id 21] und hierzu?', [[21, 'Ethik Q3.docx']])];
		$this->assertSame([21], array_column(Reply::requestFiles($history, 3), 'id'));
		$this->assertSame([], Reply::requestFiles($history, 99), 'an unknown message has none');
	}

	public function testTheQuestionNamesItsFiles(): void {
		$asked = Reply::withFiles('mach mir dazu einen Lernzettel', Reply::requestFiles(self::history(), 5));
		$this->assertStringStartsWith('mach mir dazu einen Lernzettel', $asked);
		$this->assertStringContainsString('what it is about unless it says otherwise: [file "Ethik Q3.docx", id 21]', $asked);
		$this->assertSame('just text', Reply::withFiles('just text', []));
		// A caption's placeholder gives way to the file's name.
		$this->assertStringStartsWith('und hierzu?', Reply::withFiles('{file} und hierzu?', [['id' => 21, 'name' => 'Ethik Q3.docx', 'mime' => 'x', 'size' => 1]]));
	}
}
