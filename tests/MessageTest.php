<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Listener\BotInvokeListener;
use OCA\TalkAi\Service\Conversation;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase {
	public function testNamesTheBot(): void {
		foreach (['@AI what is this?', 'AI, what is this?', 'ai: hi', 'please @ai look', '@"AI" hi'] as $text) {
			$this->assertTrue(BotInvokeListener::names($text, 'AI'), $text);
		}
		foreach (['what is AI?', 'said', '@AIDA hi', 'email@ai.org'] as $text) {
			$this->assertFalse(BotInvokeListener::names($text, 'AI'), $text);
		}
		$this->assertTrue(BotInvokeListener::names('@Claude summarize', 'Claude'));
	}

	public function testPlainTextWritesOutMentions(): void {
		$content = json_encode(['message' => 'Hi {mention-user1}, see this', 'parameters' => ['mention-user1' => ['type' => 'user', 'name' => 'Ewa']]]);
		$this->assertSame('Hi @Ewa, see this', BotInvokeListener::plainText($content));
		$this->assertSame('not json', BotInvokeListener::plainText('not json'));
	}

	public function testRenderShowsFilesWithTheirIds(): void {
		$text = Conversation::render('{file}', ['file' => ['type' => 'file', 'name' => 'Plan.pdf', 'id' => '42']]);
		$this->assertSame('[file "Plan.pdf", id 42]', $text);
		$this->assertSame('Hi @Ewa', Conversation::render('Hi {mention-user1}', ['mention-user1' => ['type' => 'user', 'name' => 'Ewa']]));
	}

	public function testBotActorIdIsTalks(): void {
		$this->assertSame('bot-' . sha1('nextcloudapp://talk_ai'), Application::botActorId());
	}
}
