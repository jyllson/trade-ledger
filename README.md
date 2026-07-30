# TradeLedger

Personal, read-only analytics application for discovering, comparing, and
monitoring copy traders, starting with eToro. See `PROJECT.md` for the full
product specification and milestone plan.

**Status:** Milestone 0 (scaffold) complete. The application does not call
the eToro API yet and contains no trading/write capability.

## Security warning

**Never commit real API keys.** `ETORO_API_KEY` and `ETORO_USER_KEY` belong
only in your local `.env` file (already git-ignored) or a secret manager.
Never paste key values into source control, issue descriptions, screenshots,
commit messages, or AI prompts.

`ETORO_ALLOW_WRITE` must stay `false`. Write/trading capability is not
implemented in this application during the MVP — see `app/Etoro/EtoroWriteGuard.php`.

## Requirements

- PHP 8.3+
- Composer
- Node.js and npm
- MySQL 8.4+ (local development targets MySQL 8.4+ compatibility; see
  `docs/DECISIONS.md` D-001 for the exact locally-used version)

## Local setup

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your local database credentials
(`DB_DATABASE=trade_ledger` by default) and, when you reach Milestone 1, your
eToro credentials. Leave `ETORO_ENABLED=false` and `ETORO_ALLOW_WRITE=false`
until you are intentionally working on the eToro integration.

```bash
composer install
npm install
php artisan migrate
php artisan make:filament-user
npm run build
php artisan serve
```

Visit `/admin` and sign in with the Filament user you just created.

## Testing

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
```

## Project control docs

- `docs/WORKLOG.md` — chronological log of commands run and changes made
- `docs/DECISIONS.md` — architectural and implementation decisions
- `docs/REVIEW_STATUS.md` — current milestone status and next steps
