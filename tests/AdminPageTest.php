<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The administration page's script (js/admin.js) against its template (templates/admin.php),
 * read as text: the repository has no JavaScript test runner. Its Save once threw before it
 * sent anything (the engine panel's box shared the house styles' class, so collect() read a
 * house style's field it does not have), and the page said nothing.
 */
class AdminPageTest extends TestCase {
	private const APP = __DIR__ . '/../app/talk_ai';

	private static function script(): string {
		return (string)file_get_contents(self::APP . '/js/admin.js');
	}

	public function testTheHouseStylesAreReadFromTheirOwnBoxAlone(): void {
		$script = self::script();
		$this->assertStringContainsString("querySelectorAll('#talk-ai-styles > .talk-ai-style')", $script);
		$this->assertDoesNotMatchRegularExpression("/querySelectorAll\\('\\.talk-ai-style'\\)/", $script, 'the engine panel shares the class');
	}

	public function testEveryElementTheScriptLooksUpByIdIsInTheTemplate(): void {
		$template = (string)file_get_contents(self::APP . '/templates/admin.php');
		preg_match_all("/getElementById\\('([\\w-]+)'\\)/", self::script(), $found);
		$this->assertNotEmpty($found[1]);
		foreach (array_unique($found[1]) as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $template, $id . ' is looked up but not on the page');
		}
	}

	public function testASaveThatFailsIsSaidNeverSilent(): void {
		$script = self::script();
		$save = substr($script, (int)strpos($script, 'async function save('), 2000);
		$this->assertStringContainsString('catch (e)', $save, 'an error of the request or the answer is caught');
		$this->assertStringContainsString("t('Not saved: {error}'", $save, 'and said in the status line');
		$this->assertStringContainsString("addEventListener('beforeunload'", $script, 'a change not saved yet is not left silently');
	}

	public function testEachChangeIsSavedAsItIsMade(): void {
		$script = self::script();
		$this->assertStringContainsString("form.addEventListener('change'", $script);
		$this->assertStringContainsString("form.addEventListener('input'", $script);
		$template = (string)file_get_contents(self::APP . '/templates/admin.php');
		$this->assertStringNotContainsString('type="submit"', $template, 'no Save button: changes save themselves');
		$this->assertStringContainsString('id="talk-ai-saved" role="status"', $template);
	}
}
