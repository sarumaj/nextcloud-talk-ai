<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\TextExtractor;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase {
	private const MARKDOWN = <<<'MD'
		# Plan for *Saturday*

		We meet at **10:00** at the [station](https://example.com/s) & go on.

		- bread
		- `cheese`

		1. first
		2. second

		| Who | What |
		|-----|------|
		| Ewa | cake |

		> a quote

		```
		code  with   spaces
		```

		---
		MD;

	public function testBlocksBecomeOdfElements(): void {
		$body = OdtWriter::blocks(self::MARKDOWN);
		$this->assertStringContainsString('<text:h text:style-name="Heading_20_1" text:outline-level="1">Plan for <text:span text:style-name="Emphasis">Saturday</text:span></text:h>', $body);
		$this->assertStringContainsString('<text:span text:style-name="Strong">10:00</text:span>', $body);
		$this->assertStringContainsString('<text:a xlink:type="simple" xlink:href="https://example.com/s">station</text:a> &amp; go on.', $body);
		$this->assertStringContainsString('<text:list text:style-name="List_20_Bullet">', $body);
		$this->assertStringContainsString('<text:span text:style-name="Code">cheese</text:span>', $body);
		$this->assertStringContainsString('<text:list text:style-name="Numbering_20_123">', $body);
		$this->assertSame(2, substr_count($body, '<table:table-row>'));
		$this->assertStringContainsString('Quotations', $body);
		$this->assertStringContainsString('code <text:s text:c="1"/>with <text:s text:c="2"/>spaces', $body);
		$this->assertStringContainsString('Horizontal_20_Line', $body);
		// Well-formed inside the document.
		$this->assertNotFalse(simplexml_load_string('<r xmlns:text="t" xmlns:table="t2" xmlns:xlink="x" xmlns:office="o">' . $body . '</r>'));
	}

	public function testOdtIsAValidPackageAndReadsBack(): void {
		$odt = (new OdtWriter())->write(self::MARKDOWN, 'Plan');
		$this->assertSame('PK', substr($odt, 0, 2));
		// The MIME type first, stored: ODF readers look for it at a fixed place.
		$this->assertSame('mimetypeapplication/vnd.oasis.opendocument.text', substr($odt, 30, 8) . substr($odt, 38, 39));
		foreach (['content.xml', 'styles.xml', 'meta.xml'] as $part) {
			$zip = new \ZipArchive();
			$path = tempnam(sys_get_temp_dir(), 't');
			file_put_contents($path, $odt);
			$zip->open($path);
			$this->assertNotFalse(simplexml_load_string((string)$zip->getFromName($part)), $part);
			$zip->close();
			unlink($path);
		}
		$text = (new TextExtractor('/nonexistent'))->extract($odt, 'application/vnd.oasis.opendocument.text', 'Plan.odt');
		$this->assertStringContainsString('Plan for Saturday', (string)$text);
		$this->assertStringContainsString("Ewa\tcake", (string)$text);
	}

	public function testExtractsWordExcelPowerPointAndHtml(): void {
		$extract = new TextExtractor('/nonexistent');
		$docx = $this->zip(['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Hello</w:t></w:r><w:r><w:tab/><w:t xml:space="preserve"> world &amp; co</w:t></w:r></w:p><w:p><w:r><w:t>Second</w:t></w:r></w:p></w:body></w:document>']);
		$this->assertSame("Hello\t world & co\nSecond", $extract->extract($docx, '', 'a.docx'));
		$xlsx = $this->zip([
			'xl/sharedStrings.xml' => '<sst><si><t>Name</t></si><si><t>Ewa</t></si></sst>',
			'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c r="A1" t="s"><v>0</v></c><c r="B1"><v>42</v></c></row><row><c t="s"><v>1</v></c><c t="inlineStr"><is><t>x</t></is></c></row></sheetData></worksheet>',
		]);
		$this->assertSame("# sheet1\nName\t42\nEwa\tx", $extract->extract($xlsx, '', 'a.xlsx'));
		$pptx = $this->zip(['ppt/slides/slide2.xml' => '<p:sld><a:p><a:t>Two</a:t></a:p></p:sld>', 'ppt/slides/slide1.xml' => '<p:sld><a:p><a:t>One</a:t></a:p></p:sld>']);
		$this->assertSame("# Slide 1\nOne\n\n# Slide 2\nTwo", $extract->extract($pptx, '', 'a.pptx'));
		$this->assertSame("Title\nText & more", $extract->extract('<html><style>x{}</style><h1>Title</h1><p>Text &amp; more</p></html>', 'text/html', 'a.html'));
		$this->assertSame('plain', $extract->extract('plain', 'text/plain', 'a.txt'));
		$this->assertNull($extract->extract('%PDF-1.4', 'application/pdf', 'a.pdf'));
		$this->assertNull($extract->extract("\x89PNG", 'image/png', 'a.png'));
	}

	private function zip(array $files): string {
		$path = tempnam(sys_get_temp_dir(), 'z');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		$data = (string)file_get_contents($path);
		unlink($path);
		return $data;
	}
}
