# rss.cursor.style

Production-oriented Laravel foundation for the `rss.cursor.style` feed ingestion platform.

## What Is Installed

- Laravel `12.54.1`
- PHP `8.4` compatible app scaffold
- Laravel Horizon (`laravel/horizon`) for Redis queue monitoring
- Laravel Sanctum (`laravel/sanctum`) for API readiness
- Predis (`predis/predis`) as Redis client fallback when `phpredis` extension is unavailable
- Session-based auth pages (register/login/logout) and a dashboard to manage feed subscriptions

## Local Domain

- Primary local host: `rss.cursor.style`
- Local DNS: `rss.cursor.style -> 127.0.0.1`
- `APP_URL` is configured as `http://rss.cursor.style`
- Home page is a marketing entry point with CTA buttons for login/registration
- Authenticated users land in a dark workspace:
  - `/dashboard` for saved feeds and subscription status
  - `/feeds/new` for URL-based feed generation with async stage loader
  - `/feeds/new` includes one-click delivery presets (Telegram/Slack/Discord/Email/Webhook)
  - `/feeds/{source}` for feed profile, fetch history, and recent articles

## Runtime Defaults

- Database driver: MySQL (`DB_DATABASE=rssfeed`)
- Queue driver: Redis
- Cache store: Redis
- Session driver: database
- Horizon queues: `ingestion`, `delivery`, `default`
- Sanctum stateful domains: `rss.cursor.style`, `rss.cursor.style:8000`
- Named rate limits for generator endpoints:
  - `RATE_LIMIT_FEED_GENERATE` (default `40/min`)
  - `RATE_LIMIT_FEED_STATUS` (default `600/min`)
  - `RATE_LIMIT_FEED_STREAM` (default `240/min`)
  - `RATE_LIMIT_FEED_PROFILE_STREAM` (default `240/min`)
- Feed generation watchdog:
  - `FEED_GENERATION_TIMEOUT_SECONDS` (default `180`)
- Source freshness / polling controls:
  - `INGESTION_CACHED_SOURCE_FRESH_MINUTES` (default `60`)
  - `INGESTION_POLL_INACTIVE_SOURCES` (default `false`)
  - `INGESTION_INACTIVE_POLLING_INTERVAL_MINUTES` (default `1440`)
  - `INGESTION_COLD_POLLING_INTERVAL_MINUTES` (default `10080`)
  - `INGESTION_COLD_SOURCE_AFTER_DAYS` (default `30`)
  - `INGESTION_SCHEDULE_JITTER_SECONDS` (default `120`)
  - `INGESTION_CAPTURE_SNAPSHOTS` (default `true`)
  - `INGESTION_SNAPSHOT_MAX_BYTES` (default `250000`)
  - preview image enrichment controls:
    - `INGESTION_PREVIEW_IMAGE_ENRICHMENT_ENABLED` (default `true`)
    - `INGESTION_PREVIEW_IMAGE_ENRICHMENT_MAX_ITEMS` (default `12`)
    - `INGESTION_PREVIEW_IMAGE_ENRICHMENT_TIMEOUT` (default `6`)
    - `INGESTION_PREVIEW_IMAGE_ENRICHMENT_TTL` (default `720`)
- Admin debug access:
  - `ADMIN_ALLOWED_EMAILS` (comma-separated emails)
  - `ADMIN_ALLOW_ALL_LOCAL=true` allows local authenticated users when admin emails are not set

## Prepared Architecture

- Domain boundaries under `app/Domain`:
  - `Source`
  - `Parsing`
  - `Article`
  - `Delivery`
  - `Subscription`
- Support utilities under `app/Support`
- DTO-style objects under `app/Data`
- Queue/event skeleton:
  - events under `app/Events`
  - jobs under `app/Jobs`
  - listeners under `app/Listeners`

## Ingestion Strategy Order

The platform is set up to follow this extraction priority:
1. RSS
2. Atom
3. JSON Feed
4. feed autodiscovery
5. deterministic HTML parsing
6. AI fallback when needed

## Scheduler + Queue Notes

`routes/console.php` includes scheduler tasks for:
- `horizon:snapshot` (every 5 minutes)
- `queue:prune-batches`
- `queue:prune-failed`
- `PollSourcesJob` (every minute) to enqueue due source discovery/fetch work
- feed generation watchdog (every minute) to escalate stale `queued/discovering/fetching/parsing` previews to failed debug state

Run scheduler locally with:

```bash
php artisan schedule:work
```

Run workers via Horizon:

```bash
php artisan horizon
```

If you use the standard local dev entrypoint, `composer dev` now starts:
- the Laravel server
- `schedule:work`
- Horizon
- logs via Pail
- Vite

If you changed queue topology (for example added the `repair` queue), restart Horizon so new supervisors are applied:

```bash
php artisan horizon:terminate
php artisan horizon
```

If Redis is not installed on your host, run it via Docker:

```bash
docker run -d --name rssfeed-redis -p 6379:6379 --restart unless-stopped redis:7-alpine
```

## Local Setup

1. Install dependencies:

```bash
composer install
```

2. Prepare environment:

```bash
cp .env.example .env
php artisan key:generate
```

3. Ensure local services are running:
- MySQL on `127.0.0.1:3306`
- Redis on `127.0.0.1:6379`

4. Create database (if needed):

```sql
CREATE DATABASE rssfeed CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

5. Run migrations:

```bash
php artisan migrate
```

6. Boot app locally:

```bash
composer dev
```

Then open `http://rss.cursor.style:8000`.

If you prefer to run processes separately, you need both of these in addition to the web server:

```bash
php artisan schedule:work
php artisan horizon
```

If Nginx is already mapped to `rss.cursor.style` in your environment, open `http://rss.cursor.style/`.

## Useful Commands

```bash
php artisan test
php artisan horizon
php artisan horizon:status
php artisan generations:watchdog
php artisan route:list
php artisan optimize
php artisan optimize:clear
```

## Current Foundation Scope

This repository currently includes:
- infrastructure and architecture skeleton
- account auth flow
- workspace UI with:
  - feed generator screen (`/feeds/new`) with async loader stages (`queued -> discovering -> fetching -> parsing -> ready`)
  - watchdog timeout escalation for stuck feed generations (default 180s) with admin debug routing
  - local safety fallback for generator: if Horizon supervisors are offline, preview job is executed synchronously so it does not stay queued forever
  - realtime status updates via SSE (`/feeds/generate/{id}/stream`) with polling fallback
  - one-click delivery presets for quick setup during save flow
  - “Add To My Feeds” save flow bound to subscription creation
  - dashboard overview (`/dashboard`) with activation/pause/delete controls
  - admin debug console (`/admin/debug/generations`) with queue snapshot, source-level diagnostics (reason per site), and one-click retry for failed generations/sources
  - feed profile page (`/feeds/{source}`) with source details, recent fetches, parsed articles, and live SSE monitor
- first working ingestion slice:
  - `SourceCreated -> DiscoverSourceTypeJob -> SourceDiscovered -> FetchSourceJob -> SourceFetched -> ParseArticlesJob -> NormalizeArticlesJob -> DetectNewArticlesJob -> NewArticlesDetected -> QueueTelegramDeliveriesJob`
  - RSS/Atom/JSON Feed deterministic parsing
  - HTML feed autodiscovery via `<link rel="alternate" ...>`
  - deduplication by canonical URL hash / content hash
  - article persistence and delivery queue records
  - source aliases table (`source_aliases`) for user-submitted URL variants -> canonical source mapping
  - cache-first generator flow: if source has fresh cached articles, preview returns from DB without refetch
  - stale-cache revalidation flow:
    - stale cached sources are refreshed live during generation
    - stale snapshot is used only as fallback when refresh/discovery/parse fails
  - active/inactive/cold usage states for sources, with scheduler designed for active-first polling
  - source health tracking fields (`health_state`, `health_score`, `consecutive_failures`, `last_attempt_at`, `last_success_at`)
  - parser schema registry table (`parser_schemas`) with versioning and active/shadow flags
  - parse attempt tracing table (`parse_attempts`) with stage/status/error diagnostics
  - source payload snapshot table (`source_snapshots`) for snapshot-based debugging
  - admin snapshot inspector route: `/admin/debug/sources/{source}/snapshot`
  - parse output hygiene improvements:
    - HTML in summaries is converted to plain text
    - image URL extraction from RSS/Atom/JSON feed items
    - fallback image enrichment from article page metadata (`og:image`, `twitter:image`) for items where feed image is missing
    - preview timestamps are rendered in user-local readable format in UI
  - AI repair path for degraded HTML/unknown sources:
    - parse failure threshold -> queue `ResolveSchemaWithAiJob` on `repair` queue
    - generated schema is stored as shadow schema and validated against recent snapshots
    - schema activation happens only after validation score + shadow success run threshold
  - generator-time HTML fallback:
    - if deterministic parsing returns zero items for `html/unknown`, generator performs immediate AI schema attempt
    - successful schema is saved as active `ai_xpath_schema`, parsing is retried in the same preview request
    - preview response includes `meta.ai_preview` diagnostics (`status`, `strategy`, `schema_id`, `parsed_count`)
  - per-domain fetch controls:
    - scheduler dispatch limits per domain per cycle
    - queue middleware domain overlap lock + domain rate limit
    - `Retry-After` support for HTTP 429 updates `next_check_at`

## Preview Endpoint

Authenticated users can generate feed previews from UI via async endpoints:

```text
POST /feeds/generate
GET /feeds/generate/{id}
GET /feeds/generate/{id}/stream
```

Payload:

```json
{
  "source_url": "https://example.com/feed.xml"
}
```

`POST` returns a generation id. UI subscribes to `stream` first, and falls back to polling `GET` if stream is interrupted.

Feed profile live monitor endpoint:

```text
GET /feeds/{source}/stream
```

## Notes

- After adding new event listeners, clear any stale cached event mappings:

```bash
php artisan event:clear
```

- Telegram sending uses `TELEGRAM_BOT_TOKEN`. In `testing`, outbound Telegram calls are skipped and logged. In `local`, jobs are skipped when no token is configured.
- If news ingestion is not moving, first check `/admin/debug/generations`:
  - `Horizon Supervisors = 0` means queue workers are offline
  - start workers with `php artisan horizon`
