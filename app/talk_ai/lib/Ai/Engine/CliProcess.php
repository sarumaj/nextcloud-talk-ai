<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

/**
 * A command line model's process: started with an argv array (never a shell),
 * its own environment (nothing of the server's), stdout read line by line,
 * stderr into a file, and killed when it is no longer wanted: in a process group
 * of its own (setsid), so what it started (the MCP bridge, a web fetch) goes too. It lives as long
 * as this object: a Turn's session holds it between Loop's turns.
 */
final class CliProcess {
	/** @var resource */
	private $proc;
	/** @var array<int, resource> */
	private array $pipes = [];
	private string $buffer = '';
	private string $stderr;
	private bool $group = false;

	/**
	 * @param list<string> $argv
	 * @param array<string, string> $env
	 */
	public function __construct(array $argv, array $env, public readonly string $dir) {
		$this->stderr = $dir . '/stderr.txt';
		$setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : '');
		if ($setsid !== '' && function_exists('posix_kill')) {
			array_unshift($argv, $setsid);
			$this->group = true;
		}
		$proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->stderr, 'a']], $this->pipes, $dir, $env);
		if (!is_resource($proc)) {
			throw new \RuntimeException('the command line tool could not be started');
		}
		$this->proc = $proc;
		stream_set_blocking($this->pipes[0], false);
		stream_set_blocking($this->pipes[1], false);
	}

	/**
	 * Writes a line, reading stdout meanwhile: a message with images runs to megabytes,
	 * and written in one go it could fill the pipe while the tool waits for its output
	 * to be read, and neither would move again.
	 */
	public function write(string $line): bool {
		$pending = $line . "\n";
		$deadline = microtime(true) + 60;
		while ($pending !== '' && microtime(true) < $deadline) {
			$written = @fwrite($this->pipes[0], $pending);
			if ($written === false || !$this->running()) {
				return false;
			}
			$pending = (string)substr($pending, $written);
			if ($pending !== '') {
				$read = [$this->pipes[1]];
				$write = [$this->pipes[0]];
				$except = null;
				@stream_select($read, $write, $except, 0, 100000);
				$chunk = @fread($this->pipes[1], 1 << 20);
				$this->buffer .= is_string($chunk) ? $chunk : '';
			}
		}
		return $pending === '';
	}

	/** Everything it writes to stdout until it exits; null when it takes longer than the time. */
	public function collect(float $deadline): ?string {
		while ($this->running() && microtime(true) < $deadline) {
			$read = [$this->pipes[1]];
			$write = $except = null;
			@stream_select($read, $write, $except, 0, 200000);
			$chunk = @fread($this->pipes[1], 1 << 20);
			$this->buffer .= is_string($chunk) ? $chunk : '';
		}
		if ($this->running()) {
			$this->kill();
			return null;
		}
		$this->buffer .= (string)@stream_get_contents($this->pipes[1]);
		return $this->buffer;
	}

	/** The end of input: the tool answers what it has and exits. */
	public function closeInput(): void {
		if (is_resource($this->pipes[0] ?? null)) {
			fclose($this->pipes[0]);
		}
	}

	/** @return resource stdout, for stream_select */
	public function stdout() {
		return $this->pipes[1];
	}

	/**
	 * The complete lines read so far (stdout must be readable, or the tool exited).
	 *
	 * @return list<string>
	 */
	public function lines(): array {
		$chunk = @fread($this->pipes[1], 1 << 20);
		$this->buffer .= is_string($chunk) ? $chunk : '';
		$lines = explode("\n", $this->buffer);
		$this->buffer = (string)array_pop($lines);
		return array_values(array_filter($lines, fn (string $l) => trim($l) !== ''));
	}

	public function running(): bool {
		return is_resource($this->proc) && (proc_get_status($this->proc)['running'] ?? false);
	}

	/** The last lines it wrote to stderr, for the error word. */
	public function stderrTail(): string {
		$text = (string)@file_get_contents($this->stderr);
		return trim(mb_substr($text, -500));
	}

	public function kill(): void {
		if (!is_resource($this->proc)) {
			return;
		}
		$this->closeInput();
		if ($this->running()) {
			$this->signal(15);
			for ($i = 0; $i < 20 && $this->running(); $i++) {
				usleep(50000);
			}
			if ($this->running()) {
				$this->signal(9);
			}
		}
		if (is_resource($this->pipes[1] ?? null)) {
			fclose($this->pipes[1]);
		}
		proc_close($this->proc);
	}

	private function signal(int $signal): void {
		$pid = (int)(proc_get_status($this->proc)['pid'] ?? 0);
		// setsid made it the leader of its group: the negative pid is the whole group.
		if ($this->group && $pid > 0 && @posix_kill(-$pid, $signal)) {
			return;
		}
		proc_terminate($this->proc, $signal);
	}

	public function __destruct() {
		$this->kill();
		self::remove($this->dir);
	}

	/**
	 * A CLI's `--help`, once per binary and version: a file in the temporary directory
	 * (each status() would otherwise start the tool, a second or two).
	 */
	public static function help(string $binary, array $env, string $salt = ''): string {
		$cache = sys_get_temp_dir() . '/talkai-cli-help-' . sha1($binary . '|' . filemtime($binary) . '|' . filesize($binary) . '|' . $salt);
		$text = @file_get_contents($cache);
		if (is_string($text) && $text !== '') {
			return $text;
		}
		try {
			$process = new self([$binary, '--help'], $env, self::tempDir());
			$process->closeInput();
			$text = (string)$process->collect(microtime(true) + 20) . $process->stderrTail();
		} catch (\Throwable) {
			return '';
		}
		if ($text !== '') {
			@file_put_contents($cache, $text);
		}
		return $text;
	}

	/** A private directory for the process's files (config, tools, socket). */
	public static function tempDir(): string {
		$dir = sys_get_temp_dir() . '/talkai-cli-' . bin2hex(random_bytes(8));
		if (!mkdir($dir, 0700)) {
			throw new \RuntimeException('no temporary directory');
		}
		return $dir;
	}

	private static function remove(string $dir): void {
		foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $name) {
			$file = $dir . '/' . $name;
			is_dir($file) && !is_link($file) ? self::remove($file) : @unlink($file);
		}
		@rmdir($dir);
	}
}
