# Buggy Trip

Sign-up, vehicle planning and cost splitting for the group's buggy/hilux rides.
PHP + SQLite, no build step, runs on Azure App Service (Free F1).

- **Group password** protects the whole site (`SITE_PASSWORD` app setting).
- **Accounts**: everyone signs up with a username + password, then joins ride days (and can add guests, whose share is charged to them).
- **Fleet**: the app proposes vehicles from the rider count (≤4 → buggy, ≤7 → hilux, then hilux + buggy, …). Book the proposal or add/remove vehicles by hand, edit prices, and mark who paid.
  Buggy: 4 seats, default R$ 370. Hilux: 7 seats, default R$ 400.
- **Money**: each day's paid vehicles are split equally across everyone riding that day. Balances, suggested settle-up transfers, and repayment history.
- Seat changes and cancellations lock at 22:00 the evening before (timezone `BUGGY_TIMEZONE`, default `America/Sao_Paulo`).

## Try it locally

```sh
docker compose up
```

Open http://localhost:8080 — group password `dune`. Data lives in the `buggy-data` Docker volume
(`docker compose down -v` wipes it). Tests: `docker compose run --rm web php tests/fleet_ledger_test.php`.

## Deploying

Pushing `main` deploys to Azure. Before the first deploy of this version:

1. In the Azure portal → App Service → Environment variables, add `SITE_PASSWORD`. Without it the site shows a "not configured" page.
2. The schema upgrade **deletes all bookings from the old version** (they had no accounts).

Data is stored in `/home/data/buggy-trip.sqlite` (override with `BUGGY_DB_PATH`), sessions in `/home/data/sessions`.

## Layout

| Path | What |
|---|---|
| `index.php` | Group-password gate, then the app shell |
| `api.php` | JSON API (all actions documented at the top) |
| `lib/` | DB/session bootstrap, schema migration, fleet proposal, ledger math |
| `views/` | Gate page and app shell HTML |
| `assets/` | CSS and JS |
