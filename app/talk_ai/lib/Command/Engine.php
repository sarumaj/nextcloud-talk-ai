<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Command;

use OCA\TalkAi\Ai\Admin;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ talk_ai:engine: an engine's settings from the command line, for Ansible and
 * the runbook. The key comes on stdin (--key-stdin), never as an argument: an
 * argument is in the process list and the shell's history. --show prints the
 * settings with the key only as set or not set.
 *
 *   echo "$KEY" | occ talk_ai:engine anthropic --model claude-opus-5-5 --key-stdin
 *   occ talk_ai:engine claude-cli --home /var/lib/talk-ai/claude --use --fallback anthropic
 */
class Engine extends Command {
	/** The settings an option can set (Admin::ENGINES says which apply to which engine). */
	private const FIELDS = ['model', 'baseUrl' => 'base-url', 'timeout', 'maxImages' => 'max-images', 'headers', 'provider', 'binary', 'home', 'php', 'path',
		'maxBudgetUsd' => 'max-budget-usd', 'taskType' => 'task-type'];

	public function __construct(
		private Admin $admin,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('talk_ai:engine')
			->setDescription('Shows or sets an AI engine: its settings, key (from stdin), features, and whether questions go to it')
			->addArgument('engine', InputArgument::OPTIONAL, implode(', ', array_keys(Admin::ENGINES)))
			->addOption('key-stdin', null, InputOption::VALUE_NONE, 'Read the API key from stdin')
			->addOption('clear-key', null, InputOption::VALUE_NONE, 'Remove the stored key')
			->addOption('on', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Switch a feature on (search, caching, thinking, documents, streaming, mcp, tools, images, structured, fallbacks)')
			->addOption('off', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Switch a feature off')
			->addOption('use', null, InputOption::VALUE_NONE, 'Questions go to this engine')
			->addOption('fallback', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'With --use: the engines that answer when it cannot, in order ("" for none)')
			->addOption('show', null, InputOption::VALUE_NONE, 'Print the settings (the key only as set or not set), as JSON');
		foreach (self::FIELDS as $field => $option) {
			$this->addOption($option, null, InputOption::VALUE_REQUIRED, 'The setting ' . (is_int($field) ? $option : $field) . ' ("" removes it)');
		}
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = (string)$input->getArgument('engine');
		$engines = $this->admin->engines();
		if ($name === '') {
			foreach ($engines as $e) {
				$output->writeln(($e['chosen'] ? '* ' : '  ') . str_pad($e['name'], 16) . ($e['status']['ready'] ? 'ready' : 'not ready: ' . $e['status']['reason']) . '  ' . $e['label']);
			}
			return 0;
		}
		if (!isset(Admin::ENGINES[$name])) {
			$output->writeln('<error>No such engine: ' . $name . '</error>');
			return 2;
		}
		$changes = [];
		foreach (self::FIELDS as $field => $option) {
			$field = is_int($field) ? $option : $field;
			$value = $input->getOption($option);
			if ($value !== null) {
				$changes[$field] = $value;
			}
		}
		$on = (array)$input->getOption('on');
		$off = (array)$input->getOption('off');
		if ($on !== [] || $off !== []) {
			$current = (array)(array_values(array_filter($engines, fn ($e) => $e['name'] === $name))[0]['settings']['flags'] ?? []);
			$changes['flags'] = array_merge($current, array_fill_keys($on, true), array_fill_keys($off, false));
		}
		if ($input->getOption('key-stdin')) {
			$key = trim((string)stream_get_contents(STDIN));
			if ($key === '') {
				$output->writeln('<error>No key on stdin</error>');
				return 2;
			}
			$changes['key'] = $key;
		}
		if ($changes !== []) {
			$errors = $this->admin->save($name, $changes);
			foreach ($errors as $field => $error) {
				$output->writeln('<error>' . $field . ': ' . $error . '</error>');
			}
			if ($errors !== []) {
				return 1;
			}
		}
		if ($input->getOption('clear-key')) {
			$this->admin->clearKey($name);
		}
		if ($input->getOption('use')) {
			$fallback = $input->getOption('fallback');
			$this->admin->choose($name, $fallback === [] ? null : array_values(array_filter((array)$fallback, fn ($f) => $f !== '')));
		}
		$engine = array_values(array_filter($this->admin->engines(), fn ($e) => $e['name'] === $name))[0];
		if ($input->getOption('show')) {
			$output->writeln((string)json_encode(['settings' => $engine['settings'], 'key' => $engine['keySet'] ? 'set' : 'not set',
				'chosen' => $engine['chosen'], 'status' => $engine['status']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		} else {
			$output->writeln($name . ': ' . ($engine['status']['ready'] ? 'ready (' . $engine['status']['model'] . ')' : 'not ready: ' . $engine['status']['reason'])
				. ($engine['chosen'] ? ', questions go to it' : ''));
		}
		return 0;
	}
}
