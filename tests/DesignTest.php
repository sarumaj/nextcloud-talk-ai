<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Code;
use OCA\TalkAi\Document\Figures;
use OCA\TalkAi\Document\OdpWriter;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\Theme;
use PHPUnit\Framework\TestCase;

class DesignTest extends TestCase {
	public function testCodeIsHighlighted(): void {
		$lines = Code::lines("def f(x):\n    # twice\n    return \"x\" * 2", 'py');
		$this->assertStringContainsString('<text:span text:style-name="CodeKeyword">def</text:span>', $lines[0]);
		$this->assertStringStartsWith('<text:s text:c="4"/>', $lines[1]);
		$this->assertStringContainsString('<text:span text:style-name="CodeComment"># twice</text:span>', $lines[1]);
		$this->assertStringContainsString('<text:span text:style-name="CodeString">&quot;x&quot;</text:span>', $lines[2]);
		$this->assertStringContainsString('<text:span text:style-name="CodeNumber">2</text:span>', $lines[2]);
		$this->assertStringContainsString('CodeKeyword">SELECT</text:span>', Code::lines('SELECT name FROM s -- all', 'sql')[0]);
		$java = Code::lines("int a; /* a\nb */ int b;", 'java');
		$this->assertStringContainsString('CodeComment">/* a</text:span>', $java[0]);
		$this->assertStringStartsWith('<text:span text:style-name="CodeComment">b */</text:span>', $java[1]);
		$this->assertSame('Python', Code::label('py'));
	}

	public function testDocumentDesign(): void {
		$odt = (new OdtWriter())->write("[TOC]\n\n# One\nText.[^a]\n\n## Two\n> [!WARNING] Careful\n> hot\n\n| L | C | R |\n|:--|:-:|--:|\n| a | b | c |\n\n\\pagebreak\n\n::: columns 2\nleft and right\n:::\n\n```chart\n{\"type\": \"pie\", \"labels\": [\"a\", \"b\"], \"series\": [{\"values\": [1, 2]}]}\n```\n\n![A photo](file:7)\n\n[^a]: A note.",
			'Doc', fn (string $source) => $source === 'file:7' ? ['data' => "\x89PNG", 'mime' => 'image/png', 'width' => 400, 'height' => 300] : null,
			['theme' => 'academic', 'cover' => ['title' => 'Doc', 'author' => 'Ewa'], 'landscape' => true]);
		$files = $this->unzip($odt);
		$content = $files['content.xml'];
		$this->assertNotFalse(simplexml_load_string($content));
		$this->assertNotFalse(simplexml_load_string($files['styles.xml']));
		$this->assertStringContainsString('<text:p text:style-name="Contents_20_1">1  One</text:p><text:p text:style-name="Contents_20_2">1.1  Two</text:p>', $content);
		$this->assertStringContainsString('text:outline-level="1">1  One</text:h>', $content);
		$this->assertStringContainsString('<text:note text:id="ftn1" text:note-class="footnote"><text:note-citation>1</text:note-citation>', $content);
		$this->assertStringContainsString('<text:span text:style-name="CalloutTitle_warning">Careful</text:span>', $content);
		$this->assertStringContainsString('Table_20_Heading_Center', $content);
		$this->assertStringContainsString('Table_20_Contents_Right', $content);
		$this->assertStringContainsString('<text:p text:style-name="Page_20_Break"/>', $content);
		$this->assertStringContainsString('<text:section text:style-name="Columns2"', $content);
		$this->assertStringContainsString('<text:p text:style-name="Cover_20_Title">Doc</text:p>', $content);
		$this->assertArrayHasKey('Pictures/picture1.svg', $files);
		$this->assertArrayHasKey('Pictures/picture2.png', $files);
		$this->assertStringContainsString('<text:p text:style-name="Caption">A photo</text:p>', $content);
		$this->assertStringContainsString('fo:page-width="29.7cm"', $files['styles.xml']);
		$this->assertStringContainsString('<text:page-number', $files['styles.xml']);
		$this->assertStringContainsString('manifest:full-path="Pictures/picture1.svg" manifest:media-type="image/svg+xml"', $files['META-INF/manifest.xml']);
	}

	public function testSlidesWithPictures(): void {
		$odp = (new OdpWriter())->write("# Talk\n\n## Chart\n- point\n```chart\n{\"type\": \"bar\", \"labels\": [\"a\"], \"series\": [{\"values\": [1]}]}\n```\n\n## Only a picture\n![x](file:1)", 'Talk',
			fn () => ['data' => "\x89PNG", 'mime' => 'image/png', 'width' => 100, 'height' => 100], 'playful');
		$files = $this->unzip($odp);
		$this->assertNotFalse(simplexml_load_string($files['content.xml']));
		$this->assertSame(2, substr_count($files['content.xml'], '<draw:image '));
		$this->assertStringContainsString('#ef476f', $files['content.xml']);
	}

	public function testHeadingNumbers(): void {
		// Numbered from the highest level used; not at all when the headings carry numbers.
		$files = $this->unzip((new OdtWriter())->write("## Intro\n### Part\n## End", 'x', null, ['theme' => 'academic']));
		$this->assertStringContainsString('>1  Intro</text:h>', $files['content.xml']);
		$this->assertStringContainsString('>1.1  Part</text:h>', $files['content.xml']);
		$this->assertStringContainsString('>2  End</text:h>', $files['content.xml']);
		$files = $this->unzip((new OdtWriter())->write("## 1. Intro\n## 2. End", 'x', null, ['theme' => 'academic']));
		$this->assertStringContainsString('>1. Intro</text:h>', $files['content.xml']);
	}

	public function testAgendaAndSections(): void {
		$files = $this->unzip((new OdpWriter())->write("# Talk\nsub\n\n# Part A\n\n## One\n- a\n\n# Part B\n\n## Two\n- b", 'Talk', null, 'modern', true, 'Gliederung'));
		$content = $files['content.xml'];
		$this->assertNotFalse(simplexml_load_string($content));
		$this->assertStringContainsString('Gliederung', $content);
		$this->assertSame(3, substr_count($content, 'draw:style-name="dpGradient"'));
		// The agenda: the sections, each by a numbered circle.
		$this->assertMatchesRegularExpression('/>1<\/text:p>.*>Part A<\/text:p>.*>2<\/text:p>.*>Part B<\/text:p>/s', $content);
		$this->assertStringContainsString('presentation:class="page-number"', $files['styles.xml']);
	}

	public function testCustomThemes(): void {
		$t = Theme::resolve(['base' => 'dark', 'accent' => '#FF6600', 'heading' => 'Caprasimo', 'body' => 'Comic Sans', 'gradient' => ['#000000', '#ff6600'], 'decoration' => 'zigzag']);
		$this->assertSame('#ff6600', $t['accent']);
		$this->assertSame('Caprasimo', $t['heading']);
		$this->assertSame('Open Sans', $t['body']);
		$this->assertSame(['#000000', '#ff6600'], $t['gradient']);
		$this->assertSame('corner', $t['decoration']);
		$this->assertTrue($t['dark']);
		$this->assertFalse(Theme::forPrint($t)['dark']);
		$this->assertSame('Karla', Theme::resolve('minimal')['heading']);
		$this->assertSame(Theme::resolve('modern'), Theme::resolve('nonsense'));
		$odp = $this->unzip((new OdpWriter())->write("# T\n\n## Numbers {stats}\n- 97 % | ocean\n\n## Steps {steps}\n- **A**: a\n- **B**: b", 'T', null, $t));
		$this->assertStringContainsString('Caprasimo', $odp['content.xml']);
		$this->assertStringContainsString('draw:start-color="#000000" draw:end-color="#ff6600"', $odp['styles.xml']);
		$this->assertStringContainsString('>97 %</text:p>', $odp['content.xml']);
	}

	public function testChatAnswersAreSplitAroundPictures(): void {
		$parts = Figures::split("Here:\n\n~~~chart\n{\"type\": \"pie\"}\n~~~\n\nand a photo\n![Cat](file:3)\nDone.");
		$this->assertSame(['text', 'chart', 'text', 'image', 'text'], array_column($parts, 'type'));
		$this->assertSame('file:3', $parts[3]['source']);
		$this->assertSame([['type' => 'text', 'text' => 'Plain.']], Figures::split('Plain.'));
	}

	/** @return array<string, string> */
	private function unzip(string $data): array {
		$path = tempnam(sys_get_temp_dir(), 'z');
		file_put_contents($path, $data);
		$zip = new \ZipArchive();
		$zip->open($path);
		$files = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$files[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		unlink($path);
		return $files;
	}
}
