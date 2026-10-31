<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Ai\Config;
use OCA\TalkAi\Ai\Conversations;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Hub;
use OCA\TalkAi\Ai\TicketJob;
use OCA\TalkAi\Ai\Tickets;
use OCA\TalkAi\Ai\Turn;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/** Questions that do not wait (tickets), and remembered conversations. */
class TicketsTest extends TestCase {
	use MemoryConfig;

	private array $queued = [];
	private ?string $saved = null;

	private function hub(ScriptEngine $engine, ?IRootFolder $root = null): Hub {
		$engines = new Engines($this->memoryConfig());
		$engines->fix($engine);
		$hub = new Hub($engines, new Conversations($this->memoryConfig(), $root ?? $this->createMock(IRootFolder::class)));
		$hub->registerScenario('assistant', ['system' => 's', 'ocs' => true, 'description' => 'd']);
		$hub->registerScenario('secret', ['system' => 's']);
		return $hub;
	}

	private function tickets(Hub $hub, FakeHttp $http): Tickets {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function ($class, $argument) {
			$this->queued[] = [$class, $argument];
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn ($p) => 'https://cloud.example' . $p);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (int $n) => substr(str_repeat(bin2hex(random_bytes(16)), 3), 0, $n));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . $v);
		$crypto->method('decrypt')->willReturnCallback(fn ($v) => substr($v, 4));
		return new Tickets($hub, $this->memoryConfig(), $jobs, $http, $urls, $random, new Config($this->memoryConfig(), $crypto));
	}

	public function testATicketIsHandedOffSignedAndItsResultKeptForItsOwner(): void {
		$hub = $this->hub(new ScriptEngine([new Turn('Hello.')]));
		$http = new FakeHttp([[200, '{"taken":true}']]);
		$tickets = $this->tickets($hub, $http);
		$id = $tickets->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'Hi']])['id'];
		$this->assertSame(['state' => 'queued'], $tickets->result('ewa', $id));
		// The hand-off: our own run route, signed with the tickets' secret.
		$request = $http->requests[0];
		$this->assertSame('https://cloud.example/index.php/apps/talk_ai/api/v1/run', $request['url']);
		$this->assertTrue($tickets->verify((string)$request['options']['body'], $request['options']['headers']['X-Talk-AI-Signature']));
		$this->assertFalse($tickets->verify((string)$request['options']['body'], str_repeat('0', 64)));
		$this->assertSame([], $this->queued, 'taken by the route: no background job');

		$tickets->run($id);
		$tickets->run($id);
		$result = $tickets->result('ewa', $id);
		$this->assertSame(['done', 'Hello.'], [$result['state'], $result['text']]);
		$this->assertArrayNotHasKey('messages', $result);
		$this->assertSame(['state' => 'error', 'error' => 'no-ticket'], $tickets->result('jan', $id), 'another person sees no ticket');
	}

	public function testAFailedHandOffGoesToABackgroundJobAndRefusalsComeAtOnce(): void {
		$tickets = $this->tickets($this->hub(new ScriptEngine([])), new FakeHttp([new \RuntimeException('cURL error 7: Failed to connect')]));
		$id = $tickets->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'Hi']])['id'];
		$this->assertSame([[TicketJob::class, ['id' => $id]]], $this->queued);
		$this->assertSame(['error' => Errors::NO_SCENARIO], $tickets->ask('ewa', 'nope', [['role' => 'user', 'text' => 'Hi']]));
		$this->assertSame('no-ticket', $tickets->result('ewa', '../etc')['error']);
	}

	public function testExpiredTicketsAreGone(): void {
		$tickets = $this->tickets($this->hub(new ScriptEngine([])), new FakeHttp([[200, '{"taken":true}']]));
		$id = $tickets->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'Hi']])['id'];
		$key = 'ai_ticket_' . $id;
		$this->values[$key] = json_encode(['expires' => time() - 1] + json_decode($this->values[$key], true));
		$this->assertSame('no-ticket', $tickets->result('ewa', $id)['error']);
		$tickets->clean();
		$this->assertArrayNotHasKey($key, $this->values);
	}

	public function testARememberedConversationKeepsWordsAndLapses(): void {
		$engine = new ScriptEngine([new Turn('A cat.'), new Turn('Grey.')]);
		$hub = $this->hub($engine);
		$png = ['type' => 'image/png', 'data' => base64_encode('PNG')];
		$hub->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'What is it?']], ['conversation' => 'c1', 'images' => [$png]]);
		$hub->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'ignored, the stored turns are the history'], ['role' => 'user', 'text' => 'Its colour?']], ['conversation' => 'c1']);
		$second = $engine->requests[1]->messages;
		$this->assertSame(['user', 'assistant', 'user'], array_column($second, 'role'));
		$this->assertSame('[1 image(s)] What is it?', $second[0]['parts'][0]['text'], 'a mark, never the image again');
		$this->assertSame('Its colour?', $second[2]['parts'][0]['text']);
		// Another person's conversation with the same token is another one.
		$hub->ask('jan', 'assistant', [['role' => 'user', 'text' => 'Hi']], ['conversation' => 'c1']);
		$this->assertCount(1, $engine->requests[2]->messages);

		foreach ($this->values as $key => $value) {
			if (str_starts_with($key, 'ai_conv_')) {
				$this->values[$key] = json_encode(['updated' => time() - 13 * 3600] + json_decode($value, true));
			}
		}
		$hub->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'Again']], ['conversation' => 'c1']);
		$this->assertCount(1, $engine->requests[3]->messages, 'unused for twelve hours: a new conversation');
	}

	public function testATranscriptGoesToFilesAsMarkdownAndForgetDropsIt(): void {
		$file = $this->createMock(File::class);
		$file->method('putContent')->willReturnCallback(function ($content) {
			$this->saved = $content;
		});
		$file->method('getPath')->willReturn('/ewa/files/AI conversations/x.md');
		// Newer Nextcloud returns IUserFolder (a Folder) from getUserFolder().
		$folder = $this->createMock(interface_exists('OCP\Files\IUserFolder') ? 'OCP\Files\IUserFolder' : Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturn($folder);
		$folder->method('newFile')->willReturn($file);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$hub = $this->hub(new ScriptEngine([new Turn('Paris.'), new Turn('Asked for a capital; it is Paris.')]), $root);
		$hub->ask('ewa', 'assistant', [['role' => 'user', 'text' => 'Capital of France?']], ['conversation' => 'c2']);
		$this->assertSame('/ewa/files/AI conversations/x.md', $hub->saveConversation('ewa', 'c2', summary: true));
		$this->assertStringContainsString('# Capital of France?', $this->saved);
		$this->assertStringContainsString("## Summary\n\nAsked for a capital; it is Paris.", $this->saved);
		$this->assertStringContainsString('**AI:** Paris.', $this->saved);
		$hub->forget('ewa', 'c2');
		$this->assertSame([], array_filter(array_keys($this->values), fn ($k) => str_starts_with($k, 'ai_conv_')));
	}

	public function testFoldWritesTheNewRollingSummary(): void {
		$engine = new ScriptEngine([new Turn('They planned a trip.')]);
		$this->assertSame('They planned a trip.', $this->hub($engine)->fold('ewa', '', ['ewa: let us go to Rome', 'jan: yes']));
		$question = $engine->requests[0]->messages[0]['parts'][0]['text'];
		$this->assertStringContainsString("Summary so far:\n(none)", $question);
		$this->assertStringContainsString('jan: yes', $question);
		$this->assertSame(['assistant' => 'd'], $this->hub(new ScriptEngine([]))->scenarios(), 'only the scenarios open to the OCS API');
	}
}
