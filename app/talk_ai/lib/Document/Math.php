<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * LaTeX formulas, the way models write them, as MathML (for documents, which
 * LibreOffice and Collabora typeset) and as readable Unicode (for the chat,
 * which shows no LaTeX): x^2 → x², \frac{a}{b} → a/b, \alpha → α.
 *
 * The usual school and office subset: fractions, roots, powers and indices,
 * sums, products, integrals and limits, Greek letters, relations, arrows and
 * sets, functions (\sin, \log, …), accents (\vec, \hat, \bar), \text, fonts
 * (\mathbf, \mathbb, …), \left…\right, and the matrix, cases and aligned
 * environments, and chemistry with \ce{…} (mhchem: \ce{2H2 + O2 -> 2H2O}).
 * Unknown commands are shown by name.
 */
class Math {
	private const GREEK = [
		'alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ', 'delta' => 'δ', 'epsilon' => 'ϵ', 'varepsilon' => 'ε', 'zeta' => 'ζ',
		'eta' => 'η', 'theta' => 'θ', 'vartheta' => 'ϑ', 'iota' => 'ι', 'kappa' => 'κ', 'lambda' => 'λ', 'mu' => 'μ', 'nu' => 'ν',
		'xi' => 'ξ', 'omicron' => 'ο', 'pi' => 'π', 'varpi' => 'ϖ', 'rho' => 'ρ', 'varrho' => 'ϱ', 'sigma' => 'σ', 'varsigma' => 'ς',
		'tau' => 'τ', 'upsilon' => 'υ', 'phi' => 'ϕ', 'varphi' => 'φ', 'chi' => 'χ', 'psi' => 'ψ', 'omega' => 'ω',
		'Gamma' => 'Γ', 'Delta' => 'Δ', 'Theta' => 'Θ', 'Lambda' => 'Λ', 'Xi' => 'Ξ', 'Pi' => 'Π', 'Sigma' => 'Σ',
		'Upsilon' => 'Υ', 'Phi' => 'Φ', 'Psi' => 'Ψ', 'Omega' => 'Ω',
		'infty' => '∞', 'partial' => '∂', 'nabla' => '∇', 'emptyset' => '∅', 'varnothing' => '∅', 'ell' => 'ℓ', 'hbar' => 'ℏ',
		'aleph' => 'ℵ', 'Re' => 'ℜ', 'Im' => 'ℑ', 'wp' => '℘', 'degree' => '°', 'circ' => '∘', 'prime' => '′',
	];

	/** Operators, relations, arrows and punctuation: <mo>. */
	private const OPERATORS = [
		'cdot' => '·', 'times' => '×', 'div' => '÷', 'pm' => '±', 'mp' => '∓', 'ast' => '∗', 'star' => '⋆', 'bullet' => '•',
		'le' => '≤', 'leq' => '≤', 'ge' => '≥', 'geq' => '≥', 'ne' => '≠', 'neq' => '≠', 'approx' => '≈', 'equiv' => '≡',
		'sim' => '∼', 'simeq' => '≃', 'cong' => '≅', 'propto' => '∝', 'll' => '≪', 'gg' => '≫', 'leqslant' => '⩽', 'geqslant' => '⩾',
		'in' => '∈', 'notin' => '∉', 'ni' => '∋', 'subset' => '⊂', 'supset' => '⊃', 'subseteq' => '⊆', 'supseteq' => '⊇',
		'cup' => '∪', 'cap' => '∩', 'setminus' => '∖', 'land' => '∧', 'wedge' => '∧', 'lor' => '∨', 'vee' => '∨', 'neg' => '¬', 'lnot' => '¬',
		'forall' => '∀', 'exists' => '∃', 'nexists' => '∄', 'perp' => '⊥', 'parallel' => '∥', 'angle' => '∠', 'triangle' => '△',
		'to' => '→', 'rightarrow' => '→', 'leftarrow' => '←', 'gets' => '←', 'leftrightarrow' => '↔', 'Rightarrow' => '⇒',
		'Leftarrow' => '⇐', 'Leftrightarrow' => '⇔', 'implies' => '⟹', 'iff' => '⟺', 'mapsto' => '↦', 'uparrow' => '↑',
		'downarrow' => '↓', 'longrightarrow' => '⟶', 'rightleftharpoons' => '⇌', 'leftrightarrows' => '⇄', 'longleftarrow' => '⟵',
		'Longrightarrow' => '⟹', 'Longleftrightarrow' => '⟺', 'nearrow' => '↗', 'searrow' => '↘', 'oplus' => '⊕', 'otimes' => '⊗', 'mid' => '∣', 'colon' => ':',
		'cdots' => '⋯', 'ldots' => '…', 'dots' => '…', 'vdots' => '⋮', 'ddots' => '⋱', 'therefore' => '∴', 'because' => '∵',
		'langle' => '⟨', 'rangle' => '⟩', 'lfloor' => '⌊', 'rfloor' => '⌋', 'lceil' => '⌈', 'rceil' => '⌉', 'vert' => '|', 'Vert' => '‖',
		'lbrace' => '{', 'rbrace' => '}', '{' => '{', '}' => '}', '|' => '‖', '%' => '%', '$' => '$', '#' => '#', '&' => '&', '_' => '_',
	];

	/** Large operators, with limits below and above in display. */
	private const LARGE = ['sum' => '∑', 'prod' => '∏', 'coprod' => '∐', 'int' => '∫', 'iint' => '∬', 'iiint' => '∭', 'oint' => '∮',
		'bigcup' => '⋃', 'bigcap' => '⋂', 'bigoplus' => '⨁', 'bigotimes' => '⨂'];

	/** Functions, upright; those with limits take them below. */
	private const FUNCTIONS = ['sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'arcsin', 'arccos', 'arctan', 'sinh', 'cosh', 'tanh',
		'log', 'ln', 'lg', 'exp', 'det', 'dim', 'ker', 'deg', 'arg', 'gcd', 'lcm', 'mod', 'bmod', 'Pr',
		'lim', 'liminf', 'limsup', 'max', 'min', 'sup', 'inf'];
	private const LIMITS = ['lim', 'liminf', 'limsup', 'max', 'min', 'sup', 'inf', 'Pr', 'gcd', 'det'];

	private const ACCENTS = ['hat' => '^', 'widehat' => '^', 'bar' => '¯', 'overline' => '¯', 'vec' => '→', 'overrightarrow' => '→',
		'dot' => '˙', 'ddot' => '¨', 'tilde' => '~', 'widetilde' => '~', 'check' => 'ˇ', 'acute' => '´', 'grave' => '`', 'breve' => '˘'];

	private const VARIANTS = ['mathbf' => 'bold', 'boldsymbol' => 'bold-italic', 'bm' => 'bold-italic', 'mathit' => 'italic',
		'mathrm' => 'normal', 'mathbb' => 'double-struck', 'mathcal' => 'script', 'mathscr' => 'script', 'mathfrak' => 'fraktur',
		'mathsf' => 'sans-serif', 'mathtt' => 'monospace'];

	private const SPACES = [',' => '0.17em', ':' => '0.22em', '>' => '0.22em', ';' => '0.28em', '!' => '-0.17em', ' ' => '0.25em',
		'quad' => '1em', 'qquad' => '2em', 'enspace' => '0.5em', 'thinspace' => '0.17em'];

	private const ENVIRONMENTS = ['matrix' => ['', ''], 'pmatrix' => ['(', ')'], 'bmatrix' => ['[', ']'], 'Bmatrix' => ['{', '}'],
		'vmatrix' => ['|', '|'], 'Vmatrix' => ['‖', '‖'], 'smallmatrix' => ['', ''], 'cases' => ['{', ''], 'rcases' => ['', '}'],
		'aligned' => ['', ''], 'align' => ['', ''], 'align*' => ['', ''], 'gathered' => ['', ''], 'gather' => ['', ''],
		'split' => ['', ''], 'array' => ['', ''], 'eqnarray' => ['', '']];

	private const SUPERSCRIPTS = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷',
		'8' => '⁸', '9' => '⁹', '+' => '⁺', '-' => '⁻', '=' => '⁼', '(' => '⁽', ')' => '⁾', 'n' => 'ⁿ', 'i' => 'ⁱ', 'x' => 'ˣ',
		'y' => 'ʸ', 'a' => 'ᵃ', 'b' => 'ᵇ', 'c' => 'ᶜ', 'd' => 'ᵈ', 'e' => 'ᵉ', 'k' => 'ᵏ', 'm' => 'ᵐ', 't' => 'ᵗ', 'T' => 'ᵀ', '∗' => '*', '′' => '′'];
	private const SUBSCRIPTS = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇',
		'8' => '₈', '9' => '₉', '+' => '₊', '-' => '₋', '=' => '₌', '(' => '₍', ')' => '₎', 'a' => 'ₐ', 'e' => 'ₑ', 'i' => 'ᵢ',
		'j' => 'ⱼ', 'k' => 'ₖ', 'n' => 'ₙ', 'm' => 'ₘ', 'o' => 'ₒ', 'r' => 'ᵣ', 'x' => 'ₓ', 't' => 'ₜ', 's' => 'ₛ', 'p' => 'ₚ'];

	/** @var list<string> */
	private array $tokens = [];
	private int $at = 0;
	private bool $display = false;

	/** The formula as MathML, with the LaTeX kept as its annotation. */
	public static function mathml(string $latex, bool $display = false): string {
		$parser = new self();
		$parser->display = $display;
		$parser->tokens = self::tokenize($latex);
		$body = $parser->row([]);
		return '<math xmlns="http://www.w3.org/1998/Math/MathML" display="' . ($display ? 'block' : 'inline') . '"><semantics><mrow>'
			. $body . '</mrow><annotation encoding="TeX">' . Odf::xml(trim($latex)) . '</annotation></semantics></math>';
	}

	/** The formula as plain text with Unicode symbols, for the chat. */
	public static function unicode(string $latex): string {
		$xml = '<r>' . (new self())->unicodeOf(self::mathml($latex)) . '</r>';
		$text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
		// A sign without a left operand is no binary operator: "-b", "(-1)".
		$text = preg_replace(['/ {2,}/', '/(^|[(\[{]) *([−+±∓]) /u', '/([,;=<>≤≥]) *([−+±∓]) /u'], [' ', '$1$2', '$1 $2'], trim($text)) ?? $text;
		return trim(preg_replace('/ {2,}/', ' ', $text) ?? $text);
	}

	/**
	 * A text's formulas ($…$, $$…$$, \(…\), \[…\]) replaced: by the callback's
	 * result for each (LaTeX, display?), outside code. A "$" followed by a digit
	 * and without a closing one is money, not math.
	 */
	public static function replace(string $text, callable $each): string {
		$parts = preg_split('/(```.*?```|`[^`\n]+`)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
		foreach ($parts as $n => $part) {
			if ($n % 2 === 1) {
				continue;
			}
			$parts[$n] = preg_replace_callback(
				'/\$\$(.+?)\$\$|\\\\\[(.+?)\\\\\]|\\\\\((.+?)\\\\\)|(?<![\\\\$\w])\$(?![\s$])((?:[^$\n\\\\]|\\\\.)+?)(?<!\s)\$(?![\d$])/s',
				function (array $m) use ($each): string {
					$display = ($m[1] ?? '') !== '' || ($m[2] ?? '') !== '';
					return $each(trim($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? '') ?: ($m[4] ?? '')), $display);
				},
				$part,
			) ?? $part;
		}
		return implode('', $parts);
	}

	/** A chat message with its formulas as Unicode; display formulas on their own line. */
	public static function forChat(string $text): string {
		$text = self::replace($text, fn (string $latex, bool $display) => $display ? "\u{E002}" . self::unicode($latex) . "\u{E002}" : self::unicode($latex));
		return preg_replace('/[ \t]*\x{E002}[ \t]*/u', "\n", $text) ?? $text;
	}

	/** @return list<string> */
	private static function tokenize(string $latex): array {
		// A decimal comma, as German and Polish write it in LaTeX: 0{,}5 is one number.
		$latex = preg_replace('/(\d)\{,\}(?=\d)/', "$1\u{E003}", $latex) ?? $latex;
		preg_match_all('/\\\\(?:[a-zA-Z]+\*?|.)|\d+(?:(?:\.|\x{E003})\d+)?|\s+|./su', $latex, $m);
		return str_replace("\u{E003}", ',', $m[0]);
	}

	private function peek(): ?string {
		while (isset($this->tokens[$this->at]) && trim($this->tokens[$this->at]) === '' && $this->tokens[$this->at] !== '') {
			$this->at++;
		}
		return $this->tokens[$this->at] ?? null;
	}

	private function next(): ?string {
		$token = $this->peek();
		$this->at++;
		return $token;
	}

	/** Atoms until one of the stop tokens (not consumed) or the end, with ^ and _ attached. */
	private function row(array $stops): string {
		$out = '';
		while (($token = $this->peek()) !== null && !in_array($token, $stops, true)) {
			if (str_starts_with($token, '\end') || $token === '}' || ($token === '\right' && !in_array('\right', $stops, true))) {
				break;
			}
			$out .= $this->scripted();
		}
		return $out;
	}

	/** An atom and its indices and powers. */
	private function scripted(): string {
		$token = $this->peek();
		$name = $token !== null && str_starts_with($token, '\\') ? substr($token, 1) : '';
		$large = isset(self::LARGE[$name]) || in_array($name, self::LIMITS, true);
		$base = $this->atom();
		$sub = $sup = null;
		while (in_array($this->peek(), ['^', '_', '\limits', '\nolimits', "'"], true)) {
			$t = $this->next();
			if ($t === "'") {
				$sup = ($sup ?? '') . '<mo>′</mo>';
			} elseif ($t === '^') {
				$sup = ($sup ?? '') . $this->argument();
			} elseif ($t === '_') {
				$sub = $this->argument();
			}
		}
		$under = $large && $this->display && !in_array($name, ['int', 'iint', 'iiint', 'oint'], true) || in_array($name, self::LIMITS, true);
		return match (true) {
			$sub !== null && $sup !== null => $under ? "<munderover>$base<mrow>$sub</mrow><mrow>$sup</mrow></munderover>" : "<msubsup>$base<mrow>$sub</mrow><mrow>$sup</mrow></msubsup>",
			$sub !== null => $under ? "<munder>$base<mrow>$sub</mrow></munder>" : "<msub>$base<mrow>$sub</mrow></msub>",
			$sup !== null => $under ? "<mover>$base<mrow>$sup</mrow></mover>" : "<msup>$base<mrow>$sup</mrow></msup>",
			default => $base,
		};
	}

	/** A command's argument: a group, or the next single atom. */
	private function argument(): string {
		if ($this->peek() === '{') {
			return $this->group();
		}
		$token = $this->peek();
		if ($token !== null && preg_match('/^\d{2,}/', $token) === 1) {
			// \frac12: one digit at a time, as TeX reads it.
			$this->tokens[$this->at] = substr($token, 1);
			return '<mn>' . $token[0] . '</mn>';
		}
		return $this->atom();
	}

	/** A {…} group's content, as a row. */
	private function group(): string {
		$this->next();
		$inner = $this->row(['}']);
		if ($this->peek() === '}') {
			$this->next();
		}
		return '<mrow>' . $inner . '</mrow>';
	}

	/** The raw text of a {…} group (for \text and environment names). */
	private function rawGroup(): string {
		if ($this->peek() !== '{') {
			return (string)$this->next();
		}
		$this->next();
		$depth = 1;
		$text = '';
		while (($t = $this->tokens[$this->at++] ?? null) !== null) {
			$depth += $t === '{' ? 1 : ($t === '}' ? -1 : 0);
			if ($depth === 0) {
				break;
			}
			$text .= str_starts_with($t, '\\') && strlen($t) === 2 ? $t[1] : $t;
		}
		return $text;
	}

	private function atom(): string {
		$token = $this->next();
		if ($token === null) {
			return '';
		}
		if ($token === '{') {
			$this->at--;
			return $this->group();
		}
		if (preg_match('/^\d/', $token) === 1) {
			return '<mn>' . $token . '</mn>';
		}
		if (!str_starts_with($token, '\\')) {
			return match (true) {
				preg_match('/^\p{L}$/u', $token) === 1 => '<mi>' . Odf::xml($token) . '</mi>',
				$token === '~' => '<mspace width="0.25em"/>',
				$token === '-' => '<mo>−</mo>',
				$token === '*' => '<mo>∗</mo>',
				default => '<mo>' . Odf::xml($token) . '</mo>',
			};
		}
		$name = substr($token, 1);
		switch (true) {
			case in_array($name, ['frac', 'dfrac', 'tfrac', 'cfrac'], true):
				return '<mfrac>' . $this->argument() . $this->argument() . '</mfrac>';
			case $name === 'binom':
				return '<mrow class="binom"><mo>(</mo><mfrac linethickness="0">' . $this->argument() . $this->argument() . '</mfrac><mo>)</mo></mrow>';
			case $name === 'sqrt':
				if ($this->peek() === '[') {
					$this->next();
					$index = $this->row([']']);
					$this->next();
					return '<mroot>' . $this->argument() . '<mrow>' . $index . '</mrow></mroot>';
				}
				return '<msqrt>' . $this->argument() . '</msqrt>';
			case $name === 'left':
				$open = $this->delimiter();
				$inner = $this->row(['\right']);
				$close = '';
				if ($this->peek() === '\right') {
					$this->next();
					$close = $this->delimiter();
				}
				return '<mrow><mo stretchy="true">' . $open . '</mo>' . $inner . '<mo stretchy="true">' . $close . '</mo></mrow>';
			case in_array($name, ['big', 'Big', 'bigg', 'Bigg', 'bigl', 'bigr', 'Bigl', 'Bigr', 'biggl', 'biggr', 'middle'], true):
				return '<mo>' . $this->delimiter() . '</mo>';
			case in_array($name, ['xrightarrow', 'xleftarrow', 'xLeftrightarrow', 'xRightarrow'], true):
				// An arrow with text above (and optionally [below]).
				$below = '';
				if ($this->peek() === '[') {
					$this->next();
					$below = $this->row([']']);
					$this->next();
				}
				$arrow = ['xrightarrow' => '⟶', 'xleftarrow' => '⟵', 'xLeftrightarrow' => '⟺', 'xRightarrow' => '⟹'][$name];
				$above = $this->argument();
				return $below === '' ? '<mover><mo stretchy="true">' . $arrow . '</mo>' . $above . '</mover>'
					: '<munderover><mo stretchy="true">' . $arrow . '</mo><mrow>' . $below . '</mrow>' . $above . '</munderover>';
			case $name === 'overset' || $name === 'stackrel':
				$top = $this->argument();
				return '<mover>' . $this->argument() . $top . '</mover>';
			case $name === 'underset':
				$bottom = $this->argument();
				return '<munder>' . $this->argument() . $bottom . '</munder>';
			case $name === 'ce' || $name === 'pu':
				// Chemistry (mhchem): subscripts, charges and arrows, upright.
				return '<mtext>' . Odf::xml(Chemistry::pretty($this->rawGroup())) . '</mtext>';
			case in_array($name, ['text', 'textrm', 'textit', 'textbf', 'mbox', 'textnormal'], true):
				return '<mtext>' . Odf::xml($this->rawGroup()) . '</mtext>';
			case $name === 'operatorname' || $name === 'operatorname*':
				return '<mi mathvariant="normal">' . Odf::xml($this->rawGroup()) . '</mi><mo>&#x2061;</mo>';
			case isset(self::VARIANTS[$name]):
				return '<mstyle mathvariant="' . self::VARIANTS[$name] . '">' . $this->argument() . '</mstyle>';
			case isset(self::ACCENTS[$name]):
				return '<mover accent="true">' . $this->argument() . '<mo>' . Odf::xml(self::ACCENTS[$name]) . '</mo></mover>';
			case $name === 'underline':
				return '<munder>' . $this->argument() . '<mo>_</mo></munder>';
			case $name === 'overbrace' || $name === 'underbrace':
				return $name === 'overbrace' ? '<mover>' . $this->argument() . '<mo>⏞</mo></mover>' : '<munder>' . $this->argument() . '<mo>⏟</mo></munder>';
			case $name === 'begin':
				return $this->environment($this->rawGroup());
			case isset(self::SPACES[$name]):
				return '<mspace width="' . self::SPACES[$name] . '"/>';
			case isset(self::LARGE[$name]):
				return '<mo largeop="true" movablelimits="' . ($this->display ? 'false' : 'true') . '">' . self::LARGE[$name] . '</mo>';
			case in_array($name, self::FUNCTIONS, true):
				return '<mi mathvariant="normal">' . $name . '</mi>' . (in_array($name, ['mod', 'bmod'], true) ? '' : '<mo>&#x2061;</mo>');
			case isset(self::GREEK[$name]):
				// Upright capitals; symbols (∞, ∅) as they are: LibreOffice misplaces scripts on an upright symbol.
				return '<mi' . (ctype_upper($name[0]) && mb_strlen(self::GREEK[$name]) === 1 && preg_match('/\p{Greek}/u', self::GREEK[$name]) === 1 ? ' mathvariant="normal"' : '') . '>' . self::GREEK[$name] . '</mi>';
			case isset(self::OPERATORS[$name]):
				return '<mo>' . Odf::xml(self::OPERATORS[$name]) . '</mo>';
			case in_array($name, ['displaystyle', 'textstyle', 'scriptstyle', 'limits', 'nolimits', 'nonumber', 'notag', 'label'], true):
				if ($name === 'label') {
					$this->rawGroup();
				}
				return '';
			case $name === '\\':
				return '';
			default:
				return '<mi mathvariant="normal">' . Odf::xml($name) . '</mi>';
		}
	}

	/** The delimiter after \left, \right or \big: a character or a command. */
	private function delimiter(): string {
		$token = (string)$this->next();
		if ($token === '.') {
			return '';
		}
		if (str_starts_with($token, '\\')) {
			$name = substr($token, 1);
			return Odf::xml(self::OPERATORS[$name] ?? $name);
		}
		return Odf::xml($token);
	}

	/** \begin{…} … \end{…}: rows by \\, cells by &, in the environment's brackets. */
	private function environment(string $name): string {
		if ($name === 'array') {
			$this->rawGroup();
		}
		[$open, $close] = self::ENVIRONMENTS[$name] ?? ['', ''];
		$rows = [];
		$cells = [];
		while (($t = $this->peek()) !== null) {
			if (str_starts_with($t, '\end')) {
				$this->next();
				$this->rawGroup();
				break;
			}
			$cell = $this->row(['&', '\\\\', '\cr']);
			$cells[] = '<mtd><mrow>' . $cell . '</mrow></mtd>';
			$t = $this->peek();
			if ($t === '&') {
				$this->next();
			} elseif ($t === '\\\\' || $t === '\cr') {
				$this->next();
				$rows[] = '<mtr>' . implode('', $cells) . '</mtr>';
				$cells = [];
			} elseif ($t === '}') {
				$this->next();
			}
		}
		if ($cells !== [] && !($cells === ['<mtd><mrow></mrow></mtd>'])) {
			$rows[] = '<mtr>' . implode('', $cells) . '</mtr>';
		}
		$align = in_array($name, ['aligned', 'align', 'align*', 'split', 'eqnarray'], true) ? ' columnalign="right left"' : (in_array($name, ['cases', 'rcases'], true) ? ' columnalign="left left"' : '');
		$table = '<mtable' . $align . '>' . implode('', $rows) . '</mtable>';
		return $open === '' && $close === '' ? $table : '<mrow><mo stretchy="true">' . Odf::xml($open) . '</mo>' . $table . '<mo stretchy="true">' . Odf::xml($close) . '</mo></mrow>';
	}

	/** MathML as text with Unicode: fractions a/b, powers as superscripts where they exist. */
	private function unicodeOf(string $xml): string {
		$xml = preg_replace('#<annotation\b.*?</annotation>#s', '', $xml) ?? $xml;
		$xml = str_replace('<mo>&#x2061;</mo>', ' ', $xml);
		$dom = new \DOMDocument();
		if (!@$dom->loadXML($xml)) {
			return Odf::xml($xml);
		}
		return Odf::xml($this->text($dom->documentElement));
	}

	private function text(\DOMNode $node): string {
		if ($node instanceof \DOMText) {
			return $node->data;
		}
		$children = [];
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$children[] = $child;
			}
		}
		$part = fn (int $i): string => isset($children[$i]) ? $this->text($children[$i]) : '';
		$name = $node instanceof \DOMElement ? $node->localName : '';
		switch ($name) {
			case 'mfrac':
				$numerator = $part(0);
				$denominator = $part(1);
				if ($node instanceof \DOMElement && $node->getAttribute('linethickness') === '0') {
					return $numerator . ', ' . $denominator;
				}
				return self::wrap($numerator) . '/' . self::wrap($denominator);
			case 'msqrt':
				return '√' . self::wrap($part(0));
			case 'mroot':
				$index = $part(1);
				return (['3' => '∛', '4' => '∜'][$index] ?? self::script($index, self::SUPERSCRIPTS, '^') . '√') . self::wrap($part(0));
			case 'msup':
			case 'mover':
				if ($name === 'mover' && $node instanceof \DOMElement && $node->getAttribute('accent') === 'true') {
					$accent = trim($part(1));
					return $part(0) . (['→' => '⃗', '^' => '̂', '¯' => '̅', '˙' => '̇', '¨' => '̈', '~' => '̃'][$accent] ?? '');
				}
				return $part(0) . self::script($part(1), self::SUPERSCRIPTS, '^');
			case 'msub':
			case 'munder':
				return $part(0) . self::script($part(1), self::SUBSCRIPTS, '_') . self::after($children[0]);
			case 'msubsup':
			case 'munderover':
				return $part(0) . self::script($part(1), self::SUBSCRIPTS, '_') . self::script($part(2), self::SUPERSCRIPTS, '^') . self::after($children[0]);
			case 'mrow':
				if ($node instanceof \DOMElement && $node->getAttribute('class') === 'binom') {
					return 'C(' . $part(1) . ')';
				}
				break;
			case 'mstyle':
				$inner = $part(0);
				return $node instanceof \DOMElement && $node->getAttribute('mathvariant') === 'double-struck'
					? strtr($inner, ['R' => 'ℝ', 'N' => 'ℕ', 'Z' => 'ℤ', 'Q' => 'ℚ', 'C' => 'ℂ', 'P' => 'ℙ', 'E' => '𝔼', '1' => '𝟙'])
					: $inner;
			case 'mtable':
				return implode('; ', array_map(fn (\DOMElement $row) => $this->text($row), $children));
			case 'mtr':
				return implode(', ', array_map(fn (\DOMElement $cell) => trim($this->text($cell)), $children));
			case 'mspace':
				return ' ';
			case 'mtext':
				return ' ' . $node->textContent . ' ';
			case 'mo':
				$op = $node->textContent;
				if ($op === ',' || $op === ';') {
					return $op . ' ';
				}
				return in_array($op, ['=', '<', '>', '≤', '≥', '≠', '≈', '→', '⇒', '⇔', '∈', '±', '+', '−', '×', '·', '÷', '≡', '⟹', '⟺', '∼'], true) ? ' ' . $op . ' ' : $op;
		}
		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= $this->text($child);
		}
		return $out;
	}

	/** A space after a large operator or a limit with its scripts: "∑ᵢ₌₁ⁿ i", "lim_(x→0) f". */
	private static function after(\DOMElement $base): string {
		return $base->localName === 'mo' && $base->getAttribute('largeop') === 'true' || $base->localName === 'mi' && in_array($base->textContent, self::LIMITS, true) ? ' ' : '';
	}

	/** In brackets unless it is one number, one letter, or already bracketed. */
	private static function wrap(string $text): string {
		$text = trim($text);
		return preg_match('/^(\p{N}+([.,]\p{N}+)?!?|\p{L}′*!?|√?\p{L})$/u', $text) === 1 || preg_match('/^\([^()]*\)$/u', $text) === 1 ? $text : '(' . $text . ')';
	}

	/** Superscript or subscript characters where all exist, else ^(…) or _(…). */
	private static function script(string $text, array $map, string $mark): string {
		$text = str_replace(' ', '', trim($text));
		$text = str_replace('−', '-', $text);
		$chars = mb_str_split($text);
		if ($chars !== [] && array_diff($chars, array_map('strval', array_keys($map))) === []) {
			return implode('', array_map(fn (string $c) => $map[$c], $chars));
		}
		return $mark . (mb_strlen($text) === 1 ? $text : '(' . $text . ')');
	}
}
