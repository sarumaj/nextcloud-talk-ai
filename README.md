# AI in Talk

A bot for [Nextcloud Talk](https://apps.nextcloud.com/apps/spreed) that answers with AI: an
assistant for work and school in your conversations. It reads the files you share, writes
documents, slides, spreadsheets and flash cards, does maths and cites sources.

- **Reads what you share:** Word, Excel (every sheet), PowerPoint (with speaker notes),
  OpenDocument, old `.doc`/`.xls`/`.ppt`, PDF, Markdown, CSV and text, and images. Text in photos
  and scanned PDFs is read with OCR. It also finds your files by name, reads web pages, and
  reads further back in the conversation (summaries, minutes).
- **Writes files into the chat:** PDF, Word, Excel (with formulas), PowerPoint (with speaker
  notes), OpenDocument, Markdown, CSV, HTML, `.ics`, `.bib` and source code.
- **Designed documents:** themes (modern, classic, academic with numbered headings, minimal,
  playful), cover pages, a table of contents, page numbers, footnotes, callout boxes (note,
  tip, warning, definition, theorem, example …), striped tables, columns, LaTeX formulas,
  highlighted code (Python, Java, SQL, pseudocode …), and charts and images embedded in
  documents, slides and chat answers. Presentations get an agenda, section slides and slide
  numbers.
- **Learning:** summaries, quizzes, and flash cards, to print (question and answer side by side,
  to fold) or to import into Anki or Quizlet.
- **Maths:** solves step by step and calculates exactly (a calculator tool, not the model's
  guess), with statistics and linear regression. Formulas in LaTeX become real formulas in
  documents and readable Unicode in the chat (x², √, ∑). Graphs of functions and line, bar,
  scatter and pie charts arrive as images.
- **Sources:** searches the web, looks things up in Wikipedia and finds scholarly papers
  (OpenAlex), and cites by DOI, ISBN or URL in APA, MLA, Chicago, Harvard, IEEE, Vancouver,
  DIN 1505-2, ISO 690 or any CSL style, also as BibTeX or RIS.
- **Subjects, computed rather than guessed:** maths (curve sketching: zeros, extrema,
  inflection points, integrals; linear systems, matrices, vectors, lines and planes, exact with
  fractions), chemistry (molar masses, balancing equations, elements), physics (units and
  constants), biology (crosses with Punnett squares, DNA to protein), statistics (probability
  distributions, t- and χ² tests), geography (places, distances, climate diagrams), music
  (scales, chords, intervals, transposition), economics (interest, loans with repayment
  schedules, break-even), physical education (heart-rate zones, pace, race times, BMI,
  calories), informatics (truth tables, encodings, SQL on example tables), history (timelines),
  and mind maps for any topic.
- **Art:** the palette of a painting or photo, colour harmonies, colour names (pigments too),
  value studies (grayscale, notan), composition grids (thirds, golden ratio), mirrored and
  blurred views, and help with exhibition texts and statements.
- **Planning:** reads and adds events and tasks in your calendar, with reminders and repeats
  (exams, deadlines, exhibitions, a timetable, a study plan), and turns dates from a PDF, a
  letter or a photo of a timetable into an `.ics` file to import anywhere.
- **Writing:** feedback as a document with marked corrections (deleted, inserted, highlighted).
- **Uses your rights:** the bot sees only what the asking person may see, and the files it sends
  are saved in their folder for the conversation.

It answers through [AI-Hub](https://apps.nextcloud.com/apps/ai_hub), your server's connection to
an AI (Claude, Gemini, OpenAI-compatible, or their command line tools), and is named after it:
*Claude*, *Gemini*, *ChatGPT*, …

## Requirements

- Nextcloud 32 to 36, with Talk 25 or later and AI-Hub set up (Admin settings → AI-Hub).
- For Word, Excel, PowerPoint and PDF files it writes, for charts, and to read old Office files:
  Nextcloud Office (Collabora). Without it, it sends OpenDocument files and SVG charts.
- To read PDFs, `pdftotext`; for scanned PDFs also `pdftoppm` (Poppler,
  `apt install poppler-utils`).
- For OCR, Tesseract with the languages you need (`apt install tesseract-ocr tesseract-ocr-deu`).
- PHP with the `zip` extension. The web (links, citations) is reached through Nextcloud's HTTP
  client.

## Installing

1. Install **AI in Talk** from the app store (*Apps*, *Social & communication*). Or unpack a
   release's `talk_ai-X.Y.Z.tar.gz` into `custom_apps/` and run `occ app:enable talk_ai`.
   This installs the bot in Talk.
2. In a conversation, open the conversation settings, then *Bots*, and enable the bot.

The bot takes the name of the AI that answers, as AI-Hub is set up for this app, and is renamed
when that changes (within ten minutes, or at its next answer). For a fixed name:
`occ config:app:set talk_ai name --value Assistant`.

## Using it

- **In a conversation with only you,** the bot answers every message.
- **With more people,** it answers when you name it (`@Claude what does this say?`, or
  `Claude, …` at the start) or reply to one of its messages.
- It reacts with 👀 at once, then answers in a few seconds to a minute.

For example:

- Share a PDF and ask "@Claude summarize this for year 9".
- "Make 20 flash cards about the French Revolution for Anki."
- "Turn my notes into a presentation" (after sharing them), "as PowerPoint".
- "Solve x² − 5x + 6 = 0 step by step and plot it."
- "A budget for the class trip as an Excel file."
- "Cite 10.1038/nature14539 in APA and as BibTeX."
- "Balance KMnO4 + HCl → KCl + MnCl2 + H2O + Cl2."
- Share a painting and ask "@Claude what is its palette, and what would complement it?"
- "Put my maths exam on 12 November at 9:00 in my calendar, with a reminder the day before."
- "A mind map of the causes of the First World War."
- Share a photo of the school timetable and ask "make it an .ics file, weekly until the summer
  holidays".
- "Curve sketching of f(x) = x³ − 3x" or "solve 2x + y − z = 8, −3x − y + 2z = −11, −2x + y + 2z = −3".
- Share a painting and ask for "a value study in three tones and the thirds grid".
- Share a photo of the blackboard and ask "@Claude what does it say?"

## How it works

The app (`app/talk_ai/`) is PHP, with no build step.

- **Receiving:** Talk calls the bot in-process (`BotInvokeEvent`, an app bot). The listener
  (`lib/Listener/BotInvokeListener.php`) only decides whether to answer and reacts 👀. It hands
  the question to the app's process route as a request signed with the bot's secret, and doesn't
  wait. When that fails, a background job answers instead.
- **Answering:** `lib/Service/Reply.php` reads the conversation's recent messages through Talk's
  message parser, as the asking person sees them. It then asks AI-Hub, scenario `chat`, with
  web search and tools that run in PHP with that person's rights:
    - files: `list_files`, `read_file` (`lib/Document/TextExtractor.php`; old formats converted
      by Collabora, OCR by Tesseract), `find_files`;
    - writing: `send_file` (`lib/Document/OdtWriter.php`, `OdsWriter.php`, `OdpWriter.php`,
      converted to Word, Excel, PowerPoint or PDF by Nextcloud's file conversion) and
      `send_flashcards`;
    - maths: `calculate` (`Calculator.php`, a parser, no code is run), `statistics`, `plot`
      (`Chart.php`, SVG rendered to PNG by Collabora); LaTeX formulas become MathML formula
      objects in documents and Unicode in the chat (`Math.php`);
    - subjects: `convert_units` (`Units.php`), `chemistry` (`Chemistry.php`, balancing by the
      exact null space), `biology`, `sport`, `informatics` (SQL in an in-memory SQLite without
      files or recursion), `colors` (palettes by Imagick);
    - more subjects: `analyze_function` (`Analysis.php`), `linear_algebra` (`LinearAlgebra.php`,
      fractions throughout), `music`, `finance`, `geo` (OpenStreetMap's Nominatim), and
      `image_study` (Imagick);
    - planning: `calendar` (`lib/Service/Calendar.php`, through Nextcloud's calendar API, and
      `.ics` files with the time zone's definition, `lib/Document/Ics.php`);
    - context and the web: `read_chat`, `read_url`, `lookup` and `cite` (`lib/Service/Web.php`;
      Wikipedia, OpenAlex; DOIs are formatted by doi.org, books come from Open Library).
- **Posting:** the bot's messages and files are posted as the bot, as Talk's bot API does. A
  file is saved in the person's folder for the conversation and sent as Talk's file message,
  as Talk 25's attachments are. If that folder doesn't exist yet, it's created and shared like
  for a first attachment, without Talk's "shared a folder" message.
- **Name:** `lib/Service/Bot.php` installs the bot and renames it (Talk updates a bot installed
  again with the same address and secret) when the AI or the setting changes.

## Developing

```bash
composer install && vendor/bin/phpunit
```

The tests need neither Nextcloud nor network. CI lints the PHP, checks `appinfo/info.xml`
against the app store's schema and runs the tests. A tag `vX.Y.Z` with the same version in
`appinfo/info.xml` is released by `.forgejo/workflows/release.yml`, signed and published on the
app store, given the secrets `APP_SIGNING_KEY` and `APPSTORE_TOKEN`.

## Reporting problems

Open an issue on GitHub:
[sarumaj/nextcloud-talk-ai](https://github.com/sarumaj/nextcloud-talk-ai/issues).

## Licence

[AGPL-3.0-or-later](LICENSE).
