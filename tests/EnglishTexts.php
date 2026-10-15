<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Texts;

/** The bot's texts untranslated, as Nextcloud gives them without a translation. */
class EnglishTexts extends Texts {
	public function __construct() {
	}

	public function t(string $userId, string $text, array $parameters = []): string {
		return $parameters === [] ? $text : vsprintf($text, $parameters);
	}
}
