<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Document\Biology;
use OCA\TalkAi\Document\Calculator;
use OCA\TalkAi\Document\Chart;
use OCA\TalkAi\Document\Chemistry;
use OCA\TalkAi\Document\Colors;
use OCA\TalkAi\Document\Informatics;
use OCA\TalkAi\Document\Math;
use OCA\TalkAi\Document\OdpWriter;
use OCA\TalkAi\Document\OdsWriter;
use OCA\TalkAi\Document\OdtWriter;
use OCA\TalkAi\Document\Sport;
use OCA\TalkAi\Document\Statistics;
use OCA\TalkAi\Document\Units;
use OCA\TalkAi\Document\TextExtractor;
use OCP\Files\File;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Answers one message: reads the conversation, asks AI-Hub (scenario "chat",
 * with web search and the tools below, which run with the asking person's
 * rights), and posts the answer as the bot. Images shared lately by the asking
 * person go with the question; files are read and sent through the tools.
 */
class Reply {
	public const SCENARIO = 'chat';
	/** Images handed to the model with a question (AI-Hub takes four, of 5 MB each). */
	private const MAX_IMAGES = 4;
	private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
	private const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
	/** Formats send_file writes as they are given. */
	private const PLAIN = ['md', 'txt', 'csv', 'tsv', 'html', 'json', 'tex', 'bib', 'ris', 'ics', 'xml', 'yaml',
		'py', 'java', 'js', 'ts', 'c', 'cpp', 'h', 'cs', 'php', 'sql', 'sh', 'kt', 'swift', 'go', 'rs', 'css'];
	/** Formats written as OpenDocument (and converted from it), by what they hold. */
	private const DOCUMENTS = ['odt', 'docx'];
	private const SPREADSHEETS = ['ods', 'xlsx'];
	private const PRESENTATIONS = ['odp', 'pptx'];
	/** Flash card formats: printable, or for import into a learning app. */
	private const CARD_FORMATS = ['pdf', 'docx', 'odt', 'anki', 'quizlet', 'csv', 'xlsx'];

	/** The question being answered: what the tools work on. */
	private ?object $room = null;
	private int $messageId = 0;
	/** @var list<array{id: int, name: string, mime: string, size: int}> */
	private array $sharedFiles = [];

	public function __construct(
		private Conversation $conversation,
		private TextExtractor $extractor,
		private OdtWriter $odt,
		private OdsWriter $ods,
		private OdpWriter $odp,
		private Office $office,
		private Bot $bot,
		private Web $web,
		private Calendar $calendar,
		private LoggerInterface $logger,
	) {
	}

	public function answer(string $token, string $userId, int $messageId, string $text): void {
		try {
			$room = $this->conversation->room($token);
		} catch (\Throwable $e) {
			$this->logger->warning('AI bot: no conversation ' . $token . ': ' . $e->getMessage());
			return;
		}
		try {
			$this->answerIn($room, $userId, $messageId, $text);
		} catch (\Throwable $e) {
			$this->logger->error('AI bot: no answer: ' . $e->getMessage(), ['exception' => $e]);
			$this->conversation->postText($room, '⚠️ I could not answer this time (' . mb_substr($e->getMessage(), 0, 200) . ').', $messageId);
		}
	}

	private function answerIn(object $room, string $userId, int $messageId, string $text): void {
		$hub = class_exists('OCA\AIHub\Service\HubService') ? Server::get('OCA\AIHub\Service\HubService') : null;
		if ($hub === null) {
			$this->conversation->postText($room, '⚠️ AI-Hub, my connection to the AI, is not installed.', $messageId);
			return;
		}
		$this->room = $room;
		$this->messageId = $messageId;
		$status = $hub->status(Application::APP_ID);
		// Named after the AI that answers: renamed here when AI-Hub's choice changed.
		$this->bot->sync($status);
		$history = $this->conversation->history($room, $userId);
		$this->sharedFiles = array_merge(...array_map(fn (array $m) => $m['files'], $history) ?: [[]]);
		$hub->registerScenario(Application::APP_ID, self::SCENARIO, [
			'system' => $this->systemPrompt($room),
			'tools' => $this->tools(),
			'search' => true,
		]);
		$images = $status['images'] ? $this->recentImages($history, $userId, $messageId) : [];
		$question = trim(preg_replace('/^\s*@?"?' . preg_quote($this->bot->name(), '/') . '"?\s*[,:]?\s*/iu', '', $text) ?? $text);
		$result = $hub->askNow($userId, Application::APP_ID, self::SCENARIO, [['role' => 'user', 'text' => $question !== '' ? $question : $text]], [
			'context' => $this->transcript($history, $messageId),
			'search' => (bool)$status['search'],
			'images' => $images,
		]);
		if (($result['state'] ?? '') === 'done' && trim((string)($result['text'] ?? '')) !== '') {
			// Talk shows Markdown but no LaTeX: formulas in Unicode.
			$this->conversation->postText($room, trim(Math::forChat((string)$result['text'])), $messageId);
		} elseif (($result['state'] ?? '') !== 'done') {
			$this->conversation->postText($room, '⚠️ I could not answer: ' . self::explain((string)($result['error'] ?? 'unknown error')), $messageId);
		}
	}

	private function systemPrompt(object $room): string {
		$pdf = $this->extractor->canReadPdf() ? '' : ' PDF files cannot be read on this server.';
		return 'You are ' . $this->bot->name() . ', an assistant in the Nextcloud Talk conversation "' . $room->getName() . '", '
			. 'for work, school, studies, art and everyday questions. Answer the message marked >>> (only that one), in the '
			. 'language that message is written in (not the language of earlier messages or of these instructions), to the point; '
			. 'Talk shows Markdown (no tables wider than a phone; no HTML). '
			. 'The context holds the conversation\'s recent messages; files shared there appear as [file "name", id N]. Images shared '
			. 'lately come with the question.' . "\n\n"
			. 'Files: read_file reads a file by id (Word, Excel, PowerPoint, OpenDocument, PDF, text; old .doc/.xls/.ppt too); '
			. 'list_files lists the files shared here; find_files finds the person\'s own files by name. When asked about "the file", '
			. '"the slides" or "the document", read the latest one that fits. You act with the rights of the person asking.' . $pdf . "\n\n"
			. 'Sending files: when the person asks for a document, a file, a PDF, slides, a spreadsheet or a table to download, use '
			. 'send_file (documents: .pdf .docx .odt .md .txt .html; spreadsheets: .xlsx .ods .csv; presentations: .pptx .odp, or .pdf '
			. 'with layout "slides"). Write a document as Markdown, a spreadsheet as Markdown tables (a "## Name" heading per sheet; '
			. 'Excel formulas like =SUM(B2:B9) work), a presentation as Markdown slides ("## Title" per slide, 3 to 6 short bullets, '
			. '"Notes:" for speaker notes). For flash cards use send_flashcards (printable PDF by default; "anki" or "quizlet" to '
			. 'import into those apps). After sending, say so in a sentence; do not repeat the content.' . "\n\n"
			. 'Learning: summaries lead with the key points, then details; adapt to the level asked (e.g. a school year). Flash cards '
			. 'ask one thing each, with short answers. Quizzes give the answers at the end. When helping with homework, explain the '
			. 'steps so the person learns, not just the result.' . "\n\n"
			. 'Maths: solve step by step. Use the calculate tool for every non-trivial calculation instead of computing in your head. '
			. 'Write formulas in LaTeX between $…$ (inline) or $$…$$ (on their own line): the chat shows them in Unicode, documents '
			. 'as real formulas. Keep chat formulas simple; for long derivations offer a PDF.' . "\n\n"
			. 'Subjects: use the exact tools instead of working it out yourself: calculate (also constants like c0, g0, h_planck, '
			. 'N_A, q_e; normcdf, invnorm, binompdf, binomcdf, poissonpdf; bin, hex), statistics, convert_units (physics and '
			. 'everyday units, temperatures), chemistry (molar masses, balancing equations, elements), biology (crosses with '
			. 'Punnett squares, DNA/RNA transcription and translation), sport (heart-rate zones, pace, race times, BMI, '
			. 'calories), informatics (truth tables, number encodings, SQL on example tables), plot (graphs of functions, '
			. 'charts, timelines), colors (a painting\'s palette, colour harmonies). Chemical formulas: \\ce{H2SO4} inside $…$, '
			. 'or write H₂SO₄. Present a tool\'s result as it is, with a short explanation of the method; do not recompute it by '
			. 'hand. If a tool fails, say so and only then work it out yourself, marked as such.' . "\n\n"
			. 'Images: you see images shared lately; read_file also reads the text in an image or a scanned PDF (OCR). For art, '
			. 'discuss composition, colour, light and technique concretely, with art-historical references where they help.' . "\n\n"
			. 'Writing: when asked to correct or give feedback on a text, you may send a document that marks changes: '
			. '~~deleted~~, ++inserted++ and ==remark== , with a short explanation of the main points.' . "\n\n"
			. 'Planning: calendar reads and adds events (exams, deadlines, exhibitions) and tasks, with reminders; a study plan can '
			. 'go there as events or tasks once the person agrees. Today is ' . date('l, Y-m-d') . '.' . "\n\n"
			. 'Chat: the context holds the last ' . Conversation::HISTORY . ' messages; read_chat reads more (for summaries or '
			. 'minutes of a discussion, with times).' . "\n\n"
			. 'Web and sources: search the web for current facts (news, prices, opening hours, weather) and name your sources. '
			. 'read_url reads a page or file at a link. lookup searches Wikipedia (in the person\'s language) and scholarly papers '
			. '(OpenAlex) for a topic. To cite, use cite with the DOI, ISBN or URL and the style asked (APA if none): it gives the '
			. 'exact citation for a DOI and real details otherwise; never invent sources, quotes or details.' . "\n\n"
			. 'The people here are a family: among them a painter and artist, a student in the last year of secondary school '
			. '(German Abitur), and a future university student. Help with exams (explain, practise, check answers and say '
			. 'why, rather than only giving results), with essays and theses (structure, arguments, sources, citations), and '
			. 'with art (technique, colour, exhibition and statement texts, titles, descriptions).';
	}

	/**
	 * The conversation as context: "Name: text" lines, oldest first, up to the
	 * question (marked). Later messages are left out: each is answered on its own.
	 */
	private function transcript(array $history, int $messageId): string {
		$lines = ['Recent messages of this conversation (oldest first), up to the message to answer (marked >>>):'];
		foreach ($history as $m) {
			if ($m['id'] > $messageId) {
				break;
			}
			$lines[] = ($m['id'] === $messageId ? '>>> ' : '') . $m['actor'] . ($m['bot'] ? ' (you)' : '') . ': ' . mb_substr($m['text'], 0, 2000);
		}
		return implode("\n", $lines);
	}

	/**
	 * The images the asking person shared since the bot last spoke (the question's
	 * own first), for the model to see.
	 *
	 * @return list<array{type: string, data: string}>
	 */
	private function recentImages(array $history, string $userId, int $messageId): array {
		$candidates = [];
		foreach (array_reverse($history) as $m) {
			if ($m['bot'] && $m['id'] < $messageId) {
				break;
			}
			foreach ($m['files'] as $f) {
				if (in_array($f['mime'], self::IMAGE_TYPES, true) && $f['size'] <= self::MAX_IMAGE_BYTES) {
					$candidates[] = $f['id'];
				}
			}
		}
		$images = [];
		foreach (array_slice(array_unique($candidates), 0, self::MAX_IMAGES) as $id) {
			$file = $this->conversation->userFile($userId, $id);
			if ($file !== null && in_array($file->getMimetype(), self::IMAGE_TYPES, true)) {
				$images[] = ['type' => $file->getMimetype(), 'data' => base64_encode($file->getContent())];
			}
		}
		return $images;
	}

	/** @return array<string, array{description: string, input: array, run: callable}> */
	private function tools(): array {
		return array_map(fn (array $tool) => ['input' => self::lenient($tool['input'])] + $tool, $this->toolList());
	}

	/**
	 * A schema that also takes numbers and flags written as text ("283", "true"): models
	 * send them so, AI-Hub checks types strictly, and the tools convert them anyway.
	 */
	private static function lenient(array $schema): array {
		$type = $schema['type'] ?? null;
		if ($type === 'integer' || $type === 'number' || $type === 'boolean') {
			$schema['type'] = [$type, 'string'];
		}
		foreach (['properties'] as $key) {
			foreach ($schema[$key] ?? [] as $name => $sub) {
				$schema[$key][$name] = self::lenient($sub);
			}
		}
		if (isset($schema['items']) && is_array($schema['items'])) {
			$schema['items'] = self::lenient($schema['items']);
		}
		return $schema;
	}

	/** @return array<string, array{description: string, input: array, run: callable}> */
	private function toolList(): array {
		return [
			'list_files' => [
				'description' => 'The files shared in this conversation lately: id, name, type and size.',
				'input' => ['type' => 'object'],
				'run' => fn (string $uid, array $args) => $this->sharedFiles,
			],
			'read_file' => [
				'description' => 'The text of a file by its id (from the conversation or find_files): Word, Excel (each sheet), PowerPoint '
					. '(each slide with its notes), OpenDocument, old .doc/.xls/.ppt, PDF, Markdown, CSV, HTML or text.',
				'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
				'run' => fn (string $uid, array $args) => $this->readFile($uid, (int)$args['id']),
			],
			'find_files' => [
				'description' => 'The person\'s files whose names contain the words: id, path, type and size, newest first.',
				'input' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
				'run' => fn (string $uid, array $args) => $this->conversation->findFiles($uid, (string)$args['query']),
			],
			'send_file' => [
				'description' => 'Sends a file into this chat; the name\'s extension sets the format. Documents (.pdf .docx .odt) from '
					. 'Markdown: headings, lists (nested, "- [ ]" check boxes), tables, bold, italic, links, code, LaTeX formulas '
					. '$…$ and $$…$$, and corrections ~~deleted~~, ++inserted++, ==highlighted==. Spreadsheets (.xlsx .ods) from Markdown tables, a "## Name" heading per sheet; numbers, 50%, and '
					. 'formulas like =SUM(B2:B5) or =B2*C2 work. Presentations (.pptx .odp) from Markdown slides: "## Title" per slide, '
					. 'bullets, "Notes:" for speaker notes; the first slide "# Title" with a subtitle line. A .pdf of slides or of a '
					. 'spreadsheet: set layout. Sent as written: .md .txt .csv .html .json .tex, references (.bib, .ris), and source '
					. 'code (.py .java .js .c .cpp .sql …).',
				'input' => ['type' => 'object', 'properties' => [
					'name' => ['type' => 'string', 'description' => 'File name with extension, e.g. "Summary.pdf", "Budget.xlsx", "Talk.pptx"'],
					'content' => ['type' => 'string', 'description' => 'The content, as described'],
					'layout' => ['type' => 'string', 'enum' => ['document', 'slides', 'spreadsheet'], 'description' => 'For .pdf: what the content is (default document)'],
				], 'required' => ['name', 'content']],
				'run' => fn (string $uid, array $args) => $this->sendFile($uid, (string)$args['name'], (string)$args['content'], (string)($args['layout'] ?? '')),
			],
			'send_flashcards' => [
				'description' => 'Sends flash cards into this chat. Formats: pdf (default; to print, question left and answer right, '
					. 'to fold or cut), docx, odt, anki (a text file to import into Anki), quizlet (to paste into Quizlet\'s import), csv, xlsx. '
					. 'Card sides may use **bold** and LaTeX $…$.',
				'input' => ['type' => 'object', 'properties' => [
					'title' => ['type' => 'string', 'description' => 'E.g. "Biology – Photosynthesis"'],
					'cards' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
						'front' => ['type' => 'string'], 'back' => ['type' => 'string'],
					], 'required' => ['front', 'back']]],
					'format' => ['type' => 'string', 'enum' => self::CARD_FORMATS],
				], 'required' => ['title', 'cards']],
				'run' => fn (string $uid, array $args) => $this->sendCards($uid, (string)$args['title'], (array)$args['cards'], (string)($args['format'] ?? 'pdf')),
			],
			'read_chat' => [
				'description' => 'More of this conversation: the last N messages (up to 500), oldest first, with times and authors.',
				'input' => ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'required' => ['count']],
				'run' => fn (string $uid, array $args) => $this->readChat($uid, (int)$args['count']),
			],
			'read_url' => [
				'description' => 'The text of a web page or file (PDF, Word, …) at an http(s) address.',
				'input' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']],
				'run' => fn (string $uid, array $args) => $this->web->read((string)$args['url']),
			],
			'cite' => [
				'description' => 'A source to cite, by DOI, ISBN or URL. A DOI (also one a scholarly page names) comes back formatted '
					. 'exactly in the style; a book (ISBN) or web page comes back as details to format yourself. Styles: '
					. implode(', ', array_keys(Web::STYLES)) . ', or any CSL style id. Format "bibtex" or "ris" for reference managers (DOIs).',
				'input' => ['type' => 'object', 'properties' => [
					'source' => ['type' => 'string', 'description' => 'DOI, ISBN or URL'],
					'style' => ['type' => 'string', 'description' => 'e.g. apa, mla, chicago, harvard, ieee, din-1505-2'],
					'locale' => ['type' => 'string', 'description' => 'Language of the citation, e.g. de-DE, en-US, pl-PL'],
					'format' => ['type' => 'string', 'enum' => ['text', 'bibtex', 'ris', 'details']],
				], 'required' => ['source']],
				'run' => fn (string $uid, array $args) => $this->web->cite((string)$args['source'], (string)($args['style'] ?? 'apa'), (string)($args['locale'] ?? ''), (string)($args['format'] ?? 'text')),
			],
			'plot' => [
				'description' => 'Draws a chart and sends it into the chat as an image. type "function": graphs of functions of x '
					. '(functions: [{expression: "x^2 - 2x", label}], x_min, x_max; same syntax as calculate); "line", "bar", "scatter": '
					. 'labels (categories or x values) and series [{label, values}] (scatter: series [{label, points: [[x, y], …]}]); '
					. '"pie": labels and one series; "timeline": events [{date (1789, 44 BC, 1789-07-14), label}] and periods [{from, to, '
					. 'label}]; "mindmap": root and branches [{label, children: [short points]}] (up to 8 branches of 6). Optional: '
					. 'title, x_label, y_label, y_min, y_max, degrees.',
				'input' => ['type' => 'object', 'properties' => [
					'type' => ['type' => 'string', 'enum' => ['function', 'line', 'bar', 'scatter', 'pie', 'timeline', 'mindmap']],
					'root' => ['type' => 'string'],
					'branches' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'children' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
					'title' => ['type' => 'string'], 'x_label' => ['type' => 'string'], 'y_label' => ['type' => 'string'],
					'functions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['expression' => ['type' => 'string'], 'label' => ['type' => 'string']], 'required' => ['expression']]],
					'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
					'series' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
						'label' => ['type' => 'string'],
						'values' => ['type' => 'array', 'items' => ['type' => 'number']],
						'points' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'number']]],
					]]],
					'x_min' => ['type' => 'number'], 'x_max' => ['type' => 'number'], 'y_min' => ['type' => 'number'], 'y_max' => ['type' => 'number'],
					'degrees' => ['type' => 'boolean'],
					'events' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string'], 'label' => ['type' => 'string']]]],
					'periods' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'label' => ['type' => 'string']]]],
				], 'required' => ['type']],
				'run' => fn (string $uid, array $args) => $this->plot($uid, $args),
			],
			'statistics' => [
				'description' => 'Statistics of numbers: count, sum, mean, median, mode, min, max, quartiles, sample and population '
					. 'standard deviation and variance. With y_values as well: the linear regression y = a + bx and the correlation r.',
				'input' => ['type' => 'object', 'properties' => [
					'values' => ['type' => 'string', 'description' => 'Numbers separated by spaces, commas or semicolons'],
					'y_values' => ['type' => 'string', 'description' => 'For a regression: the y value of each value above'],
				], 'required' => ['values']],
				'run' => fn (string $uid, array $args) => isset($args['y_values']) && trim((string)$args['y_values']) !== ''
					? Statistics::regression(Statistics::numbers((string)$args['values']), Statistics::numbers((string)$args['y_values']))
					: Statistics::describe(Statistics::numbers((string)$args['values'])),
			],
			'calendar' => [
				'description' => 'The asking person\'s Nextcloud calendars, in their time zone. action "events" (from, to as dates): what is '
					. 'planned; "add_event" (title, start and end as "2026-11-12 09:00" or a date for all day, location, description, '
					. 'reminder_minutes before, calendar name): adds an event; "add_task" (title, due, priority 1–9, description): adds a '
					. 'to-do; "calendars": lists them. Add only what the person asked for, and say what you added.',
				'input' => ['type' => 'object', 'properties' => [
					'action' => ['type' => 'string', 'enum' => ['events', 'add_event', 'add_task', 'calendars']],
					'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
					'title' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string'], 'due' => ['type' => 'string'],
					'location' => ['type' => 'string'], 'description' => ['type' => 'string'], 'reminder_minutes' => ['type' => 'integer'],
					'priority' => ['type' => 'integer'], 'calendar' => ['type' => 'string'],
				], 'required' => ['action']],
				'run' => fn (string $uid, array $args) => match ((string)$args['action']) {
					'events' => $this->calendar->events($uid, (string)($args['from'] ?? date('Y-m-d')), (string)($args['to'] ?? date('Y-m-d', strtotime('+7 days')))),
					'add_event' => $this->calendar->addEvent($uid, $args),
					'add_task' => $this->calendar->addTask($uid, $args),
					default => $this->calendar->list($uid),
				},
			],
			'convert_units' => [
				'description' => 'Converts a value between units, with a check that they measure the same thing: SI with prefixes '
					. '(km, mA, µF, kWh, hPa), compound units (km/h, m/s^2, g/cm^3, N*m), inch, ft, mi, lb, oz, L, gal, bar, atm, mmHg, '
					. 'psi, eV, cal, hp, PS, knots, ly, AU, °, rad, and temperatures °C, °F, K.',
				'input' => ['type' => 'object', 'properties' => ['value' => ['type' => 'number'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string']], 'required' => ['value', 'from', 'to']],
				'run' => fn (string $uid, array $args) => Units::convert((float)$args['value'], (string)$args['from'], (string)$args['to']),
			],
			'chemistry' => [
				'description' => 'action "molar_mass": molar mass and composition of a formula (Ca(OH)2, CuSO4·5H2O, SO4^2-); '
					. '"balance": balances a reaction ("Fe + O2 -> Fe2O3", ions as Fe^3+); "element": an element\'s data by symbol, '
					. 'English name or atomic number.',
				'input' => ['type' => 'object', 'properties' => [
					'action' => ['type' => 'string', 'enum' => ['molar_mass', 'balance', 'element']],
					'input' => ['type' => 'string', 'description' => 'The formula, the equation or the element'],
				], 'required' => ['action', 'input']],
				'run' => fn (string $uid, array $args) => match ((string)$args['action']) {
					'balance' => Chemistry::balance((string)$args['input']),
					'element' => Chemistry::element((string)$args['input']),
					default => Chemistry::molarMass((string)$args['input']),
				},
			],
			'biology' => [
				'description' => 'action "cross": a genetic cross of two genotypes (Aa x aa, AaBb x AaBb, X-linked XAXa x XAY; capital '
					. 'letters dominant): gametes, Punnett square, genotype and phenotype ratios. "sequence": a DNA or RNA sequence\'s '
					. 'complement, mRNA (from the coding or the template strand), codons and protein, GC content.',
				'input' => ['type' => 'object', 'properties' => [
					'action' => ['type' => 'string', 'enum' => ['cross', 'sequence']],
					'parent1' => ['type' => 'string'], 'parent2' => ['type' => 'string'],
					'sequence' => ['type' => 'string'], 'strand' => ['type' => 'string', 'enum' => ['coding', 'template']],
				], 'required' => ['action']],
				'run' => fn (string $uid, array $args) => ($args['action'] ?? '') === 'cross'
					? Biology::cross((string)($args['parent1'] ?? ''), (string)($args['parent2'] ?? ''))
					: Biology::sequence((string)($args['sequence'] ?? ''), (string)($args['strand'] ?? 'coding')),
			],
			'sport' => [
				'description' => 'action "heart_rate_zones" (age, optional resting and maximum heart rate): Karvonen training zones; '
					. '"pace" (km, time as "mm:ss" or "h:mm:ss"): pace and speed; "predict_race" (km, time, target_km): Riegel\'s '
					. 'prediction; "bmi" (kg, cm); "calories" (met, kg, minutes).',
				'input' => ['type' => 'object', 'properties' => [
					'action' => ['type' => 'string', 'enum' => ['heart_rate_zones', 'pace', 'predict_race', 'bmi', 'calories']],
					'age' => ['type' => 'integer'], 'resting' => ['type' => 'integer'], 'maximum' => ['type' => 'integer'],
					'km' => ['type' => 'number'], 'time' => ['type' => 'string'], 'target_km' => ['type' => 'number'],
					'kg' => ['type' => 'number'], 'cm' => ['type' => 'number'], 'met' => ['type' => 'number'], 'minutes' => ['type' => 'number'],
				], 'required' => ['action']],
				'run' => fn (string $uid, array $args) => match ((string)$args['action']) {
					'heart_rate_zones' => Sport::heartRateZones((int)($args['age'] ?? 0), isset($args['resting']) ? (int)$args['resting'] : null, isset($args['maximum']) ? (int)$args['maximum'] : null),
					'pace' => Sport::pace((float)($args['km'] ?? 0), (string)($args['time'] ?? '')),
					'predict_race' => Sport::predict((float)($args['km'] ?? 0), (string)($args['time'] ?? ''), (float)($args['target_km'] ?? 0)),
					'bmi' => Sport::bmi((float)($args['kg'] ?? 0), (float)($args['cm'] ?? 0)),
					default => Sport::calories((float)($args['met'] ?? 0), (float)($args['kg'] ?? 0), (float)($args['minutes'] ?? 0)),
				},
			],
			'informatics' => [
				'description' => 'action "truth_table" (expression with and/or/not/xor/nand/nor/->/<->, also ∧ ∨ ¬): the table and '
					. 'whether it is a tautology; "encode" (text, kind: number for bases and two\'s complement with bits, text for UTF-8 '
					. 'bytes and code points, base64, base64_decode, url, url_decode, hash); "sql" (setup: CREATE TABLE and INSERT '
					. 'statements, query: SELECT … ; several separated by ;): runs them in a throwaway SQLite database.',
				'input' => ['type' => 'object', 'properties' => [
					'action' => ['type' => 'string', 'enum' => ['truth_table', 'encode', 'sql']],
					'expression' => ['type' => 'string'], 'text' => ['type' => 'string'],
					'kind' => ['type' => 'string', 'enum' => ['number', 'text', 'base64', 'base64_decode', 'url', 'url_decode', 'hash']],
					'bits' => ['type' => 'integer'], 'setup' => ['type' => 'string'], 'query' => ['type' => 'string'],
				], 'required' => ['action']],
				'run' => fn (string $uid, array $args) => match ((string)$args['action']) {
					'truth_table' => Informatics::truthTable((string)($args['expression'] ?? '')),
					'encode' => Informatics::encode((string)($args['text'] ?? ''), (string)($args['kind'] ?? 'text'), (int)($args['bits'] ?? 8)),
					default => Informatics::sql((string)($args['setup'] ?? ''), (string)($args['query'] ?? '')),
				},
			],
			'colors' => [
				'description' => 'For painting and design. action "palette" (file_id of an image, count 2–12): the image\'s dominant '
					. 'colours with shares; "harmonies" (color as #hex, rgb() or a name): complementary, analogous, triadic, split-'
					. 'complementary and tetradic colours, tints and shades. Each colour comes with hex, RGB, HSL, CMYK and the nearest '
					. 'name (also pigments like ultramarine, burnt sienna); a swatch image is sent into the chat.',
				'input' => ['type' => 'object', 'properties' => [
					'action' => ['type' => 'string', 'enum' => ['palette', 'harmonies']],
					'file_id' => ['type' => 'integer'], 'count' => ['type' => 'integer'], 'color' => ['type' => 'string'],
				], 'required' => ['action']],
				'run' => fn (string $uid, array $args) => $this->colors($uid, $args),
			],
			'lookup' => [
				'description' => 'Knowledge from reliable sources. source "wikipedia" (query, language like de/en/pl, full: the whole '
					. 'article instead of the introduction): the best-matching article with its link; source "papers" (query, '
					. 'from_year, open_access): scholarly works from OpenAlex with authors, year, venue, DOI, citations and a free link.',
				'input' => ['type' => 'object', 'properties' => [
					'source' => ['type' => 'string', 'enum' => ['wikipedia', 'papers']],
					'query' => ['type' => 'string'], 'language' => ['type' => 'string'], 'full' => ['type' => 'boolean'],
					'from_year' => ['type' => 'integer'], 'open_access' => ['type' => 'boolean'], 'count' => ['type' => 'integer'],
				], 'required' => ['source', 'query']],
				'run' => fn (string $uid, array $args) => ($args['source'] ?? '') === 'papers'
					? $this->web->papers((string)$args['query'], isset($args['from_year']) ? (int)$args['from_year'] : null, self::flag($args['open_access'] ?? false), (int)($args['count'] ?? 8))
					: $this->web->wikipedia((string)$args['query'], (string)($args['language'] ?? 'en'), self::flag($args['full'] ?? false)),
			],
			'calculate' => [
				'description' => 'Calculates exactly: + - * / ^ % ( ) ! , pi, e, sqrt, cbrt, root(x,n), abs, exp, ln, log (base 10), log2, '
					. 'sin cos tan asin acos atan (radians, or degrees: true), sinh cosh tanh, floor ceil round(x,digits), min, max, gcd, '
					. 'lcm, binom(n,k), npr(n,k), mod(a,b), hypot. Variables: "r = 2; pi r^2". Several expressions separated by ";" or '
					. 'new lines; each result, with a fraction where it is a simple one.',
				'input' => ['type' => 'object', 'properties' => [
					'expressions' => ['type' => 'string'],
					'degrees' => ['type' => 'boolean', 'description' => 'Angles in degrees'],
				], 'required' => ['expressions']],
				'run' => fn (string $uid, array $args) => (new Calculator(self::flag($args['degrees'] ?? false)))->evaluate((string)$args['expressions']),
			],
		];
	}

	private function readFile(string $uid, int $id): string {
		$file = $this->conversation->userFile($uid, $id);
		if ($file === null) {
			return 'No file with this id that the person may read.';
		}
		if (str_starts_with($file->getMimetype(), 'image/')) {
			$text = $file->getSize() <= 30 * 1024 * 1024 ? $this->extractor->ocr($file->getContent()) : null;
			return 'This is an image (' . $file->getName() . '). Images shared lately come with the question.'
				. ($text === null ? ($this->extractor->canOcr() ? ' No text was recognised in it.' : '') : " Its text, recognised by OCR (may contain errors):\n" . $text);
		}
		if ($file->getSize() > 50 * 1024 * 1024) {
			return 'The file is too large to read (' . round($file->getSize() / 1048576) . ' MB).';
		}
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if (isset(Office::LEGACY[$extension])) {
			$modern = $this->office->modernize($file);
			if ($modern === null) {
				return 'This old ' . strtoupper($extension) . ' file cannot be read here (it needs Nextcloud Office). Ask for it in a newer format.';
			}
			$text = $this->extractor->extract($modern['data'], '', $modern['name']);
		} else {
			$text = $this->extractor->extract($file->getContent(), $file->getMimetype(), $file->getName());
		}
		return $text === null ? 'This kind of file (' . $file->getMimetype() . ') cannot be read as text.' : "File \"" . $file->getName() . "\":\n" . $text;
	}

	private function sendFile(string $uid, string $name, string $content, string $layout): string {
		$name = self::fileName($name);
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$base = pathinfo($name, PATHINFO_FILENAME);
		if (!in_array($extension, [...self::PLAIN, ...self::DOCUMENTS, ...self::SPREADSHEETS, ...self::PRESENTATIONS, 'pdf'], true)) {
			$base = $name;
			$extension = 'md';
		}
		if (in_array($extension, self::PLAIN, true)) {
			return $this->post($this->conversation->saveFile($this->room, $uid, $base . '.' . $extension, $content), $extension);
		}
		$kind = match (true) {
			in_array($extension, self::SPREADSHEETS, true) || $layout === 'spreadsheet' => 'ods',
			in_array($extension, self::PRESENTATIONS, true) || $layout === 'slides' => 'odp',
			default => 'odt',
		};
		$data = match ($kind) {
			'ods' => $this->ods->write($content, $base),
			'odp' => $this->odp->write($content, $base),
			default => $this->odt->write($content, $base),
		};
		return $this->sendOdf($uid, $base, $kind, $data, $extension);
	}

	/**
	 * @param list<array{front?: string, back?: string}> $cards
	 */
	private function sendCards(string $uid, string $title, array $cards, string $format): string {
		$cards = array_values(array_filter(array_map(fn ($c) => ['front' => trim((string)($c['front'] ?? '')), 'back' => trim((string)($c['back'] ?? ''))], $cards), fn (array $c) => $c['front'] !== ''));
		if ($cards === []) {
			return 'No cards given.';
		}
		$base = self::fileName($title !== '' ? $title : 'Flash cards');
		$format = in_array($format, self::CARD_FORMATS, true) ? $format : 'pdf';
		$line = fn (string $text) => str_replace(["\t", "\r\n", "\n"], [' ', '<br>', '<br>'], $text);
		switch ($format) {
			case 'anki':
				// Anki's import: tab-separated, HTML allowed; its MathJax reads \( \) and \[ \].
				$rows = array_map(fn (array $c) => implode("\t", array_map(fn (string $s) => $line(Math::replace($s, fn (string $latex, bool $display) => $display ? '\[' . $latex . '\]' : '\(' . $latex . '\)')), [$c['front'], $c['back']])), $cards);
				return $this->post($this->conversation->saveFile($this->room, $uid, $base . ' (Anki).txt', "#separator:tab\n#html:true\n#columns:Front\tBack\n" . implode("\n", $rows) . "\n"), 'anki');
			case 'quizlet':
				// Quizlet's import takes "term<tab>definition" lines; no formatting.
				$rows = array_map(fn (array $c) => implode("\t", array_map(fn (string $s) => str_replace(["\t", "\n"], ' ', Math::forChat(strip_tags($s))), [$c['front'], $c['back']])), $cards);
				return $this->post($this->conversation->saveFile($this->room, $uid, $base . ' (Quizlet).txt', implode("\n", $rows) . "\n"), 'quizlet');
			case 'csv':
				$out = fopen('php://temp', 'r+');
				fputcsv($out, ['Front', 'Back'], ',', '"', '');
				foreach ($cards as $c) {
					fputcsv($out, [Math::forChat($c['front']), Math::forChat($c['back'])], ',', '"', '');
				}
				rewind($out);
				return $this->post($this->conversation->saveFile($this->room, $uid, $base . '.csv', (string)stream_get_contents($out)), 'csv');
			case 'xlsx':
				$table = "## Cards\n| Front | Back |\n|---|---|\n" . implode("\n", array_map(fn (array $c) => '| ' . str_replace('|', '\|', $line(Math::forChat($c['front']))) . ' | ' . str_replace('|', '\|', $line(Math::forChat($c['back']))) . ' |', $cards));
				return $this->sendOdf($uid, $base, 'ods', $this->ods->write($table, $title), 'xlsx');
			default:
				return $this->sendOdf($uid, $base, 'odt', $this->odt->cards($cards, $title), $format);
		}
	}

	/** An OpenDocument file saved, converted when another format is wanted, and posted. */
	private function sendOdf(string $uid, string $base, string $kind, string $data, string $extension): string {
		$file = $this->conversation->saveFile($this->room, $uid, $base . '.' . $kind, $data);
		if ($extension !== $kind && isset(Office::TARGETS[$extension])) {
			$converted = $this->office->convert($file, $extension, $base . '.' . $extension);
			if ($converted === null) {
				$this->conversation->postFile($this->room, $file, $this->messageId);
				return 'Could not make a ' . strtoupper($extension) . ' (no Nextcloud Office on this server); sent "' . $file->getName() . '" instead. Tell the person.';
			}
			$file = $converted;
		}
		return $this->post($file, $extension);
	}

	private function readChat(string $uid, int $count): string {
		$lines = [];
		foreach ($this->conversation->history($this->room, $uid, max(1, min(500, $count))) as $m) {
			$lines[] = '[' . $m['time'] . '] ' . $m['actor'] . ($m['bot'] ? ' (you)' : '') . ': ' . mb_substr($m['text'], 0, 3000);
		}
		return implode("\n", $lines);
	}

	/** A chart as PNG (rendered by Collabora), or as SVG where the server cannot. */
	private function plot(string $uid, array $spec): string {
		$spec['degrees'] = self::flag($spec['degrees'] ?? false);
		$svg = Chart::svg($spec);
		$base = self::fileName((string)($spec['title'] ?? '') ?: 'Chart');
		$png = $this->office->png($svg, $base);
		return $png !== null
			? $this->post($this->conversation->saveFile($this->room, $uid, $base . '.png', $png), 'png')
			: $this->post($this->conversation->saveFile($this->room, $uid, $base . '.svg', $svg), 'svg');
	}

	/** A palette or harmonies, with a swatch image sent into the chat. */
	private function colors(string $uid, array $args): array {
		if (($args['action'] ?? '') === 'palette') {
			$file = $this->conversation->userFile($uid, (int)($args['file_id'] ?? 0));
			if ($file === null || !str_starts_with($file->getMimetype(), 'image/')) {
				return ['error' => 'No image with this id that the person may read.'];
			}
			$result = ['palette' => Colors::palette($file->getContent(), (int)($args['count'] ?? 6))];
			$swatches = $result['palette'];
			$title = 'Palette of ' . pathinfo($file->getName(), PATHINFO_FILENAME);
		} else {
			$result = Colors::harmonies((string)($args['color'] ?? ''));
			// The colour, its complement, and the analogous and triadic ones.
			$swatches = array_merge([$result['color']], $result['complementary'], $result['analogous'], $result['triadic']);
			$title = 'Harmonies of ' . $result['color']['hex'];
		}
		$base = self::fileName($title);
		$svg = Colors::swatches($swatches, $title);
		$png = $this->office->png($svg, $base);
		$result['swatches'] = $png !== null
			? $this->post($this->conversation->saveFile($this->room, $uid, $base . '.png', $png), 'png')
			: $this->post($this->conversation->saveFile($this->room, $uid, $base . '.svg', $svg), 'svg');
		return $result;
	}

	private function post(File $file, string $format): string {
		$this->conversation->postFile($this->room, $file, $this->messageId);
		$hint = match ($format) {
			'anki' => ' To import: in Anki, File → Import, choose this file.',
			'quizlet' => ' To import: in Quizlet, create a set, "Import", paste the file\'s text (tab between term and definition).',
			default => '',
		};
		return 'Sent "' . $file->getName() . '" to the chat. Say so briefly; do not repeat its content.' . ($hint !== '' ? ' Tell the person:' . $hint : '');
	}

	/** A flag as the model sends it: true, "true", 1 or "yes". */
	private static function flag(mixed $value): bool {
		return is_string($value) ? in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true) : (bool)$value;
	}

	/** A file name without path or characters that file systems refuse. */
	private static function fileName(string $name): string {
		$name = trim(preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#u', ' ', $name) ?? '', " .");
		return mb_substr($name !== '' ? $name : 'File', 0, 120);
	}

	/** AI-Hub's error codes in words. */
	public static function explain(string $error): string {
		return match ($error) {
			'not-ready' => 'the AI connection is not set up (AI-Hub, admin settings).',
			'not-allowed', 'refused' => 'you may not use the AI here.',
			'rate-limited', 'busy' => 'too many questions at once; try again in a minute.',
			default => $error,
		};
	}
}
