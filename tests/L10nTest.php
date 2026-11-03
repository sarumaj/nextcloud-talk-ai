<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every text the app translates is in every translation (l10n/*.json), and the .js and
 * .json of a language agree. The texts are found where the code passes them as literals:
 * $l->t('…') in the templates, $this->texts->t($user, '…') and $t('…') in lib/, the
 * English texts of the $en tables, the settings' labels and help (Settings::FIELDS),
 * and t('…') in js/admin.js. Texts made at run time (a match over states) are not found.
 */
class L10nTest extends TestCase {
	private const APP = __DIR__ . '/../app/talk_ai';
	private const STRING = "'((?:[^'\\\\]|\\\\.)*)'";

	/** @return array<string, true> the texts the app translates */
	private static function wanted(): array {
		$wanted = [];
		$add = function (string $literal) use (&$wanted): void {
			// A quoted literal: \' and \\ stand for ' and \.
			$wanted[(string)preg_replace('/\\\\([\\\\\'])/', '$1', $literal)] = true;
		};
		$files = glob(self::APP . '/templates/*.php');
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::APP . '/lib'));
		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}
		$s = self::STRING;
		foreach ($files as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all('/\$l->t\(' . $s . '/', $code, $m);
			preg_match_all('/\$t\(' . $s . '/', $code, $n);
			preg_match_all('/texts->t\([^,()]*(?:\([^()]*\))?[^,()]*,\s*' . $s . '/', $code, $o);
			array_map($add, [...$m[1], ...$n[1], ...$o[1]]);
			preg_match_all('/\$en = \[(.*?)\];/s', $code, $tables);
			foreach ($tables[1] as $table) {
				preg_match_all('/' . $s . '\s*=>\s*' . $s . '/', $table, $pairs);
				array_map($add, $pairs[2]);
			}
		}
		foreach (Settings::FIELDS as $field) {
			$wanted[$field[5]] = true;
			$wanted[$field[6]] = true;
		}
		preg_match_all('/\bt\(' . $s . '/', (string)file_get_contents(self::APP . '/js/admin.js'), $m);
		array_map($add, $m[1]);
		return $wanted;
	}

	/** @return array<string, array{string}> */
	public static function languages(): array {
		$languages = [];
		foreach (glob(self::APP . '/l10n/*.json') as $file) {
			$languages[basename($file, '.json')] = [basename($file, '.json')];
		}
		return $languages;
	}

	public function testTheSourceIsFound(): void {
		$wanted = self::wanted();
		// One of each kind, so that a changed pattern does not quietly find nothing.
		foreach (['AI connection', 'Done.', 'Thinking', 'Building “%s”', 'The bot answers', 'Must be {range}.'] as $text) {
			$this->assertArrayHasKey($text, $wanted);
		}
	}

	#[DataProvider('languages')]
	public function testEveryTextIsTranslated(string $language): void {
		$json = json_decode((string)file_get_contents(self::APP . "/l10n/$language.json"), true, 512, JSON_THROW_ON_ERROR);
		$missing = array_keys(array_diff_key(self::wanted(), $json['translations']));
		$this->assertSame([], $missing, "$language.json misses these texts");
	}

	#[DataProvider('languages')]
	public function testTheJsAgreesWithTheJson(string $language): void {
		$json = json_decode((string)file_get_contents(self::APP . "/l10n/$language.json"), true, 512, JSON_THROW_ON_ERROR);
		$js = (string)file_get_contents(self::APP . "/l10n/$language.js");
		$this->assertMatchesRegularExpression('/^OC\.L10N\.register\(\s*"talk_ai",\s*(\{.*\}),\s*(".*")\);\s*$/s', $js);
		preg_match('/^OC\.L10N\.register\(\s*"talk_ai",\s*(\{.*\}),\s*(".*")\);\s*$/s', $js, $m);
		$translations = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
		ksort($translations);
		ksort($json['translations']);
		$this->assertSame($json['translations'], $translations, "$language.js differs from $language.json");
		$this->assertSame($json['pluralForm'], json_decode($m[2], true, 512, JSON_THROW_ON_ERROR));
	}
}
