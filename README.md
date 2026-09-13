<div align="center">

# YoutubeBoost

**Give the videos in your back catalogue a second life.**

YoutubeBoost spots the videos that are fading, works out what they are about,
generates five new thumbnails with your face on them, pushes them to YouTube in
one click and tells you 28 days later whether it worked.

Self-hosted · single user · MIT

</div>

---

> [!WARNING]
> **Experimental, and meant to run on `localhost` for now.**
>
> This is version 0.1.0. It does the job on a real channel, but it has not been
> hardened for the open internet.
>
> The whole instance sits behind **one shared password** read from an environment
> variable: no accounts, no second factor, no audit trail. Behind that password
> are your Google refresh token and write access to your channel's thumbnails.
> Sign-in attempts are rate limited and the token is encrypted at rest with
> `APP_SECRET`, but that is the whole of the defence.
>
> Run it with `SERVER_NAME=localhost`, on your own machine or inside a private
> network you control. Exposing it on a public domain is not supported yet.

---

## What it does

An older video sees its daily views drop. Often YouTube is still serving it:
it is the viewers who have stopped clicking. Changing the thumbnail is
sometimes enough to bring it back.

YoutubeBoost automates the tedious part of that work.

| Step | What happens | Trigger |
|---|---|---|
| **Spot** | A score from 0 to 100 per video, from four weighted signals taken from your YouTube statistics. | automatic after every sync |
| **Understand** | The video transcript is downloaded, then read by Gemini, which produces a summary and five thumbnail angles. | one click, per video |
| **Propose** | Five thumbnails generated from your reference photos, with the overlay text burned in. Each one can be retouched with a plain-language instruction. | in the background |
| **Apply** | The old thumbnail is archived, the new one is pushed to YouTube. One click to roll back. | one click |
| **Measure** | Before / after comparison at 14 and 28 days, a clear verdict, and a suggestion to revert if the result is negative. | automatic after every sync |

Nothing expensive runs without an explicit click, and the cost in quota units
is written on every button that spends them.

---

## Requirements

- Docker and Docker Compose.
- A Google Cloud project, for the YouTube APIs.
- A Google AI Studio API key, for Gemini. **Image generation is billed**: plan
  on enabling billing on the Google project.
- Three photos of yourself: front, right profile, left profile.

Allow thirty minutes for the first install, twenty-five of which are in the
Google Cloud console.

---

## Installation

Two ways in. Both end up at the same place; pick the one that matches how much
you want to think about it.

### The short way: one file, one command

Nothing to clone, nothing to build. Docker pulls the published image.

```bash
mkdir youtubeboost && cd youtubeboost
curl -o compose.yaml https://raw.githubusercontent.com/yoanbernabeu/youtubeboost/main/compose.standalone.yaml
curl -o .env.local   https://raw.githubusercontent.com/yoanbernabeu/youtubeboost/main/.env.example

# Fill in the six values described below
$EDITOR .env.local

docker compose up -d
```

That is the whole install. Open <https://localhost>, and read
[Local certificate](#local-certificate) if the browser complains.

To pin a version instead of following every release, add `VERSION=v0.1.0` to
`.env.local`.

### The full way: clone the repository

Choose this one if you want to read the code, change it, or run the tests. It
builds the image locally instead of pulling it, and it gives you `make`.

```bash
git clone https://github.com/yoanbernabeu/youtubeboost.git
cd youtubeboost
cp .env.example .env.local     # fill in the variables below
make start
```

`make start` builds the image, starts the three containers, waits until they are
healthy and prints the address to open. `make help` lists everything else.

| Command | What it does |
|---|---|
| `make start` | Starts the instance, building the image if needed. |
| `make stop` | Stops it. |
| `make restart` | Restarts it **after you edit `.env.local`** — see the note below. |
| `make status` | Shows the state of the containers. |
| `make logs` | Follows the application logs. `make worker-logs` for the background worker. |
| `make update` | Pulls the latest version and restarts. |
| `make backup` | Writes a database dump and an image archive into `./backups`. |
| `make trust-cert` | Makes your system accept the local certificate. |

In both cases the application container applies the database migrations at
startup: there is no command to run for that.

Keep `SERVER_NAME=localhost` for now. Everything below works the same with a
real domain, but read the warning at the top of this file before putting the
instance on one.

### Environment variables

Six values are all that matter; the rest of `.env.local` has working defaults.

| Variable | Purpose |
|---|---|
| `APP_PASSWORD` | The single password protecting the instance. Pick a long one. |
| `APP_SECRET` | Random string. It encrypts the Google token in the database: **never change it** after the first connection, or you will have to reconnect the channel. |
| `POSTGRES_PASSWORD` | Database password. Change it, in both lines where it appears. |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | OAuth credentials from the Google Cloud project. |
| `GEMINI_API_KEY` | Google AI Studio key. |

And the ones you can usually leave alone:

| Variable | Purpose |
|---|---|
| `SERVER_NAME` | The name served. Keep `localhost`, which makes Caddy sign a local certificate. A real domain works, but read the warning at the top first. |
| `GOOGLE_REDIRECT_URI` | `https://localhost/oauth/callback`, character for character, and the same string registered in the Google console. |
| `GEMINI_TEXT_MODEL` | Text model. Default: `gemini-3.1-flash-lite`. |
| `GEMINI_IMAGE_MODEL` | Image model accepting several references. Default: `gemini-3.1-flash-image`. |
| `ANALYTICS_REQUEST_DELAY_MS` | Pause between two Analytics requests during a sync. Default: 250 ms. |

Generate a secret with `openssl rand -hex 32`.

> **Worth remembering.** Docker Compose reads `.env.local` **when it creates a
> container**. After editing that file, `docker compose restart` therefore
> changes nothing: the containers have to be recreated. `make restart` does it,
> or by hand:
>
> ```bash
> docker compose stop
> docker compose up -d --force-recreate --wait
> ```

### Local certificate

The browser will say the connection is not private. That is expected, and it is
worth thirty seconds to fix properly.

FrankenPHP is built on Caddy, which arranges HTTPS on its own. On a real domain
it fetches a Let's Encrypt certificate. On `localhost` that is impossible — no
public authority signs for `localhost` — so Caddy creates its **own** certificate
authority inside the container and signs a certificate for `localhost` with it.
Your browser has never heard of that authority, hence the warning.

The fix is to tell your system to trust it:

```bash
make trust-cert
```

Or, without the repository:

```bash
# Copy the public half of Caddy's authority out of the container
docker compose cp app:/data/caddy/pki/authorities/local/root.crt /tmp/youtubeboost-root.crt

# macOS
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain /tmp/youtubeboost-root.crt
# Linux
sudo cp /tmp/youtubeboost-root.crt /usr/local/share/ca-certificates/ && sudo update-ca-certificates
```

Restart the browser afterwards so it re-reads the store.

Two things to know before you run it:

- **Trusting a root authority is not a small thing.** An authority your system
  trusts can sign a certificate for *any* hostname, not just `localhost`. Its
  private key stays inside the `caddy_data` Docker volume, on your own machine,
  so the blast radius is your machine — but it is not a no-op.
- **It is tied to that volume.** `docker compose down -v` destroys `caddy_data`,
  Caddy generates a fresh authority on the next start, and the one you approved
  becomes a stale root sitting in your store. You would have to import the new
  one, and ideally remove the old.

You can also just click through the warning every time. It costs nothing but
patience.

---

## Setting up Google Cloud

### 1. The project and the APIs

1. Create a project on [console.cloud.google.com](https://console.cloud.google.com/).
2. Enable **YouTube Data API v3**, **YouTube Analytics API** and
   **YouTube Reporting API**.

### 2. The OAuth consent screen

1. User type: **Internal** if your account belongs to a Google Workspace,
   **External** otherwise.
2. Add your own address as a test user, then **publish the application**
   (status "In production").

> **The trap to know about.** An application left in "Testing" status makes the
> Google authorization **expire after seven days**. You would then have to
> reconnect the channel every week. Moving the application to "In production"
> is enough to fix it: review by Google is not required. The "Google hasn't
> verified this app" screen is normal; click "Advanced" then "Continue".

### 3. The credentials

1. **Credentials → Create → OAuth client ID → Web application**.
2. Authorized redirect URI: exactly the value of `GOOGLE_REDIRECT_URI` —
   `https://localhost/oauth/callback` with the shipped defaults. Google accepts
   a localhost URI on a web client, so there is nothing to publish to try the
   tool out.
3. Copy the client ID and the secret into `.env.local`.

### 4. The Gemini key

Create a key on [aistudio.google.com](https://aistudio.google.com/apikey) and
enable billing on the project: the image models are not free.

---

## First run

Open the application, enter `APP_PASSWORD`, and follow the guide:

1. **Connect the channel.** The button sends you to Google. On the way back,
   YoutubeBoost immediately creates the thumbnail impressions report.
2. **Upload the reference photos.** One front, one right profile, one left
   profile, at minimum. Sharp face, well lit, no sunglasses. More angles and
   more expressions improve the result.
3. **Write the style instructions.** Palette, typography, recurring elements,
   things to avoid. This text goes into every prompt: the more precise it is,
   the more the thumbnails look like you.

Then, from the catalogue, click **Sync**. The first sync of a three hundred
video catalogue costs around twenty quota units and takes a few minutes, the
time needed to fetch the day-by-day history.

---

## Interface language

The interface is available in English and in French. English is the default.
The language selector sits in the sidebar, and the choice is stored in the
database, so it sticks across restarts.

The same setting decides the language of the words burned into the generated
thumbnails. The instructions sent to Gemini stay in English, because that is
what the model follows best, but the two to four words your audience reads are
written in the language the application is set to. Switching language changes
the thumbnails generated from then on, not the ones already produced.

---

## How the score is calculated

A video is eligible if it was published at least 60 days ago, if it is neither
a Short nor a live stream nor a private video, and if it is not already being
tracked. A video that is not eligible scores zero and stays hidden.

Four signals, each normalised between 0 and 1:

| Signal | What it measures | Default weight |
|---|---|---|
| **Decline** | Current views/day compared with the video's best period, release spike excluded. | 30 |
| **Low CTR** | Click-through rate compared with the channel median. | 30 |
| **Impressions** | Is YouTube still serving it? A new thumbnail only has an effect if it is seen. | 15 |
| **Potential** | Cumulative audience (logarithmic scale) and retention, relative to the channel. | 25 |

`score = 100 × Σ(weight × signal) ÷ Σ(weight)`, computed **only over the
signals that have data**. A video with no impression data is not penalised: the
signal is dropped from the calculation, and the score is flagged as incomplete
in the interface.

Every weight and every threshold is configurable under **Settings**.

---

## Quota and costs

The YouTube Data API grants 10,000 units a day, reset at midnight Pacific time.
The counter is shown permanently at the top of the screen.

| Operation | Cost |
|---|---|
| Sync of about 300 videos | ~20 units |
| Analysis of one video | 250 units |
| Applying or reverting a thumbnail | 50 units |

A typical session — one sync, three analyses, three applications — costs about
920 units, under 10% of the daily quota. The Analytics API does not spend these
units, it only rate-limits requests.

On the Gemini side, one analysis costs one text call (a few cents at most) and
five image generations. The price depends on the model chosen in the settings.

---

## Things the YouTube API imposes

These constraints come from Google, not from the tool. They are documented here
so that the behaviour of the application does not come as a surprise.

**Analytics data lags by two to three days.** The interface always shows the
date of the last available day, and calculations stop at that date instead of
inventing zeros.

**Impressions and CTR do not come from the Analytics API.** Google has exposed
them since January 2026 through the Reporting API, as daily reports that only
start accumulating from the moment the job is created. YoutubeBoost creates
that job as soon as the channel is connected, but **no impression data exists
before that date**. The two signals concerned therefore stay neutral for the
first few weeks, without skewing the score.

**Automatic captions are often refused to third-party applications.** When that
happens, the analysis is done from the title, the description and the
statistics, and the interface says so clearly. Captions you uploaded yourself
download normally.

**Nothing identifies a Short in the API.** YoutubeBoost uses the
`creatorContentType` dimension of the Analytics API, which is YouTube's own
classification. For videos with no data, it falls back to duration (180 seconds
maximum since October 2024). The type can be corrected by hand on a video's
page, and that choice wins over any automatic detection.

**Thumbnails are limited to 2 MB.** Images are delivered as 1280 × 720 PNG; if
the PNG exceeds the limit, the upload switches automatically to JPEG to avoid a
rejection that would cost 50 units for nothing.

---

## Running the instance

From the cloned repository, everything is a `make` target:

```bash
make status       # state of the containers
make logs         # application logs, Ctrl-C to stop following
make worker-logs  # the background worker, where analyses and generations run
make restart      # after editing .env.local
make update       # pull the latest version and restart
make backup       # database + images into ./backups
make stop
```

`make help` prints the full list.

Without the repository, the same things with plain Compose:

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f worker
docker compose pull && docker compose up -d --wait     # update
docker compose stop && docker compose up -d --force-recreate --wait
```

### Backups

`make backup` writes two timestamped files into `./backups`: a compressed
database dump, and an archive of the images.

**Keep both.** The database holds the scores, the analyses and the relaunch
history; the images are the generated thumbnails, your reference photos and —
crucially — the **archived original thumbnails**. Without those, rolling a
relaunch back is impossible.

By hand, if you are not using the repository:

```bash
docker compose exec -T database pg_dump -U app app | gzip > backup.sql.gz
docker compose exec -T app tar czf - -C /app/var/share --exclude='./*/pools' . > images.tar.gz
```

### When the YouTube connection expires

The interface shows a message and a reconnect button. Go to **Settings →
YouTube channel → Reconnect**. If this happens every week, it means the OAuth
application is still in "Testing" status.

---

## Development

```bash
make up        # start the development stack, with the profiler and hot reload
make reload    # reload .env.local after editing it
make check     # everything below, in one command
make help      # list every target
```

The project is written in Symfony 8.1 on PHP 8.4, served by FrankenPHP in
worker mode. Code and comments are in English; the interface is translated,
English by default.

### Layout

```
src/
  Analysis/    transcript, reading by Gemini, thumbnail angles
  Catalog/     videos, daily statistics, synchronisation
  Job/         tracking of background operations
  Relaunch/    apply, archive, roll back, verdicts
  Scoring/     signals, weights, score
  Security/    single password
  Settings/    settings, reference photos, onboarding
  Shared/      image, storage, maths, dates
  Thumbnail/   image generation, proposals, iterations
  YouTube/     OAuth, clients for the three Google APIs, quota
```

Long operations are Messenger messages consumed by the `worker` container:
`SynchronizeCatalog`, `AnalyzeVideo`, `GenerateThumbnail`, `IterateThumbnail`,
`ApplyThumbnail`, `RevertThumbnail`.

### Checking your work

`make check` is the one command that says whether a change holds. It stops at
the first failure:

| Step | What it proves |
|---|---|
| `make cs` | The code follows the `@Symfony` style. `make cs-fix` fixes it. |
| `make stan` | PHPStan at max level finds no type or logic error. |
| `make lint` | Templates, YAML and the service container are valid. |
| `make lsp` | Symfony Language Tools resolves every route, service, template, Twig component and translation key. Needs the [Symfony CLI](https://symfony.com/download) 5.20+. |
| `make test` | The whole suite: unit, integration on PostgreSQL, functional HTTP. |

`make test-unit` gives a two-second answer on pure logic, but it does not
replace `make check` before calling something done.

Integration tests run against a real PostgreSQL, never SQLite: the `ON CONFLICT`
of bulk writes and the time zone of the quota counter cannot be verified any
other way. None of this touches your development database — the test
environment appends `_test` to the database name, and `make db-test` refuses to
drop a schema whose database is not named that way.

---

## What the tool does not do

Deliberately out of scope: title suggestions, editing descriptions and tags,
multi-channel support, notifications, scheduled synchronisation, YouTube's
native A/B test (not exposed by the API) and AI providers other than Gemini.

A video title is **never** modified: one lever at a time, otherwise the verdict
means nothing.

---

## Contributing

Bug reports and pull requests are welcome. [CONTRIBUTING.md](CONTRIBUTING.md)
covers the setup, the single command that checks your work (`make check`), and
the rule about translations. Taking part means agreeing to the
[Code of Conduct](CODE_OF_CONDUCT.md).

Found a security issue? Do not open an issue — [SECURITY.md](SECURITY.md)
explains how to report it privately.

---

## License

MIT. See [LICENSE](LICENSE).
