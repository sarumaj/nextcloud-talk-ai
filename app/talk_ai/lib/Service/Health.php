<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

/**
 * What the bot needs and what it loses without it (occ talk_ai:check and the
 * administration): AI-Hub and its connection's abilities, the document sandbox
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
	) {
	}

	/**
	 * @return list<array{name: string, ok: bool|null, detail: string, without: string}> ok null: optional and missing
	 */
	public function check(bool $deep = false, string $userId = ''): array {
		$out = [];
		$hub = class_exists('OCA\AIHub\Service\HubService') ? Server::get('OCA\AIHub\Service\HubService') : null;
		$status = null;
		if ($hub === null) {
			$out[] = self::item('AI-Hub', false, 'not installed', 'the bot cannot answer at all');
		} else {
			$status = $hub->status(Application::APP_ID);
			$out[] = self::item('AI-Hub', (bool)$status['ready'], $status['ready'] ? $status['provider'] . ' ' . $status['model'] : 'not set up', 'the bot cannot answer at all');
		}
		if ($hub !== null && $status !== null && $status['ready']) {
			if ($deep) {
				$this->capabilities->reset();
			}
			$caps = $this->capabilities->of($hub, $status, $userId !== '' ? $userId : 'admin');
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
