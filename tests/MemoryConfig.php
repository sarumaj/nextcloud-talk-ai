<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCP\IAppConfig;

/** An app config in memory, for a TestCase: the values in $this->values by key. */
trait MemoryConfig {
	private array $values = [];

	private function memoryConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->values[$key] ?? $default);
		$config->method('getValueInt')->willReturnCallback(fn ($app, $key, $default = 0) => (int)($this->values[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function ($app, $key, $value) {
			$this->values[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function ($app, $key) {
			unset($this->values[$key]);
		});
		$config->method('getKeys')->willReturnCallback(fn () => array_keys($this->values));
		return $config;
	}
}
