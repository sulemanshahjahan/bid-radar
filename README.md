# BidRadar

Finds best-fit Freelancer.com projects, scores them with Claude Haiku and drafts a tailored proposal with
Claude Sonnet. You copy the proposal and bid **manually** on Freelancer. The app never bids for you.

See `SPEC.md` for the full spec and hard rules.

## Local setup

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan db:seed            # local test owner (APP_ENV=local only)
php artisan serve              # http://127.0.0.1:8000
php artisan queue:work         # in a second terminal: scoring and drafting run as queued jobs
```

Set `ANTHROPIC_API_KEY` in `.env` before pasting a project.

## Commands

| Command | What it does |
|---|---|
| `php artisan bidradar:user you@example.com` | Create or update the single owner account (asks for the password) |
| `php artisan bidradar:backup` | Snapshot the SQLite DB to `storage/app/backups` (scheduled daily, keeps 14) |
| `php artisan bidradar:poll --dry-run` | Fetch + filter new Freelancer projects, print results, save nothing |
| `php artisan bidradar:skills` | Look up Freelancer skill IDs for the target skills |
| `php artisan bidradar:purge` | Delete project data older than 24h (scheduled hourly) |
| `php artisan bidradar:telegram chat-id\|webhook\|test` | Telegram setup helpers |
| `php artisan test` | Test suite (uses a fake Claude client, costs nothing) |
