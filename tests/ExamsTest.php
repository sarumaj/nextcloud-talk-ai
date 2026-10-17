<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Document\Exams;
use PHPUnit\Framework\TestCase;

/**
 * Exam arithmetic with the rules given as parameters. The expected values come from
 * real regulations (Hessen's OAVO, Anlagen 9a, 9b, 9c, 10a, 10b; the KMK's agreement),
 * which the generic rules must reproduce.
 */
class ExamsTest extends TestCase {
	public function testPointsByTable(): void {
		$this->assertSame(8, Exams::points(64)['result']);
		$this->assertSame(4, Exams::points(44.9)['result'], 'the whole percent, not rounded');
		$this->assertSame(1, Exams::points(33.5, 100, [1 => 33.4, 0 => 0], false)['result'], 'the exact percent');
		$this->assertSame(0, Exams::points(33.5, 100, [1 => 33.4, 0 => 0])['result'], 'the whole percent, 33');
		$this->assertSame(10, Exams::points(57, 80)['result']);
		$this->assertStringStartsWith('gut', Exams::points(57, 80)['grade']);
		$this->assertSame(0, Exams::points(19)['result']);
		$this->assertSame('2,0', Exams::points(41, 50, ['1,0' => 95, '2,0' => 80, '3,0' => 65, '4,0' => 50, '5,0' => 0])['result']);
	}

	public function testErrorIndex(): void {
		$this->assertSame(1.0, Exams::errorIndex(7, 200)['deduction']);
		$this->assertSame(0.0, Exams::errorIndex(5.9, 200)['deduction']);
		$this->assertSame(2.0, Exams::errorIndex(12, 200, [], [], 9)['deduction']);
		$this->assertSame(7.0, Exams::errorIndex(12, 200, [], [], 9)['points_after']);
		// A translation scale (Hessen's Anlage 9c): up to index 1 → 15, … up to 16.5 → 1.
		$latin = [[1, 15], [2, 14], [3, 13], [4, 12], [5, 11], [6, 10], [7, 9], [8, 8], [9, 7], [10, 6], [11, 5], [12, 4], [13.5, 3], [15, 2], [16.5, 1]];
		$this->assertSame(10, Exams::errorIndex(5.5, 100, [], $latin)['result']);
		$this->assertSame(3, Exams::errorIndex(13.5, 100, [], $latin)['result']);
		$this->assertSame('beyond the table', Exams::errorIndex(17, 100, [], $latin)['result']);
	}

	public function testWeightedAndCombined(): void {
		$this->assertSame('10', Exams::weighted([['name' => 'content', 'points' => 11, 'weight' => 70], ['name' => 'language', 'points' => 9, 'weight' => 30]])['result']);
		// Hessen's Anlage 10a: written 10, oral 11 → 41; written 1, oral 0 → 2; 15 and 14 → 58.
		$this->assertSame(41, Exams::combine(10, 11)['result']);
		$this->assertSame(2, Exams::combine(1, 0)['result']);
		$this->assertSame(58, Exams::combine(15, 14)['result']);
	}

	public function testAbiturByKmkFormulas(): void {
		// The KMK formula gives Hessen's Anlage 10b exactly.
		foreach ([900 => '1,0', 823 => '1,0', 822 => '1,1', 805 => '1,1', 804 => '1,2', 642 => '2,1', 600 => '2,3', 301 => '3,9', 300 => '4,0'] as $total => $grade) {
			$this->assertSame($grade, Exams::average($total), (string)$total);
		}
		$this->assertSame(733, Exams::needed(1.5));
		$this->assertSame(823, Exams::needed(1.0));
		$this->assertSame(300, Exams::needed(4.0));
		// 8 LK courses counting twice and 24 GK courses: (sum / 40) × 40, i.e. Hessen's 2 × LK + GK.
		$courses = [...array_fill(0, 8, ['points' => 10, 'weight' => 2]), ...array_fill(0, 24, ['points' => 10, 'weight' => 1])];
		$a = Exams::abitur($courses, null, [10, 10, 10, 10, 10], 1.9);
		$this->assertSame([400, 200, '600 / 900', '2,3'], [$a['block_1']['points'], $a['block_2']['points'], $a['total'], $a['average']]);
		$this->assertSame(261, $a['for_target']['block_2_needed']);
		$this->assertSame(200, Exams::abitur([], 400, [10, 10, 10, 10])['block_2']['points'], 'four exams count five times');
	}

	public function testCheckAndPlan(): void {
		$tasks = [['id' => '1', 'operator' => 'Nennen', 'afb' => 'I', 'marks' => 6], ['id' => '2', 'operator' => 'Erläutern', 'afb' => 'II', 'marks' => 8],
			['id' => '3', 'operator' => 'Beurteilen', 'afb' => 'III', 'marks' => 6]];
		$this->assertTrue(Exams::check($tasks, 20, 90)['fine']);
		$bad = Exams::check([['id' => '1', 'afb' => 'I', 'marks' => 10]], 20);
		$this->assertFalse($bad['fine']);
		$this->assertCount(6, $bad['problems'], 'no operator, wrong total, I too high, II and III too low, no III');
		$plan = Exams::plan([['name' => 'Maths', 'date' => '2027-05-05', 'topics' => ['Integrals', 'Vectors', 'Probability']],
			['name' => 'German', 'date' => '2027-04-27', 'topics' => ['Faust', 'Kafka']]], '2026-10-19');
		$this->assertSame('2026-10-19', array_key_first($plan['weeks']));
		$this->assertSame('2027-04-21', $plan['final_phase'][0]['revision_from']);
		// Both exams start in the first week: four weeks with topics, then two revision tasks.
		$this->assertCount(4 + 2, $plan['tasks']);
	}
}
