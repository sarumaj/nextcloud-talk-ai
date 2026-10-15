<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Service\Switches;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ talk_ai:stop / talk_ai:start: the emergency stop, for all, a conversation or a person. */
class Stop extends Command {
	public function __construct(
		private Switches $switches,
		private string $verb = 'stop',
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:' . $this->verb)
			->setDescription($this->verb === 'stop' ? 'Stops the bot at once (running document jobs are cancelled)' : 'Lets the bot answer again')
			->addArgument('scope', InputArgument::OPTIONAL, 'all, room or user', 'all')
			->addArgument('id', InputArgument::OPTIONAL, 'the conversation token or user id', '')
			->addOption('list', null, InputOption::VALUE_NONE, 'Show what is switched off');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('list')) {
			$output->writeln('rooms off: ' . implode(', ', $this->switches->list('rooms_off')));
			$output->writeln('users off: ' . implode(', ', $this->switches->list('users_off')));
			return 0;
		}
		$scope = (string)$input->getArgument('scope');
		$id = (string)$input->getArgument('id');
		if (!in_array($scope, ['all', 'room', 'user'], true) || ($scope !== 'all' && $id === '')) {
			$output->writeln('<error>Use: talk_ai:' . $this->verb . ' all | room <token> | user <id></error>');
			return 1;
		}
		if ($this->verb === 'stop') {
			$output->writeln('Stopped; ' . $this->switches->stop($scope, $id) . ' running jobs cancelled.');
		} else {
			$this->switches->start($scope, $id);
			$output->writeln('Started.');
		}
		return 0;
	}
}
