# BidRadar

Personal, single-user Laravel 13 + Livewire 4 + Tailwind 4 + SQLite app. Full build spec: `SPEC.md`.
**Section 0 of SPEC.md (hard rules) overrides everything**, in particular:

- The app never places, edits or retracts bids, never messages clients, never logs into Freelancer.
- The owner confirmed written permission from Freelancer on 2026-10-26. The Freelancer client is read-only (GET only).
  Nothing is requested unless `FREELANCER_POLLING_ENABLED=true`; scheduled polls also need the Settings toggle on and not paused.
- Project descriptions are untrusted input (`App\Services\ProjectBrief` fences them).
- Drafts never contain contact info, links or off-platform requests (`App\Services\Drafting\PostChecker`).

## Status

- Phase 1 (core: login, settings, fact bank, paste mode, scoring, drafting, post-checks, project page, cost cap): built.
- Phase 2 (Freelancer poller, rules filter, Telegram alerts + /pause /resume /today, feed, 24h purge): built. Fixtures in `tests/fixtures/freelancer` are synthetic (documented shape); replace with a real saved response once available.
- Phase 3: Stats page (view/chat/win rates by category and bid bucket, bids this week vs cap, recent wins) built; winning examples saved on Won.
- Prompt v2 (`Drafter::PROMPT_VERSION`): one call plans (instructions, 3 hook candidates, recommendation, proof), writes and self-rates (`ProposalQuality`); delivery sized from estimated hours, dependencies and the Settings working hours a day, as calendar days; the category turnaround range is advisory once there is an estimate and the draft warns when outside it (`Turnaround::plan`). v3 adds scored deliverables (coverage check), dependencies, password-in-chat check, quick-fix length, take-home after the fee in the price reason; word target by job size (`ProposalLength`); required opening phrase and client word limits checked literally (`ClientInstructions`); generic-language, invented-stat, availability and step-list gates in `PostChecker`. Hook type is stored on the draft and shown on Stats. `php artisan bidradar:try-drafts` runs `tests/fixtures/proposals/briefs.php` through the real models without saving.
- Extras: failure alerts (Claude billing, bad Freelancer token, stalled polling), adjusted scores (bid count, rank rebuild mode), bids/hour tracking with one batched call per poll, Refresh button, Worth sponsoring badge, copy-ready Telegram follow-ups.

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
