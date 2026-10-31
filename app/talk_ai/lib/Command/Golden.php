<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Service\Golden as GoldenSet;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ talk_ai:golden ACCOUNT [CASE …]: the acceptance cases with the current AI connection (minutes per case). */
class Golden extends Command {
	public function __construct(
		private GoldenSet $golden,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:golden')
			->setDescription('Runs the document golden set (T-01 … T-08) with the AI connection the app uses now, as a test account')
			->addArgument('account', InputArgument::REQUIRED, 'A test account (its files get a temporary folder)')
			->addArgument('cases', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Only these cases, e.g. T-01 T-03');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		set_time_limit(0);
		$results = $this->golden->run((string)$input->getArgument('account'), (array)$input->getArgument('cases'), fn (string $line) => $output->writeln($line));
		$passed = count(array_filter($results, fn ($r) => $r['ok']));
		$output->writeln($passed . ' of ' . count($results) . ' cases pass');
		foreach ($results as $r) {
			if (!$r['ok']) {
				$output->writeln("\n" . $r['case'] . ' (' . $r['status'] . "):\n" . $r['report']);
			}
		}
		return $passed === count($results) ? 0 : 1;
	}
}
