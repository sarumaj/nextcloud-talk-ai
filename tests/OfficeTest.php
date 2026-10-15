<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Calculator;
use OCA\TalkAi\Document\Chart;
use OCA\TalkAi\Document\Math;
use OCA\TalkAi\Document\OdpWriter;
use OCA\TalkAi\Document\OdsWriter;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\Statistics;
use OCA\TalkAi\Document\TextExtractor;
use OCA\TalkAi\Service\Bot;
use OCA\TalkAi\Service\Web;
use PHPUnit\Framework\TestCase;

class OfficeTest extends TestCase {
	public function testMathAsUnicodeForTheChat(): void {
		$this->assertSame('(−b ± √(b² − 4ac))/(2a)', Math::unicode('\frac{-b \pm \sqrt{b^2-4ac}}{2a}'));
		$this->assertSame('∑ᵢ₌₁ⁿ i = (n(n + 1))/2', Math::unicode('\sum_{i=1}^{n} i = \frac{n(n+1)}{2}'));
		$this->assertSame('A = (1, 2; 3, 4)', Math::unicode('A = \begin{pmatrix} 1 & 2 \\\\ 3 & 4 \end{pmatrix}'));
		$this->assertSame('C(n, k) = n!/(k!(n − k)!)', Math::unicode('\binom{n}{k} = \frac{n!}{k!(n-k)!}'));
		$this->assertSame('ℝ², x ∈ ℕ', Math::unicode('\mathbb{R}^2, x \in \mathbb{N}'));
		$this->assertSame('lim_(x→0) (sin x)/x = 1', Math::unicode('\lim_{x \to 0} \frac{\sin x}{x} = 1'));
		// Money is no formula; code is left alone; display formulas get their own line.
		$this->assertSame("It costs \$5 or \$10, so x² and\n∫ₐᵇ f\n`\$a\$`", Math::forChat('It costs $5 or $10, so $x^2$ and $$\int_a^b f$$ `$a$`'));
	}

	public function testMathAsMathMl(): void {
		$mathml = Math::mathml('\int_0^\infty e^{-x^2}\,dx', true);
		$this->assertNotFalse(simplexml_load_string($mathml));
		$this->assertStringContainsString('<msubsup><mo largeop="true" movablelimits="false">∫</mo><mrow><mn>0</mn></mrow><mrow><mi>∞</mi></mrow></msubsup>', $mathml);
		$this->assertStringContainsString('<annotation encoding="TeX">\int_0^\infty e^{-x^2}\,dx</annotation>', $mathml);
		$this->assertNotFalse(simplexml_load_string(Math::mathml('f(x) = \begin{cases} x & x \ge 0 \\\\ -x & \text{else} \end{cases} \left( \frac12 \right) \unknown \vec{v}')));
	}

	public function testDocumentsEmbedFormulas(): void {
		$odt = (new OdtWriter())->write("Area: \$A = \\pi r^2\$\n\n\$\$\\frac{a}{b}\$\$\n\nCosts \$5 and \$10.", 'Maths');
		$files = $this->unzip($odt);
		$this->assertArrayHasKey('Formula1/content.xml', $files);
		$this->assertArrayHasKey('Formula2/content.xml', $files);
		$this->assertArrayNotHasKey('Formula3/content.xml', $files);
		$this->assertStringContainsString('manifest:full-path="Formula2/" manifest:media-type="application/vnd.oasis.opendocument.formula"', $files['META-INF/manifest.xml']);
		$this->assertStringContainsString('<text:p text:style-name="Formula_20_Display"><draw:frame', $files['content.xml']);
		$this->assertStringContainsString('Costs $5 and $10.', $files['content.xml']);
		foreach ($files as $name => $xml) {
			if (str_ends_with($name, '.xml')) {
				$this->assertNotFalse(simplexml_load_string($xml), $name);
			}
		}
	}

	public function testNestedListsAndCheckBoxes(): void {
		$body = OdtWriter::blocks("- one\n  - inner\n    continued\n- [ ] todo\n- [x] done");
		$this->assertSame(2, substr_count($body, '<text:list '));
		$this->assertStringContainsString('inner continued', $body);
		$this->assertStringContainsString('☐ todo', $body);
		$this->assertStringContainsString('☑ done', $body);
	}

	public function testFlashCards(): void {
		$files = $this->unzip((new OdtWriter())->cards([['front' => 'What is $\sqrt{16}$?', 'back' => "4\n- because 4² = 16"]], 'Maths'));
		$this->assertSame(1, substr_count($files['content.xml'], '<table:table-row table:style-name="Card">'));
		$this->assertArrayHasKey('Formula1/content.xml', $files);
		$this->assertStringContainsString('• because 4² = 16', $files['content.xml']);
	}

	public function testSpreadsheets(): void {
		$sheets = OdsWriter::sheets("## Budget\n| Item | Price |\n|---|---|\n| Bread | 2.50 |\n\n| Extra | 1 |\n|---|---|\n\n## Budget\n| A | B |\n|--|--|");
		$this->assertSame(['Budget', 'Budget 2'], array_column($sheets, 'name'));
		$this->assertSame([['Item', 'Price'], ['Bread', '2.50'], [], ['Extra', '1']], $sheets[0]['rows']);
		$this->assertSame([['a', 'b'], ['1', '2']], OdsWriter::sheets("a,b\n1,2")[0]['rows']);
		$this->assertSame('=[.B2]*[.C2]', OdsWriter::formula('B2*C2'));
		$this->assertSame('=SUM([.D2:.D9]; 1)', OdsWriter::formula('SUM(D2:D9, 1)'));
		$this->assertSame("=IF([.A1]>0; \"yes, sure\"; [\$'My sheet'.\$B\$3])", str_replace("['", "[\$'", OdsWriter::formula("IF(A1>0, \"yes, sure\", 'My sheet'!\$B\$3)")));
		$this->assertSame(1234.5, OdsWriter::number('1,234.5'));
		$this->assertSame(1234.5, OdsWriter::number('1.234,5'));
		$this->assertSame(0.5, OdsWriter::number('0,5'));
		$this->assertSame(19.0, OdsWriter::number('19 %'));
		$this->assertNull(OdsWriter::number('B12'));
		$this->assertStringContainsString('office:value-type="percentage" office:value="0.19"', OdsWriter::cell('19%'));
		$this->assertStringContainsString('table:formula="of:=SUM([.B2:.B3])"', OdsWriter::cell('=SUM(B2:B3)'));
		$files = $this->unzip((new OdsWriter())->write("## Budget\n| Item | Price |\n|---|---|\n| Bread | 2.50 |", 'Budget'));
		$this->assertNotFalse(simplexml_load_string($files['content.xml']));
		$text = (new TextExtractor('/nonexistent'))->extract((new OdsWriter())->write("## Budget\n| Item | Price |\n|---|---|\n| Bread | 2.50 |"), '', 'b.ods');
		$this->assertSame("# Sheet: Budget\nItem\tPrice\nBread\t2.50", $text);
	}

	public function testPresentations(): void {
		$markdown = "# Photosynthesis\nClass 7b\n\n## The equation\n- light\n  - \$6CO_2\$\nNotes: ask first\n\n---\nThank you!";
		$slides = OdpWriter::slides($markdown);
		$this->assertSame(['Photosynthesis', 'The equation', ''], array_column($slides, 'title'));
		$this->assertSame(['ask first'], $slides[1]['notes']);
		$odp = (new OdpWriter())->write($markdown, 'Photosynthesis');
		$files = $this->unzip($odp);
		$this->assertNotFalse(simplexml_load_string($files['content.xml']));
		$this->assertSame(3, substr_count($files['content.xml'], '<draw:page '));
		$text = (new TextExtractor('/nonexistent'))->extract($odp, '', 'p.odp');
		$this->assertStringContainsString("# Slide 2\nThe equation\nlight\n6CO₂", (string)$text);
		$this->assertStringContainsString('Notes: ask first', (string)$text);
		$this->assertStringContainsString("# Slide 3\nThank you!", (string)$text);
	}

	public function testCalculator(): void {
		$results = (new Calculator())->evaluate("0.1 + 0.2; -2^2; 2^3^2; 5!; r = 2; pi r^2; 1/3 + 1/6; 10/0; binom(49, 6)");
		$this->assertSame(['0.3', '-4', '512', '120', '2', '12.5663706143592', '0.5'], array_column(array_slice($results, 0, 7), 'result'));
		$this->assertSame('1/2', $results[6]['fraction']);
		$this->assertSame('division by zero', $results[7]['error']);
		$this->assertSame('13983816', $results[8]['result']);
		$this->assertSame('0.5', (new Calculator(true))->evaluate('sin(30)')[0]['result']);
	}

	public function testStatistics(): void {
		$d = Statistics::describe(Statistics::numbers('2, 4, 4, 4, 5, 5, 7, 9'));
		$this->assertSame(['8', '40', '5', '4.5', '2', '9'], [(string)$d['count'], $d['sum'], $d['mean'], $d['median'], $d['min'], $d['max']]);
		$this->assertSame(['4'], $d['mode']);
		$this->assertSame('2', $d['stdev_population']);
		$this->assertSame('2.1380899353', $d['stdev_sample']);
		$this->assertSame([1.5, 2.0, 3.25], Statistics::numbers('1,5; 2 3,25'));
		$r = Statistics::regression([1, 2, 3, 4], [3, 5, 7, 9]);
		$this->assertSame('y = 1 + 2x', $r['line']);
		$this->assertSame('1', $r['pearson_r']);
		$this->assertSame('5', (new Calculator())->evaluate('median(9, 1, 5)')[0]['result']);
	}

	public function testCharts(): void {
		$this->assertSame([0.0, 0.2, 0.4, 0.6, 0.8, 1.0, 1.2, 1.4, 1.6, 1.8, 2.0], Chart::ticks(0, 2));
		$this->assertSame([-20.0, -10.0, 0.0, 10.0, 20.0, 30.0], Chart::ticks(-25, 35));
		foreach ([
			['type' => 'function', 'title' => 'f & g', 'functions' => [['expression' => 'x^2'], ['expression' => 'tan(x)'], ['expression' => 'sqrt(x)']], 'x_min' => -3, 'x_max' => 3],
			['type' => 'bar', 'labels' => ['a', 'b'], 'series' => [['values' => [1, -2]]]],
			['type' => 'line', 'labels' => ['Mon', 'Tue'], 'series' => [['label' => 'x', 'values' => [3, 4]]]],
			['type' => 'scatter', 'series' => [['points' => [[1, 2], [3, 4]]]]],
			['type' => 'pie', 'labels' => ['a', 'b'], 'series' => [['values' => [1, 3]]]],
		] as $spec) {
			$svg = Chart::svg($spec);
			$this->assertNotFalse(simplexml_load_string($svg), $spec['type']);
		}
		$this->assertStringContainsString('f &amp; g', Chart::svg(['type' => 'function', 'title' => 'f & g', 'functions' => [['expression' => 'x']]]));
		$this->expectExceptionMessage('unknown');
		Chart::svg(['type' => 'function', 'functions' => [['expression' => 'foo(x)']]]);
	}

	public function testPageMetadata(): void {
		$html = '<html><head><title>Fallback</title><meta name="citation_title" content="On Things &amp; Stuff">'
			. '<meta name="citation_author" content="Doe, Jane"><meta name="citation_author" content="Roe, R.">'
			. '<meta name="citation_doi" content="https://doi.org/10.1000/xyz123"><meta property="og:site_name" content="Journal">'
			. '<meta name="citation_publication_date" content="2024/05/01"><meta name="citation_firstpage" content="10"><meta name="citation_lastpage" content="20"></head></html>';
		$meta = Web::metadata($html, 'https://example.org/a');
		$this->assertSame('On Things & Stuff', $meta['title']);
		$this->assertSame(['Doe, Jane', 'Roe, R.'], $meta['authors']);
		$this->assertSame('10.1000/xyz123', $meta['doi']);
		$this->assertSame('10–20', $meta['pages']);
		$this->assertSame(['title' => 'Plain', 'site' => 'example.org'], Web::metadata('<title>Plain</title>', 'https://example.org/'));
	}

	public function testBotNames(): void {
		$this->assertSame('Claude', Bot::nameFor('claude', 'claude-opus'));
		$this->assertSame('Gemini', Bot::nameFor('gemini', ''));
		$this->assertSame('ChatGPT', Bot::nameFor('openai', 'gpt-4.1'));
		$this->assertSame('Llama', Bot::nameFor('openai', 'meta-llama/Llama-3.3-70B'));
		$this->assertSame('AI', Bot::nameFor('openai', 'some-model'));
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
