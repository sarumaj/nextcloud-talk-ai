<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Genetics computed, not guessed: crosses (Punnett squares) of up to four
 * genes with complete dominance, also X-linked ("XᴬXᵃ × XᴬY" written XAXa x XAY),
 * with genotype and phenotype ratios; and DNA and RNA: complement, transcription,
 * translation with the standard genetic code, GC content.
 */
class Biology {
	/** The standard genetic code, by mRNA codon; "*" is a stop. */
	private const CODONS = [
		'UUU' => 'Phe', 'UUC' => 'Phe', 'UUA' => 'Leu', 'UUG' => 'Leu', 'CUU' => 'Leu', 'CUC' => 'Leu', 'CUA' => 'Leu', 'CUG' => 'Leu',
		'AUU' => 'Ile', 'AUC' => 'Ile', 'AUA' => 'Ile', 'AUG' => 'Met', 'GUU' => 'Val', 'GUC' => 'Val', 'GUA' => 'Val', 'GUG' => 'Val',
		'UCU' => 'Ser', 'UCC' => 'Ser', 'UCA' => 'Ser', 'UCG' => 'Ser', 'CCU' => 'Pro', 'CCC' => 'Pro', 'CCA' => 'Pro', 'CCG' => 'Pro',
		'ACU' => 'Thr', 'ACC' => 'Thr', 'ACA' => 'Thr', 'ACG' => 'Thr', 'GCU' => 'Ala', 'GCC' => 'Ala', 'GCA' => 'Ala', 'GCG' => 'Ala',
		'UAU' => 'Tyr', 'UAC' => 'Tyr', 'UAA' => '*', 'UAG' => '*', 'CAU' => 'His', 'CAC' => 'His', 'CAA' => 'Gln', 'CAG' => 'Gln',
		'AAU' => 'Asn', 'AAC' => 'Asn', 'AAA' => 'Lys', 'AAG' => 'Lys', 'GAU' => 'Asp', 'GAC' => 'Asp', 'GAA' => 'Glu', 'GAG' => 'Glu',
		'UGU' => 'Cys', 'UGC' => 'Cys', 'UGA' => '*', 'UGG' => 'Trp', 'CGU' => 'Arg', 'CGC' => 'Arg', 'CGA' => 'Arg', 'CGG' => 'Arg',
		'AGU' => 'Ser', 'AGC' => 'Ser', 'AGA' => 'Arg', 'AGG' => 'Arg', 'GGU' => 'Gly', 'GGC' => 'Gly', 'GGA' => 'Gly', 'GGG' => 'Gly',
	];
	private const ONE_LETTER = ['Ala' => 'A', 'Arg' => 'R', 'Asn' => 'N', 'Asp' => 'D', 'Cys' => 'C', 'Gln' => 'Q', 'Glu' => 'E', 'Gly' => 'G',
		'His' => 'H', 'Ile' => 'I', 'Leu' => 'L', 'Lys' => 'K', 'Met' => 'M', 'Phe' => 'F', 'Pro' => 'P', 'Ser' => 'S', 'Thr' => 'T',
		'Trp' => 'W', 'Tyr' => 'Y', 'Val' => 'V', '*' => '*'];

	/**
	 * A cross: its gametes, Punnett square (as a Markdown table, up to 16 × 16),
	 * and the offspring's genotypes and phenotypes with ratios.
	 */
	public static function cross(string $parent1, string $parent2): array {
		$g1 = self::gametes(self::genes($parent1));
		$g2 = self::gametes(self::genes($parent2));
		$genotypes = [];
		$square = '| | ' . implode(' | ', $g2) . " |\n|---" . str_repeat('|---', count($g2)) . "|\n";
		foreach ($g1 as $a) {
			$row = [];
			foreach ($g2 as $b) {
				$child = self::combine($a, $b);
				$row[] = $child;
				$genotypes[$child] = ($genotypes[$child] ?? 0) + 1;
			}
			$square .= '| **' . $a . '** | ' . implode(' | ', $row) . " |\n";
		}
		$total = count($g1) * count($g2);
		$phenotypes = [];
		foreach ($genotypes as $genotype => $n) {
			$phenotype = self::phenotype($genotype);
			$phenotypes[$phenotype] = ($phenotypes[$phenotype] ?? 0) + $n;
		}
		arsort($genotypes);
		arsort($phenotypes);
		$ratio = fn (array $counts) => array_map(fn (int $n) => ['count' => $n . '/' . $total, 'percent' => round($n / $total * 100, 2)], $counts);
		return [
			'gametes' => ['parent 1' => $g1, 'parent 2' => $g2],
			'punnett_square' => count($g1) <= 16 && count($g2) <= 16 ? trim($square) : '(too large to show)',
			'genotypes' => $ratio($genotypes),
			'genotype_ratio' => implode(' : ', $genotypes),
			'phenotypes' => $ratio($phenotypes),
			'phenotype_ratio' => implode(' : ', $phenotypes),
			'note' => 'Complete dominance (capital letter dominant), independent assortment. For X-linked genes the sex is part of the phenotype.',
		];
	}

	/**
	 * A genotype as genes, each a pair of alleles: "AaBb" => [[A, a], [B, b]],
	 * "XAXa" => [[XA, Xa]], "XAY" => [[XA, Y]].
	 *
	 * @return list<array{string, string}>
	 */
	private static function genes(string $genotype): array {
		$genotype = preg_replace('/[\s^_]/', '', $genotype) ?? '';
		$sex = [];
		$genotype = preg_replace_callback('/X([A-Za-z])|Y/', function (array $m) use (&$sex): string {
			$sex[] = $m[0] === 'Y' ? 'Y' : 'X' . $m[1];
			return '';
		}, $genotype) ?? '';
		$letters = [];
		foreach (str_split($genotype) as $c) {
			if (!ctype_alpha($c)) {
				throw new \InvalidArgumentException('write genotypes as letters, e.g. AaBb or XAXa');
			}
			$letters[strtolower($c)][] = $c;
		}
		$genes = [];
		foreach ($letters as $gene => $alleles) {
			if (count($alleles) !== 2) {
				throw new \InvalidArgumentException('gene ' . $gene . ' needs two alleles in ' . $genotype);
			}
			$genes[] = $alleles;
		}
		if ($sex !== []) {
			if (count($sex) !== 2) {
				throw new \InvalidArgumentException('sex chromosomes come in pairs: XAXa or XAY');
			}
			$genes[] = $sex;
		}
		if ($genes === [] || count($genes) > 4) {
			throw new \InvalidArgumentException('between one and four genes, please');
		}
		return $genes;
	}

	/** @return list<string> the distinct gametes, in a stable order */
	private static function gametes(array $genes): array {
		$gametes = [''];
		foreach ($genes as $pair) {
			$next = [];
			foreach ($gametes as $g) {
				foreach (array_unique($pair) as $allele) {
					$next[] = $g . $allele;
				}
			}
			$gametes = $next;
		}
		return $gametes;
	}

	/** Two gametes' child, each gene's alleles sorted (dominant, then X before Y). */
	private static function combine(string $a, string $b): string {
		$split = fn (string $g) => preg_match_all('/X[A-Za-z]|Y|[A-Za-z]/', $g, $m) ? $m[0] : [];
		$child = '';
		foreach (array_map(null, $split($a), $split($b)) as [$x, $y]) {
			$pair = [$x, $y];
			// The allele's letter decides (Xh is recessive), then X before Y.
			usort($pair, fn ($p, $q) => [$p === 'Y', ctype_lower(substr($p, -1))] <=> [$q === 'Y', ctype_lower(substr($q, -1))]);
			$child .= implode('', $pair);
		}
		return $child;
	}

	private static function phenotype(string $genotype): string {
		preg_match_all('/X[A-Za-z]|Y|[A-Za-z]/', $genotype, $m);
		$traits = [];
		$alleles = $m[0];
		for ($i = 0; $i < count($alleles); $i += 2) {
			[$x, $y] = [$alleles[$i], $alleles[$i + 1]];
			if (str_starts_with($x, 'X')) {
				$male = $y === 'Y';
				$letter = $male ? $x[1] : (ctype_upper($x[1]) || ctype_upper($y[1]) ? strtoupper($x[1]) : $x[1]);
				$traits[] = ($male ? 'male' : 'female') . ' ' . (ctype_upper($letter) ? strtoupper($letter) . ' (dominant)' : strtolower($letter) . ' (recessive)');
			} else {
				$traits[] = ctype_upper($x) || ctype_upper($y) ? strtoupper($x) . '_' : strtolower($x) . strtolower($x);
			}
		}
		return implode(', ', $traits);
	}

	/**
	 * DNA or RNA worked on: complement, reverse complement, mRNA (from the coding
	 * or the template strand), and the protein (from the first AUG, or from the
	 * start), with GC content.
	 */
	public static function sequence(string $sequence, string $strand = 'coding'): array {
		$s = strtoupper(preg_replace('/[^ACGTUacgtu]/', '', $sequence) ?? '');
		if ($s === '') {
			throw new \InvalidArgumentException('no bases (A, C, G, T or U)');
		}
		$rna = str_contains($s, 'U') && !str_contains($s, 'T');
		$dna = $rna ? strtr($s, 'U', 'T') : $s;
		$complement = strtr($dna, 'ACGT', 'TGCA');
		$mrna = $rna ? $s : ($strand === 'template' ? strtr($complement, 'T', 'U') : strtr($dna, 'T', 'U'));
		$gc = substr_count($dna, 'G') + substr_count($dna, 'C');
		$start = strpos($mrna, 'AUG');
		return [
			'length' => strlen($s) . ' bases',
			'gc_content' => round($gc / strlen($s) * 100, 1) . ' %',
			'complementary_dna_3_to_5' => $complement,
			'reverse_complement_5_to_3' => strrev($complement),
			'mrna_5_to_3' => $mrna . ($rna ? '' : ' (from the ' . ($strand === 'template' ? 'template' : 'coding') . ' strand)'),
			'codons' => implode(' ', str_split($mrna, 3)),
			'protein_from_start' => self::translate($mrna),
			'protein_from_first_aug' => $start === false ? 'no AUG' : self::translate(substr($mrna, $start)),
		];
	}

	private static function translate(string $mrna): string {
		$three = [];
		foreach (str_split($mrna, 3) as $codon) {
			if (strlen($codon) < 3) {
				break;
			}
			$amino = self::CODONS[$codon];
			$three[] = $amino === '*' ? 'Stop' : $amino;
			if ($amino === '*') {
				break;
			}
		}
		$one = implode('', array_map(fn ($a) => self::ONE_LETTER[$a === 'Stop' ? '*' : $a], $three));
		return implode('-', $three) . ' (' . $one . ')';
	}
}
