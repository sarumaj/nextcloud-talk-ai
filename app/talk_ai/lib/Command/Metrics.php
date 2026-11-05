<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Ai\Admin;
use OCA\TalkAi\Ai\Metrics as AiMetrics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ talk_ai:metrics: the engines in use as Prometheus metrics (Ai\Metrics), e.g.
 * for node_exporter's textfile collector. --probe first asks every ready engine
 * the connection test's short question, so that an engine nobody used lately
 * still shows whether it is signed in (one small request per engine);
 * --probe-stale only those that failed since their last answer or have not
 * answered for a day, e.g. hourly from a timer.
 */
class Metrics extends Command {
	public function __construct(
		private AiMetrics $metrics,
		private Admin $admin,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:metrics')
			->setDescription('Prints the AI engines\' state as Prometheus metrics: ready, signed in, last answer and error, sign-in expiry')
			->addOption('probe', null, InputOption::VALUE_NONE, 'Ask every ready engine in use one short question first (a small request each)')
			->addOption('probe-stale', null, InputOption::VALUE_NONE, 'Only those that failed since their last answer or have not answered for a day');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$probe = $input->getOption('probe') ? array_keys($this->metrics->inUse()) : ($input->getOption('probe-stale') ? $this->metrics->stale() : []);
		foreach ($probe as $name) {
			$result = $this->admin->test($name);
			// Watch has recorded the outcome; stderr says it for a person at the terminal.
			if ($output instanceof ConsoleOutputInterface) {
				$output->getErrorOutput()->writeln($name . ': ' . ($result['ok'] ? 'answered' : 'failed (' . $result['error'] . ')'));
			}
		}
		$output->write($this->metrics->render(), false, OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
