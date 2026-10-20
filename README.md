# Buggy Trip Sign-Up — Azure App Service (Free F1)

Files in `buggy-trip-azure.zip`: `index.html` (the app), `api.php` (backend), `index.php` (serves the app at the root address).
No database server and no `config.php`: bookings are stored in a SQLite file that `api.php` creates on the first request at
`/home/data/buggy-trip.sqlite` — persistent across restarts and redeployments, and outside the web root so it can't be downloaded.

## Create the web app (once)
Azure portal → **Create a resource → Web App**:
- **Publish:** Code · **Runtime stack:** PHP 8.x (newest offered) · **Operating system:** Linux · **Region:** e.g. West Europe
- **Pricing plan:** create new → **Free F1**
- **Review + create** → **Create**

## Deploy (each time you change the app)
Web app → **Deployment Center → Manual Deployment (Push) → Publish files (new)** → drop `buggy-trip-azure.zip` → deploy (no build step needed).
The site address is on the web app's **Overview** page (**Default domain**).

## Behaviour (unchanged from the SiteGround version)
Date picker / Today / Tomorrow choose the date; joining, the list, cancel, drag-and-drop and "Reset this date" apply to that date only.
Cancellations and seat moves close at 22:00 on the day before the ride date (server clock, UTC on Azure).

## Free tier limits
60 CPU minutes per day (each request uses milliseconds), 1 GB storage, no "Always On": the app sleeps after about 20 minutes
without visitors, so the first visit afterwards takes a few seconds.

## Storage note
Azure keeps `/home` on a network share whose file locks SQLite can't rely on. `api.php` therefore serialises requests with a
lock on local disk and turns SQLite's own locking off. Tested locally with 40 simultaneous bookings: all 40 saved, database intact.
This relies on the app running on one instance, which is always the case on Free F1.
To store the database elsewhere, set the app setting `BUGGY_DB_PATH` (Settings → Environment variables).
