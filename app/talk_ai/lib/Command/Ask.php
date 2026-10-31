<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Hub;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ talk_ai:ask: one question through the AI layer, for testing an engine. The
 * scenario "assistant" (no tools) by default, or "probe" (one tool, ping); the
 * bot's own scenarios need Talk and are not asked from here. It counts against
 * the limits like any question.
 */
class Ask extends Command {
	public function __construct(
		private Hub $hub,
		private Engines $engines,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:ask')
			->setDescription('Asks the AI one question through the app\'s AI layer')
			->addArgument('question', InputArgument::REQUIRED)
			->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'assistant or probe', 'assistant')
			->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Another engine than the chosen one')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'The account that asks', 'admin')
			->addOption('stream', null, InputOption::VALUE_NONE, 'Print the answer as it comes');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$scenario = (string)$input->getOption('scenario');
		if ($scenario === 'probe') {
			$this->hub->registerScenario('probe', ['system' => 'You test whether tools can be called.', 'maxTools' => 2,
				'tools' => ['ping' => ['description' => 'Answers pong.', 'input' => ['type' => 'object'], 'run' => function () use ($output): string {
					$output->writeln('<info>→ ping called</info>');
					return 'pong';
				}]]]);
		}
		if ($input->getOption('engine') !== null) {
			$this->engines->fix($this->engines->get((string)$input->getOption('engine')));
		}
		$streamed = false;
		$result = $this->hub->ask((string)$input->getOption('user'), $scenario, [['role' => 'user', 'text' => (string)$input->getArgument('question')]], [
			'onDelta' => $input->getOption('stream') ? function (string $delta) use ($output, &$streamed): void {
				$streamed = true;
				$output->write($delta);
			} : null,
			'pause' => fn (int $seconds) => sleep(min($seconds, 5)),
		]);
		if ($result['state'] !== 'done') {
			$output->writeln('<error>' . $result['error'] . '</error>');
			return 1;
		}
		$output->writeln($streamed ? '' : $result['text']);
		$u = $result['usage'];
		$output->writeln(sprintf('<comment>%s · tools: %s · tokens in %d (cached %d), out %d%s%s</comment>', $this->hub->status($scenario)->engine,
			implode(', ', $result['tools']) ?: 'none', $u['in'], $u['cachedIn'], $u['out'], $u['cost'] !== null ? sprintf(' · $%.4f', $u['cost']) : '',
			$u['reported'] ? '' : ' (estimated)'));
		return 0;
	}
}
