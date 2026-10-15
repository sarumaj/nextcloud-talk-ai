<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Service\Switches;

/** occ talk_ai:start: undoes talk_ai:stop. */
class Start extends Stop {
	public function __construct(Switches $switches) {
		parent::__construct($switches, 'start');
	}
}
