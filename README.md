# AI in Talk

A bot for [Nextcloud Talk](https://apps.nextcloud.com/apps/spreed) that answers with AI. It
reads the files you share in the conversation, searches the web, and sends files back, such as
a PDF it wrote for you.

- **Reads what you share:** images, text, Markdown, CSV, Word, ODT, Excel, PowerPoint and PDF
  files in the conversation.
- **Finds your files** by name, when you ask about one.
- **Sends files into the chat:** Markdown, text, CSV, HTML, JSON, ODT, or PDF.
- **Searches the web**, when the AI connection can.
- **Uses your rights:** the bot sees only what the asking person may see, and the files it sends
  are saved in their folder for the conversation.

It answers through [AI-Hub](https://apps.nextcloud.com/apps/ai_hub), your server's connection to
an AI (Claude, Gemini, OpenAI-compatible, or their command line tools).

## Requirements

- Nextcloud 32 to 36, with Talk 25 or later and AI-Hub set up (Admin settings → AI-Hub).
- To read PDFs, `pdftotext` (Poppler, `apt install poppler-utils`) on the server.
- To send PDFs, Collabora Online (Nextcloud Office): it converts the document the bot writes.
- PHP with the `zip` extension.

## Installing

1. Install **AI in Talk** from the app store (*Apps*, *Social & communication*). Or unpack a
   release's `talk_ai-X.Y.Z.tar.gz` into `custom_apps/` and run `occ app:enable talk_ai`.
   This installs the bot in Talk.
2. In a conversation, open the conversation settings, then *Bots*, and enable **AI**.

The bot's name is `AI`. For another name, set it before installing the app:
`occ config:app:set talk_ai name --value Claude`.

## Using it

- **In a conversation with only you,** the bot answers every message.
- **With more people,** it answers when you name it (`@AI what does this say?`, or `AI, …` at
  the start) or reply to one of its messages.
- It reacts with 👀 at once, then answers in a few seconds to a minute.

For example:

- Share a PDF and ask "@AI summarize this".
- "Make me a packing list for the trip as a PDF."
- "What's the weather in Berlin this weekend?"
- Share a photo of a receipt and ask "@AI what did we pay for the bread?"

Images you shared since the bot last answered go with your question. Files shared earlier in
the conversation, and your own files, the bot reads when it needs them.

## How it works

The app (`app/talk_ai/`) is PHP, with no build step.

- **Receiving:** Talk calls the bot in-process (`BotInvokeEvent`, an app bot). The listener
  (`lib/Listener/BotInvokeListener.php`) only decides whether to answer and reacts 👀. It hands
  the question to the app's process route as a request signed with the bot's secret, and doesn't
  wait. When that fails, a background job answers instead.
- **Answering:** `lib/Service/Reply.php` reads the conversation's recent messages through Talk's
  message parser, as the asking person sees them. It then asks AI-Hub, scenario `chat`, with
  web search and four tools that run in PHP with that person's rights:
    - `list_files` lists the files shared in the conversation;
    - `read_file` reads one (`lib/Document/TextExtractor.php`);
    - `find_files` finds the person's files by name;
    - `send_file` writes Markdown, text, CSV, HTML or JSON as is, and ODT from Markdown
      (`lib/Document/OdtWriter.php`). For a PDF it writes the ODT and converts it with
      Nextcloud's file conversion (Collabora).
- **Posting:** the bot's messages and files are posted as the bot, as Talk's bot API does. A
  file is saved in the person's folder for the conversation and sent as Talk's file message,
  as Talk 25's attachments are. If that folder doesn't exist yet, it's created and shared like
  for a first attachment, without Talk's "shared a folder" message.

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
