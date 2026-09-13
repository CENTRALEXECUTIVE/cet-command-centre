# ETO Notifications → CET

ETO's notifications are a big matrix: for each **event** (Booking confirmed,
unconfirmed, quote request, driver assigned, job accepted/rejected, en route,
arrived, on board, no-show, completed, cancelled, driver cancelled, updated,
incomplete, fleet assigned/removed; Feedback: comment/lost property/complaint;
Users: awaiting approval/activated) × each **recipient** (Admin, Customer,
Driver, Fleet Operator) × each **channel** (**Email, SMS, Push, Panel**), with
extras: attach iCalendar, attach booking details, attach invoice, attach
driver/vehicle details, attach tracking link, a footer message, and "skip
notifications about your own actions".

## How CET does notifications (different by design)

| Channel | CET | Notes |
|---|---|---|
| **SMS** | **Skip** | Costs money per message — against the cost rule. CET uses WhatsApp instead. |
| **WhatsApp** (ETO has none) | ✅ | CET's main customer channel: office taps a `wa.me` link (driver details, reminders, review, tip). Deliberately **manual** — nothing auto-sends to customers. |
| **Email** | ✅ (partial) | Auto customer confirmation on web bookings; invoices; review/tip email option. Could extend to more events (free). |
| **Push** | ✅ (drivers) | Driver web-push for new job / nudges. Customers aren't app users, so no customer push. |
| **Panel** (in-app) | ✅ | The control-tower alerts feed + watchdog cover the admin "panel" notifications. |

## Event-by-event (CET status)

| ETO event | CET equivalent | Notes |
|---|---|---|
| Booking confirmed → customer | 🟡 | Web bookings get an email confirm; office WhatsApps others by hand. |
| Quote request → admin/customer | ✅ | Enquiry inbox + office reply. |
| Driver assigned → driver | ✅ | Driver push + the job appears in their app; driver-details WhatsApp to customer (manual). |
| Job accepted / rejected / declined | ✅ | Status flow + watchdog escalation. |
| En route (+ tracking link) → customer | 🟡 | CET has the tracking link (`/track/{token}`); currently shared by hand, not auto-emailed. |
| Arrived / on board / completed | ✅ (office) | Progress pings to the office; customer messaging is manual. |
| No-show / cancelled → admin + customer | ✅ (admin) | Office alerted immediately; customer cancellation message manual. |
| Driver cancelled / assignment removed → driver | ✅ | Push + status. |
| Booking updated (+iCalendar) → driver | 🟡 | Driver sees live job; no iCalendar attach. |
| Feedback (comment / lost property / complaint) → admin | 🟡 | Review dashboard exists; dedicated lost-property/complaint intake not built. |
| Users awaiting approval / activated → admin | ✅ | User management. |
| Attach iCalendar to email | ❌ | Nice. |
| Attach invoice to completed email | 🟡 | Invoices exist; auto-attach on completion is a toggle. |
| Attach tracking link to en-route | 🟡 | Link exists; auto-send is the gap. |
| "Skip notifications about your own actions" | ✅ | Watchdog already won't alert a director about their own job in some paths. |
| Booking-details email footer message | ❌ | Nice — a configurable footer. |

## The big decision (needs Abdi)
ETO leans on **auto email + SMS to customers** for every status. CET's model is
**manual WhatsApp + auto email only for web bookings**, on purpose (cost + control,
premium personal touch). Options:
- **Keep CET's model** (recommended) — WhatsApp stays manual; email stays for
  web confirmations + invoices. SMS off (cost).
- **Add opt-in auto customer emails** for key events (confirmed, en-route with
  tracking link, completed + invoice). Free, and closes most of the ETO gap
  without SMS costs.

**SMS: Skip** either way (paid, and WhatsApp covers it).

## Verdict
Not a go-live blocker — CET already notifies the office (panel/push) and the
driver, and handles customers by WhatsApp/email. The one worthwhile build is an
**opt-in auto-email set** (confirmed / en-route+tracking / completed+invoice) IF
Abdi wants less manual tapping. Decide the model, then I'll wire the chosen emails.
