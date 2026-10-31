<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * The engines' settings in the app config: per engine one JSON value
 * ("ai_engine_<name>": model, base URL, binary, flags …) and its secrets apart,
 * encrypted with ICrypto and stored sensitive and lazy ("ai_secret_<name>_<field>"),
 * so they are in no listing, no log and no settings page; the page only learns
 * whether one is set.
 */
class Config {
	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
	) {
	}

	/** An engine's settings, its secrets decrypted ('key' and the like); [] for none. */
	public function engine(string $name): array {
		$settings = json_decode($this->appConfig->getValueString(Engines::APP, 'ai_engine_' . $name, '{}', lazy: true), true);
		$settings = is_array($settings) ? $settings : [];
		foreach (['key'] as $field) {
			$secret = $this->secret($name, $field);
			if ($secret !== '') {
				$settings[$field] = $secret;
			}
		}
		return $settings;
	}

	/** Merges settings (null removes one); secrets go through setSecret(). */
	public function set(string $name, array $settings): void {
		$current = json_decode($this->appConfig->getValueString(Engines::APP, 'ai_engine_' . $name, '{}', lazy: true), true);
		$merged = array_filter(array_replace(is_array($current) ? $current : [], $settings), fn ($v) => $v !== null);
		unset($merged['key']);
		$this->appConfig->setValueString(Engines::APP, 'ai_engine_' . $name, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', lazy: true);
	}

	public function setSecret(string $name, string $field, string $value): void {
		$key = 'ai_secret_' . $name . '_' . $field;
		if ($value === '') {
			$this->appConfig->deleteKey(Engines::APP, $key);
			return;
		}
		$this->appConfig->setValueString(Engines::APP, $key, $this->crypto->encrypt($value), lazy: true, sensitive: true);
	}

	public function hasSecret(string $name, string $field): bool {
		return $this->secret($name, $field) !== '';
	}

	private function secret(string $name, string $field): string {
		$stored = $this->appConfig->getValueString(Engines::APP, 'ai_secret_' . $name . '_' . $field, '', lazy: true);
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable) {
			// Encrypted with another secret (a restored or moved server): to be entered again.
			return '';
		}
	}
}
