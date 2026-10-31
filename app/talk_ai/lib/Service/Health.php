<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\Ai\Admin;
use OCA\TalkAi\Ai\Engine\ClaudeCli;
use OCA\TalkAi\Ai\Engine\GeminiCli;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

/**
 * What the bot needs and what it loses without it (occ talk_ai:check and the
 * administration): the AI connection and its abilities, the document sandbox
 * with LibreOffice, Poppler and the libraries, Collabora, pdftotext, pdftoppm,
 * Tesseract, speech-to-text, PHP's zip, and how often background jobs run.
 */
class Health {
	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IAppManager $apps,
		private Sandbox $sandbox,
		private Capabilities $capabilities,
		private Hub $hub,
		private Admin $admin,
		private Engines $engines,
	) {
	}

	/**
	 * @return list<array{name: string, ok: bool|null, detail: string, without: string}> ok null: optional and missing
	 */
	public function check(bool $deep = false, string $userId = ''): array {
		$out = [];
		$status = $this->hub->status(Reply::SCENARIO);
		$out[] = self::item('AI connection', $status->ready, $status->ready ? $status->engine . ': ' . $status->provider . ' ' . $status->model
			. ' (images ' . ($status->images ? $status->maxImages : 'no') . ', search ' . ($status->search ? 'yes' : 'no') . ', PDFs ' . ($status->documents ? 'yes' : 'no')
			. ', streaming ' . ($status->streaming ? 'yes' : 'no') . ')' : 'not ready (' . $status->reason . ')', 'the bot cannot answer at all');
		$out = [...$out, ...$this->engineItems()];
		if ($status->ready) {
			if ($deep) {
				$this->capabilities->reset();
			}
			$caps = $this->capabilities->of($this->hub, $status, $userId !== '' ? $userId : 'admin');
			$out[] = self::item('Tool calling', $caps['tools'], $caps['tools'] ? 'works (probe)' : 'the model did not call the probe tool', 'only chat, reading files and summaries; no documents, calendar, calculations');
			$out[] = self::item('Image input', $caps['images'] ? true : null, $caps['images'] ? 'yes' : 'no', 'photos only via OCR; document layout checked as text');
			$out[] = self::item('Context', true, number_format($caps['context'], 0, '.', ' ') . ' tokens (' . $caps['family'] . ')', '');
		}
		$health = $this->sandbox->health();
		$out[] = self::item('Document sandbox', $health !== null ? true : null, $health !== null
			? 'runner up, ' . $health['jobs'] . ' of ' . $health['max_jobs'] . ' jobs, image ' . $health['image']
			: ($this->appConfig->getValueString(Application::APP_ID, 'sandbox_socket', '') === '' ? 'not configured (no runner socket, no External App talk_ai_sandbox)' : 'runner not reachable'),
			'no documents built with code (create_document); the bot writes simpler documents itself');
		if ($deep && $health !== null) {
			$out = [...$out, ...$this->sandboxTools()];
		}
		$out[] = self::item('Collabora (Nextcloud Office)', $this->apps->isEnabledForAnyone('richdocuments') ? true : null,
			$this->apps->isEnabledForAnyone('richdocuments') ? 'richdocuments ' . $this->apps->getAppVersion('richdocuments') : 'not enabled',
			'files written as OpenDocument only (no Word, Excel, PowerPoint, PDF), charts as SVG, old .doc/.xls/.ppt unreadable');
		foreach ([['pdftotext', '/usr/bin/pdftotext', '-v', 'PDFs cannot be read'], ['pdftoppm', '/usr/bin/pdftoppm', '-v', 'scanned PDFs get no OCR'],
			['Tesseract', '/usr/bin/tesseract', '--version', 'no text from photos and scans']] as [$name, $path, $flag, $without]) {
			$version = is_executable($path) ? self::version($path . ' ' . $flag) : '';
			$detail = is_executable($path) ? $version : 'missing (' . $path . ')';
			if ($name === 'Tesseract' && is_executable($path)) {
				$detail .= ', languages ' . trim(str_replace("\n", ' ', preg_replace('/^List of.*\n/', '', (string)shell_exec($path . ' --list-langs 2>&1')) ?? ''));
			}
			$out[] = self::item($name, is_executable($path) ? true : null, $detail, $without);
		}
		$stt = false;
		try {
			$stt = isset(Server::get(\OCP\TaskProcessing\IManager::class)->getAvailableTaskTypes()[\OCP\TaskProcessing\TaskTypes\AudioToText::ID]);
		} catch (\Throwable) {
		}
		$out[] = self::item('Speech-to-text', $stt ? true : null, $stt ? 'a provider is installed' : 'no provider', 'voice messages are not understood');
		$out[] = self::item('PHP zip', extension_loaded('zip'), extension_loaded('zip') ? phpversion('zip') : 'missing', 'no office files read or written');
		$out[] = $this->cron();
		return $out;
	}

	/** The chosen engine and its fallbacks one by one, what a CLI lacks, the limits and today's use. */
	private function engineItems(): array {
		$out = [];
		foreach (array_values(array_unique([$this->engines->chosen(), ...$this->engines->fallback()])) as $i => $name) {
			$engine = $this->engines->get($name);
			$status = $engine->status();
			$missing = $engine instanceof ClaudeCli || $engine instanceof GeminiCli ? $engine->missingFlags() : [];
			$out[] = self::item(($i === 0 ? 'Engine ' : 'Fallback ') . $name, $i === 0 ? $status->ready : ($status->ready ? true : null),
				($status->ready ? 'ready, ' . $status->model : 'not ready (' . $status->reason . ')') . ($missing !== [] ? '; the CLI lacks ' . implode(' ', $missing) : '')
				. ($engine instanceof ClaudeCli && $status->ready ? '; tools ' . ($engine->mcpOn() ? 'by MCP' : 'as text') : ''),
				$i === 0 ? 'the bot cannot answer' : 'no fallback when the first engine fails');
		}
		$setting = fn (string $key, string $default) => $this->engines->setting($key, $default);
		$out[] = self::item('AI limits', true, sprintf('%s questions per person and minute, %s at once, %s tool calls per answer, provider for Nextcloud %s',
			$setting('ai_rate_per_minute', '10'), $setting('ai_concurrent', '4'), $setting('ai_max_tools', '20'), $setting('ai_provider', 'no') === 'yes' ? 'on' : 'off'), '');
		$usage = $this->admin->usage(1);
		$today = array_filter($usage['days'], fn ($r) => $r['day'] === date('Y-m-d'));
		$out[] = self::item('AI use today', true, sprintf('%d questions, %d errors, %d tokens in (%d cached), %d out, $%.2f; %d running now',
			array_sum(array_column($today, 'questions')), array_sum(array_column($today, 'errors')), array_sum(array_column($today, 'tokens_in')),
			array_sum(array_column($today, 'tokens_cached')), array_sum(array_column($today, 'tokens_out')), array_sum(array_column($today, 'cost')), $usage['running']), '');
		return $out;
	}

	/** How background jobs run: every few minutes by cron is slow for queued answers and documents (B-02). */
	private function cron(): array {
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		$last = (int)$this->appConfig->getValueInt('core', 'lastcron', 0);
		$ago = $last > 0 ? time() - $last : -1;
		$ok = $mode === 'cron' && $ago >= 0 && $ago < 600;
		return self::item('Background jobs', $ok ? true : false, $mode . ($ago >= 0 ? ', last run ' . $ago . ' s ago' : ', never ran')
			. ($mode === 'cron' ? ' (cron runs every 5 minutes by default; for faster queues use a systemd timer every minute or a dedicated worker: occ background-job:worker)' : ' (set cron)'),
			'queued answers and document jobs wait for the next run');
	}

	/** The tools inside the sandbox image: one short container. */
	private function sandboxTools(): array {
		try {
			$job = $this->sandbox->create(['cpus' => 1, 'memory_mb' => 512, 'disk_mb' => 64, 'seconds' => 60]);
		} catch (\Throwable $e) {
			return [self::item('Sandbox container', false, $e->getMessage(), 'no documents built with code')];
		}
		try {
			$r = $this->sandbox->exec($job, 'soffice --version 2>/dev/null | head -1; pdftoppm -v 2>&1 | head -1; python3 -c "import docx, openpyxl, pptx, odf; print(\'python libraries ok\')"; '
				. 'node -e "require(\'docx\'); require(\'exceljs\'); require(\'pptxgenjs\'); console.log(\'node libraries ok\')"; hunspell -D 2>&1 | grep -c "_" ; cat /skills/VERSION 2>/dev/null', 60);
			$lines = explode("\n", trim($r['stdout']));
			return [
				self::item('Sandbox: LibreOffice', str_contains($r['stdout'], 'LibreOffice'), $lines[0] ?? '', 'no rendering or conversion'),
				self::item('Sandbox: Poppler', str_contains($r['stdout'], 'pdftoppm'), $lines[1] ?? '', 'no page images'),
				self::item('Sandbox: libraries', str_contains($r['stdout'], 'python libraries ok') && str_contains($r['stdout'], 'node libraries ok'), 'python-docx, openpyxl, python-pptx, odfpy, docx, exceljs, pptxgenjs', 'documents cannot be built'),
				self::item('Sandbox: skills', isset($lines[5]) && $lines[5] !== '', 'version ' . ($lines[5] ?? '?'), 'no guides for the model'),
			];
		} finally {
			$this->sandbox->delete($job);
		}
	}

	private static function version(string $command): string {
		return trim((string)strtok((string)shell_exec($command . ' 2>&1'), "\n"));
	}

	private static function item(string $name, ?bool $ok, string $detail, string $without): array {
		return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'without' => $without];
	}
}
