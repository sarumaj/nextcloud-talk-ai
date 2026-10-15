<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Service\Health;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ talk_ai:check: what the bot needs, present or missing, and what is lost without it. */
class Check extends Command {
	public function __construct(
		private Health $health,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:check')
			->setDescription('Checks AI-Hub, tool calling, the document sandbox, Collabora, Poppler, Tesseract and more')
			->addOption('deep', null, InputOption::VALUE_NONE, 'Probe tool calling again and look inside a sandbox container')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'The account to probe the AI with', 'admin')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'plain or json', 'plain');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$items = $this->health->check((bool)$input->getOption('deep'), (string)$input->getOption('user'));
		if ($input->getOption('output') === 'json') {
			$output->writeln(json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		} else {
			foreach ($items as $item) {
				$mark = $item['ok'] === true ? '<info>✓</info>' : ($item['ok'] === null ? '<comment>–</comment>' : '<error>✗</error>');
				$output->writeln($mark . ' ' . str_pad($item['name'], 30) . ' ' . $item['detail']
					. ($item['ok'] !== true && $item['without'] !== '' ? "\n    without it: " . $item['without'] : ''));
			}
		}
		return in_array(false, array_column($items, 'ok'), true) ? 1 : 0;
	}
}
