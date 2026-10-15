<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Money maths: compound interest, savings plans, loans with their repayment
 * schedule (annuity), inflation, and break-even. Rates are yearly percentages.
 */
class Finance {
	/** A sum growing at a yearly rate, compounded n times a year, with optional regular deposits. */
	public static function growth(float $principal, float $ratePercent, float $years, int $perYear = 1, float $deposit = 0.0): array {
		$r = $ratePercent / 100 / max(1, $perYear);
		$n = (int)round($years * max(1, $perYear));
		$value = $principal * (1 + $r) ** $n + ($r == 0.0 ? $deposit * $n : $deposit * ((1 + $r) ** $n - 1) / $r);
		$paid = $principal + $deposit * $n;
		return ['final_value' => self::money($value), 'paid_in' => self::money($paid), 'interest_earned' => self::money($value - $paid),
			'effective_yearly_rate' => round(((1 + $r) ** max(1, $perYear) - 1) * 100, 4) . ' %',
			'periods' => $n, 'note' => 'Deposits at the end of each period; before taxes and fees.'];
	}

	/**
	 * A loan repaid in equal instalments (annuity): the instalment, total interest,
	 * and the schedule by year (or by month for up to two years).
	 */
	public static function loan(float $amount, float $ratePercent, float $years, int $perYear = 12): array {
		$r = $ratePercent / 100 / $perYear;
		$n = (int)round($years * $perYear);
		if ($n < 1 || $amount <= 0) {
			throw new \InvalidArgumentException('amount and term must be positive');
		}
		$payment = $r == 0.0 ? $amount / $n : $amount * $r / (1 - (1 + $r) ** -$n);
		$balance = $amount;
		$rows = [];
		$yearInterest = $yearPrincipal = 0.0;
		$monthly = $n <= 24;
		for ($k = 1; $k <= $n; $k++) {
			$interest = $balance * $r;
			$principal = $payment - $interest;
			$balance -= $principal;
			$yearInterest += $interest;
			$yearPrincipal += $principal;
			if ($monthly || $k % $perYear === 0 || $k === $n) {
				$rows[] = '| ' . ($monthly ? $k : (int)ceil($k / $perYear)) . ' | ' . self::money($yearInterest) . ' | ' . self::money($yearPrincipal) . ' | ' . self::money(max(0, $balance)) . ' |';
				$yearInterest = $yearPrincipal = 0.0;
			}
		}
		return ['instalment' => self::money($payment) . ' per ' . ($perYear === 12 ? 'month' : 'period'), 'total_paid' => self::money($payment * $n),
			'total_interest' => self::money($payment * $n - $amount),
			'schedule' => '| ' . ($monthly ? 'Month' : 'Year') . " | Interest | Repayment | Remaining |\n|---|---|---|---|\n" . implode("\n", $rows)];
	}

	/** What a sum is worth after years of inflation, and what it takes to keep its value. */
	public static function inflation(float $amount, float $ratePercent, float $years): array {
		$factor = (1 + $ratePercent / 100) ** $years;
		return ['purchasing_power_then' => self::money($amount / $factor), 'needed_then_for_the_same' => self::money($amount * $factor),
			'price_increase' => round(($factor - 1) * 100, 2) . ' %'];
	}

	/** The quantity at which revenue covers fixed and variable costs. */
	public static function breakEven(float $fixed, float $price, float $variable): array {
		if ($price <= $variable) {
			throw new \InvalidArgumentException('the price must exceed the variable cost per unit');
		}
		$units = $fixed / ($price - $variable);
		return ['break_even_units' => Calculator::format(round($units, 2)), 'rounded_up' => (int)ceil($units - 1e-9),
			'break_even_revenue' => self::money($units * $price), 'contribution_margin_per_unit' => self::money($price - $variable)];
	}

	private static function money(float $v): string {
		return number_format($v, 2, '.', ' ');
	}
}
