<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Service\Jobs as JobStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ talk_ai:jobs: the document jobs with their state; --cancel stops one. */
class Jobs extends Command {
	public function __construct(
		private JobStore $jobs,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:jobs')
			->setDescription('Lists the document jobs (queued, running, done, failed, cancelled)')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many', '20')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this person\'s')
			->addOption('cancel', null, InputOption::VALUE_REQUIRED, 'Cancel the job with this id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('cancel') !== null) {
			$this->jobs->cancel((int)$input->getOption('cancel'));
			$output->writeln('Cancelling job ' . (int)$input->getOption('cancel') . '.');
			return 0;
		}
		$user = (string)($input->getOption('user') ?? '');
		$rows = $user !== '' ? $this->jobs->ofUser($user, (int)$input->getOption('limit')) : $this->jobs->recent((int)$input->getOption('limit'));
		$table = new Table($output);
		$table->setHeaders(['id', 'created', 'user', 'status', 'document', 'seconds', 'tools', 'rounds', 'tokens (est.)', 'rating', 'outcome']);
		foreach ($rows as $job) {
			$table->addRow([$job['id'], date('Y-m-d H:i', $job['created_at']), $job['user_id'], $job['status'], $job['name'] . '.' . $job['format'],
				$job['finished_at'] > 0 ? $job['finished_at'] - $job['started_at'] : '', $job['tool_calls'], $job['rounds'], $job['tokens'],
				isset($job['options']['rating']) ? ($job['options']['rating']['value'] > 0 ? '👍' : '👎') : '',
				mb_substr(preg_replace('/\s+/', ' ', (string)$job['outcome']) ?? '', 0, 60)]);
		}
		$table->render();
		return 0;
	}
}
