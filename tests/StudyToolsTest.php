<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Equilibria;
use OCA\TalkAi\Document\Law;
use OCA\TalkAi\Document\Machines;
use OCA\TalkAi\Document\Measurement;
use OCA\TalkAi\Document\Numerics;
use OCA\TalkAi\Document\Statistics;
use OCA\TalkAi\Document\Study;
use PHPUnit\Framework\TestCase;

/** The tools for exams and studies, against values worked out independently. */
class StudyToolsTest extends TestCase {
	public function testBinomialTest(): void {
		$this->assertSame('{59, …, 100}', Statistics::binomialTest(100, 0.5, 0.05, 'right')['rejection_region']);
		$this->assertSame('{0, …, 41}', Statistics::binomialTest(100, 0.5, 0.05, 'left')['rejection_region']);
		$two = Statistics::binomialTest(100, 0.5, 0.05, 'two', 62, 0.6);
		$this->assertSame('{0, …, 39} ∪ {61, …, 100}', $two['rejection_region']);
		$this->assertStringStartsWith('reject', $two['decision']);
		$this->assertEqualsWithDelta(0.0443, (float)Statistics::binomialTest(100, 0.5, 0.05)['error_type_1'], 1e-4);
	}

	public function testRankTestsAndAnova(): void {
		$a = Statistics::anova([[1, 2, 3], [4, 5, 6]]);
		$this->assertSame('13.5', $a['F']);
		$this->assertEqualsWithDelta(0.0213, (float)$a['p'], 1e-4);
		$this->assertEqualsWithDelta(3.857, (float)Statistics::kruskal([[1, 2, 3], [4, 5, 6]])['H'], 1e-3);
		$this->assertSame('0', Statistics::mannWhitney([1, 2, 3], [4, 5, 6])['U']);
		$this->assertSame('1', Statistics::spearman([1, 2, 3, 4], [10, 20, 30, 45])['rho']);
		$ci = Statistics::confidence([4, 5, 6, 5, 5]);
		$this->assertSame('[4.12201; 5.87799]', $ci['interval']);
		$this->assertEqualsWithDelta(2.776, Statistics::tQuantile(0.95, 4), 1e-3);
	}

	public function testChemistry(): void {
		$this->assertSame('2', Equilibria::ph('strong_acid', 0.01)['pH']);
		$weak = Equilibria::ph('weak_acid', 0.1, 4.75);
		$this->assertSame(['2.878', '2.875'], [$weak['pH'], $weak['pH_approximation']]);
		$this->assertSame('4.75', Equilibria::ph('buffer', 0.1, 4.75, 0.1)['pH']);
		$t = Equilibria::titration('acid', 0.1, 20, 0.1);
		$this->assertSame(['20', '7'], [$t['key_points']['equivalence']['mL'], $t['key_points']['equivalence']['pH']]);
		$weakT = Equilibria::titration('acid', 0.1, 20, 0.1, 4.75);
		$this->assertEqualsWithDelta(4.75, (float)$weakT['key_points']['half_equivalence']['pH'], 0.01);
		$e = Equilibria::equilibrium('H2 + I2 <=> 2 HI', ['H2' => 1, 'I2' => 1], 49);
		$this->assertSame('1.556', $e['equilibrium']['HI']);
		$this->assertSame('49', Equilibria::equilibrium('H2 + I2 <=> 2 HI', ['H2' => 0.2, 'I2' => 0.2, 'HI' => 1.4])['Kc']);
	}

	public function testMachines(): void {
		$sum = Machines::registerMachine("LOAD 1\nADD 2\nSTORE 3\nEND", ['1' => 5, '2' => 3]);
		$this->assertSame([8, 'END'], [$sum['registers'][3], $sum['ended']]);
		$loop = Machines::registerMachine("m: LOAD 1\nJZERO e\nSUB #1\nSTORE 1\nGOTO m\ne: END", ['1' => 3]);
		$this->assertSame(0, $loop['registers'][1]);
		$this->assertSame(18, $loop['steps'], 'three rounds of five, then LOAD, JZERO and END');
		$this->assertSame(4, Machines::registerMachine("LOAD #9\nSTORE 2\nLOAD #4\nSTORE *1\nEND", ['1' => 2])['registers'][2], 'indirect');
		$dfa = Machines::automaton("q0, a -> q1\nq0, b -> q0\nq1, a -> q0\nq1, b -> q1", 'q0', ['q0'], ['abba', 'ab', '']);
		$this->assertTrue($dfa['deterministic']);
		$this->assertSame([true, false, true], array_column($dfa['words'], 'accepted'));
		$this->assertSame([true, false], array_column(Machines::regex('a(b|c)*d', ['abcbd', 'abx'])['words'], 'matches'));
	}

	public function testGrades(): void {
		$modules = [['name' => 'Analysis', 'grade' => '1,3', 'ects' => 10], ['name' => 'Programmierung', 'grade' => 2.7, 'ects' => 5]];
		$this->assertSame('1,7', Study::average($modules)['average']);
		$this->assertSame('1,8', Study::average($modules, ['mode' => 'round'])['average']);
		$this->assertSame('1,76', Study::average($modules, ['decimals' => 2])['average']);
		$best = Study::average([...$modules, ['name' => 'Seminar', 'grade' => 4.0, 'ects' => 5, 'group' => 'S'], ['name' => 'Seminar 2', 'grade' => 1.0, 'ects' => 5, 'group' => 'S']],
			['best_of' => ['S' => 1]]);
		$this->assertCount(1, $best['not_counted']);
		$this->assertSame('at most 1,74', Study::required([['grade' => 1.3, 'ects' => 10]], 20, 1.5)['needed_average']);
		$this->assertSame('2,0', Study::foreign(3.0, 4.0, 1.0)['german_grade']);
		$this->assertSame('2,0', Study::foreign(2, 1, 4)['german_grade']);
		$this->assertStringContainsString('vollbefriedigend', Study::juraPoints([9, 10])['average']);
		$this->assertSame(180, Study::workload(6, '2027-02-15', 'exam', 30, '2026-10-19')['workload_hours']);
	}

	public function testMeasurement(): void {
		$this->assertSame('9.812 ± 0.024', Measurement::result(9.8123, 0.0234));
		$this->assertSame('5.000 ± 0.071', Measurement::propagate('U/I', ['U' => ['value' => 10, 'u' => 0.1], 'I' => ['value' => 2, 'u' => 0.02]])['result']);
		$this->assertSame('0.1', Measurement::propagate('U/I', ['U' => ['value' => 10, 'u' => 0.1], 'I' => ['value' => 2, 'u' => 0.02]], 'max')['u']);
		$this->assertSame('5', Measurement::typeA([4, 5, 6, 5, 5])['mean']);
	}

	public function testNumerics(): void {
		$this->assertSame('1.414213562', Numerics::root('x^2 - 2', 1)['root']);
		$this->assertEqualsWithDelta(1.41421356, (float)Numerics::root('x^2 - 2', 0, 2, 'bisection')['root'], 1e-8);
		$this->assertSame('9', Numerics::integrate('x^2', 0, 3, 4)['simpson']);
		$this->assertEqualsWithDelta(M_E, (float)Numerics::ode('y', 0, 1, 1, 10)['end']['y'], 1e-5);
		$this->assertEqualsWithDelta(-1.0, (float)Numerics::ode(['y2', '-y1'], 0, [1, 0], M_PI, 100)['end']['y1'], 1e-6);
		$this->assertSame(['3', '1'], Numerics::eigenvalues([[2, 1], [1, 2]])['eigenvalues']);
	}

	public function testLaw(): void {
		$this->assertSame('2027-03-28', Law::easter(2027)->format('Y-m-d'));
		$this->assertSame('2026-04-05', Law::easter(2026)->format('Y-m-d'));
		$this->assertSame('Fronleichnam', Law::holidays(2027, 'HE')['2027-05-27']);
		$this->assertArrayNotHasKey('2027-05-27', Law::holidays(2027, 'HH'));
		$this->assertStringStartsWith('Friday, 29.01.2027', Law::deadline('2027-01-15', 2, 'weeks')['ends']);
		// One month from 31 January ends on 28 February 2027, a Sunday: § 193 moves it to Monday.
		$this->assertStringStartsWith('Monday, 01.03.2027', Law::deadline('2027-01-31', 1, 'months')['ends']);
		// Two weeks from 12 March 2027 end on Karfreitag; Saturday, Easter Sunday and Ostermontag follow.
		$this->assertStringStartsWith('Tuesday, 30.03.2027', Law::deadline('2027-03-12', 14, 'days', 'event', 'HE')['ends']);
		$this->assertSame('https://www.gesetze-im-internet.de/bgb/__823.html', Law::normUrl('BGB', '§ 823'));
		$this->assertSame('https://www.gesetze-im-internet.de/gg/art_20.html', Law::normUrl('GG', 'Art. 20'));
	}
}
