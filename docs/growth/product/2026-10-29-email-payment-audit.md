# Email and payment-confirmation audit (29 Oct 2026)

Read-only audit. No code changed, nothing committed, pushed or deployed.
Branch checked: `claude/cet-command-centre-guide-r1azew` at local commit `068869e`
(not pushed: `cet:reconcile-payments` plus webhook logging).

Scope: widget `store` / `pay` / `paid`, `PublicBookingController`, `BookingStatusService`
(`onFarePaid`, `confirmPaidWebBooking`, `emailPaidConfirmation`, `emailOffice`),
`PaymentController::markPaid`, `BookingTransactionController`, `BookingCommsController`,
`WebhookController`, Stripe and Square services, `PaymentGateway`, all Mailables and
transports, `Booking::billingEntity / senderAddress / senderName / pvtMailer /
customerEmailAllowed / markFarePaid`, `MailFailure`, `BookingEmailLog`, `routes/console.php`.

Severity: **CRITICAL** = money taken and nobody is told, or the wrong company is paid,
with no alert. **HIGH** = an email is missed or a customer can be charged twice in a
likely scenario. **MEDIUM** = it happens in a real but narrower case. **LOW** = edge case
or tidy-up.

---

## Most likely explanation

### CET-CB4218 (£360, card, paid through widget.pay, stayed Pending, no emails)

`CET-CB4218` is a single reference (6 hex characters, no A/B suffix), so it is a one-way
web booking. The widget stamps `billing_entity` at creation, so if no VAT was asked for it
was `chauffeurs`, which means **Stripe** (a `FARE-CET-CB4218` Payment Link).

The booking stayed **Pending**, so `markFarePaid()` never ran. That rules out a failed
email after the payment was recorded. `markFarePaid` would have flipped the status to paid
before any email. So the provider event either:

1. **never reached the app, or was refused.** Before 068869e every refusal was silent.
   The likely causes, in order:
   - The signing secret does not match this endpoint, or the endpoint is in the other
     live/test mode from the `sk_` key. Reply: 403 `bad signature`.
   - The endpoint URL redirects (http to https, www, or a trailing slash). Stripe does not
     follow redirects.
   - The endpoint is registered on the wrong host (live domain instead of staging).
   - The server clock is more than 5 minutes out. `verifyWebhook` has a 300s tolerance
     (`StripePaymentService.php:371`).
2. **or it arrived, was verified, and matched nothing.** The app replies 200
   `{"recorded":false}`, which Stripe treats as delivered, so it never retries. Possible
   causes:
   - A "thin" payload with no `data.object`.
   - A `client_reference_id` that does not resolve to a booking.

If the booking was VAT (Square): the commit message names the most likely cause. The
Square token lacks `ORDERS_READ`, so `retrieveOrderReference()` returned null and every
fare webhook quietly did nothing. Square also got a 200 and never retried. **The new
reconcile command uses the same order lookup, so it would fail the same way** (see F9).

**Data that would confirm it** (all read-only):
- **Stripe Dashboard → Payments → the £360 payment → its Checkout Session:** check
  `client_reference_id`, `metadata.reference`, `metadata.cet_booking`, `payment_link` and
  `payment_status`.
- **Stripe Dashboard → Developers → Webhooks / Event destinations →** the
  `checkout.session.completed` event for that session. Check the delivery attempts, the
  HTTP status and the response body:

  | Response | Meaning |
  |---|---|
  | 403 `bad signature` | Signing secret mismatch |
  | 403 `not configured` | Stripe key not set |
  | 404, 3xx | Wrong URL, or a redirect |
  | 5xx | The app threw an error |
  | 200 `recorded:false` | Arrived but matched no booking |

  Also confirm the endpoint mode and host.
- **Staging web server access log:** `POST /webhooks/stripe` around the payment time.
- **Same Stripe account:** a **second** successful payment for CET-CB4218. If the customer
  went back to the "Pay now" button while the booking still read unpaid, `widget.pay` made
  a brand-new link and could charge again (see F5).
- **If it was Square:** Square Developer Dashboard → Webhooks → Logs, and the token's
  permission list (`ORDERS_READ`, `PAYMENTS_READ`).
- **Booking meta:** `billing_entity`, `vat_invoice_requested`, no `square_payment`, and
  `email_log` (which should show the "Confirmation + pay link" and "Office notice (new)"
  sends and their From addresses).

### The PVT "Incomplete booking (awaiting payment)" office email showing "Central Executive Transfers"

**The display name does not prove `billingEntity()` returned `transfers`.** Widget, public
and admin bookings all stamp `billing_entity = chauffeurs` for no-VAT at creation
(`BookingWidgetController.php:553`, `PublicBookingController.php:203`,
`BookingController.php:383-388`). `billingEntity()` returns the stamp without looking at
Stripe (`Booking.php:5863-5866`). So for a web booking the office mail almost certainly
took the PVT branch, with From name `config('cet.company_novat.name')`
(`OfficeBookingMail.php:44-50`).

The name was most likely lost **after** the Mailable built it:
- **PVT sending is effectively off.** Either `mail_pvt_enabled` is not set, or no PVT
  mailer is configured (no Resend key, Gmail or PVT SMTP). Then:
  - `senderAddressFor(true)` returns the **main** address (`Booking.php:5903-5909`).
  - `pvtMailer()` returns null, so the message goes through the **default** mailer
    (`Booking.php:5954-5969`).
- **If the default mailer is Microsoft 365 Graph** (`mail_transport = graph`):
  - `GraphTransport` passes only the address and drops the display name
    (`GraphTransport.php:31-60`).
  - `GraphMailClient::sendMail` sets `from = {address}` with no name. A From on another
    domain is silently replaced with the real mailbox (`GraphMailClient.php:141-192`).
  - Exchange then shows the mailbox's own display name, "Central Executive Transfers".
- **Even over SMTP:** a message From `admin@centralexecutivetransfers.co.uk` delivered to
  that same Exchange mailbox is usually shown under the directory (GAL) name, not the
  header name.

The handover already warns that the Settings "Send test email" button bypasses the PVT
toggle (`SettingsController.php:83-90`), so a passing test does not prove the toggle is on.

**Data that would confirm it:**
- **The received office email's raw headers.**
  - If `From:` reads `"Central Executive Transfers PVT LTD" <admin@centralexecutivetransfers.co.uk>`,
    the name was right in the message and the mail client rewrote it.
  - If it reads the bare mailbox, Graph rewrote it.
  - Resend mail carries Resend/SES `Message-ID` and `Received` headers. Graph mail carries
    `X-MS-Exchange-*` headers.
- **That booking's `meta.billing_entity`** and its `email_log` entry `from` address. The
  main address there means PVT sending was off.
- **Settings values** via tinker, read-only, on staging:
  - `mail_pvt_enabled`, `mail_transport`
  - whether `mail_pvt_resend_key` is filled
  - `config('cet.company_novat.name')`, in case `CET_NOVAT_COMPANY_NAME` is overridden in
    `.env`.

**Customer emails are different by design.** `senderName()` always returns the brand, never
"PVT LTD" (`Booking.php:5913-5916`). If the operator expects "PVT LTD" in the customer's
From name, the current design never does that (see F7).

---

## Findings (ranked)

### F1. CRITICAL: A recorded payment whose follow-up step fails never gets its emails, and nothing retries
- **Where:**
  - `BookingStatusService.php:228-273` (`onFarePaid`) and `:83-152` (`confirmPaidWebBooking`)
  - `StripePaymentService.php:275`, `SquareBookingPaymentService.php:251`
  - `WebhookController.php:123-125, 175-177, 208-210`
  - `ReconcilePayments.php:35-39, 46-50`
  - `BookingController.php:561-567`
- **Scenario:**
  1. `markFarePaid()` saves the payment and the paid status outside any transaction.
  2. `onFarePaid()` then runs the rotation allocation, `adminAlerts->notify`, the voucher
     redeem, `fareVatBreakdown` and the two emails.
  3. Anything in step 2 that throws (rotation, push, a DB hiccup, or `MailFailure::report`
     itself, see F20) is a 500 to the provider.
  4. The provider retries. `markFarePaid` now returns false ("already recorded"), so
     `recordFareFromWebhook` returns null, so `onFarePaid` is **never called again**.
  5. The new reconcile command has the same "only if newly recorded" gate, so it never
     heals it either.
  6. The only heal is an admin **opening the booking page** (`BookingController::show`).
     Booking reads Paid, and neither the customer nor the office is emailed.
- **Fix:**
  - Separate "record the money" from "follow-up". Make `cet:reconcile-payments` also sweep
    bookings with `meta.square_payment` set and (`paid_email_sent` empty, or
    `office_email_paid_sent` empty, or `payment_status != paid`) from the last N days.
    Call `onFarePaid()` on each, in its own try/catch.
  - Wrap `confirmPaidWebBooking`'s non-essential steps (rotation, alerts) so they cannot
    stop the emails.
- **Test:** `ReconcilePaymentsTest::test_recorded_payment_with_missing_emails_is_healed`.
  Seed a booking with `square_payment` and no email flags, make rotation throw once, run
  the command, assert `Mail::assertSent(BookingConfirmationMail)` and `OfficeBookingMail`
  with stage `paid`.

### F2. HIGH: A second payment (balance after a re-price) sends no customer or office email
- **Where:** `BookingStatusService.php:163-165` (`paid_email_sent`) and `:201-203`
  (`office_email_paid_sent`), with the add-not-overwrite behaviour from d572d03
  (`Booking.php:5979-5995`).
- **Scenario:**
  1. The customer pays £55.
  2. The office re-prices to £100, and the customer pays the £45 balance.
  3. `onFarePaid` runs, but both "sent once" flags are already set, so the customer gets
     no receipt and the office gets no "Payment received" email for the £45.
- **Fix:**
  - Key the flags per payment: store the `square_payment.id` (or the running total)
    last emailed, and send again when a new payment id or a higher total appears.
  - Subject: "Balance received".
- **Test:** `PaidEmailsTest::test_balance_payment_after_reprice_emails_customer_and_office_again`.

### F3. HIGH: Office email failures are silent (log line only, no alert, no history)
- **Where:**
  - `BookingStatusService.php:212-214` (`emailOffice`, `Log::warning` only)
  - `BookingWidgetController.php:658-664`
  - `PublicBookingController.php:222-228`, and the enquiry email at `:249`
- **Scenario:**
  - A PVT booking's office copy goes through the PVT mailer (`OfficeBookingMail.php:32-34`).
    If Resend rejects it (domain unverified, key revoked) or SMTP is blocked, the office
    never hears about a new booking or a payment.
  - Only `storage/logs` knows. There is no `email_failed` watchdog alert, and no failed
    entry in the booking's email history (the customer paths use `MailFailure::report`,
    the office paths do not).
- **Fix:** Route every office send through `MailFailure::report('office notice (stage)', ...)`.
  Consider using `emailOffice('new')` from the widget and public store too, so the
  sent-once flag and the reporting are uniform.
- **Test:** `OfficeEmailFailureTest`. Make the mailer throw, assert a `WatchdogEvent`
  of type `email_failed` and a failed `email_log` entry for both the `new` and `paid` stages.

### F4. HIGH: No fallback when the PVT mailer fails, so all PVT email stops at once
- **Where:**
  - `Booking.php:5952-5970` (`pvtMailerFor`)
  - The Mailable constructors calling `$this->mailer($name)`: `BookingConfirmationMail.php:36-38`,
    `OfficeBookingMail.php:32-34`, `PaymentLinkMail.php:27-29`, `BookingCancellationMail.php:25-27`,
    `BookingReceiptMail.php:37-39`
- **Scenario:**
  - Once "Send PVT LTD emails" is on with a Resend key, every no-VAT customer confirmation,
    pay link, paid receipt and office notice goes **only** via Resend.
  - One Resend problem means none of them send. The customer ones raise an alert. The
    office ones do not (F3).
  - This conflicts with "emails should always work".
- **Fix:**
  - For **office** mail (internal), on a PVT mailer exception retry once through the
    default mailer from the main address. Mark it in `email_log` as "sent via main
    (PVT failed)".
  - For **customer** mail, whether to fall back to the main company sender is a director
    decision. If no, raise a critical alert rather than a warning.
- **Test:** `PvtMailerFallbackTest`. Fake the `resend` transport to throw, assert the office
  mail is still sent on the default mailer and an alert is logged.

### F5. HIGH: The stable pay link mints a new provider link on every click, so a customer can be charged twice
- **Where:**
  - `BookingWidgetController.php:707-740` (`pay`)
  - `StripePaymentService.php:64-83, 93-139` (a new Price plus a new single-use Payment
    Link on every call)
  - `SquareBookingPaymentService.php:173-212` (a new order and payment link on every call)
  - No overpayment check in `Booking.php:5972-6013` (`markFarePaid`)
- **Scenario:**
  1. While webhooks are broken (as with CET-CB4218) the booking keeps reading unpaid.
  2. The customer or the office clicks "Pay now" again (email, thank-you page, office
     notice link). `pay()` sees `fareIsPaid()` false and creates a **fresh** link.
  3. The customer pays a second time.
  4. Reconcile later records both payment ids, so `onlinePaidTotal` is 2x, with no alert.
  - Email link scanners that pre-fetch GET URLs also mint stray Stripe Prices and Links.
- **Fix:**
  - Store the provider link per (booking, amount) in meta (Stripe `plink_` id and URL;
    Square `payment_link.id`, `order_id` and URL) and reuse it while the amount is
    unchanged. Stripe's `completed_sessions.limit=1` then blocks a second payment.
  - Before minting, check the provider for a completed session or order for that stored link.
  - In `markFarePaid`, raise a critical alert when `onlinePaidTotal > amountPayable + 0.01`
    ("possible double charge, refund").
- **Test:** `WidgetPayTest::test_second_click_reuses_the_same_stripe_link`.
  `MarkFarePaidTest::test_overpayment_raises_alert`.

### F6. HIGH: A PVT booking can be charged on the VAT company's Square account
- **Where:**
  - `PaymentGateway.php:30-36, 44-55`
  - `SquareBookingPaymentService.php:36-64`: `account('chauffeurs')` silently returns the
    **main** account when the sister isn't configured.
  - `:177`, and `PublicBookingController.php:232` (uses `enabled()` for any provider, not
    the booking's own).
- **Scenario:**
  1. Bookings are now stamped `chauffeurs` regardless of Stripe.
  2. If Stripe is ever off (key cleared, rotated, or a setting cache miss), `PaymentGateway`
     falls through to `square->enabled('chauffeurs')`, which is true via the main account.
  3. The PVT customer pays **Central Executive Transfers Ltd's** Square.
  4. `/webhooks/square` records `entity: transfers` while the invoice and sender say PVT LTD.
  5. No alert. It looks like it worked.
- **Fix:**
  - In `PaymentGateway`, `chauffeurs` may only use Stripe or a **genuinely configured**
    Square sister. Add `SquareBookingPaymentService::sisterConfigured()` and never use the
    main fallback for fares.
  - Return null and alert the office ("PVT card payments not configured") instead.
  - Use `enabledForBooking()` in `PublicBookingController`.
- **Test:** `PaymentGatewayTest::test_pvt_booking_never_routes_to_main_square_when_stripe_is_off`.

### F7. MEDIUM-HIGH: The PVT sender identity is decided in four places, and transports can rewrite it
- **Where:**
  - `OfficeBookingMail.php:44-50` uses the PVT name.
  - `Booking::senderName()` (`Booking.php:5913-5916`) always uses the brand name.
  - `senderAddressFor()` (`Booking.php:5899-5910`) uses the main address unless PVT is enabled.
  - Graph drops names and rewrites cross-domain From (`GraphMailClient.php:141-192`).
  - Gmail API may rewrite the From to the authorised mailbox (`GmailApiTransport.php:100-107`).
- **Scenario:** The operator sees "Central Executive Transfers" on PVT mail and can't tell
  whether the routing or the transport is wrong. A customer PVT email never says "PVT LTD"
  in its From name.
- **Fix:**
  - One method, `Booking::senderIdentity(): Address`, used by every Mailable, with the PVT
    name for `chauffeurs`.
  - Log in `email_log` the **mailer used** and the From **name**, not just the address
    (`BookingEmailLog.php:55-64` records addresses only).
  - Have the "Sending as" label and Settings flag "PVT on, but the default mailer is Graph,
    so the PVT name and address will be rewritten".
- **Test:** `SenderIdentityTest`. For a PVT booking with PVT on and with PVT off, assert the
  envelope From name and address on Confirmation, Office, PaymentLink, Cancellation and
  Receipt. Assert `email_log` records the mailer and name.

### F8. MEDIUM: Invoice company and sender/provider come from different sources of truth
- **Where:**
  - Invoice and VAT use `vatInvoiceRequested()`, which **includes `payment_method = account`**
    (`Booking.php:1134-1138`).
  - Sender and provider use `billingEntity()`, which looks only at `meta.vat_invoice_requested`
    (`Booking.php:5868`).
- **Mismatch cases:**
  - **Admin account job without the VAT tick.** `BookingController.php:383` stamps
    `chauffeurs`. The invoice is Ltd with VAT, but the sender is PVT and any card link is Stripe.
  - **`setVatInvoiceRequested(false)` while Stripe isn't ready** (`Booking.php:1154-1160`)
    stamps `transfers`. The invoice says PVT LTD (`companyFor(false)`) but the email comes
    from Ltd and the money goes to Ltd's Square. The flash message still says "PVT LTD
    (card via Stripe)" (`BookingController.php:1763-1766`).
  - **The VAT toggle changes one leg only** (`BookingController.php:1755-1767`). The other
    leg of a return keeps the old entity, but `pay()` charges the whole trip through the
    clicked leg's provider.
  - **Unstamped creation paths** recompute every time and drop to `transfers` whenever the
    Stripe key reads blank: intake (`BookingIntakeService.php:225`), Outlook ingest
    (`OutlookBookingService.php:293`), ETO/calendar import, recurring.
  - A ledger link minted before a toggle stays live on the old provider.
- **Fix:**
  - Have `billingEntity()` use `vatInvoiceRequested()`.
  - Always stamp no-VAT as `chauffeurs` in `setVatInvoiceRequested(false)`.
  - Apply the toggle to `invoiceGroupBookings()`.
  - Stamp in intake, Outlook and recurring.
  - Cancel or flag open ledger links on toggle.
- **Test:** `BillingEntityConsistencyTest`: an account job is `transfers`, the toggle covers
  both legs, toggle-off with no Stripe is still `chauffeurs`, and an intake booking is stamped.

### F9. MEDIUM: Gaps in the reconcile safety net (068869e)
- **Where:** `ReconcilePayments.php`, `StripePaymentService.php:239-322`,
  `SquareBookingPaymentService.php:219-356`, `routes/console.php:82-83`.
- **Gaps:**
  1. **Follow-ups are not retried.** It only calls `onFarePaid` when the payment is
     *newly* recorded (F1).
  2. **No per-item try/catch.** One `onFarePaid` exception aborts the whole run, so later
     payments wait for the next run and hit the same exception. `->after()` stamps the
     heartbeat even when the command failed, so health reads green.
  3. **Square uses the same order lookup as the webhook.** If the token lacks `ORDERS_READ`
     (the suspected Square cause), reconcile fails exactly as the webhook did, with a log
     warning every 5 minutes per payment. Fix: save `order_id` and `payment_link.id` on the
     booking when the link is created (`createCheckoutUrl` gets them back), and match by
     `payment.order_id` first.
  4. **Reference matching is fragile.** `where external_reference = X or reference = X ->first()`
     has no order, so:
     - **Office edits `external_reference`** (`BookingService.php:351-354`) after a ledger
       link is minted. The old ref no longer matches, and there is only a log warning.
     - **Duplicates:** the unique index is per `(source_system, external_reference)`, and
       NULL `source_system` allows duplicates. Collisions between `reference` and another
       booking's `external_reference` are not prevented.

     Fix: use `metadata.cet_booking` (Stripe already sends it, `StripePaymentService.php:76`)
     and the stored Square `order_id` as the primary key. Use the reference only as a
     fallback. Raise an **alert**, not a log line, for "paid but no booking matched".
  5. **Customer edits the Payment Link URL.** `client_reference_id` wins over metadata
     (`StripePaymentService.php:253-257`). A changed `client_reference_id` marks a
     **different** booking paid. A removed one falls back to `metadata.reference`.
     Stripe's docs say Payment Link metadata is copied to the session; confirm on the
     CB4218 session JSON. Fix: metadata `cet_booking` is the authority, and a mismatch
     raises an alert.
  6. **Window.** `--days=3`. A deploy more than 3 days after a missed payment never sees it.
     After deploying, run `php artisan cet:reconcile-payments --days=14` once. The Square
     order-ref cache lasts 2 days but the window is 3 days, so a re-fetch on day 3 (minor).
  7. **Async payment methods.** Payment Links don't restrict `payment_method_types`, so the
     Dashboard's automatic methods apply. An async method completes with
     `payment_status = unpaid` and later fires `checkout.session.async_payment_succeeded`,
     which the webhook ignores (`StripePaymentService.php:241`). Reconcile catches it within
     3 days. Fix: handle that event too, or set `payment_method_types[]=card` on the link.
  8. **Race.** Webhook and reconcile on the same payment at once: both pass the "already
     recorded" and "sent once" checks, so the customer and office get double emails. Fix:
     `Cache::lock('fare:'.$booking->id)` around record plus `onFarePaid`.
  9. **COVER- payments** (combined cover invoices) are not reconciled at all.
  10. **Square location.** Square list is filtered by the current `location_id`, so a
      location change in Settings hides older payments.
- **Tests:**
  - `ReconcilePaymentsTest::test_one_failing_booking_does_not_stop_the_run`
  - `test_square_matched_by_stored_order_id_without_orders_read`
  - `test_stripe_metadata_booking_id_beats_tampered_client_reference`
  - `test_concurrent_webhook_and_reconcile_send_one_email`

### F10. MEDIUM: A paid confirmation skipped by the safety gate is logged at info level and nobody is told
- **Where:** `BookingStatusService.php:174-178`, `Booking.php:1416-1445`.
- **Scenario:**
  - A payment that lands (or is reconciled) more than 2h after pickup, or on a
    completed/cancelled job, is skipped with `Log::info` only.
  - The `source_system` eto/calendar skip at `:169-171` is silent too.
  - The office assumes the customer has their receipt.
- **Fix:**
  - Write a "skipped (reason)" entry to `email_log`.
  - Add a line to the office "Payment received" email: "Customer NOT emailed: pickup
    already passed, send the receipt by hand".
- **Test:** `PaidEmailsTest::test_skipped_paid_confirmation_is_recorded_and_flagged_to_office`.

### F11. MEDIUM: A return paid in one go can leave the other leg unpaid
- **Where:** `BookingStatusService.php:254-256`.
- **Scenario:**
  - `pay()` charges the **outstanding** across both legs (after deposits).
  - The cascade only settles leg B if the payment is at least the **gross** group total.
  - With any deposit or earlier part-payment, the customer pays the whole balance, but
    leg B stays pending and gets no confirmation.
  - The office then chases a customer who has already paid.
- **Fix:** Compare against the group's outstanding amount at payment time. Store
  `meta.square_payment.covers_group = [ids]` when `pay()` charges for several legs, and
  settle exactly those.
- **Test:** `ReturnPaymentTest::test_full_trip_payment_after_deposit_settles_both_legs`.

### F12. MEDIUM: Emails are sent during the web request with no timeouts, so a slow mail server can kill the request
- **Where:**
  - `config/mail.php:48` (`'timeout' => null`, which uses PHP's default socket timeout,
    usually 60s)
  - `ResendClient.php:58` and `GraphMailClient.php:175` (no `->timeout()`, so Laravel's
    default 30s)
  - `BookingWidgetController.php:636-664` (up to 3 sends in a row)
- **Scenario:**
  - The host is known to block SMTP. A hanging connection exceeds `max_execution_time`,
    which is a fatal error that `catch (\Throwable)` cannot stop.
  - The booking exists, but the thank-you or pay page never renders, and some emails and
    flags are missing.
- **Fix:**
  - Set an SMTP timeout (for example 10s) and `->timeout(10)` on the Resend and Graph calls.
  - Better: send the office email after the response (`dispatch(...)->afterResponse()`),
    or queue all mail with a worker, with the reconcile sweep as backstop. The cron is
    already required, so a minute-tick `queue:work --stop-when-empty` is free.
- **Test:** `WidgetStoreTest::test_mail_exception_still_renders_pay_page` (exception path),
  plus a config assertion that the timeouts are set.

### F13. MEDIUM: The `/book` public flow sends the customer nothing before payment
- **Where:** `PublicBookingController.php:230-237`.
- **Scenario:**
  - A priced booking redirects straight to checkout **before** any customer email. A
    customer confirmation exists only if the webhook succeeds.
  - An abandoned checkout, or a broken webhook, leaves the customer with no record at all.
  - It also uses `payments->enabled()` (any provider) instead of `enabledForBooking()` (F6).
- **Fix:** Reuse `CustomerBookingMailer::confirmIfWebBooking()` before the redirect, as the
  widget does. Use `enabledForBooking()`.
- **Test:** `PublicBookingTest::test_priced_booking_emails_confirmation_with_pay_link_before_redirect`.

### F14. MEDIUM-LOW: "Payment unavailable" is shown to the customer without alerting the office
- **Where:** `BookingWidgetController.php:735-737`.
- **Scenario:** If the Stripe or Square API errors when the link is built, the customer
  lands on `widget.paid?unavailable=1` and the office only gets a `Log::warning`.
- **Fix:** `WatchdogEvent::log('payment_link_failed', ..., 'critical', $booking)` plus an
  admin push.
- **Test:** `WidgetPayTest::test_provider_failure_alerts_office`.

### F15. LOW: The Square sister webhook ignores keys saved in Settings
- **Where:** `WebhookController.php:145-146` and `Booking.php:5873-5874`
  (`squareSisterReady`) read `config()` only. `SquareBookingPaymentService::account()`
  reads Settings first.
- **Scenario:** Sister keys entered in Settings mean the sister webhook replies 403 "not
  configured" and `billingEntity()` thinks the sister isn't ready. This is a legacy path,
  but reconcile now uses Settings, so the two disagree.
- **Fix:** One `sisterConfigured()` helper reading Settings, then config.
- **Test:** `SquareSisterWebhookTest::test_settings_keys_accept_webhook`.

### F16. LOW: Emails are sent inside a DB transaction
- **Where:** `BookingStatusService.php:91-151` (`emailPaidConfirmation` at `:138`).
- **Scenario:** If anything after the send throws, the transaction rolls back the
  `paid_email_sent` flag but the email has gone. The next heal sends a duplicate.
- **Fix:** Send after commit (`DB::afterCommit`, or move the call out of the closure).
- **Test:** Covered by the F1 test (assert exactly one send).

### F17. LOW: The return leg gets a second paid email despite the comment saying it won't
- **Where:** `BookingStatusService.php:265-267`, which calls `confirmPaidWebBooking`, which
  emails at `:138`.
- **Scenario:** A paid return sends the customer two "paid" confirmations (A and B). This
  may be what the directors want, but the code comment says the opposite.
- **Fix:** Decide which. Pass a `$email = false` flag for the cascade if one email is wanted.
- **Test:** `ReturnPaymentTest::test_paid_return_sends_expected_number_of_customer_emails`.

### F18. LOW: The stable pay links depend on APP_KEY and APP_URL
- **Where:** `URL::signedRoute('widget.pay')` at `CustomerBookingMailer.php:76`,
  `ConfirmationEmailState.php:59`, `OfficeBookingMail.php:85`, `BookingController.php:467`.
- **Scenario:** A key rotation or a host change at go-live returns 403 on every pay link
  already emailed. Links built from the CLI (reconcile) use `APP_URL`, so a wrong `APP_URL`
  on staging breaks the tip and pay links in cron-sent emails.
- **Fix:** Go-live checklist item. Optionally an id+token route that isn't tied to the host.
- **Test:** None. Manual check.

### F19. LOW: Tip link is hidden when the Square keys live only in Settings
- **Where:** `SquareTipService.php:38-42` (`enabled()` is config-only), used by
  `BookingConfirmationMail.php:52`.
- **Fix:** Read Settings first, like the fare service.

### F20. LOW: `MailFailure::report` can itself throw
- **Where:** `MailFailure.php:28-33` (`WatchdogEvent::log` is not wrapped).
- **Scenario:** It runs inside every caller's `catch`. A DB error there escapes the catch
  and turns a soft mail failure into a 500 (and feeds F1).
- **Fix:** Wrap it in try/catch.

---

## Cron and queue dependencies (question 4)
- **No mail is queued.** No Mailable implements `ShouldQueue`, and every send is
  synchronous `Mail::send`, so a stopped queue worker cannot hold up email.
  `QUEUE_CONNECTION` defaults to `database` but nothing relies on it for mail. (F12 argues
  for deliberately moving office mail off the request.)
- **The cron is the only safety net.** `cet:reconcile-payments` (new), calendar push,
  watchdog alerts and auto-deploy all need `schedule:run`. Without the cron, a missed
  webhook is healed only when an admin opens the booking page
  (`BookingController.php:561-567`).
- **Health can read green when reconcile fails.** The heartbeat stamps on
  `after()`, which runs even when the command fails (F9.2). Use `onSuccess()` for the
  heartbeat and `onFailure()` for an alert.
- **Settings are cached forever** (`Setting.php:15`). CLI and web share the cache store, and
  `optimize:clear` clears it. A Settings change made outside `Setting::set()` (for example
  hand-editing the DB) is invisible until the cache is cleared, including the PVT toggle
  and the Stripe key.

## Recommended order (when the operator is awake, nothing deployed tonight)
1. **Diagnose (no code):** get the CB4218 Stripe delivery log, the Stripe search for a
   second charge, the PVT email raw headers and the PVT settings values (see the
   "Most likely explanation" section).
2. **Deploy 068869e** with the operator, then run
   `php artisan cet:reconcile-payments --days=14` once.
3. **First patch:**
   - F1 (sweep for recorded payments with missing emails, plus per-item try/catch)
   - F3 (report office email failures)
   - F2 (per-payment email flags)
   - F5 (reuse the provider link and add an overpayment alert)
   - F6 (no main-Square fallback for PVT)
4. **Second patch:**
   - F4 (PVT mailer fallback; needs a director decision for customer mail)
   - F7 and F8 (single sender identity and single entity source)
   - F9 (match by stored ids, lock)
   - F12 (timeouts)

All of these can be done without a migration (meta and settings only).
