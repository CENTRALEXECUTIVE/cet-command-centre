# CET Command Centre — Go-Live Checklist

Run this top to bottom on the **staging server** (`~/cet-staging`) before taking real
bookings. Every command assumes you're in that directory:

```bash
cd ~/cet-staging
```

Tick each box. The **£1 end-to-end test (Part 3) is the decisive one** — if it passes,
the money + email chain is proven.

---

## Part 0 — Confirm the latest code is deployed

```bash
git log --oneline -1
```
- [ ] Shows the newest commit (the auto-deploy pulls every ~2 min). If stale:
```bash
git fetch origin claude/cet-command-centre-guide-r1azew \
  && git reset --hard origin/claude/cet-command-centre-guide-r1azew \
  && php artisan migrate --force && php artisan optimize:clear
```

---

## Part 1 — Verify configuration

Run each check; it prints `set` / `MISSING`.

**Payments — Stripe (non-VAT / PVT LTD):**
```bash
php artisan tinker --execute="echo app(App\Services\Payments\StripePaymentService::class)->enabled() ? 'STRIPE: set' : 'STRIPE: MISSING';"
```
- [ ] STRIPE: set

**Payments — Square (VAT / Ltd):**
```bash
php artisan tinker --execute="echo (config('services.square.access_token') && config('services.square.location_id')) ? 'SQUARE: set' : 'SQUARE: MISSING';"
```
- [ ] SQUARE: set  _(only needed if you'll issue VAT invoices)_

**Google Maps key** (geofencing, postcode lookup, distance checks):
```bash
php artisan tinker --execute="echo App\Models\Setting::mapsKey() ? 'MAPS: set' : 'MAPS: MISSING';"
```
- [ ] MAPS: set

**Office inbox + branded sender:**
```bash
php artisan tinker --execute="echo 'OPS: '.(config('cet.ops_email') ?: 'MISSING').' | FROM: '.(config('cet.office_from') ?: 'unset (office emails show as Note to self)');"
```
- [ ] OPS email correct
- [ ] FROM set to a send-enabled alias (e.g. `no-reply@centralexecutivetransfers.co.uk`) — optional but removes "Note to self"

**Scheduler running** (number masking close-off, watchdog alerts, auto-deploy):
```bash
php artisan schedule:list
```
- [ ] Lists jobs (cet:close-proxy-sessions, cet:status-watchdog, cet:auto-deploy, …)
- [ ] A system cron calls `php artisan schedule:run` every minute (check cPanel → Cron Jobs)

**Optional extras:**
```bash
php artisan tinker --execute="echo 'GETADDRESS: '.(App\Models\Setting::getAddressKey() ? 'set' : 'unset').' | VAPID: '.(config('webpush.vapid.public_key') ? 'set' : 'unset');"
```
- [ ] getAddress.io key (full postcode → address list) — optional
- [ ] VAPID keys (driver push notifications) — optional

---

## Part 2 — Register the payment webhooks

This is the **most important dependency**. Without it, a payment is taken but the app
never hears about it (status won't flip, confirmation emails won't auto-send).

In the **Stripe** dashboard → Developers → Webhooks, add an endpoint:
- URL: `https://staging.centralexecutivetransfers.co.uk/webhooks/stripe`
- Event: `checkout.session.completed`
- [ ] Added, and the signing secret is saved in Settings → payments (Stripe)

In **Square** (sister / PVT account) → Webhooks:
- URL: `https://staging.centralexecutivetransfers.co.uk/webhooks/square-chauffeurs`
- [ ] Added (only if using Square for the sister company)

In **Square** (main / Ltd, for VAT + tips):
- URL: `https://staging.centralexecutivetransfers.co.uk/webhooks/square`
- [ ] Added

---

## Part 3 — The £1 end-to-end test (DECISIVE)

1. **New booking** → create a booking to a real address, price **£1**, payment **Card**,
   tick **Email the customer**, use an email inbox you can check.
   - [ ] You receive the office email **"Incomplete · Unpaid — awaiting payment"**
   - [ ] The customer inbox gets a confirmation with a **Pay now** button
2. **Click Pay now** → it opens Stripe (non-VAT) or Square (VAT) showing **£1.00**.
   - [ ] The amount is £1.00 (not a stale price)
3. **Pay** with a real card (refund it afterwards in Stripe/Square).
   - [ ] You land on the **"Booking confirmed"** page
4. Within a few seconds, **without opening the booking**:
   - [ ] The booking's status shows **Paid** in the Command Centre
   - [ ] You receive the office email **"Payment received — booking confirmed"**
   - [ ] The customer receives the **paid confirmation with the invoice PDF** attached
5. If step 4 only happens **after** you open the booking → the **webhook isn't reaching
   the app**. Fix Part 2 before go-live.

- [ ] **All of Part 3 passed** ← the system is money-ready

---

## Part 4 — Driver flow smoke test

1. Allocate the test booking to a driver (or yourself), open **My jobs** on a phone.
2. Tap through: Set off → Arrived → Passenger on board → **Complete**.
   - [ ] Each step works with location on
   - [ ] **Complete** works when within ~1 mile of the drop-off
   - [ ] Leaving the phone idle for a while, then tapping a status, does **not** show
     "session expired"

---

## Part 5 — Final checks

- [ ] `php artisan test` is green (run on staging if in doubt)
- [ ] The live public site (centralexecutivetransfers.co.uk) is untouched — the Command
      Centre is a **separate app** at staging.centralexecutivetransfers.co.uk
- [ ] The Google Maps key shared in chat earlier has been **regenerated** (security)
- [ ] You've done one real booking for a real customer and watched it the whole way

---

**When Parts 1–4 are ticked, the booking system is ready to go live.**
