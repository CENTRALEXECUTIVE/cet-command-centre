# ETO Invoices → CET

ETO invoice settings + the KB article on how invoices work.

## What ETO does (from the KB article)
- Auto **PDF invoice attached to the booking confirmation email** (web / app /
  admin-assisted).
- Customers download/print invoices from their **online account**.
- **Corporate accounts: deferred payment + periodic (consolidated) invoices** —
  book now, bill later, one invoice covering many bookings.
- Invoice content configurable: journey details, company logo, payment history,
  custom fields 1–4, customer company number, customer VAT number, additional /
  company info.

## CET today
- `InvoiceService` + `BookingInvoicePdf` / `InvoicePdf` generate invoice PDFs;
  invoices routes (`/invoices`, PDF download) exist; VAT handled (`VatService`).

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Enable invoices | ✅ | — | |
| Journey details / company logo / payment history | ✅/🟡 | Nice | Confirm logo + payment-history render on CET's PDF. |
| Custom fields 1–4 | ❌ | Nice | Rarely needed; add if a client asks. |
| Customer **company number + VAT number** on invoice | 🟡 | **Need** | Account customers (JELD-WEN etc.) need their company + VAT no. on the invoice. |
| Additional / company info block | 🟡 | Nice | CET's own company details block. |
| Auto-attach invoice PDF to confirmation email | 🟡 | Nice | CET emails web confirmations; confirm the PDF is attached. |
| **Periodic / consolidated account invoices** | ❌ | **Need** | The big one: monthly statement per corporate account covering many bookings + deferred (on-account) billing. CET has per-booking invoices only. |

## Verdict
Per-booking invoicing exists. The **real gap is corporate account billing**:
deferred "on account" bookings + a **monthly consolidated invoice per account**
(JELD-WEN, LB Foster, Forged Solutions…), with the customer's company + VAT
number on it. That's a **Need** for account customers and pairs with the
"account payment method" item from the widget screen.
