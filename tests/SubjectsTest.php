<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Analysis;
use OCA\TalkAi\Document\Biology;
use OCA\TalkAi\Document\Calculator;
use OCA\TalkAi\Document\Chart;
use OCA\TalkAi\Document\Chemistry;
use OCA\TalkAi\Document\Colors;
use OCA\TalkAi\Document\Finance;
use OCA\TalkAi\Document\Ics;
use OCA\TalkAi\Document\Informatics;
use OCA\TalkAi\Document\LinearAlgebra;
use OCA\TalkAi\Document\Math;
use OCA\TalkAi\Document\Music;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\Sport;
use OCA\TalkAi\Document\Statistics;
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

	public function testCurveSketching(): void {
		$r = Analysis::analyze('x^3 - 3x', -3, 3, 1);
		$this->assertSame(['-1.732051', '0', '1.732051'], $r['zeros']);
		$this->assertSame([['x' => '-1', 'y' => '2', 'kind' => 'maximum'], ['x' => '1', 'y' => '-2', 'kind' => 'minimum']], $r['extrema']);
		$this->assertSame('22.5', $r['area_to_x_axis']);
		$this->assertSame('point-symmetric about the origin (odd)', $r['symmetry']);
		$this->assertSame('y = -2', $r['at_x=1']['tangent']);
		$this->assertSame('1.772415', Analysis::analyze('e^(-x^2)', -3, 3)['integral']);
		$this->assertSame('none in the interval', Analysis::analyze('1/x', -2, 2)['zeros']);
	}

	public function testLinearAlgebra(): void {
		$this->assertSame(['x' => '2', 'y' => '3', 'z' => '-1'], LinearAlgebra::solve([[2, 1, -1, 8], [-3, -1, 2, -11], [-2, 1, 2, -3]])['solution']);
		$this->assertSame(['infinitely many' => ['x' => 'z', 'y' => '3 − 2·z', 'z' => 'z (free)']], LinearAlgebra::solve([[1, 2, 3, 6], [2, 4, 6, 12], [1, 1, 1, 3]])['solution']);
		$this->assertSame('none (the system is inconsistent)', LinearAlgebra::solve([[1, 1, 2], [1, 1, 3]])['solution']);
		$this->assertSame(['x' => '6/5', 'y' => '6/5'], LinearAlgebra::solve([['1/2', '1/3', 1], [1, -1, 0]])['solution']);
		$this->assertSame('-2', LinearAlgebra::determinant([[1, 2], [3, 4]])['determinant']);
		$this->assertSame("[3/5, -7/10]\n[-1/5, 2/5]", LinearAlgebra::inverse([[4, 7], [2, 6]])['inverse']);
		$this->assertSame('(2, 4, -5)', LinearAlgebra::vectors([1, 2, 2], [2, -1, 0])['u×v']);
		$this->assertSame('(2, 2, 2)', LinearAlgebra::geometry(['point' => [0, 0, 0], 'direction' => [1, 1, 1]], ['equation' => '2x + y - z = 4'])['intersection']);
		$this->assertSame('skew', LinearAlgebra::geometry(['point' => [0, 0, 0], 'direction' => [1, 0, 0]], ['point' => [0, 1, 1], 'direction' => [0, 1, 0]])['position']);
	}

	public function testHypothesisTests(): void {
		$this->assertSame('0.0455003', Statistics::chiSquareIndependence([[20, 30], [30, 20]])['p']);
		$this->assertSame('0.0576689', Statistics::tTest([10, 12, 9, 11], [9, 11, 9, 10], 0, true)['p_two_sided']);
		$this->assertSame('0.00516293', Statistics::correlationTest(0.6, 20)['p_two_sided']);
		$this->assertSame('0.7154', Statistics::chiSquareFit([18, 22, 20, 25, 15, 20])['p']);
	}

	public function testMusicAndFinance(): void {
		$this->assertSame('F# – G# – A# – B – C# – D# – E#', Music::scale('F#', 'major')['notes']);
		$this->assertSame('Eb – F – Gb – Ab – Bb – Cb – Db', Music::scale('Eb', 'minor')['notes']);
		$this->assertSame('F# – G# – A – B – C# – D – E', Music::scale('Fis', 'moll')['notes']);
		$this->assertSame('E – G# – B#', Music::chord('E', 'aug')['notes']);
		$this->assertSame('diminished fifth (tritone)', Music::interval('C', 'Gb')['interval']);
		$this->assertSame('Eb4 G4 Bb4 D4', Music::transpose('C4 E4 G4 B3', 3)['transposed']);
		$this->assertSame('261.63 Hz', Music::frequency('C4')['frequency']);
		$this->assertSame('1 628.89', Finance::growth(1000, 5, 10)['final_value']);
		$this->assertSame('443.21 per month', Finance::loan(10000, 6, 2)['instalment']);
		$this->assertSame(500, Finance::breakEven(5000, 25, 15)['rounded_up']);
	}

	public function testClimateDiagram(): void {
		$svg = Chart::svg(['type' => 'climate', 'place' => 'Kairo', 'temperature' => [14, 15.5, 18, 21.5, 25, 27.5, 28, 28, 26, 23.5, 19, 15.5], 'precipitation' => [5, 4, 4, 1, 0, 0, 0, 0, 0, 1, 3, 5]]);
		$this->assertNotFalse(simplexml_load_string($svg));
		$this->assertStringContainsString('12 arid months', $svg);
	}

	public function testCalendarFile(): void {
		$ics = Ics::calendar([
			['title' => 'Maths, room 204', 'start' => '2026-09-07 08:00', 'end' => '2026-09-07 09:30', 'repeat' => 'weekly', 'until' => '2027-06-30', 'except' => ['2026-10-19'], 'reminder_minutes' => 10],
			['title' => 'Exam; final, part 1', 'start' => '2027-04-20'],
		], [['title' => 'Hand in essay', 'due' => '2026-11-30']], new \DateTimeZone('Europe/Berlin'), 'School', fn () => 'fixed@talk-ai');
		$this->assertStringContainsString("DTSTART;TZID=Europe/Berlin:20260907T080000\r\n", $ics);
		$this->assertStringContainsString("RRULE:FREQ=WEEKLY;UNTIL=20270630T220000Z\r\n", $ics);
		$this->assertStringContainsString("EXDATE;TZID=Europe/Berlin:20261019T080000\r\n", $ics);
		$this->assertStringContainsString("DTSTART;VALUE=DATE:20270420\r\nDTEND;VALUE=DATE:20270421", $ics);
		$this->assertStringContainsString('SUMMARY:Exam\; final\, part 1', $ics);
		$this->assertStringContainsString("BEGIN:VTODO", $ics);
		// Summer time ends on 25 October 2026 and starts on 28 March 2027.
		$this->assertStringContainsString("BEGIN:STANDARD\r\nDTSTART:20261025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100", $ics);
		$this->assertStringContainsString("BEGIN:DAYLIGHT\r\nDTSTART:20270328T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200", $ics);
		foreach (explode("\r\n", $ics) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line));
		}
	}

	public function testCorrections(): void {
		$body = OdtWriter::blocks('This ~~are~~ ++is++ a ==test==, C++ stays.');
		$this->assertStringContainsString('<text:span text:style-name="Deleted">are</text:span> <text:span text:style-name="Inserted">is</text:span> a <text:span text:style-name="Highlight">test</text:span>, C++ stays.', $body);
	}
}
