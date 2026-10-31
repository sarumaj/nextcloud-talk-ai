<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Admin;
use OCA\TalkAi\Ai\Config;
use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Engine\Missing;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Http;
use OCA\TalkAi\Controller\SettingsController;
use OCA\TalkAi\Service\Health;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Policy;
use OCA\TalkAi\Service\Requests;
use OCA\TalkAi\Service\Settings;
use OCA\TalkAi\Service\Switches;
use OCP\AppFramework\Http as HttpStatus;
use OCP\Files\IAppData;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/** The administration of the AI connection: limits, access, engines, keys, the chain, the scenarios. */
class AiSettingsTest extends TestCase {
	use MemoryConfig;

	private array $params = [];

	private function settings(): Settings {
		$switches = $this->createMock(Switches::class);
		$switches->method('list')->willReturn([]);
		return new Settings($this->memoryConfig(), $this->createMock(Jobs::class), $this->createMock(Requests::class), $this->createMock(Health::class),
			$this->createMock(Policy::class), $switches);
	}

	private function controller(): SettingsController {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . strrev($v));
		$crypto->method('decrypt')->willReturnCallback(fn ($v) => strrev(substr($v, 4)));
		$config = new Config($this->memoryConfig(), $crypto);
		// Nextcloud's own AI needs the server; here it is simply not set up.
		$engines = new class($this->memoryConfig(), $config, new Http(new FakeHttp())) extends Engines {
			public function get(?string $name = null, ?string $model = null): Engine {
				return $name === 'taskprocessing' ? new Missing() : parent::get($name, $model);
			}
		};
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn () => $this->params);
		return new SettingsController('talk_ai', $request, $this->settings(), $this->createMock(Jobs::class), $this->createMock(IAppData::class),
			new Admin($engines, $config, $this->memoryConfig()), $engines);
	}

	public function testTheLimitsAndAccessAreCheckedAndSaved(): void {
		$settings = $this->settings();
		$errors = $settings->save(['values' => ['ai_rate_per_minute' => '5', 'ai_concurrent' => '1000', 'ai_provider' => true, 'ai_max_images' => '0'],
			'ai_groups' => ['staff', ' ', 'staff', 'kids']]);
		$this->assertSame(['ai_concurrent'], array_keys($errors));
		$this->assertSame(['5', 'yes', '0'], [$this->values['ai_rate_per_minute'], $this->values['ai_provider'], $this->values['ai_max_images']]);
		$this->assertArrayNotHasKey('ai_concurrent', $this->values);
		$this->assertSame(['staff', 'kids'], $settings->all()['ai_groups']);
		$this->assertSame('yes', $settings->all()['values']['ai_provider']);
		$settings->save(['ai_groups' => []]);
		$this->assertSame([], $settings->all()['ai_groups'], 'empty: everyone');
	}

	public function testAKeyIsStoredEncryptedAndNeverSentBack(): void {
		$controller = $this->controller();
		$this->params = ['name' => 'anthropic', 'key' => 'sk-ant-secret-123456', 'model' => 'claude-x', 'timeout' => '30', 'bogus' => 'x', 'flags' => ['search' => false]];
		$response = $controller->saveEngine('anthropic');
		$this->assertSame(HttpStatus::STATUS_OK, $response->getStatus());
		$sent = (string)json_encode($response->getData());
		$this->assertStringNotContainsString('secret', $sent);
		$this->assertTrue($response->getData()['engine']['keySet']);
		$this->assertSame(['model' => 'claude-x', 'timeout' => 30, 'flags' => ['search' => false, 'caching' => true, 'thinking' => true, 'documents' => true,
			'streaming' => true, 'fallbacks' => true]], json_decode($this->values['ai_engine_anthropic'], true));
		$this->assertStringNotContainsString('secret', $this->values['ai_engine_anthropic']);
		$this->assertStringStartsWith('enc:', $this->values['ai_secret_anthropic_key']);
		$this->assertStringNotContainsString('secret', (string)json_encode($controller->ai()->getData()));

		// An empty key keeps the one there; Clear removes it.
		$this->params = ['model' => 'claude-y'];
		$this->assertTrue($controller->saveEngine('anthropic')->getData()['engine']['keySet']);
		$this->assertFalse($controller->clearKey('anthropic')->getData()['engine']['keySet']);
		$this->assertArrayNotHasKey('ai_secret_anthropic_key', $this->values);
	}

	public function testAWrongFieldIsReportedAndUnknownEnginesAreRefused(): void {
		$controller = $this->controller();
		$this->params = ['baseUrl' => 'file:///etc/passwd', 'model' => 'm'];
		$response = $controller->saveEngine('openai');
		$this->assertSame(HttpStatus::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['baseUrl'], array_keys((array)$response->getData()['errors']));
		$this->params = ['home' => 'relative/dir'];
		$this->assertSame(['home'], array_keys((array)$controller->saveEngine('claude-cli')->getData()['errors']));
		foreach (['saveEngine', 'clearKey', 'models', 'test', 'probe'] as $method) {
			$this->assertSame(HttpStatus::STATUS_NOT_FOUND, $controller->$method('nope')->getStatus(), $method);
		}
	}

	public function testTheChainLeavesOutUnknownEnginesAndTheChosenOne(): void {
		$controller = $this->controller();
		$data = $controller->choose('anthropic', ['anthropic', 'gemini', 'nope', 'claude-cli', 'gemini'])->getData();
		$this->assertSame(['anthropic', ['gemini', 'claude-cli']], [$data['chosen'], $data['fallback']]);
		$this->assertSame(HttpStatus::STATUS_BAD_REQUEST, $controller->choose('nope')->getStatus());
		$this->assertSame('anthropic', $this->values['ai_engine']);
	}

	public function testScenarioOverridesAreCleaned(): void {
		$data = $this->controller()->saveScenarios([
			'chat' => ['engine' => 'gemini', 'model' => ' gemini-3-pro ', 'prompt' => 'Be brief.'],
			'unknown' => ['engine' => 'gemini'],
			'document' => ['engine' => 'nope'],
			'probe' => ['engine' => '', 'model' => '', 'prompt' => ''],
		])->getData();
		$this->assertEquals((object)['chat' => ['engine' => 'gemini', 'model' => 'gemini-3-pro', 'prompt' => 'Be brief.']], $data['scenarios']);
	}

	public function testThePageGetsEveryEngineWithItsState(): void {
		$data = $this->controller()->ai()->getData();
		$this->assertSame(array_keys(Admin::ENGINES), array_column($data['engines'], 'name'));
		$this->assertSame(Admin::SCENARIOS, $data['scenarioNames']);
		$this->assertSame(['days' => [], 'running' => 0], $data['usage']);
		$this->assertFalse($data['engines'][0]['status']['ready'], 'no key: not ready');
	}
}
