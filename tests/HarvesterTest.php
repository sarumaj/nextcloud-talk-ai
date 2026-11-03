<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\OdpWriter;
use OCA\TalkAi\Document\OdsWriter;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\TextExtractor;
use OCA\TalkAi\Service\Actions;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Calendar;
use OCA\TalkAi\Service\Capabilities;
use OCA\TalkAi\Service\Confirmations;
use OCA\TalkAi\Service\Conversation;
use OCA\TalkAi\Service\Dispatcher;
use OCA\TalkAi\Service\Harvester;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Office;
use OCA\TalkAi\Service\Policy;
use OCA\TalkAi\Service\Reply;
use OCA\TalkAi\Service\Requests;
use OCA\TalkAi\Service\Sandbox;
use OCA\TalkAi\Service\Web;
use OCP\Files\File;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Harvester tools with AppAPI's exAppRequest played by a fake: Harvester's refusals go
 * back to the model, an accepted recipe becomes the person's template and a file in the chat,
 * nothing is offered without Harvester, and no account or session reaches the model.
 */
class HarvesterTest extends TestCase {
	use MemoryConfig;

	/** @var list<array{method: string, route: string, user: ?string, params: array}> the requests to Harvester */
	private array $calls = [];
	/** @var callable(string, string, array): array{0: int, 1: mixed} Harvester's answers by method and route */
	private $answer;

	private const RECIPE = '{"format": "harvester-recipe", "version": 1, "name": "Minutes", "source": "site", "options": '
		. '{"recipe": {"start": "https://example.org/minutes", "links": {"selector": "a[href]", "pattern": "\\\\.pdf$"}}}}';
	private const JOB = ['name' => 'Minutes', 'source' => 'site', 'schedule' => '', 'once' => false, 'tags' => [],
		'options' => ['recipe' => "{\n  \"start\": \"https://example.org/minutes\"\n}"], 'target' => '', 'enabled' => true];

	protected function setUp(): void {
		$this->values = ['harvester' => 'yes'];
		$this->answer = fn () => [500, ['detail' => 'unexpected']];
	}

	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(is_string($body) ? $body : (string)json_encode($body));
		return $response;
	}

	/** Harvester through a fake AppAPI; $appApi false: AppAPI not installed. */
	private function harvester(bool $appApi = true, bool $enabled = true): Harvester {
		$test = $this;
		$api = new class($test, $enabled) {
			public function __construct(
				private HarvesterTest $test,
				private bool $enabled,
			) {
			}

			public function getExApp(string $appId): ?array {
				return ['appid' => $appId, 'enabled' => $this->enabled];
			}

			public function exAppRequest(string $appId, string $route, ?string $userId = null, string $method = 'POST', array $params = [], array $options = []): array|IResponse {
				return $this->test->request($appId, $route, $userId, $method, $params);
			}
		};
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/index.php/apps/app_api/embedded/harvester/harvester');
		return new class($this->memoryConfig(), $urls, $appApi ? $api : null) extends Harvester {
			public function __construct(
				\OCP\IAppConfig $config,
				IURLGenerator $urls,
				private ?object $api,
			) {
				parent::__construct($config, $urls);
			}

			protected function appApi(): ?object {
				return $this->api;
			}

			protected function pause(int $seconds): void {
			}
		};
	}

	/** The fake AppAPI's exAppRequest: recorded, answered by $this->answer. */
	public function request(string $appId, string $route, ?string $userId, string $method, array $params): IResponse {
		$this->assertSame('harvester', $appId);
		$this->assertStringNotContainsString('credentials', $route, 'the credentials route is never called');
		// As Harvester gets them: JSON (a job's options an object, not a list).
		$params = json_decode((string)json_encode($params), true);
		$this->calls[] = ['method' => $method, 'route' => $route, 'user' => $userId, 'params' => $params];
		[$status, $body] = ($this->answer)($method, $route, $params);
		return $this->response($status, $body);
	}

	/** A Reply whose Harvester tools run with this Harvester; the chat's files are recorded. */
	private function reply(Harvester $harvester, array &$posted, array $features = ['harvester' => true]): array {
		$conversation = $this->createMock(Conversation::class);
		$conversation->method('numberOfPeople')->willReturn(1);
		$conversation->method('saveFile')->willReturnCallback(function ($room, $uid, string $name, string $data) use (&$posted): File {
			$posted[] = ['name' => $name, 'data' => $data];
			$file = $this->createMock(File::class);
			$file->method('getName')->willReturn($name);
			return $file;
		});
		$shared = $this->createMock(File::class);
		$shared->method('getSize')->willReturn(strlen(self::RECIPE));
		$shared->method('getContent')->willReturn(self::RECIPE);
		$conversation->method('userFile')->willReturnCallback(fn ($uid, int $id) => $id === 42 ? $shared : null);
		$reply = new Reply($conversation, $this->createMock(TextExtractor::class), $this->createMock(OdtWriter::class),
			$this->createMock(OdsWriter::class), $this->createMock(OdpWriter::class), $this->createMock(Office::class), $this->createMock(Bot::class),
			$this->createMock(Web::class), $this->createMock(Calendar::class), $harvester, $this->createMock(Requests::class), $this->createMock(Actions::class),
			$this->createMock(Capabilities::class), $this->createMock(\OCA\TalkAi\Ai\Hub::class), $this->createMock(Sandbox::class), $this->createMock(Dispatcher::class),
			$this->createMock(Jobs::class), $this->createMock(Policy::class), $this->createMock(Confirmations::class), new FakeProgress(),
			$this->createMock(\OCP\IConfig::class), $this->createMock(\OCP\IAppConfig::class), new EnglishTexts(), new NullLogger());
		$set = function (string $name, mixed $value) use ($reply): void {
			(new \ReflectionProperty(Reply::class, $name))->setValue($reply, $value);
		};
		$set('features', $features + ['documents' => true, 'sandbox' => true, 'web' => true, 'calendar' => true]);
		$set('caps', ['images' => true]);
		$set('room', new class {
			public function getToken(): string {
				return 'room1';
			}
		});
		return (new \ReflectionMethod(Reply::class, 'tools'))->invoke($reply);
	}

	public function testARefusedRecipeGoesBackToTheModelWithHarvestersProblems(): void {
		$this->answer = fn (string $method, string $route) => $route === '/api/recipes/import'
			? [422, ['detail' => 'The recipe file is refused: options Website has not: colour']] : [500, []];
		$posted = [];
		$result = $this->reply($this->harvester(), $posted)['harvester_recipe']['run']('anna', ['action' => 'save', 'recipe' => self::RECIPE, 'name' => 'Minutes']);
		$this->assertStringContainsString('Harvester refused the recipe: The recipe file is refused: options Website has not: colour', $result);
		$this->assertStringContainsString('Fix these and call again', $result);
		$this->assertSame([], $posted, 'nothing is posted');
		$this->assertSame(['/api/recipes/import'], array_column($this->calls, 'route'), 'nothing is saved');
		$this->assertSame('anna', $this->calls[0]['user'], 'as the person');
		$this->assertSame(['text' => self::RECIPE], $this->calls[0]['params']);
	}

	public function testAnAcceptedRecipeIsSavedAsTheirTemplateAndPostedAsAFile(): void {
		$file = ['format' => 'harvester-recipe', 'version' => 1, 'name' => 'Council minutes', 'source' => 'site'];
		$this->answer = fn (string $method, string $route, array $params) => match ($route) {
			'/api/recipes/import' => [200, self::JOB],
			'/api/templates' => [200, ['id' => 7, 'name' => $params['name'], 'file' => $file]],
			default => [500, []],
		};
		$posted = [];
		$result = $this->reply($this->harvester(), $posted)['harvester_recipe']['run']('anna', ['action' => 'save', 'file' => 42, 'name' => 'Council minutes']);
		$this->assertSame(['/api/recipes/import', '/api/templates'], array_column($this->calls, 'route'));
		$saved = $this->calls[1];
		$this->assertSame('POST', $saved['method']);
		$this->assertSame('Council minutes', $saved['params']['name']);
		$this->assertSame('/', $saved['params']['target'], 'a template has no folder; Harvester wants one all the same');
		$this->assertSame('site', $saved['params']['source']);
		$this->assertSame(self::JOB['options'], $saved['params']['options']);
		$this->assertCount(1, $posted);
		$this->assertSame('Council minutes.harvester.json', $posted[0]['name']);
		$this->assertSame($file, json_decode($posted[0]['data'], true));
		$this->assertStringContainsString('id 7', $result);
		$this->assertStringContainsString('https://cloud.example/index.php/apps/app_api/embedded/harvester/harvester', $result);
		$this->assertStringNotContainsString('/api/jobs', implode(' ', array_column($this->calls, 'route')), 'no job is made or run');
	}

	public function testWithoutHarvesterNothingIsOffered(): void {
		$this->assertFalse($this->harvester(false)->available(), 'without AppAPI');
		$this->assertFalse($this->harvester(true, false)->available(), 'Harvester disabled');
		$this->assertTrue($this->harvester()->available());
		$this->values['harvester'] = 'no';
		$this->assertFalse($this->harvester()->available(), 'switched off by the administrator');
		$posted = [];
		$tools = $this->reply($this->harvester(), $posted, ['harvester' => false]);
		$this->assertArrayNotHasKey('harvester_recipe', $tools);
		$this->assertArrayNotHasKey('harvester_job', $tools);
		$this->assertSame([], $this->calls);
	}

	public function testTheJobsListHoldsNoOptionsAccountOrChallengeAddress(): void {
		$this->answer = fn () => [200, [[
			'id' => 3, 'user' => 'anna', 'name' => 'Bank', 'source' => 'site', 'target' => '/Bank', 'schedule' => '0 6 * * *', 'timezone' => 'Europe/Berlin',
			'options' => ['recipe' => '{"start": "https://bank.example/statements", "steps": [{"action": "fill", "selector": "#pw", "value": "hunter2"}]}'],
			'enabled' => true, 'last_run' => 1762150000, 'last_status' => 'failed', 'last_message' => 'step 2 (click “Sign in”) failed',
			'challenge_url' => 'https://bank.example/verify?token=secret', 'asking' => null, 'running' => false, 'queued' => false,
			'waiting' => '', 'fetches' => [],
		]]];
		$jobs = $this->harvester()->jobs('anna');
		$text = (string)json_encode($jobs);
		$this->assertSame(3, $jobs[0]['id']);
		$this->assertSame('bank.example', $jobs[0]['site']);
		$this->assertSame('failed', $jobs[0]['last_status']);
		$this->assertTrue($jobs[0]['needs_person']);
		foreach (['hunter2', 'token=secret', '"options"', '"user"'] as $secret) {
			$this->assertStringNotContainsString($secret, $text);
		}
	}

	public function testARecipeHidesPasswordsAndAFixGetsThemBackKeepingTheJobsOwnSettings(): void {
		$recipe = '{"start": "https://bank.example/statements", "steps": [{"action": "fill", "selector": "#user", "credential": "username"}, '
			. '{"action": "fill", "selector": "#pw", "value": "hunter2"}, {"action": "click", "selector": "#go"}]}';
		$job = ['id' => 3, 'name' => 'Bank', 'source' => 'site', 'target' => '/Bank', 'schedule' => '0 6 * * *', 'timezone' => 'Europe/Berlin',
			'options' => ['recipe' => $recipe, 'path' => '{filename}'], 'tags' => ['Bank'], 'enabled' => true, 'once' => false];
		$this->answer = function (string $method, string $route, array $params) use ($job) {
			return match ($method . ' ' . $route) {
				'GET /api/jobs' => [200, [$job]],
				'POST /api/recipes/export' => [200, ['format' => 'harvester-recipe', 'version' => 1, 'name' => 'Bank', 'source' => 'site',
					'options' => ['recipe' => json_decode($params['options']['recipe'], true), 'path' => '{filename}']]],
				'POST /api/recipes/import' => [200, ['name' => 'Bank', 'source' => 'site', 'schedule' => '', 'tags' => [], 'once' => false, 'target' => '',
					'options' => ['recipe' => json_encode(json_decode(json_decode($params['text'], true)['options']['recipe'], true)), 'max_pages' => 5]]],
				'PUT /api/jobs/3' => [200, $params + ['id' => 3]],
				default => [500, []],
			};
		};
		$harvester = $this->harvester();
		$file = $harvester->recipe('anna', 3);
		$this->assertStringNotContainsString('hunter2', (string)json_encode($file));
		$this->assertSame(Harvester::HIDDEN, $file['options']['recipe']['steps'][1]['value']);

		// The model's fix: another button, the hidden value kept as it read it.
		$file['options']['recipe']['steps'][2]['selector'] = 'button[type=submit]';
		$file['options']['recipe'] = json_encode($file['options']['recipe']);
		$result = $harvester->apply('anna', 3, (string)json_encode($file));
		$this->assertTrue($result['ok'], $result['problems'] ?? '');
		$put = end($this->calls);
		$this->assertSame('PUT', $put['method']);
		$this->assertSame('/api/jobs/3', $put['route']);
		$this->assertSame(['/Bank', '0 6 * * *', 'Europe/Berlin', ['Bank'], 'Bank'],
			[$put['params']['target'], $put['params']['schedule'], $put['params']['timezone'], $put['params']['tags'], $put['params']['name']]);
		$steps = json_decode($put['params']['options']['recipe'], true)['steps'];
		$this->assertSame('hunter2', $steps[1]['value'], 'the job\'s own value again');
		$this->assertSame('button[type=submit]', $steps[2]['selector']);
		$this->assertSame(5, $put['params']['options']['max_pages']);
		$this->assertSame('{filename}', $put['params']['options']['path'], 'options the file lacks stay');
		$this->assertSame($recipe, ((array)$result['before']['options'])['recipe'], 'kept for undo');
	}

	public function testApplyingAFixWaitsForThePersonsYes(): void {
		$this->answer = fn (string $method, string $route) => [200, [['id' => 3, 'name' => 'Bank', 'source' => 'site']]];
		$posted = [];
		$this->reply($this->harvester(), $posted)['harvester_job']['run']('anna', ['action' => 'apply', 'job' => 3, 'recipe' => self::RECIPE]);
		$this->assertSame(['GET'], array_column($this->calls, 'method'), 'nothing is changed before the yes');
	}

	public function testTheBrowsersPictureGoesToTheModelAsAnImage(): void {
		$this->answer = fn () => [200, ['at' => 1762150000, 'url' => 'https://bank.example/login', 'title' => 'Sign in', 'what' => 'step 3 failed', 'image' => 'SlBFRw==']];
		$posted = [];
		$result = $this->reply($this->harvester(), $posted)['harvester_job']['run']('anna', ['action' => 'shot', 'job' => 3]);
		$this->assertSame([['type' => 'image/jpeg', 'data' => 'SlBFRw==']], $result['images']);
		$this->assertStringContainsString('step 3 failed', $result['text']);
		$this->assertStringNotContainsString('SlBFRw==', $result['text']);
	}

	public function testATestRequestReturnsTheLinesOfItsWait(): void {
		$after = [];
		$this->answer = function (string $method, string $route, array $params) use (&$after) {
			if ($route === '/api/log') {
				$after[] = $params['after'];
				return [200, ['lines' => (int)$params['after'] === 0 ? [['id' => 10, 'at' => 1, 'level' => 'info', 'message' => 'old']]
					: [['id' => 11, 'at' => 2, 'level' => 'info', 'message' => str_repeat('x', 1000)]]]];
			}
			return $route === '/api/jobs/3/test' ? [200, []] : [500, []];
		};
		$test = $this->harvester()->test('anna', 3, 10);
		$this->assertTrue($test['requested']);
		$this->assertSame([0, 10, 11], $after, 'only the lines after the request');
		$this->assertLessThanOrEqual(400, mb_strlen($test['lines'][0]['message']), 'a line is cut');
	}
}
