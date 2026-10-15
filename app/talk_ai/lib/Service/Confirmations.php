<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Actions that change something outside the chat (calendar entries, shares,
 * deleting data) wait for the person's yes: one pending action per person and
 * conversation, valid for ten minutes and for exactly one run.
 */
class Confirmations {
	private const VALID = 600;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/** Keeps an action until the person answers; the question to ask them. */
	public function ask(string $token, string $userId, string $tool, array $args, string $summary): string {
		$this->appConfig->setValueString(Application::APP_ID, self::key($token, $userId), json_encode([
			'tool' => $tool, 'args' => $args, 'summary' => $summary, 'expires' => $this->time->getTime() + self::VALID,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), lazy: true);
		return 'Not done yet: this needs the person\'s confirmation. Ask them in one short question, in the language of their message, whether to do it ("' . $summary
			. '"), and say they can answer yes or no (in that language). Do not call the tool again; their yes runs it.';
	}

	/**
	 * The pending action, taken (it is gone afterwards), or null; the answer as
	 * yes, no or something else.
	 *
	 * @return array{action: array, answer: string}|null
	 */
	public function take(string $token, string $userId, string $text): ?array {
		$key = self::key($token, $userId);
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '', lazy: true);
		if ($raw === '') {
			return null;
		}
		$this->appConfig->deleteKey(Application::APP_ID, $key);
		$action = json_decode($raw, true);
		if (!is_array($action) || (int)($action['expires'] ?? 0) < $this->time->getTime()) {
			return null;
		}
		return ['action' => $action, 'answer' => self::answer($text)];
	}

	/** yes, no, or other: a short answer to a confirmation question. */
	public static function answer(string $text): string {
		$text = mb_strtolower(trim(preg_replace('/^@\S+\s*|^@"[^"]+"\s*/u', '', $text) ?? $text));
		if (preg_match('/^(ja|jo|jep|yes|yep|yeah|y|ok|okay|okey|tak|dobrze|zgoda|passt|mach( das)?|bitte|gerne|klar|sicher|sure|go|do it|confirm|bestätigt?|👍|✅)(?=[\s!.]|$)/u', $text) === 1
			&& mb_strlen($text) < 40) {
			return 'yes';
		}
		if (preg_match('/^(nein|nee|no|nope|nie|stop|stopp|abbrechen|cancel|lieber nicht|doch nicht|👎|❌)(?=[\s!.,]|$)/u', $text) === 1) {
			return 'no';
		}
		return 'other';
	}

	private static function key(string $token, string $userId): string {
		return 'confirm_' . sha1($token . '|' . $userId);
	}
}
