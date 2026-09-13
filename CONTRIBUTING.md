# Contributing to YoutubeBoost

Thanks for taking the time. This file is short on ceremony and specific about
the two or three things that actually matter here.

**Language.** Issues, pull requests, commit messages, code, class names and
comments are in **English**. The interface itself is translated — see
[Translations](#translations). Two documents stay in French because they are
internal design records, not contributor-facing: `docs/PRD.md` and
`docs/decisions-techniques.md`.

---

## Before you write code

- **A bug?** Open an issue with the steps, what you expected, and what happened.
  Logs from `docker compose logs app worker` help more than a description of the
  screen.
- **A feature?** Open an issue first. `docs/PRD.md` lists what is deliberately
  out of scope (titles, notifications, multi-channel, AI providers other than
  Gemini). If your idea is on that list, say why it should move off it — that is
  a fine conversation to have, but not one to discover at review time.
- **A security issue?** Do not open an issue. Read [SECURITY.md](SECURITY.md).

---

## Getting set up

You need Docker, Docker Compose, and the [Symfony CLI](https://symfony.com/download)
(version 5.20 or later, for the Language Tools check).

```bash
git clone https://github.com/yoanbernabeu/youtubeboost.git
cd youtubeboost
cp .env.example .env.local     # fill in what the README describes
make up                        # starts app, worker, database, tailwind
make db                        # runs the migrations
```

`make help` lists every target.

You do **not** need a real YouTube channel or a Gemini key to run the test
suite. You do need both to exercise the application by hand.

---

## The feedback loop

One command tells you whether your work holds:

```bash
make check
```

It runs, in order, stopping at the first failure:

| Step | What it proves |
|---|---|
| `make cs` | The code follows the `@Symfony` style. |
| `make stan` | PHPStan at max level finds no type or logic error. |
| `make lint` | Templates, YAML and the service container are valid. |
| `make lsp` | Symfony Language Tools resolves every route, service, template, Twig component and **translation key**. |
| `make test` | The whole suite passes: unit, integration, functional. |

**A change is not finished until `make check` is green.** If you need to fix
style, `make cs-fix` does it for you.

`make check` never touches your development database. The test environment
appends `_test` to the database name (`dbname_suffix` in
`config/packages/doctrine.yaml`), and `make db-test` refuses to drop a schema
whose database name does not end in `_test`.

---

## Translations

The interface ships in English and French. English is the default.

- Every string a user reads goes through a translation key. No hard-coded text
  in a template, a controller or a flash message.
- In PHP, use `TranslatableMessage` and let the view translate with `|trans`.
  Do not inject the translator into a business service. The two places that do
  — `VideoChartFactory` and `SyncPanel` — are commented on the spot, because the
  string leaves PHP inside a JSON payload where no Twig filter can reach it.
- A new key goes into **both** `translations/messages.en.yaml` and
  `translations/messages.fr.yaml`. `TranslationCatalogueTest` fails if the two
  files drift apart, and `make lsp` fails if a template uses a key that does not
  exist.
- Write the English first and make it read like English, not like translated
  French.

---

## Architecture, in one paragraph

`src/` is split by domain, not by layer: `Analysis`, `Catalog`, `Job`,
`Relaunch`, `Scoring`, `Security`, `Settings`, `Shared`, `Thumbnail`, `YouTube`.
Business modules depend on `Settings` and `Shared`, never the other way round;
when two modules need to talk, they do it through a tagged interface. Anything
that costs quota or money runs in a Messenger message tracked by a `Job`, never
in a web request. [AGENTS.md](AGENTS.md) has the full conventions, and
`docs/decisions-techniques.md` records why the non-obvious choices were made.

---

## Tests

A feature is not done until a test exercises it the way a caller would.

- `tests/Unit` — pure logic. No database, no network. HTTP clients are faked
  with `MockHttpClient`.
- `tests/Integration` — a real PostgreSQL. The only place where the bulk-write
  `ON CONFLICT` and the quota counter's timezone can be verified.
- `tests/Functional` — real HTTP requests against the six screens.

Tests run in English, because that is the default locale. Assert on the English
wording.

---

## Commits and pull requests

Commit messages are English, imperative, and say what changed **for the person
using the tool** rather than which file moved:

```
Stop cutting off the end of long transcripts
Let a reference photo be deleted during onboarding
```

not `fix bug` or `update TranscriptDownloader.php`.

Keep a pull request to one subject. If you find a second thing to fix on the
way, that is a second pull request. Say in the description what you changed and
how you convinced yourself it works; if `make check` is green, say so.

Schema changes go through a migration (`make migration`), never through
`doctrine:schema:update` or hand-written SQL.

---

## Code of Conduct

Taking part in this project means agreeing to the
[Code of Conduct](CODE_OF_CONDUCT.md).
