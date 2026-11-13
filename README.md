# BidRadar

Finds best-fit Freelancer.com projects, scores them with an AI model and drafts a tailored
proposal. You copy the proposal and bid **manually** on Freelancer. The app never bids for you.

See `SPEC.md` for the full spec and hard rules.

## Local setup

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan db:seed            # local test owner (APP_ENV=local only)
php artisan serve              # http://127.0.0.1:8000
php artisan queue:work --queue=scoring,default,drafting   # in a second terminal: scoring first, then drafts
```

Set `ANTHROPIC_API_KEY` in `.env` before pasting a project.

### Queues (deploy note)

Scoring runs on the `scoring` queue and drafting on the `drafting` queue, so a long draft never makes a
new project wait to be scored. The worker must listen to both, scoring first:

```bash
php artisan queue:work --queue=scoring,default,drafting --timeout=330
```

On Forge, change the queue daemon command to the line above **before** deploying this code (the old code
only uses `default`, which the new command still works). If the code goes out first with the old
`php artisan queue:work`, new projects sit unscored on the `scoring` queue until the daemon is changed.
For drafts in parallel with scoring, add a second daemon: `php artisan queue:work --queue=drafting --timeout=330`.

Automatic drafts (made right after scoring) are fast drafts: one pass at the "Automatic draft effort"
setting (default `low`), with no quality revision; the hard-check retry still runs. Draft / Regenerate in
the app keeps the full drafting effort and the revision. `/alerts/feed` lists a project again when its
draft is written (`draft_ready_at`), so a watcher that saw it at scoring time also gets the draft.
Compare draft latency with `php artisan bidradar:try-drafts --only=A,B,C` and the same with `--fast`
(prints the draft time per brief).

## Commands

| Command | What it does |
|---|---|
| `php artisan bidradar:user you@example.com` | Create or update the single owner account (asks for the password) |
| `php artisan bidradar:backup` | Snapshot the SQLite DB to `storage/app/backups` (scheduled daily, keeps 14) |
| `php artisan bidradar:poll --dry-run` | Fetch + filter new Freelancer projects, print results, save nothing |
| `php artisan bidradar:skills` | Look up Freelancer skill IDs for the target skills |
| `php artisan bidradar:purge` | Delete project data older than 24h (scheduled hourly) |
| `php artisan bidradar:telegram chat-id\|webhook\|test` | Telegram setup helpers |
| `php artisan test` | Test suite (uses a fake AI client, costs nothing) |
