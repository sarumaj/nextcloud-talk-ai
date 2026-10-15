<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Biology;
use OCA\TalkAi\Document\Calculator;
use OCA\TalkAi\Document\Chart;
use OCA\TalkAi\Document\Chemistry;
use OCA\TalkAi\Document\Colors;
use OCA\TalkAi\Document\Informatics;
use OCA\TalkAi\Document\Math;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\Sport;
use OCA\TalkAi\Document\Units;
use PHPUnit\Framework\TestCase;

class SubjectsTest extends TestCase {
	public function testChemistry(): void {
		$this->assertSame('98.072 g/mol', Chemistry::molarMass('H2SO4')['molar_mass']);
		$this->assertSame('249.677 g/mol', Chemistry::molarMass('CuSO4·5H2O')['molar_mass']);
		$this->assertSame(-2, Chemistry::molarMass('SO4^2-')['charge']);
		$this->assertSame('368.345 g/mol', Chemistry::molarMass('K4[Fe(CN)6]')['molar_mass']);
		$this->assertSame('4 Fe + 3 O₂ → 2 Fe₂O₃', Chemistry::balance('Fe + O2 -> Fe2O3')['balanced']);
		$this->assertSame('2 KMnO₄ + 16 HCl → 2 KCl + 2 MnCl₂ + 8 H₂O + 5 Cl₂', Chemistry::balance('KMnO4 + HCl -> KCl + MnCl2 + H2O + Cl2')['balanced']);
		$this->assertSame('MnO₄⁻ + 5 Fe²⁺ + 8 H⁺ → Mn²⁺ + 5 Fe³⁺ + 4 H₂O', Chemistry::balance('MnO4^- + Fe^2+ + H^+ -> Mn^2+ + Fe^3+ + H2O')['balanced']);
		$this->assertSame(['Au', 79, 6, 11], array_values(array_intersect_key(Chemistry::element('gold'), array_flip(['symbol', 'atomic_number', 'period', 'group']))));
		$this->assertSame(18, Chemistry::element('Og')['group']);
		$this->assertSame('H₂SO₄', Math::unicode('\ce{H2SO4}'));
		$this->expectExceptionMessage('several independent balances');
		Chemistry::balance('H2 + O2 -> H2O2 + H2O');
	}

	public function testUnitsAndConstants(): void {
		$this->assertSame('27.7777777778', Units::convert(100, 'km/h', 'm/s')['value']);
		$this->assertSame('3600000', Units::convert(1, 'kWh', 'J')['value']);
		$this->assertSame('68', Units::convert(20, '°C', '°F')['value']);
		$this->assertSame('26.85', Units::convert(300, 'K', '°C')['value']);
		$this->assertSame('1013.25', Units::convert(1, 'atm', 'hPa')['value']);
		$this->assertSame('30.48', Units::convert(12, 'in', 'cm')['value']);
		$results = (new Calculator())->evaluate('m = 2; 0.5 m c0^2 / 1e16; normcdf(1.96); invnorm(0.975); binompdf(10, 0.5, 5); 0xFF + 1');
		$this->assertSame('8.98755178736818', $results[1]['result']);
		$this->assertSame('0.975002104851779', $results[2]['result']);
		$this->assertSame('1.95996398454005', $results[3]['result']);
		$this->assertSame('63/256', $results[4]['fraction']);
		$this->assertSame('100000000', $results[5]['binary']);
		$this->expectExceptionMessage('do not measure the same thing');
		Units::convert(1, 'm', 's');
	}

	public function testBiology(): void {
		$this->assertSame('9 : 3 : 3 : 1', Biology::cross('AaBb', 'AaBb')['phenotype_ratio']);
		$sexLinked = Biology::cross('XHXh', 'XHY');
		$this->assertStringContainsString('| **Xh** | XHXh | XhY |', $sexLinked['punnett_square']);
		$this->assertSame('1/4', $sexLinked['phenotypes']['male h (recessive)']['count']);
		$dna = Biology::sequence('TACGGCATTACT', 'template');
		$this->assertStringStartsWith('AUGCCGUAAUGA', $dna['mrna_5_to_3']);
		$this->assertSame('Met-Pro-Stop (MP*)', $dna['protein_from_start']);
	}

	public function testSport(): void {
		$this->assertSame('125–139 bpm', Sport::heartRateZones(18, 55)['zones']['1 recovery (50–60 %)']);
		$this->assertSame('5:15 min/km', Sport::pace(10, '52:30')['pace']);
		$this->assertStringStartsWith('1:55:00', Sport::predict(5, '25:00', 21.0975)['prediction']);
		$this->assertSame(22.1, Sport::bmi(70, 178)['bmi']);
		$this->assertSame(540, Sport::calories(9.8, 70, 45)['kcal']);
	}

	public function testInformatics(): void {
		$this->assertSame('tautology (always true)', Informatics::truthTable('(a -> b) <-> (not a or b)')['kind']);
		$this->assertSame(2, Informatics::truthTable('a xor b')['true_rows']);
		$this->assertSame('contradiction (never true)', Informatics::truthTable('p ∧ ¬p')['kind']);
		$this->assertSame('11111011', Informatics::encode('-5', 'number')['twos_complement_8_bit']);
		$this->assertSame('48 C3 A4', Informatics::encode('Hä', 'text')['utf8_bytes_hex']);
		$sql = Informatics::sql("CREATE TABLE s(name TEXT, k TEXT); INSERT INTO s VALUES ('Anna','12a'),('Ben','12b'),('Cem','12a');", 'SELECT k, COUNT(*) AS n FROM s GROUP BY k ORDER BY k');
		$this->assertSame("| k | n |\n|---|---|\n| 12a | 2 |\n| 12b | 1 |", $sql[0]['table']);
		$this->expectExceptionMessage('not allowed');
		Informatics::sql('', "ATTACH 'x.db' AS x");
	}

	public function testArtAndHistory(): void {
		$harmonies = Colors::harmonies('#e2725b');
		$this->assertSame('#5BCBE2', $harmonies['complementary'][0]['hex']);
		$this->assertSame('terracotta', $harmonies['color']['name']);
		$this->assertSame('ultramarine', Colors::describe('#120a8f')['name']);
		$this->assertNotFalse(simplexml_load_string(Colors::swatches(['#120a8f', '#e2725b'], 'P & Q')));
		$timeline = Chart::svg(['type' => 'timeline', 'events' => [['date' => '44 BC', 'label' => 'Caesar'], ['date' => '14.07.1789', 'label' => 'Bastille']], 'periods' => [['from' => -27, 'to' => 476, 'label' => 'Empire']]]);
		$this->assertNotFalse(simplexml_load_string($timeline));
		$this->assertStringContainsString('BC', $timeline);
	}

	public function testCorrections(): void {
		$body = OdtWriter::blocks('This ~~are~~ ++is++ a ==test==, C++ stays.');
		$this->assertStringContainsString('<text:span text:style-name="Deleted">are</text:span> <text:span text:style-name="Inserted">is</text:span> a <text:span text:style-name="Highlight">test</text:span>, C++ stays.', $body);
	}
}
