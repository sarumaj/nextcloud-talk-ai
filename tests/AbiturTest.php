<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Abitur;
use PHPUnit\Framework\TestCase;

/** The Landesabitur 2027: its data, and the OAVO's tables (9a, 9b, 9c, 10a, 10b) and § 26. */
class AbiturTest extends TestCase {
	public function testSubjectsAndLevels(): void {
		$this->assertSame('mathematik', Abitur::subjectKey('Mathe LK'));
		$this->assertSame('powi', Abitur::subjectKey('PoWi'));
		$gk = Abitur::subject('Mathematik', 'GK');
		$lk = Abitur::subject('Mathematik', 'LK');
		$this->assertSame(['date' => '2027-05-05', 'makeup' => '2027-06-03', 'minutes' => 285, 'be' => 80], $gk['exam']);
		$this->assertSame(330, $lk['exam']['minutes']);
		$this->assertNotContains('Q3.3', array_column($gk['topics'], 'id'), 'Hypothesentests are LK only');
		$this->assertContains('Q3.3', array_column($lk['topics'], 'id'));
		$deutsch = Abitur::subject('Deutsch', 'LK');
		$this->assertContains('Faust I', array_column($deutsch['required_texts'], 'title'));
		foreach (Abitur::data()['subjects'] as $key => $s) {
			$this->assertNotEmpty($s['topics'], $key);
			$this->assertFileExists(Abitur::DIR . '/operators/' . $s['operators'] . '.json', $key);
		}
	}

	public function testScheduleIsInOrderWithTimes(): void {
		$s = Abitur::schedule(['Mathematik LK', 'Deutsch GK']);
		$this->assertSame(['Deutsch GK', 'Mathematik LK'], array_column($s['exams'], 'subject'));
		$this->assertSame(['09:00', '13:45'], [$s['exams'][0]['start'], $s['exams'][0]['end']]);
		$this->assertSame('2027-05-05 14:30', $s['events'][1]['end']);
		$this->assertSame('2027-03-19', $s['key_dates']['end of Q4 (last lessons)']);
	}

	public function testOperators(): void {
		$op = Abitur::operators('Mathematik', 'berechnen')['operators'][0];
		$this->assertSame('I–II', $op['afb']);
		$this->assertStringContainsString('WTR/CAS', $op['definition']);
		$this->assertNotEmpty(Abitur::operators('Englisch', 'analyse')['operators']);
		$this->assertArrayHasKey('found', Abitur::operators('Deutsch', 'zaubern'));
	}

	public function testPercentToPoints(): void {
		$this->assertSame(8, Abitur::points(64)['points']);
		$this->assertSame(4, Abitur::points(44.9)['points'], 'the whole percent, not rounded');
		$this->assertSame(10, Abitur::points(57, 80)['points']);
		$this->assertSame(15, Abitur::points(95)['points']);
		$this->assertSame(0, Abitur::points(19)['points']);
	}

	public function testLanguageAndTranslation(): void {
		$this->assertSame(1, Abitur::errorIndex(7, 200)['deduction_points']);
		$this->assertSame(0, Abitur::errorIndex(5.9, 200)['deduction_points']);
		$this->assertSame(2, Abitur::errorIndex(12, 200)['deduction_points']);
		$d = Abitur::deutsch(11, 9, 7, 200);
		$this->assertSame([10, 9], [$d['points'], $d['final_points']]);
		$this->assertSame(3, Abitur::deutsch(12, 0)['points'], 'Darstellung 0: at most 3');
		$this->assertSame(10, Abitur::translation(5.5, 100)['points']);
		$this->assertSame(3, Abitur::translation(13.5, 100)['points']);
		$this->assertSame(0, Abitur::translation(17, 100)['points']);
	}

	public function testCombinedAndTotal(): void {
		$this->assertSame(41, Abitur::combined(10, 11)['points_fourfold']);
		$this->assertSame(2, Abitur::combined(1, 0)['points_fourfold']);
		$this->assertSame(58, Abitur::combined(15, 14)['points_fourfold']);
		$this->assertSame('1,0', Abitur::average(823));
		$this->assertSame('1,1', Abitur::average(822));
		$this->assertSame('1,2', Abitur::average(804));
		$this->assertSame('2,1', Abitur::average(642));
		$this->assertSame('3,9', Abitur::average(301));
		$this->assertSame('4,0', Abitur::average(300));
		$this->assertSame(733, Abitur::needed(1.5));
		$t = Abitur::total(array_fill(0, 8, 10), array_fill(0, 24, 10), [10, 10, 10, 10, 10], 1.9);
		$this->assertSame(['600 / 900', '2,3', true], [$t['total'], $t['average_grade'], $t['passed']]);
		$this->assertSame(661 - 400, $t['for_target']['block_2_needed']);
		$fail = Abitur::total([...array_fill(0, 5, 10), 3, 3, 3], array_fill(0, 24, 10), [10, 3, 3, 10, 0]);
		$this->assertFalse($fail['passed']);
		$this->assertCount(3, $fail['problems']);
	}

	public function testBlueprintAndPlan(): void {
		$b = Abitur::blueprint('Mathematik', 'LK', 'WTR');
		$this->assertStringContainsString('30 BE', $b['parts'][0]['tasks']);
		$this->assertStringContainsString('110 minutes', $b['parts'][0]['hand_in']);
		$plan = Abitur::plan(['Mathematik LK', 'PoWi mündlich'], '2026-10-19');
		$this->assertSame('2026-10-19', array_key_first($plan['weeks']));
		$this->assertLessThanOrEqual(100, count($plan['tasks']));
		$this->assertSame('2027-04-21', $plan['final_phase'][0]['revision_from']);
		$this->assertSame('2027-05-10', $plan['final_phase'][1]['exam']);
	}
}
