# कथामृत — Hindi spiritual story platform

A Hindi-first web application for reading and listening to stories from the Sanatan tradition —
Bhagavad Gita, Ramayana, Mahabharata, Puranas, saints, vrat kathas and festivals — with honest
source references, locally generated Hindi narration, personal libraries and fully voluntary UPI support.

Everything runs as **one Symfony application on one server**: PHP + MySQL + the local filesystem.
No Redis, RabbitMQ, Elasticsearch, S3, Docker or Node.js backend is needed.

---

## Contents

1. [Features](#features)
2. [Architecture](#architecture)
3. [Requirements](#requirements)
4. [Local installation](#local-installation)
5. [Configuration reference](#configuration-reference)
6. [Users, roles and the first admin](#users-roles-and-the-first-admin)
7. [Editorial workflow and source verification](#editorial-workflow-and-source-verification)
8. [Audio narration (TTS + FFmpeg)](#audio-narration-tts--ffmpeg)
9. [Background jobs: worker, scheduler, cron](#background-jobs-worker-scheduler-cron)
10. [Storage, media and backups](#storage-media-and-backups)
11. [Facebook publishing](#facebook-publishing)
12. [Voluntary UPI support](#voluntary-upi-support)
13. [Search, analytics, popularity, caching](#search-analytics-popularity-caching)
14. [SEO](#seo)
15. [Security](#security)
16. [Tests and quality checks](#tests-and-quality-checks)
17. [Production deployment](#production-deployment)
18. [Troubleshooting](#troubleshooting)
19. [Project structure](#project-structure)
20. [Content and ethics rules](#content-and-ethics-rules)

---

## Features

**Readers**
- Homepage driven entirely by the database: story of the day, featured, popular (recent activity), recent,
  short listens, upcoming festival, category highlights — with graceful fallbacks when content is thin.
- Story pages with comfortable Devanagari typography, reading progress, source box, series navigation,
  related stories, share buttons, JSON-LD and OpenGraph.
- Custom audio player: play/pause, seek bar, ±10 s, speed 0.75×–2×, volume/mute, keyboard shortcuts
  (Space/K, J, L), lock-screen/notification controls (Media Session) and a persistent mini-player that keeps
  playing while navigating (Turbo). Listening position is saved (server-side for members, locally for guests).
- Accounts (register, email verification, login, password reset, profile, password change, account deletion),
  bookmarks, collections (private / unlisted / public), reading and listening history with "resume".
- MySQL FULLTEXT search with suggestions and filters (category, deity, scripture, festival, tag, source type,
  audio, duration).
- Taxonomy pages: categories, tags, deities, scriptures (granth), series, festivals.

**Editors and admins** (`/admin`)
- Story editor with a sanitised rich-text editor, live slug preview (Devanagari → readable ASCII), search-result preview,
  source references, cover picker, revisions with restore, preview of drafts.
- Explicit editorial workflow with a reviewer-only **स्रोत सत्यापित (Source Verified)** badge that is reset
  automatically when content or sources change.
- Narration: pronunciation dictionary, generation queue, retries, failure reasons, generation history.
- Taxonomy, pages (legal pages included), media library, users and roles, collections moderation,
  contact messages, notifications, audit log, analytics with CSV export, SEO tools, settings,
  system health and failed-job management.
- Facebook Page publishing (scheduled, retried, token never exposed) and optional audiograms.
- Manual verification of declared UPI contributions.

---

## Architecture

| Concern | Implementation |
|---|---|
| Framework | Symfony 7.4 LTS, Twig, Forms, Validator, Security (voters), UX Turbo + Stimulus + LiveComponent, AssetMapper (no Node build) |
| Database | MySQL 8 (MariaDB 10.4+ works for development) via Doctrine ORM 3 + Migrations |
| Background work | Messenger with the **Doctrine transport** (`messenger_messages` table) + Symfony Scheduler; `app:cron:run` as a cron fallback; Symfony Lock (`flock`) prevents overlaps |
| Files | Local disks: `public/media` (covers, QR codes, audiograms) and `var/storage/private` (narration MP3s, served through a controller with HTTP Range support) |
| Narration | Pluggable TTS (`none` / `local` command-line engine / `http` endpoint) + FFmpeg via Symfony Process |
| Search | MySQL `FULLTEXT` indexes (boolean mode) with a `LIKE` fallback |
| Cache | Filesystem cache with tags (`cache.content`) for homepage blocks, menus and settings |
| Analytics | First-party, cookie-less daily counters per story (`daily_story_metric`) |
| Mail | Symfony Mailer (any SMTP; `null://null` disables sending) |

Request flow for a story page: controller → `StoryPageDataBuilder` (one query for related cards, cached
taxonomy/navigation) → Twig. Writes go through services (`StoryEditorService`, `StoryWorkflowService`,
`DonationService`, …) that are transactional and audited.

---

## Requirements

- **PHP 8.3+ recommended** (8.2 is the minimum) with extensions: `ctype`, `iconv`, `intl`, `mbstring`,
  `pdo_mysql`, `fileinfo`, `gd`, `xml`; `opcache` in production.
- **MySQL 8.0+** (production) or MariaDB 10.4+ (development), `utf8mb4`.
- **Composer 2**.
- A web server: Apache 2.4 (mod_rewrite) or Nginx + PHP-FPM. `php -S` works for development.
- Optional, for narration: **FFmpeg + ffprobe** and a Hindi TTS engine (e.g. espeak-ng or Piper) or an HTTP TTS service.
- Cron (always) and, ideally, systemd or Supervisor for the worker.

Recommended `php.ini`: `memory_limit=256M`, `upload_max_filesize=10M`, `post_max_size=12M`,
`max_execution_time=60`, `opcache.enable=1`, `opcache.validate_timestamps=0` (prod), `date.timezone=UTC`.

---

## Local installation

```bash
git clone <repository> katha && cd katha
composer install

# 1. Database credentials (never commit .env.local)
cat > .env.local <<'ENV'
DATABASE_URL="mysql://root:@127.0.0.1:3306/ramkatha?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
UPI_ID=demo@upi
ENV

# 2. Database
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction

# 3. Directories, starter legal pages, pronunciation examples, social defaults (idempotent)
php bin/console app:setup

# 4. EITHER your own admin account …
php bin/console app:create-admin you@example.org --name="Your Name"
#    … OR the demo content (local development only — this WIPES the database first)
php bin/console doctrine:fixtures:load

# 5. Front-end vendor files (AssetMapper; downloads into assets/vendor once)
php bin/console importmap:install

# 6. Run
php -S 127.0.0.1:8000 -t public bin/dev-router.php      # or: symfony serve
```

Open http://127.0.0.1:8000 and http://127.0.0.1:8000/admin.

### Demo accounts (fixtures only)

All demo accounts use the password **`Demo@12345`**. They exist only after loading fixtures and must never
be used outside a local machine.

| Email | Role |
|---|---|
| superadmin@example.test | Super admin |
| admin@example.test | Admin |
| editor@example.test | Editor |
| reviewer@example.test | Reviewer |
| author@example.test | Author |
| user@example.test | Reader (bookmarks, collections, history) |
| reader2@example.test | Reader, email not verified |
| deactivated@example.test | Deactivated (login refused) |

Demo stories are clearly labelled **डेमो सामग्री (Demo Content)**, tagged `demo-content`, and are short
summaries — not translations of any scripture. Demo narration is silence (provider `demo-silence`).
Fixtures are a development dependency: a production installation (`composer install --no-dev`) starts empty
and can never load them.

### XAMPP note

If the project lives inside `htdocs`, the root `.htaccess` blocks everything outside `public/`
(`.env.local`, `var/`, `vendor/`, …). Point a virtual host at `public/` for a clean setup.

---

## Configuration reference

Environment variables live in `.env` (documented defaults) and are overridden in `.env.local`
or real environment variables. Production template: [`docs/deploy/env.prod.example`](docs/deploy/env.prod.example).

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_SECRET` | `prod` + a long random secret in production |
| `SITE_URL` | Canonical base URL (no trailing slash) used for canonical links, sitemaps, OpenGraph, e-mails, Facebook links |
| `DEFAULT_URI` | Base URL for URLs generated in CLI commands (set to `SITE_URL`) |
| `APP_TIMEZONE` | Display timezone (default `Asia/Kolkata`). Everything is stored in UTC |
| `DATABASE_URL` | MySQL DSN with `serverVersion` and `charset=utf8mb4` |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` (queue table created by the migration) |
| `LOCK_DSN` | `flock` (single server) |
| `MAILER_DSN`, `MAILER_FROM`, `MAILER_FROM_NAME` | Outgoing mail (`null://null` disables it; the UI then says so) |
| `APP_MEDIA_DIR`, `APP_MEDIA_URL_PREFIX` | Public media disk (default `public/media` served at `/media`) |
| `APP_STORAGE_DIR` | Private storage root (default `var/storage`; never web-accessible) |
| `APP_X_SENDFILE` | `1` lets Apache mod_xsendfile / nginx X-Accel stream narration files |
| `AUDIT_LOG_STORE_IP` | `0` stops storing client IPs in the audit log |
| `TRUSTED_PROXIES` | Only behind a reverse proxy / load balancer |
| `TTS_*`, `FFMPEG_BINARY`, `FFPROBE_BINARY`, `AUDIO_BITRATE`, `AUDIOGRAM_ENABLED` | See [Audio narration](#audio-narration-tts--ffmpeg) |
| `FACEBOOK_*` | See [Facebook publishing](#facebook-publishing) |
| `UPI_ID`, `UPI_PAYEE_NAME` | Defaults for voluntary support (admin settings take precedence) |

Secrets (`APP_SECRET`, database password, `FACEBOOK_ACCESS_TOKEN`, `TTS_HTTP_API_KEY`, SMTP password) belong
in `.env.local` on the server or in the Symfony vault (`php bin/console secrets:set NAME --env=prod`).
They are never stored in the database or rendered in HTML.

**Admin → Settings** (super admin) holds the non-secret, editable configuration: site name, tagline, logo,
favicon, default share image, social profile links, SEO defaults, content disclaimer, listing sizes,
completion threshold, feature switches (registration, public collections, analytics, automatic narration,
donations), UPI ID / payee name / presets / limits / messages, and failure e-mails.

---

## Users, roles and the first admin

| Role | Can |
|---|---|
| `ROLE_USER` | Read, listen, bookmark, collections, history, contribute |
| `ROLE_AUTHOR` | Create stories; edit/preview/submit **own drafts** |
| `ROLE_REVIEWER` | Preview everything, **verify sources**, send back to draft |
| `ROLE_EDITOR` | Edit any story, taxonomy, pages, media, pronunciation, narration; approve, schedule, publish |
| `ROLE_ADMIN` | Editor + Reviewer + users, donations, collections, messages, social, analytics, audit log, archive |
| `ROLE_SUPER_ADMIN` | Everything, including settings, roles and system tools |

```bash
php bin/console app:create-admin admin@example.org --name="Admin" --role=ROLE_SUPER_ADMIN
# non-interactive (CI / provisioning): read the password from an environment variable
ADMIN_PASSWORD='…' php bin/console app:create-admin admin@example.org --password-env=ADMIN_PASSWORD
```

Authorisation is enforced by voters (`StoryVoter`, `CollectionVoter`, `DonationVoter`, `UserManagementVoter`),
never by hidden form fields. Deactivating a user (or changing their password or roles) ends their
existing sessions on the next request.

---

## Editorial workflow and source verification

```
draft → under_review → source_verified → (audio_pending → audio_generated) → approved → scheduled → published
                ↑______________ request changes ______________|                       archived ↔ draft
```

- Transitions are an explicit table (`App\Enum\StoryTransition`) checked by `StoryWorkflowService` and
  permissioned per role by `StoryVoter`. Invalid jumps (e.g. draft → published) are refused.
- **Source Verified** can only be granted by a reviewer through the workflow. Any change to the title,
  text, source classification, source notes, references or scripture of a verified story resets
  verification automatically; the public badge disappears until a reviewer verifies again.
- Every story declares an honest **source classification** (direct translation, summary, traditional
  narrative, commentary, …). Classifications that require a reference cannot be submitted without one.
- A revision (with diff summary) is recorded whenever content changes; changed slugs keep a permanent **301**
  from the old URL. Edits to title, subtitle or text also mark existing narration as outdated.
- Scheduled stories are published by the scheduler/cron (idempotent, locked).

---

## Audio narration (TTS + FFmpeg)

Pipeline (`AudioGenerationService`):

```
story HTML → plain text → punctuation normalisation → pronunciation dictionary → chunks (≤ TTS_CHUNK_MAX_CHARS)
→ TTS per chunk → validation → FFmpeg concat + loudness normalisation → MP3 → ffprobe duration
→ var/storage/private/audio/YYYY/MM/<uuid>.mp3 → story updated → temp files removed
```

Nothing is faked: if TTS or FFmpeg is missing, the job fails with **"Feature is not configured: …"**, the
reason is shown in the story editor and on Admin → Audio, and existing audio keeps playing.

### Providers (`TTS_PROVIDER`)

| Value | Engine |
|---|---|
| `none` | Default. Narration disabled with a clear message |
| `local` | Any command-line engine run through Symfony Process (no shell): `TTS_BINARY_PATH` + `TTS_BINARY_ARGS` |
| `http` | `POST TTS_HTTP_ENDPOINT` with JSON `{text, voice, language, format}`, optional `Authorization: Bearer TTS_HTTP_API_KEY`; must answer with audio bytes (`audio/*`) |

`TTS_BINARY_ARGS` placeholders: `{input}` (text file), `{output}`, `{voice}`, `{language}`. Without `{input}`
the text is written to the engine's standard input. Paths must not contain spaces.

**espeak-ng** (robotic but fully offline, available in every distribution):

```dotenv
TTS_PROVIDER=local
TTS_BINARY_PATH=/usr/bin/espeak-ng
TTS_BINARY_ARGS="-v {voice} -f {input} -w {output}"
TTS_VOICE=hi
TTS_OUTPUT_FORMAT=wav
```

**Piper** (natural neural voices, CPU-only): download the `piper` binary and a Hindi voice model
(`.onnx` + `.onnx.json`) from the Piper voices repository, then:

```dotenv
TTS_PROVIDER=local
TTS_BINARY_PATH=/opt/piper/piper
TTS_BINARY_ARGS="--model {voice} --output_file {output}"
TTS_VOICE=/opt/piper/voices/<hindi-voice>.onnx
TTS_OUTPUT_FORMAT=wav
```

Test an engine by hand as the web server user first, e.g.
`echo "नमस्ते" | sudo -u www-data /opt/piper/piper --model … --output_file /tmp/t.wav`.

### FFmpeg

```bash
sudo apt install ffmpeg            # provides ffmpeg + ffprobe
```

Set `FFMPEG_BINARY` / `FFPROBE_BINARY` if they are not on the `PATH` (Windows: full path to `ffmpeg.exe`).
`AUDIO_BITRATE` (default `64k`) controls the MP3 size; speech at 64 kbit/s mono is ~0.5 MB per minute.
Admin → System shows whether both binaries are detected (with a "re-check" button).

### Pronunciation dictionary

Admin → Pronunciation: whole-word or substring rules, case sensitivity, priority (higher first) and a live
preview. Matching understands Devanagari matras (`राम` does not match inside `रामायण`). Rules apply only to
the narration text, never to the published story.

### Commands

```bash
php bin/console app:audio:generate <id|slug>          # synchronous, prints progress and errors
php bin/console app:audio:generate <id|slug> --queue  # hand over to the worker
php bin/console app:audio:retry-failed                # bounded automatic retries (also scheduled)
```

Narration can be generated automatically when a story is published (setting "Generate audio automatically
on publish"). Optional **audiograms** (square video for social media) require FFmpeg and `AUDIOGRAM_ENABLED=1`.

---

## Background jobs: worker, scheduler, cron

Queued work (narration, Facebook posts, audiograms, notification e-mails) is stored in MySQL
(`messenger_messages`). Recurring tasks:

| Task | Interval |
|---|---|
| Publish scheduled stories | 1 min |
| Publish due Facebook posts / retry transient failures | 5 min |
| Retry failed narration | 10 min |
| Aggregate analytics + popularity | 1 h |
| Maintenance (temp files, expired tokens, stale contributions, old notifications) | daily |

**Recommended:** a long-running worker that consumes the queue *and* the scheduler —
[`docs/deploy/katha-worker.service`](docs/deploy/katha-worker.service) (systemd) or
[`docs/deploy/supervisor-katha.conf`](docs/deploy/supervisor-katha.conf):

```bash
php bin/console messenger:consume async scheduler_default --time-limit=3600 --memory-limit=256M
```

**Always** install the cron entry as well ([`docs/deploy/crontab`](docs/deploy/crontab)); it is harmless next
to the worker because every task runs under a lock and records a heartbeat:

```cron
* * * * * cd /var/www/katha && php bin/console app:cron:run --env=prod >> var/log/cron.log 2>&1
```

Without a worker (shared hosting) use `app:cron:run --consume=50` so cron also processes queued jobs.
Run a single task with `--task=publish-scheduled` (`social-due`, `audio-retry`, `analytics`, `maintenance`).

Failed jobs: Admin → System → Failed jobs (retry/remove), or `messenger:failed:show` / `messenger:failed:retry`.
After every deployment run `php bin/console messenger:stop-workers` so workers restart with the new code.

---

## Storage, media and backups

| Path | Content | Web access |
|---|---|---|
| `public/media/covers/…` | Uploaded images + generated variants (400/800/1600 px) | Direct (static) |
| `public/media/qr/<sha1>.svg` | UPI QR codes (generated locally, no external API) | Direct |
| `var/storage/private/audio/…` | Narration MP3s | Only via `/audio/<uuid>.mp3` (published stories or staff) |
| `var/storage/temp/…` | Working files of the narration pipeline (cleaned automatically) | Never |

Uploads accept **JPEG, PNG and WebP only**, verified by content sniffing, decoding and dimension checks;
SVG, double extensions and files with embedded code are rejected, and file names are generated (UUIDs).
`public/media/.htaccess` and the Nginx/Apache examples additionally forbid script execution there.

Permissions (Linux): the web server/worker user needs write access to `var/` and `public/media/`:

```bash
sudo chown -R deploy:www-data /var/www/katha
sudo chmod -R g+rwX var public/media
```

**Backups** — [`docs/deploy/backup.sh`](docs/deploy/backup.sh) dumps MySQL (`mysqldump --single-transaction`)
and archives `public/media` and `var/storage` (without temp). Back up `.env.local` / the vault decryption key
**separately and securely**. Restore = import the dump + extract both archives + `cache:clear`.
`php bin/console app:media:cleanup --dry-run` lists orphaned files (`--force` deletes them).

---

## Facebook publishing

1. Create a Meta app, add the **Pages** product and generate a **long-lived Page access token** with
   `pages_manage_posts` and `pages_read_engagement` for your Page.
2. Configure the server (never the database):
   ```dotenv
   FACEBOOK_ENABLED=1
   FACEBOOK_PAGE_ID=1234567890          # numeric Page ID
   FACEBOOK_ACCESS_TOKEN=EAAB…          # or: php bin/console secrets:set FACEBOOK_ACCESS_TOKEN
   FACEBOOK_API_VERSION=v23.0
   ```
3. Admin → Facebook: enable publishing, "Test connection", caption template (`{{story_title}}`, `{{story_excerpt}}`,
   `{{story_url}}`, `{{source_name}}`, `{{source_type}}`, `{{hashtags}}`, `{{site_name}}`) and default hashtags.
4. Per story: tick "Post to Facebook automatically when published" (SEO & social section), or use
   "Create Facebook post" in the story's Social panel → edit → publish now or schedule. Results, attempts and errors are
   listed under Admin → Social; transient errors (rate limits, 5xx) are retried automatically, invalid tokens
   (code 190) are reported with a hint. Tokens are redacted from every stored response and log line.

Facebook renders the story's OpenGraph image/title as the link preview — keep `SITE_URL` correct and public.

---

## Voluntary UPI support

- Visitors choose an amount → the site creates a contribution with a short reference code and shows a
  standard `upi://pay?pa=…&pn=…&am=…&cu=INR&tn=Seva <code>` link and a **locally generated QR code**.
- Payment happens **inside the visitor's own UPI app**. The site never asks for or stores a UPI PIN, OTP,
  card or bank credentials.
- A static UPI QR cannot prove payment. Contributions are therefore only **initiated → declared**
  (the donor may enter the UTR/reference) until an **admin verifies** them against the bank statement
  (Admin → Donations → verify / reject, with notes and CSV export). Duplicate UTRs are flagged.
- Configure under Admin → Settings → Donations (UPI ID, payee name, presets, min/max, messages) or via
  `UPI_ID` / `UPI_PAYEE_NAME`. Without a valid UPI ID the page says the feature is not configured.
- Donor name, e-mail and phone are optional and never shown publicly.

Messaging is about keeping stories free, narration costs, digitisation and maintenance. The platform never
promises spiritual or material results for contributing (see [Content and ethics rules](#content-and-ethics-rules)).

---

## Search, analytics, popularity, caching

- **Search** uses InnoDB `FULLTEXT` indexes on title/subtitle/original title and on excerpt + plain text
  (boolean mode, prefix matching, title matches weighted ×3) with a `LIKE` fallback when every word is
  shorter than 3 characters. The default
  `innodb_ft_min_token_size=3` suits Hindi; lower it to 2 (and rebuild the indexes) if you need 2-letter words.
- **Analytics** are first-party and cookie-less: the browser batches events (view, play, 25/50/75 %, complete,
  share) to `/api/analytics/events`; bookmarks, collection adds and donations are counted server-side so they
  cannot be spoofed. Counters are upserted per story per day.
- **Popularity** (recent activity, not lifetime views), recomputed hourly:
  `Σ over the last 14 days of (views + 2·plays + 4·completions + 5·bookmarks + 3·collection adds + 3·shares) × 0.5^(age/7 days)`.
- **Caching**: homepage blocks, taxonomy menus, popular lists and settings live in a tag-aware filesystem cache
  and are invalidated on publish/edit/settings changes. HTML pages are sent as `private` (they show the visitor's
  account state and carry a per-request CSP nonce); assets, media, narration files (immutable per URL), sitemaps
  and `robots.txt` are publicly cacheable.

---

## SEO

Canonical URLs from `SITE_URL`, per-page titles/descriptions (overridable per story), OpenGraph/Twitter cards,
JSON-LD (`Article`, `BreadcrumbList`, `WebSite` + search action, `AudioObject` when narration exists),
`/sitemap.xml` index with story, taxonomy and page sitemaps, `/robots.txt`, readable transliterated slugs
with permanent redirects from old slugs, `noindex` for private/unlisted pages and previews.
Admin → SEO lists published stories with incomplete SEO data and can reset the sitemap/navigation caches.

---

## Security

- Passwords hashed with Symfony's `auto` hasher; login throttling (5 attempts / 15 min); rate limits on
  registration, password reset, contact form, donations, analytics and JSON APIs.
- **Stateless CSRF** protection (same-origin `Sec-Fetch-Site`/`Origin` checks + token) on every form, JSON
  write endpoint, login and logout. Admin actions are POST-only.
- Account tokens (verification, password reset) are `selector.verifier` pairs: only a SHA-256 of the
  verifier is stored, compared in constant time, single-use and expiring.
- Rich text is sanitised on save **and** on output (Symfony HtmlSanitizer allow-list); Twig auto-escaping everywhere.
- Content-Security-Policy with a per-request script nonce, `X-Frame-Options`, `nosniff`, `Referrer-Policy`,
  `Permissions-Policy`; session cookies `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS.
- Strict upload validation (see storage), keys validated against path traversal, private files only via controller.
- Audit log for publishing, verification, audio, donations, settings, roles and social actions;
  passwords and tokens are never logged (Monolog channels: `audio`, `social`, `donation`, `security`, `jobs`).
- Production errors show friendly Hindi error pages without stack traces.

---

## Tests and quality checks

The test database (`<dbname>_test`) is created from the migrations and the demo fixtures; every test runs
inside a transaction that is rolled back (DAMA), so the suite is repeatable.

The test environment does **not** read `.env.local`; put the database URL into `.env.test.local`
(the `_test` suffix is added automatically):

```bash
echo 'DATABASE_URL="mysql://root:@127.0.0.1:3306/ramkatha?serverVersion=10.4.32-MariaDB&charset=utf8mb4"' > .env.test.local
composer test:setup     # once (and after new migrations): drop/create test DB, migrate, load fixtures
composer test           # php bin/phpunit — unit, integration and functional suites

php bin/phpunit --testsuite unit          # pure PHP: transliteration, UPI URIs, money, QR, voters, workflow table,
                                           # pronunciation/chunking, TTS process provider, Facebook (mocked HTTP),
                                           # upload validation, storage path traversal
php bin/phpunit --testsuite integration   # real container: sanitizer (XSS), slugs, tokens, workflow + scheduling,
                                           # narration failure handling, donations, captions
php bin/phpunit --testsuite functional    # HTTP: pages, SEO, auth, roles, CSRF, registration, APIs, collections,
                                           # donation flow, audio streaming (Range), admin workflow, uploads
```

Other checks used during development:

```bash
composer validate
php bin/console lint:yaml config
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate
php bin/console app:system:health
```

---

## Production deployment

Templates: [`docs/deploy/`](docs/deploy/) — `apache-vhost.conf`, `nginx.conf`, `katha-worker.service`,
`supervisor-katha.conf`, `crontab`, `env.prod.example`, `backup.sh`.

### First deployment checklist

1. Server: PHP 8.3 FPM + extensions, MySQL 8, Composer, FFmpeg (+ TTS engine if narration is wanted).
2. Database and user:
   ```sql
   CREATE DATABASE katha CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'katha'@'localhost' IDENTIFIED BY '…';
   GRANT ALL PRIVILEGES ON katha.* TO 'katha'@'localhost';
   ```
3. Code in `/var/www/katha`, `.env.local` from `docs/deploy/env.prod.example` (`APP_ENV=prod`, real `APP_SECRET`,
   `SITE_URL`, `DATABASE_URL`, mail, TTS, Facebook, UPI).
4. Build:
   ```bash
   composer install --no-dev --optimize-autoloader --classmap-authoritative
   composer dump-env prod
   php bin/console doctrine:migrations:migrate --no-interaction
   php bin/console importmap:install
   php bin/console asset-map:compile
   php bin/console app:setup
   php bin/console app:create-admin admin@example.org --name="Admin"
   php bin/console cache:clear && php bin/console cache:warmup
   ```
5. Permissions for `var/` and `public/media/` (see above).
6. Web server: DocumentRoot = `public/`, HTTPS (Let's Encrypt), config from `docs/deploy/`.
7. Worker (systemd or Supervisor) **and** cron.
8. Verify: `php bin/console app:system:health`, Admin → System, a test narration, Facebook "Test connection",
   a test UPI flow with a small amount, `/sitemap.xml`, `/robots.txt`.
9. Edit the starter legal pages (Admin → Pages) — they are templates, not legal advice — and set the
   contact e-mail, logo and default share image.
10. Backups scheduled and a restore tested once.

### Updating

```bash
git pull
composer install --no-dev --optimize-autoloader --classmap-authoritative
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console asset-map:compile
php bin/console cache:clear
php bin/console messenger:stop-workers   # workers restart with the new code
```

Maintenance page during deployments: `public/maintenance.html` is a static page the web server can serve
while a flag file exists (`touch var/maintenance.flag` … `rm var/maintenance.flag`):

```nginx
# nginx (server block)
if (-f $document_root/../var/maintenance.flag) { return 503; }
error_page 503 /maintenance.html;
```

```apache
# Apache (virtual host)
RewriteEngine On
RewriteCond %{DOCUMENT_ROOT}/../var/maintenance.flag -f
RewriteCond %{REQUEST_URI} !=/maintenance.html
RewriteRule ^ - [R=503,L]
ErrorDocument 503 /maintenance.html
```

---

## Troubleshooting

| Symptom | Check |
|---|---|
| "Feature is not configured" on narration | `TTS_PROVIDER`, engine path/arguments (`app:system:health`), FFmpeg detection (Admin → System → re-check) |
| Narration stays "queued" | No worker and cron without `--consume`; run `php bin/console app:cron:run --consume=50` once and check `var/log/` |
| Scheduled story not published | Cron/worker running? Admin → System shows the last heartbeat of every task |
| Facebook error 190 | Token expired/invalid → generate a new long-lived Page token |
| Facebook error 10/200 | Missing `pages_manage_posts` / `pages_read_engagement` |
| UPI page says not configured | Set a valid VPA (`name@bank`) in Admin → Settings → Donations or `UPI_ID` |
| Search finds nothing for a word | FULLTEXT min token size (see Search); only published stories are searchable |
| Images not resized | `gd` extension missing (`app:system:health`) |
| Mail not sent | `MAILER_DSN` is `null://null` by default; failures are shown, never hidden |
| 403 on forms ("Invalid CSRF token") | The site must be served from `SITE_URL`'s origin; proxies must forward `Host`/`X-Forwarded-*` (`TRUSTED_PROXIES`) |
| Times shifted by hours | The database must store UTC (do not change `date.timezone` handling); display timezone is `APP_TIMEZONE` |

Logs: `var/log/prod.log` (application) plus channels `audio`, `social`, `donation`, `security`, `jobs`.

---

## Project structure

```
assets/                 Stimulus controllers, CSS (design tokens, components, pages), small JS libs
config/                 Symfony configuration (security, messenger, rate limits, sanitizer, cache, …)
docs/deploy/            Web server, worker, cron, env and backup templates
migrations/             Doctrine migrations (complete schema incl. FULLTEXT and queue tables)
public/                 Front controller, static files, media/ (uploads + QR codes)
src/
  Command/              app:* console commands (cron, setup, create-admin, audio, health, …)
  Controller/           Public/, Account/, Api/, Admin/ controllers (thin)
  DataFixtures/         Demo content (dev/test only, clearly labelled)
  DTO/  Enum/  Entity/  Repository/
  EventSubscriber/      Security events, security headers
  Form/                 Account/, Admin/ and public forms
  Message/ MessageHandler/ Scheduler/   Background jobs and the recurring schedule
  Security/             Roles, user checker, voters, CSP
  Service/              Story, Audio (TTS, FFmpeg, pronunciation), Donation (UPI, QR, money), Social,
                        Media, Seo, Analytics, Settings, Storage, System, Account, Mail, …
  Twig/                 Extension (formatting, icons, settings) and components
templates/              Twig templates (public site, account, admin, e-mails, error pages)
tests/                  Unit/, Integration/, Functional/
```

---

## Content and ethics rules

- Generated or paraphrased text is never presented as scripture. Every story carries an honest source
  classification; only a reviewer can grant **Source Verified**, and edits revoke it automatically.
- No fabricated quotations: demo content is labelled **Demo Content** and is not a translation.
- Contributions are fully voluntary. The platform never claims or implies that paying fulfils wishes,
  brings blessings or is required to benefit from a story; on story pages the support box comes after the story.
- Payments are never marked verified without an admin (or a future verified gateway) confirming them.
- Minimum personal data; donor details are never public; analytics are first-party and cookie-less.
- Legal pages are starting points — have them reviewed for your organisation and jurisdiction.
