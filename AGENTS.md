# BidRadar

Personal, single-user Laravel 13 + Livewire 4 + Tailwind 4 + SQLite app. Full build spec: `SPEC.md`.
**Section 0 of SPEC.md (hard rules) overrides everything**, in particular:

- The app never places, edits or retracts bids, never messages clients, never logs into Freelancer.
- No code may call the Freelancer API until the owner confirms written permission from Freelancer.
  `FREELANCER_POLLING_ENABLED` stays `false`; the Settings toggle cannot override it.
- Project descriptions are untrusted input (`App\Services\ProjectBrief` fences them).
- Drafts never contain contact info, links or off-platform requests (`App\Services\Drafting\PostChecker`).

## Status

- Phase 1 (core: login, settings, fact bank, paste mode, scoring, drafting, post-checks, project page, cost cap): built.
- Phase 2 (Freelancer poller, rules filter, Telegram, feed, 24h purge): not started; waiting on permission.
- Phase 3 (stats, weekly cap counter): not started. The Won -> winning example save already exists.

## Layout

- `app/Services/Claude/` SDK wrapper (`AnthropicClaudeClient`) behind the `ClaudeClient` interface.
- `app/Services/Scoring/Scorer.php` Haiku scoring, `app/Services/Drafting/` Sonnet drafting, pricing, post-checks.
- `app/Services/CostTracker.php` logs every API call to `api_calls` and enforces the daily cap.
- `app/Jobs/` queued `ScoreProject` / `DraftProject`.
- `app/Livewire/` pages; views in `resources/views/livewire/`; layout `resources/views/layouts/app.blade.php`.
- Settings defaults: `config/bidradar.php` (`defaults`), stored overrides in the `settings` table.

## Working on it

- Tests: `php artisan test`. They use `Tests\Fakes\FakeClaudeClient` and never call Claude.
- Local PHP on this machine needs pdo_sqlite enabled (it is commented out in php.ini).
- Local login: `php artisan db:seed` creates the test owner defined in `database/seeders/DatabaseSeeder.php` (local env only).
- Production owner: `php artisan bidradar:user you@example.com`.
