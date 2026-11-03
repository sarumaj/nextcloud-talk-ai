<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Capabilities;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase {
	private function known(bool $tools, int $checked, string $engine = 'claude-cli|anthropic|m1'): array {
		return ['engine' => $engine, 'checked' => $checked, 'caps' => ['tools' => $tools]];
	}

	public function testAWorkingProbeHoldsADay(): void {
		$now = 1_000_000;
		$this->assertTrue(Capabilities::fresh($this->known(true, $now - 3600), 'claude-cli|anthropic|m1', $now));
		$this->assertFalse(Capabilities::fresh($this->known(true, $now - Capabilities::TTL), 'claude-cli|anthropic|m1', $now));
	}

	public function testAFailedProbeIsRepeatedAfterAQuarterOfAnHour(): void {
		$now = 1_000_000;
		$this->assertTrue(Capabilities::fresh($this->known(false, $now - 600), 'claude-cli|anthropic|m1', $now));
		$this->assertFalse(Capabilities::fresh($this->known(false, $now - Capabilities::FAILED_TTL), 'claude-cli|anthropic|m1', $now));
	}

	public function testAnotherEngineIsCheckedAnew(): void {
		$now = 1_000_000;
		$this->assertFalse(Capabilities::fresh($this->known(true, $now), 'gemini-cli|google|m2', $now));
		$this->assertFalse(Capabilities::fresh(['engine' => 'claude-cli|anthropic|m1', 'checked' => $now], 'claude-cli|anthropic|m1', $now));
	}
}
