# ETO Email → CET

ETO's outbound email config: From name, From email, Reply-to, Admin email,
Connection type (Sendmail / SMTP), a test-email button, and an email health tool.
(CET values shown: from "Central Executive Transfers", admin@centralexecutivetransfers.co.uk.)

## CET today
- Laravel mail is configured via `.env` (MAIL_* → from name/address, SMTP host).
  CET already sends: web-booking customer confirmations (`CustomerBookingMailer`),
  review/tip requests (email option), and invoice PDFs.
- Inbound (ETO enquiry/booking emails) comes via the Outlook/Graph client.

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| From name / From email | ✅ | — | Set in `.env` (`MAIL_FROM_NAME`, `MAIL_FROM_ADDRESS`). |
| Reply-to | 🟡 | Nice | Add a reply-to if they want replies to a different inbox. |
| Admin email (where office copies go) | 🟡 | Nice | A settings value for the office notification inbox. |
| Connection type Sendmail / **SMTP** | ✅ | — | Use SMTP in `.env` — more reliable than sendmail. |
| Send a test email | ❌ | Nice | Add a "send test email" button on a CET settings page. |
| Email health report (SPF/DKIM/DMARC) | ❌ | Nice | Nice-to-have deliverability check; not a blocker. |

## Verdict
Email works; it's configured in `.env` rather than an admin screen. For go-live
the key is **deliverability**: SMTP set, and SPF/DKIM/DMARC on
`centralexecutivetransfers.co.uk` so confirmations/invoices don't spam-filter.
A small in-app "test email + from/reply-to/admin" settings panel is **Nice**.

**Action for go-live:** confirm SMTP + SPF/DKIM/DMARC are set on staging/live.
