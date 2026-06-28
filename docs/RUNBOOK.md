# CET Command Centre — Operations Runbook

Plain-English commands for running the calendar automation safely. All commands
run from the cPanel **Terminal**, inside the project: `cd ~/cet-staging` first.

## Login
- Command Centre URL: the cPanel **Domains** entry whose document root is
  `…/cet-staging/public`.
- Admin login: `abdi@centralexecutivetransfers.co.uk` / `Central1234??`
  (also `maj@centralexecutivetransfers.co.uk`).

---

## The golden rule: ONE source at a time
Duplicates happen when two sources write the same bookings under different keys.
So we keep **one** base and let the live email feed update it by reference:

- **Base** = your ICS export (`database/imports/base.ics`) — the trusted calendar.
- **Live feed** = new ETO emails, matched to the base **by booking reference**.

The database has a hard rule (a unique index on the booking reference), so the
**same reference can physically never exist twice.** Duplicates can only come
from a *different* reference for the same trip — which is why we restore from the
base (real ETO references) before going live.

---

## Restore the calendar to your base (safe rebuild)
Use this any time the calendar looks wrong. It pauses automation, clears
everything, and rebuilds exactly from your base export:

```
crontab -l 2>/dev/null | grep -v 'cet-staging.*schedule:run' | crontab - ; \
cd ~/cet-staging && git pull origin claude/cet-command-centre-phase-1-e0jtio && php artisan config:clear && \
nohup bash -c 'php artisan cet:purge-calendar; php artisan cet:wipe-eto; php artisan cet:import-ics database/imports/base.ics; php artisan cet:sync-calendar; php artisan cet:verify-calendar' > restore.txt 2>&1 &
```
Wait ~8–10 min, then read `restore.txt`. Expect `Restored 507…`, `0 missing`.

---

## Turn the automation ON (only when the calendar looks right)
```
PHPBIN=$(command -v php) && { crontab -l 2>/dev/null | grep -v 'cet-staging.*schedule:run'; \
echo "* * * * * cd $HOME/cet-staging && $PHPBIN artisan schedule:run >> /dev/null 2>&1"; } | crontab - && crontab -l
```

## Turn the automation OFF (stop new bookings being added)
```
crontab -l 2>/dev/null | grep -v 'cet-staging.*schedule:run' | crontab - ; echo "paused"
```

---

## The commands (what each does)
| Command | What it does |
|---|---|
| `cet:ingest-outlook` | Read recent ETO emails → calendar (by reference) |
| `cet:sync-calendar` | Push any pending/failed events to Google |
| `cet:verify-calendar` | Re-confirm every upcoming booking is on the calendar |
| `cet:import-ics database/imports/base.ics` | Rebuild the calendar exactly from the base export |
| `cet:purge-calendar` | Delete every system-created Google event (clears duplicates) |
| `cet:wipe-eto` | Delete all ETO bookings from the database + reset rotation |
| `cet:dedupe-bookings` | Remove same-trip duplicate bookings (keeps the richest) |
| `cet:test-graph` / `cet:test-calendar` | Check the email / Google connections |
| `cet:dump-email` | Show an unread booking email as the parser sees it |

## If something looks off
1. Pause automation (above).
2. `php artisan cet:purge-calendar` then `cet:dedupe-bookings` (clean duplicates).
3. Restore from base (above) if needed.
4. `tail -n 40 storage/logs/laravel.log` shows the reason for any failure.

## Updating a fresh base
When you're happy with the calendar, export it from Google Calendar
(Settings → Import & export → Export), and replace `database/imports/base.ics`
so future restores use the latest trusted state.
