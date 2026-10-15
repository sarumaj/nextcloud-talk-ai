<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Migration;

use OCA\TalkAi\Service\Bot;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Installs the bot in Talk (an app bot: Talk calls it in-process), on install
 * and after each upgrade, which also renames it (see Bot). Its secret signs the
 * hand-off of a question to the process route. Without Talk nothing happens.
 */
class InstallBot implements IRepairStep {
	public function __construct(
		private Bot $bot,
	) {
	}

	public function getName(): string {
		return 'Install the AI bot in Talk';
	}

	public function run(IOutput $output): void {
		if (!$this->bot->install()) {
			$output->info('Talk is not installed: the bot is installed with Talk.');
		}
	}
}
