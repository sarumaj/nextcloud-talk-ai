<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * The spoken reply to a voice message: the answer as speech, through Nextcloud's
 * text-to-speech (TaskProcessing's core:text2speech, e.g. the External App
 * talk_ai_voice with Piper), posted as a voice message beside the text answer.
 *
 * What is spoken is the answer's prose: no code, links, formulas, tables or
 * citation marks, and at most SPOKEN characters, ending on a sentence, with a note
 * that the rest is in the chat. Its language (de, pl, en) is read from the answer
 * itself, the person's language deciding when it is unclear. Anything failing
 * leaves the text answer alone.
 */
class Speech {
	/** Characters spoken at most: about a minute and a half. */
	public const SPOKEN = 1200;
	public const LANGUAGES = ['de', 'pl', 'en'];
	/** Said after a cut answer, in the language spoken. */
	private const REST = [
		'de' => 'Den Rest findest du im Chat.',
		'pl' => 'Resztę znajdziesz w czacie.',
		'en' => 'The rest is in the chat.',
	];
	/** Words that mostly appear in one of the languages, for a guess from the text. */
	private const WORDS = [
		'de' => ['und', 'der', 'die', 'das', 'ist', 'nicht', 'ich', 'du', 'mit', 'auf', 'für', 'ein', 'eine', 'auch', 'zu', 'sie', 'es', 'wie', 'aber', 'oder', 'wenn', 'dass'],
		'pl' => ['i', 'nie', 'się', 'to', 'jest', 'na', 'że', 'w', 'z', 'do', 'jak', 'ale', 'czy', 'co', 'tak', 'dla', 'jestem', 'są', 'też', 'albo', 'jeśli', 'mi'],
		'en' => ['the', 'and', 'is', 'not', 'you', 'with', 'for', 'a', 'to', 'of', 'it', 'that', 'this', 'are', 'or', 'if', 'but', 'what', 'how', 'can', 'your', 'be'],
	];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/** Whether a reply is spoken: switched on, to a voice message, a person (not a guest), a provider there. */
	public static function wanted(bool $switchedOn, bool $voiceMessage, bool $guest, bool $available): bool {
		return $switchedOn && $voiceMessage && !$guest && $available;
	}

	/** Whether the administrator lets the bot answer voice messages by voice (voice_replies). */
	public function switchedOn(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'voice_replies', 'yes') === 'yes';
	}

	/** Whether a text-to-speech provider is installed. */
	public function available(): bool {
		try {
			return isset(Server::get(\OCP\TaskProcessing\IManager::class)->getAvailableTaskTypes()[\OCP\TaskProcessing\TaskTypes\TextToSpeech::ID]);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The answer as it is spoken: its prose without Markdown, code, links, formulas,
	 * tables and citation marks; cut after the last sentence within $max characters,
	 * then the note that the rest is in the chat. Empty when nothing is left to say.
	 */
	public static function spokenText(string $answer, string $language, int $max = self::SPOKEN): string {
		$text = str_replace("\r", '', $answer);
		// Code blocks, display formulas and tables are not read out.
		$text = (string)preg_replace('/^(```|~~~).*?^\1[^\n]*$/ms', ' ', $text);
		$text = (string)preg_replace('/\$\$.*?\$\$|\\\\\[.*?\\\\\]/s', ' ', $text);
		$text = (string)preg_replace('/^\s*\|.*\|\s*$/m', ' ', $text);
		// Pictures go, links keep their text, bare addresses go.
		$text = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text);
		$text = (string)preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
		$text = (string)preg_replace('#\b(?:https?://|www\.)\S+#i', ' ', $text);
		// Citation marks: [1], [1, 2], [^1], 【1】.
		$text = (string)preg_replace('/\[\^?\d+(?:\s*[,–-]\s*\d+)*\]|【[^】]*】/u', '', $text);
		// Inline code and formulas.
		$text = (string)preg_replace('/`[^`]*`|\$[^$\n]+\$/', ' ', $text);
		// Markdown's marks: headings, quotes, list bullets and numbers, emphasis, rules.
		$text = (string)preg_replace('/^\s{0,3}(#{1,6}|>+)\s*/m', '', $text);
		$text = (string)preg_replace('/^\s*(?:[-*+•]|\d+[.)])\s+/m', '', $text);
		$text = (string)preg_replace('/^\s*([-*_])(\s*\1){2,}\s*$/m', ' ', $text);
		$text = (string)preg_replace('/(\*\*|__|\*|_|~~)(\S(?:.*?\S)?)\1/u', '$2', $text);
		// A line is a sentence when it does not end in one (headings, list items).
		$lines = array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== '');
		$text = implode(' ', array_map(fn ($l) => preg_match('/[.!?:;…]$/u', $l) === 1 ? $l : $l . '.', $lines));
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));
		// What was taken out leaves no space before a mark, nor a comma twice.
		$text = (string)preg_replace(['/\s+([.,!?;:…])/u', '/([,;:])(?:\s*[,;:])+/u'], ['$1', '$1'], $text);
		if (preg_match('/\p{L}/u', $text) !== 1) {
			return '';
		}
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$cut = mb_substr($text, 0, $max);
		$end = max((int)mb_strrpos($cut, '. '), (int)mb_strrpos($cut, '! '), (int)mb_strrpos($cut, '? '));
		$cut = $end > $max / 3 ? mb_substr($cut, 0, $end + 1) : rtrim(mb_substr($cut, 0, (int)mb_strrpos($cut, ' ') ?: $max), ' ,;:') . '…';
		return $cut . ' ' . (self::REST[$language] ?? self::REST['en']);
	}

	/** The language the text is in (de, pl, en), by its letters and common words; $fallback when unclear. */
	public static function language(string $text, string $fallback): string {
		$fallback = in_array($fallback, self::LANGUAGES, true) ? $fallback : 'en';
		$lower = mb_strtolower($text);
		$score = ['de' => 0, 'pl' => 0, 'en' => 0];
		$score['pl'] += 3 * (int)preg_match_all('/[ąćęłńśźż]/u', $lower);
		$score['de'] += 3 * (int)preg_match_all('/[äöüß]/u', $lower);
		preg_match_all('/\p{L}+/u', $lower, $m);
		foreach ($m[0] as $word) {
			foreach (self::WORDS as $language => $words) {
				if (in_array($word, $words, true)) {
					$score[$language]++;
				}
			}
		}
		arsort($score);
		$best = array_key_first($score);
		$values = array_values($score);
		return $values[0] >= 3 && (float)$values[0] >= 1.5 * (float)$values[1] ? $best : $fallback;
	}

	/**
	 * The text spoken (TaskProcessing text-to-speech, with the language for a provider
	 * that takes it): the audio and its MIME type, or null (logged).
	 *
	 * @return array{data: string, mime: string}|null
	 */
	public function synthesize(string $userId, string $text, string $language, string $requestId = ''): ?array {
		try {
			$manager = Server::get(\OCP\TaskProcessing\IManager::class);
			$type = \OCP\TaskProcessing\TaskTypes\TextToSpeech::ID;
			if (!isset($manager->getAvailableTaskTypes()[$type])) {
				return null;
			}
			// A provider without a language input drops the key (TaskProcessing removes unknown ones).
			$task = new \OCP\TaskProcessing\Task($type, ['input' => $text, 'language' => $language], Application::APP_ID, $userId);
			$done = $manager->runTask($task);
			$id = (int)($done->getOutput()['speech'] ?? 0);
			// The task's files are in the app data, outside the people's folders: from the root.
			/** @var Folder $root */
			$root = Server::get('OCP\Files\IRootFolder');
			$file = $id > 0 ? $root->getFirstNodeById($id) : null;
			if (!$file instanceof File) {
				$this->logger->info('AI bot: the text-to-speech task gave no audio', ['request' => $requestId]);
				return null;
			}
			$data = $file->getContent();
			return $data === '' ? null : ['data' => $data, 'mime' => self::mime($data, $file->getMimetype())];
		} catch (\Throwable $e) {
			$this->logger->info('AI bot: no spoken reply: ' . $e->getMessage(), ['request' => $requestId]);
			return null;
		}
	}

	/** The audio's type by its first bytes (the task's file may have none of its own). */
	public static function mime(string $data, string $given = ''): string {
		return match (true) {
			str_starts_with($data, 'ID3') || (strlen($data) > 1 && ord($data[0]) === 0xFF && (ord($data[1]) & 0xE0) === 0xE0) => 'audio/mpeg',
			str_starts_with($data, 'OggS') => 'audio/ogg',
			str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WAVE' => 'audio/wav',
			str_starts_with($given, 'audio/') => $given,
			default => 'audio/mpeg',
		};
	}

	/** The file extension of an audio type. */
	public static function extension(string $mime): string {
		return ['audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/mp4' => 'm4a'][$mime] ?? 'mp3';
	}
}
