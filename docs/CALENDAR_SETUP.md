# Switch on: ETO email → Google Calendar

Three credentials make the live flow work. Add each to `~/cet-staging/.env`, then
run `php artisan config:clear`. None of these are passwords — they're API keys you
generate. **Do not share them in chat; paste them only into `.env` on the server.**

After all three are set, add this ONE cron (cPanel → Cron Jobs, every minute) so it
runs automatically:
```
* * * * * cd /home/u2beq0g0k7mj/cet-staging && php artisan schedule:run >> /dev/null 2>&1
```
(`cet:ingest-outlook` reads new emails every 5 min; `cet:sync-calendar` pushes
events; both are inside the scheduler.)

---

## 1. Microsoft 365 — read the ETO emails (Microsoft Graph)
In **portal.azure.com** (sign in as a 365 admin):
1. **Microsoft Entra ID** → **App registrations** → **New registration**.
   - Name: `CET Command Centre`. Accounts: *this organisation only*. Register.
2. On the app's **Overview**, copy:
   - **Application (client) ID** → `.env` `MS_GRAPH_CLIENT_ID`
   - **Directory (tenant) ID** → `.env` `MS_GRAPH_TENANT_ID`
3. **Certificates & secrets** → **New client secret** → copy the **Value** (now, it
   hides later) → `.env` `MS_GRAPH_CLIENT_SECRET`.
4. **API permissions** → **Add a permission** → **Microsoft Graph** →
   **Application permissions** → add **Mail.ReadWrite** → then **Grant admin
   consent** (button at top).
5. `.env` `MS_GRAPH_MAILBOX` = the mailbox the ETO emails land in
   (e.g. `admin@centralexecutivetransfers.co.uk`).

```
MS_GRAPH_CLIENT_ID=...
MS_GRAPH_CLIENT_SECRET=...
MS_GRAPH_TENANT_ID=...
MS_GRAPH_MAILBOX=admin@centralexecutivetransfers.co.uk
```
> Make sure ETO's booking/amendment/cancellation emails actually arrive in that
> mailbox (forward/rule if needed).

## 2. Anthropic — understand the email (claude-opus-4-8)
1. **console.anthropic.com** → API keys → create a key.
2. `.env` `ANTHROPIC_API_KEY=sk-ant-...`
(Needed because the system reads the email and extracts the reference, time,
addresses, vehicle, etc.)

## 3. Google Calendar — write the events
In **console.cloud.google.com** (signed in as the Google account that owns the
`admin@…` calendar — it must be a Google/Workspace calendar you can open at
calendar.google.com):
1. Create/select a project → **APIs & Services** → **Enable APIs** → enable
   **Google Calendar API**.
2. **APIs & Services → Credentials → Create credentials → Service account**.
   Name it `cet-calendar`. Create.
3. Open the service account → **Keys → Add key → Create new key → JSON** →
   download the file. Note the service account **email** (looks like
   `cet-calendar@yourproject.iam.gserviceaccount.com`).
4. In **Google Calendar** (calendar.google.com), open **Settings** for the
   `admin@…` calendar → **Share with specific people** → add the service-account
   email with **"Make changes to events"**.
5. Upload the JSON to the server (File Manager) into
   `~/cet-staging/storage/google-calendar.json`, then in `.env`:
```
GOOGLE_CALENDAR_CREDENTIALS=/home/u2beq0g0k7mj/cet-staging/storage/google-calendar.json
CET_CALENDAR_ID=admin@centralexecutivetransfers.co.uk
```

---

## Test it (Terminal)
```
cd ~/cet-staging
php artisan config:clear
php artisan cet:ingest-outlook    # reads unread ETO emails -> bookings
php artisan cet:sync-calendar     # pushes events to Google Calendar
```
Then check the `admin@…` Google Calendar — the booking should appear at the right
UK time, formatted like `*👀 James Watson MAN (ABDI)*`. Send another ETO email
with the **same reference** and a changed time → the same calendar entry moves
(no duplicate). A cancellation email cancels it.

If anything errors, `tail -n 40 storage/logs/laravel.log` shows the reason.
