<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Progress;
use OCP\ICache;
use OCP\ICacheFactory;

/** Progress over an array instead of Redis. */
class FakeProgress extends Progress {
	public function __construct() {
		$cache = new class implements ICache {
			private array $data = [];
			public function get($key) { return $this->data[$key] ?? null; }
			public function set($key, $value, $ttl = 0) { $this->data[$key] = $value; return true; }
			public function hasKey($key) { return isset($this->data[$key]); }
			public function remove($key) { unset($this->data[$key]); return true; }
			public function clear($prefix = '') { $this->data = []; return true; }
			public static function isAvailable(): bool { return true; }
		};
		$factory = new class($cache) implements ICacheFactory {
			public function __construct(private ICache $cache) {}
			public function isAvailable(): bool { return true; }
			public function isLocalCacheAvailable(): bool { return true; }
			public function createLocking(string $prefix = ''): \OCP\IMemcache { throw new \LogicException(); }
			public function createDistributed(string $prefix = ''): ICache { return $this->cache; }
			public function createLocal(string $prefix = ''): ICache { return $this->cache; }
			public function createInMemory(int $capacity = 512): ICache { return $this->cache; }
		};
		parent::__construct($factory);
	}
}
