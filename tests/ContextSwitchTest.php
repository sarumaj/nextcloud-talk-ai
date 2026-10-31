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
	private static function message(int $id, string $actor, string $text, array $files = [], bool $bot = false, int $at = 0): array {
		return ['id' => $id, 'actor' => $actor, 'time' => '2026-10-17 15:0' . ($id % 10), 'at' => $at > 0 ? $at : 1792249200 + $id * 60, 'bot' => $bot, 'text' => $text,
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

	/**
	 * Several files with a caption from Talk's mobile apps: posted side by side, the captioned
	 * one first, the bot's status message among them (2026-10-25: it answered about one file).
	 */
	public function testTheRestOfACaptionedBatchBelongsToIt(): void {
		$at = 1792250000;
		$history = [
			...array_slice(self::history(), 0, 2),
			self::message(3, 'Maciej', '[file "a.pdf", id 21] @Claude fasse alle zusammen', [[21, 'a.pdf']], false, $at),
			self::message(4, 'Claude', '⏳ …', [], true, $at + 1),
			self::message(5, 'Maciej', '[file "b.pdf", id 22]', [[22, 'b.pdf']], false, $at + 3),
			self::message(6, 'Maciej', '[file "c.jpg", id 23]', [[23, 'c.jpg', 'image/jpeg']], false, $at + 20),
			self::message(7, 'Ewa', 'Danke!', [], false, $at + 30),
			self::message(8, 'Maciej', '[file "d.pdf", id 24]', [[24, 'd.pdf']], false, $at + 40),
		];
		// Not after someone else's message.
		$this->assertSame([21, 22, 23], array_column(Reply::requestFiles($history, 3), 'id'));
	}

	public function testABatchEndsWithTheAuthorsNextTextOrAfterItsTime(): void {
		$at = 1792250000;
		$base = [...array_slice(self::history(), 0, 2), self::message(3, 'Maciej', '[file "a.pdf", id 21] und hierzu?', [[21, 'a.pdf']], false, $at)];
		$text = [...$base, self::message(4, 'Maciej', '[file "b.pdf", id 22] und das?', [[22, 'b.pdf']], false, $at + 5),
			self::message(5, 'Maciej', '[file "c.pdf", id 23]', [[23, 'c.pdf']], false, $at + 6)];
		$this->assertSame([21], array_column(Reply::requestFiles($text, 3), 'id'), 'a file with its own caption is its own question');
		$late = [...$base, self::message(4, 'Maciej', '[file "b.pdf", id 22]', [[22, 'b.pdf']], false, $at + 600)];
		$this->assertSame([21], array_column(Reply::requestFiles($late, 3), 'id'), 'ten minutes later it is a new file');
	}

	/**
	 * Comparing two files: a command line model ran one tool block per reply, so both go into one
	 * read_file call (2026-10-25: the second block was dropped and called a failure).
	 */
	public function testReadFileTakesSeveralIds(): void {
		$this->assertSame([49931, 49932], Reply::fileIds(['ids' => [49931, '49932', 49931]]));
		$this->assertSame([7], Reply::fileIds(['id' => '7']));
		$this->assertSame([7], Reply::fileIds(['id' => 7, 'ids' => []]), 'an empty ids falls back to id');
		$this->assertSame([], Reply::fileIds(['ids' => ['x', 0, -3]]));
		$this->assertSame([], Reply::fileIds([]));
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
