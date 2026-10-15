<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * Syntax highlighting for code in documents: comments, strings, numbers,
 * keywords and built-in types or functions, for the languages of school and
 * university (Python, Java, JavaScript/TypeScript, C, C++, C#, PHP, SQL, Bash,
 * Kotlin, Go, Rust, HTML/XML, CSS, JSON) and German school pseudocode. Each
 * line comes back as ODF text with spans of the styles CodeKeyword, CodeType,
 * CodeString, CodeComment and CodeNumber.
 */
class Code {
	private const KEYWORDS = [
		'python' => 'and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return True try while with yield match case',
		'java' => 'abstract assert boolean break byte case catch char class const continue default do double else enum extends final finally float for goto if implements import instanceof int interface long native new package private protected public return short static strictfp super switch synchronized this throw throws transient try void volatile while true false null var record',
		'javascript' => 'async await break case catch class const continue debugger default delete do else export extends false finally for function if import in instanceof let new null return super switch this throw true try typeof undefined var void while with yield of interface type enum implements private public protected readonly',
		'c' => 'auto break case char const continue default do double else enum extern float for goto if inline int long register return short signed sizeof static struct switch typedef union unsigned void volatile while bool true false NULL nullptr class public private protected virtual template typename namespace using new delete this throw try catch std string vector include define',
		'csharp' => 'abstract as base bool break byte case catch char checked class const continue decimal default delegate do double else enum event explicit extern false finally fixed float for foreach goto if implicit in int interface internal is lock long namespace new null object operator out override params private protected public readonly ref return sbyte sealed short sizeof static string struct switch this throw true try typeof uint ulong unchecked unsafe ushort using var virtual void volatile while async await',
		'php' => 'abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile extends final finally fn for foreach function global goto if implements include instanceof insteadof interface isset list match namespace new null or print private protected public readonly require return static switch throw trait try unset use var while xor yield true false',
		'sql' => 'select from where and or not insert into values update set delete create table drop alter add primary key foreign references join inner left right outer full on group by order having distinct as limit offset union all in is null like between exists case when then else end count sum avg min max index view default unique check constraint asc desc integer int varchar text real date boolean',
		'bash' => 'if then else elif fi for while until do done case esac function return in echo exit export local read set unset shift source cd ls grep sed awk cat true false',
		'kotlin' => 'as break class continue do else false for fun if in interface is null object package return super this throw true try typealias val var when while by catch constructor data enum finally get import init internal open override private protected public sealed set suspend',
		'go' => 'break case chan const continue default defer else fallthrough for func go goto if import interface map package range return select struct switch type var true false nil',
		'rust' => 'as async await break const continue crate dyn else enum extern false fn for if impl in let loop match mod move mut pub ref return self Self static struct super trait true type unsafe use where while',
		'pseudocode' => 'algorithmus algorithm eingabe ausgabe input output wenn dann sonst ende if then else end solange while wiederhole repeat bis until für for von bis to do gib return und and oder or nicht not funktion function prozedur procedure setze set lies read schreibe write print',
	];
	private const TYPES = [
		'python' => 'int float str bool list dict set tuple print len range enumerate zip map filter sorted sum min max abs open input type isinstance self',
		'java' => 'String Integer Double Boolean Object List ArrayList Map HashMap Set HashSet System Math Scanner Exception',
		'javascript' => 'console Math JSON Array Object String Number Boolean Promise Map Set document window number string boolean any void never',
		'csharp' => 'Console List Dictionary Math String Exception Task',
		'kotlin' => 'Int Double String Boolean List MutableList Map println',
		'go' => 'int string bool float64 error fmt make len append',
		'rust' => 'i32 i64 u32 u64 f64 usize String Vec Option Some None Result Ok Err println',
	];
	private const ALIASES = ['py' => 'python', 'python3' => 'python', 'js' => 'javascript', 'ts' => 'javascript', 'typescript' => 'javascript',
		'jsx' => 'javascript', 'tsx' => 'javascript', 'cpp' => 'c', 'c++' => 'c', 'h' => 'c', 'cs' => 'csharp', 'c#' => 'csharp', 'sh' => 'bash',
		'shell' => 'bash', 'zsh' => 'bash', 'kt' => 'kotlin', 'golang' => 'go', 'rs' => 'rust', 'mysql' => 'sql', 'postgresql' => 'sql',
		'sqlite' => 'sql', 'pseudo' => 'pseudocode', 'pseudocode' => 'pseudocode', 'pseudokode' => 'pseudocode'];
	private const NAMES = ['python' => 'Python', 'java' => 'Java', 'javascript' => 'JavaScript', 'c' => 'C / C++', 'csharp' => 'C#', 'php' => 'PHP',
		'sql' => 'SQL', 'bash' => 'Shell', 'kotlin' => 'Kotlin', 'go' => 'Go', 'rust' => 'Rust', 'pseudocode' => 'Pseudocode', 'html' => 'HTML',
		'xml' => 'XML', 'css' => 'CSS', 'json' => 'JSON'];

	/** The language's name to show, or '' for none or unknown. */
	public static function label(string $language): string {
		$l = self::language($language);
		if (in_array($l, ['', 'text', 'txt', 'plain', 'plaintext', 'output', 'console'], true)) {
			return '';
		}
		return self::NAMES[$l] ?? ($language !== '' ? $language : '');
	}

	/**
	 * Each line of the code as ODF text with highlighting; runs of spaces kept.
	 *
	 * @return list<string>
	 */
	public static function lines(string $code, string $language): array {
		$l = self::language($language);
		$markup = in_array($l, ['html', 'xml'], true);
		$keywords = array_flip(preg_split('/\s+/', self::KEYWORDS[$l] ?? '') ?: []);
		$types = array_flip(preg_split('/\s+/', self::TYPES[$l] ?? '') ?: []);
		$caseless = in_array($l, ['sql', 'pseudocode'], true);
		$lineComment = match ($l) {
			'python', 'bash' => '#',
			'sql' => '--',
			'pseudocode' => '//',
			'', 'json', 'html', 'xml', 'css' => null,
			default => '//',
		};
		$blockComment = in_array($l, ['java', 'javascript', 'c', 'csharp', 'php', 'kotlin', 'go', 'rust', 'css', 'sql'], true);
		$out = [];
		$inBlock = false;
		foreach (explode("\n", str_replace(["\r\n", "\t"], ["\n", '    '], rtrim($code, "\n"))) as $line) {
			$html = '';
			$at = 0;
			$length = strlen($line);
			while ($at < $length) {
				$rest = substr($line, $at);
				if ($inBlock) {
					$end = strpos($rest, '*/');
					$piece = $end === false ? $rest : substr($rest, 0, $end + 2);
					$html .= self::span('CodeComment', $piece);
					$at += strlen($piece);
					$inBlock = $end === false;
					continue;
				}
				if ($markup) {
					if (preg_match('/^(<!--.*?(-->|$))|^(<\/?[\w:.-]+)|^("[^"]*"|\'[^\']*\')|^(\/?>)/', $rest, $m) === 1) {
						$html .= self::span($m[1] !== '' ? 'CodeComment' : (($m[3] ?? '') !== '' ? 'CodeKeyword' : (($m[4] ?? '') !== '' ? 'CodeString' : 'CodeKeyword')), $m[0]);
						$at += strlen($m[0]);
						continue;
					}
				} elseif ($blockComment && str_starts_with($rest, '/*')) {
					$inBlock = true;
					continue;
				} elseif ($lineComment !== null && str_starts_with($rest, $lineComment) && !($l === 'bash' && $at > 0 && $line[$at - 1] === '$')) {
					$html .= self::span('CodeComment', $rest);
					break;
				} elseif (preg_match('/^("""|\'\'\')/', $rest, $m) === 1 && $l === 'python') {
					$html .= self::span('CodeString', $rest);
					break;
				} elseif (preg_match('/^("(?:[^"\\\\]|\\\\.)*"?|\'(?:[^\'\\\\]|\\\\.)*\'?|`(?:[^`\\\\]|\\\\.)*`?)/', $rest, $m) === 1) {
					$html .= self::span('CodeString', $m[0]);
					$at += strlen($m[0]);
					continue;
				} elseif (preg_match('/^(0[xX][0-9a-fA-F]+|0[bB][01]+|\d+\.?\d*(?:[eE][-+]?\d+)?[fFlLdD]?)\b/', $rest, $m) === 1) {
					$html .= self::span('CodeNumber', $m[0]);
					$at += strlen($m[0]);
					continue;
				} elseif (preg_match('/^[@$]?[A-Za-z_ÄÖÜäöüß][\wÄÖÜäöüß]*/u', $rest, $m) === 1) {
					$word = $m[0];
					$key = $caseless ? strtolower($word) : $word;
					$style = isset($keywords[$key]) || ($caseless && isset($keywords[$word])) ? 'CodeKeyword' : (isset($types[$word]) ? 'CodeType' : '');
					$html .= $style === '' ? Odf::xml($word) : self::span($style, $word);
					$at += strlen($word);
					continue;
				}
				// Anything else, a character at a time (whole UTF-8 characters).
				$char = mb_substr($rest, 0, 1);
				$html .= Odf::xml($char);
				$at += strlen($char);
			}
			$out[] = self::collapse($html);
		}
		return $out;
	}

	private static function language(string $language): string {
		$l = strtolower(trim($language));
		return self::ALIASES[$l] ?? $l;
	}

	private static function span(string $style, string $text): string {
		return '<text:span text:style-name="' . $style . '">' . Odf::xml($text) . '</text:span>';
	}

	/**
	 * Spaces as ODF keeps them: a paragraph drops its leading spaces and collapses
	 * runs, so the indentation becomes <text:s/> and runs keep their length.
	 */
	private static function collapse(string $html): string {
		$html = preg_replace_callback('/^ +/', fn (array $m) => '<text:s text:c="' . strlen($m[0]) . '"/>', $html) ?? $html;
		return preg_replace_callback('/ {2,}/', fn (array $m) => ' <text:s text:c="' . (strlen($m[0]) - 1) . '"/>', $html) ?? $html;
	}
}
