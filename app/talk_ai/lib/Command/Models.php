<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Ai\Admin;
use OCA\TalkAi\Ai\Engines;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ talk_ai:models [engine]: the models an engine's provider offers (from its API where it has one). */
class Models extends Command {
	public function __construct(
		private Admin $admin,
		private Engines $engines,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:models')
			->setDescription('Lists the models of an AI engine (default: the one questions go to)')
			->addArgument('engine', InputArgument::OPTIONAL, implode(', ', array_keys(Admin::ENGINES)));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = (string)($input->getArgument('engine') ?: $this->engines->chosen());
		$models = $this->admin->models($name);
		if ($models === []) {
			$output->writeln('<comment>No models from ' . $name . ' (not set up, or its API lists none)</comment>');
			return 1;
		}
		foreach ($models as $model) {
			$output->writeln($model);
		}
		return 0;
	}
}
