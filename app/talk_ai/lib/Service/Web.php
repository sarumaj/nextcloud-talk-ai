<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\Document\TextExtractor;
use OCP\Http\Client\IClientService;

/**
 * The web for the bot, through Nextcloud's HTTP client (which refuses local and
 * private addresses unless the admin allows them): a page's text, and sources
 * to cite. A DOI is formatted by doi.org (Crossref and DataCite) in any CSL
 * style; a book's details come from Open Library by ISBN; a page's from its
 * metadata. Knowledge: Wikipedia articles (in the language asked), and
 * scholarly works from OpenAlex (a free index of papers, with DOIs).
 */
class Web {
	private const MAX_BYTES = 5 * 1024 * 1024;
	private const AGENT = 'Nextcloud-TalkAI/1.0 (+https://github.com/sarumaj/nextcloud-talk-ai)';

	/** Common style names to their CSL ids (https://github.com/citation-style-language/styles). */
	public const STYLES = [
		'apa' => 'apa', 'mla' => 'modern-language-association', 'chicago' => 'chicago-author-date', 'chicago-notes' => 'chicago-note-bibliography',
		'harvard' => 'harvard-cite-them-right', 'ieee' => 'ieee', 'vancouver' => 'vancouver', 'ama' => 'american-medical-association',
		'acs' => 'american-chemical-society', 'nature' => 'nature', 'din-1505-2' => 'din-1505-2', 'iso690' => 'iso690-author-date-en',
		'turabian' => 'turabian-fullnote-bibliography', 'oscola' => 'oscola',
	];

	public function __construct(
		private IClientService $clients,
		private TextExtractor $extractor,
	) {
	}

	/** A web page's (or file's) text, with its title. */
	public function read(string $url): string {
		$url = self::url($url);
		$response = $this->clients->newClient()->get($url, ['timeout' => 20, 'headers' => ['User-Agent' => self::AGENT], 'stream' => true]);
		$body = $response->getBody();
		$data = is_resource($body) ? (string)stream_get_contents($body, self::MAX_BYTES) : substr((string)$body, 0, self::MAX_BYTES);
		$type = strtolower(trim(explode(';', $response->getHeader('Content-Type'))[0]));
		$name = basename((string)parse_url($url, PHP_URL_PATH)) ?: 'page.html';
		if ($type === 'text/html' || $type === 'application/xhtml+xml' || $type === '') {
			$meta = self::metadata($data, $url);
			$text = TextExtractor::html(self::mainHtml($data));
			$text = trim(preg_replace(["/[ \\t]+/", "/\\n\\s*\\n\\s*\\n+/"], [' ', "\n\n"], $text) ?? $text);
			return 'Page "' . ($meta['title'] ?? $url) . "\" ($url):\n" . mb_substr($text, 0, TextExtractor::MAX);
		}
		$text = $this->extractor->extract($data, $type, $name);
		return $text === null ? 'The link is a ' . $type . ' file, which cannot be read as text.' : "File at $url:\n" . $text;
	}

	/**
	 * A source to cite: by DOI, the citation in the style (and the details);
	 * by ISBN or URL, the details for the model to format.
	 *
	 * @return array<string, mixed>
	 */
	public function cite(string $source, string $style, string $locale, string $format): array {
		$source = trim($source);
		$csl = self::STYLES[strtolower($style)] ?? strtolower($style);
		if (preg_match('#(10\.\d{4,9}/[^\s"<>]+)#', $source, $m) === 1) {
			$doi = rtrim($m[1], '.,;)');
			$accept = match ($format) {
				'bibtex' => 'application/x-bibtex',
				'ris' => 'application/x-research-info-systems',
				default => 'text/x-bibliography; style=' . $csl . '; locale=' . ($locale ?: 'en-US'),
			};
			$client = $this->clients->newClient();
			$citation = trim((string)$client->get('https://doi.org/' . $doi, ['timeout' => 20, 'headers' => ['Accept' => $accept, 'User-Agent' => self::AGENT]])->getBody());
			return ['doi' => $doi, 'style' => $format === 'text' || $format === '' ? $csl : $format, 'citation' => $citation,
				'note' => 'Formatted by doi.org; use it as it is (italics may be lost in plain text).'];
		}
		$isbn = preg_replace('/[^0-9X]/i', '', $source) ?? '';
		if (preg_match('/^(\d{9}[\dX]|\d{13})$/i', $isbn) === 1 && !str_contains($source, '://')) {
			$data = json_decode((string)$this->clients->newClient()->get('https://openlibrary.org/api/books?format=json&jscmd=data&bibkeys=ISBN:' . $isbn, ['timeout' => 20, 'headers' => ['User-Agent' => self::AGENT]])->getBody(), true);
			$book = $data['ISBN:' . $isbn] ?? null;
			if (!is_array($book)) {
				return ['isbn' => $isbn, 'error' => 'Open Library does not know this ISBN; search the web for the book\'s details.'];
			}
			return ['type' => 'book', 'isbn' => $isbn, 'title' => $book['title'] ?? '', 'subtitle' => $book['subtitle'] ?? '',
				'authors' => array_column($book['authors'] ?? [], 'name'), 'publishers' => array_column($book['publishers'] ?? [], 'name'),
				'places' => array_column($book['publish_places'] ?? [], 'name'), 'date' => $book['publish_date'] ?? '',
				'edition' => $book['edition_name'] ?? '', 'pages' => $book['number_of_pages'] ?? null,
				'note' => 'Details from Open Library; format them in the ' . $style . ' style yourself.'];
		}
		$url = self::url($source);
		$response = $this->clients->newClient()->get($url, ['timeout' => 20, 'headers' => ['User-Agent' => self::AGENT], 'stream' => true]);
		$body = $response->getBody();
		$html = is_resource($body) ? (string)stream_get_contents($body, 2 * 1024 * 1024) : (string)$body;
		$meta = self::metadata($html, $url);
		if (isset($meta['doi']) && $format !== 'details') {
			// A scholarly page names its DOI: the exact citation from there.
			return $this->cite($meta['doi'], $style, $locale, $format) + ['url' => $url];
		}
		return ['type' => 'webpage'] + $meta + ['url' => $url, 'accessed' => date('Y-m-d'),
			'note' => 'Details from the page\'s metadata; format them in the ' . $style . ' style yourself, with the access date.'];
	}

	/**
	 * Wikipedia on a topic: the best-matching articles, and the first one's
	 * introduction (or its whole text).
	 */
	public function wikipedia(string $query, string $language, bool $full): array {
		$language = preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : 'en';
		$base = 'https://' . $language . '.wikipedia.org';
		$client = $this->clients->newClient();
		$options = ['timeout' => 20, 'headers' => ['User-Agent' => self::AGENT]];
		$found = json_decode((string)$client->get($base . '/w/rest.php/v1/search/page?' . http_build_query(['q' => $query, 'limit' => 5]), $options)->getBody(), true);
		$pages = $found['pages'] ?? [];
		if ($pages === []) {
			return ['error' => 'No Wikipedia article found in "' . $language . '" for "' . $query . '".'];
		}
		$title = (string)$pages[0]['title'];
		$extract = json_decode((string)$client->get($base . '/w/api.php?' . http_build_query([
			'action' => 'query', 'prop' => 'extracts|info', 'inprop' => 'url', 'explaintext' => 1, 'exintro' => $full ? 0 : 1,
			'redirects' => 1, 'titles' => $title, 'format' => 'json', 'formatversion' => 2,
		]), $options)->getBody(), true);
		$page = $extract['query']['pages'][0] ?? [];
		return [
			'title' => $page['title'] ?? $title,
			'url' => $page['fullurl'] ?? $base . '/wiki/' . rawurlencode(str_replace(' ', '_', $title)),
			'description' => $pages[0]['description'] ?? '',
			'text' => mb_substr(trim((string)($page['extract'] ?? '')), 0, $full ? 60000 : 6000),
			'other_articles' => array_map(fn (array $p) => $p['title'] . (isset($p['description']) ? ' – ' . $p['description'] : ''), array_slice($pages, 1)),
			'accessed' => date('Y-m-d'),
			'note' => 'Wikipedia, CC BY-SA. Name it as the source; for school work, check important facts in a second source.',
		];
	}

	/** Scholarly works on a topic from OpenAlex, most relevant first. */
	public function papers(string $query, ?int $fromYear, bool $openAccessOnly, int $count): array {
		$filters = [];
		if ($fromYear !== null) {
			$filters[] = 'from_publication_date:' . $fromYear . '-01-01';
		}
		if ($openAccessOnly) {
			$filters[] = 'is_oa:true';
		}
		$parameters = ['search' => $query, 'per-page' => max(1, min(15, $count)),
			'select' => 'doi,title,publication_year,authorships,primary_location,cited_by_count,open_access,type'];
		if ($filters !== []) {
			$parameters['filter'] = implode(',', $filters);
		}
		$data = json_decode((string)$this->clients->newClient()->get('https://api.openalex.org/works?' . http_build_query($parameters), ['timeout' => 20, 'headers' => ['User-Agent' => self::AGENT]])->getBody(), true);
		$works = [];
		foreach ($data['results'] ?? [] as $w) {
			$authors = array_map(fn (array $a) => (string)($a['author']['display_name'] ?? ''), array_slice($w['authorships'] ?? [], 0, 4));
			$works[] = array_filter([
				'title' => $w['title'] ?? '',
				'authors' => implode(', ', $authors) . (count($w['authorships'] ?? []) > 4 ? ' et al.' : ''),
				'year' => $w['publication_year'] ?? null,
				'type' => $w['type'] ?? null,
				'venue' => $w['primary_location']['source']['display_name'] ?? null,
				'doi' => isset($w['doi']) ? preg_replace('#^https://doi.org/#', '', (string)$w['doi']) : null,
				'cited_by' => $w['cited_by_count'] ?? null,
				'free_to_read' => ($w['open_access']['is_oa'] ?? false) ? ($w['open_access']['oa_url'] ?? 'yes') : null,
			], fn ($v) => $v !== null && $v !== '');
		}
		return ['found' => (int)($data['meta']['count'] ?? 0), 'works' => $works,
			'note' => 'From OpenAlex. Cite a work with cite and its DOI; read a free one with read_url.'];
	}

	/** An http(s) URL, or an exception. */
	private static function url(string $url): string {
		$url = trim($url);
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}
		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new \InvalidArgumentException('not a web address: ' . $url);
		}
		return $url;
	}

	/**
	 * A page's details from its meta tags: scholarly (citation_*), Dublin Core,
	 * Open Graph, and the title.
	 *
	 * @return array<string, string|list<string>>
	 */
	public static function metadata(string $html, string $url = ''): array {
		$head = substr($html, 0, 300000);
		$tags = [];
		preg_match_all('#<meta\b[^>]*>#i', $head, $metas);
		foreach ($metas[0] as $meta) {
			if (preg_match('#\b(?:name|property)\s*=\s*["\']([^"\']+)["\']#i', $meta, $n) === 1 && preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#is', $meta, $c) === 1) {
				$tags[strtolower($n[1])][] = trim(html_entity_decode($c[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			}
		}
		$first = fn (string ...$names): ?string => array_reduce($names, fn (?string $found, string $n) => $found ?? ($tags[$n][0] ?? null));
		$title = $first('citation_title', 'dc.title', 'og:title', 'twitter:title');
		if ($title === null && preg_match('#<title[^>]*>(.*?)</title>#is', $head, $t) === 1) {
			$title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		$authors = $tags['citation_author'] ?? $tags['dc.creator'] ?? $tags['author'] ?? $tags['article:author'] ?? [];
		$out = array_filter([
			'title' => $title,
			'authors' => array_values(array_unique(array_filter($authors, fn (string $a) => !str_starts_with($a, 'http')))),
			'site' => $first('og:site_name', 'citation_journal_title', 'citation_publisher', 'dc.publisher') ?? (parse_url($url, PHP_URL_HOST) ?: null),
			'date' => $first('citation_publication_date', 'citation_date', 'article:published_time', 'dc.date', 'date', 'og:updated_time'),
			'doi' => isset($tags['citation_doi'][0]) ? preg_replace('#^(https?://(dx\.)?doi\.org/|doi:)#i', '', $tags['citation_doi'][0]) : null,
			'volume' => $first('citation_volume'),
			'issue' => $first('citation_issue'),
			'pages' => ($p = $first('citation_firstpage')) !== null ? $p . (($l = $first('citation_lastpage')) !== null ? '–' . $l : '') : null,
			'description' => $first('description', 'og:description'),
		], fn ($v) => $v !== null && $v !== '' && $v !== []);
		return $out;
	}

	/** The page's main part where it marks one (main, article), else the body. */
	private static function mainHtml(string $html): string {
		$html = preg_replace('#<(nav|header|footer|aside|form|noscript|svg)\b.*?</\1>#is', '', $html) ?? $html;
		foreach (['main', 'article'] as $tag) {
			if (preg_match('#<' . $tag . '\b[^>]*>(.*)</' . $tag . '>#is', $html, $m) === 1 && mb_strlen(strip_tags($m[1])) > 500) {
				return $m[1];
			}
		}
		return $html;
	}
}
