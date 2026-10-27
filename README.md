# Account and Finance Management

Hotel Management System – Accounts and Finance Department module.

A PHP and MySQL web application for a hotel's finance team. It covers guest billing, invoices, payments, deposits, refunds, expenses, suppliers, reports, reconciliations, budgets and period closing, with role-based access and an audit trail. The look is dusty pink, khaki and cream.

> **Important:** this code was written without a PHP or MySQL server available, so it has **not been run**. Work through the testing checklist in section 8 and report any error message you see.

## 1. Requirements

- XAMPP with **PHP 8.0 or newer** (the code uses `match`) and MariaDB or MySQL.
- A web browser and an **internet connection** (Bootstrap, Bootstrap Icons, Google Fonts and Chart.js load from CDNs).
- Visual Studio Code (optional, for editing).

## 2. Install and run

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy the `hotel-finance` folder into `C:\xampp\htdocs\` (Mac: `/Applications/XAMPP/htdocs/`, Linux: `/opt/lampp/htdocs/`). Keep the folder name.
3. Open `http://localhost/phpmyadmin`, choose **Import**, select `hotel-finance/database.sql` and click **Import**. It drops and recreates `hotel_finance_db`, so use it only for a fresh start.
4. If your MySQL root user has a password, set `DB_PASS` in `config/database.php`.
5. Open `http://localhost/hotel-finance/` and log in.

## 3. Test logins

| Role | Username | Password |
|---|---|---|
| Finance Administrator | `admin` | `Admin@123` |
| Finance Staff | `staff` | `Staff@123` |
| Finance Manager | `manager` | `Manager@123` |

Change these passwords in **Users and Roles** before any real use.

## 4. Project structure

```text
hotel-finance/
├── config/            database.php (PDO connection), .htaccess (blocks web access)
├── includes/          functions.php, auth.php, permissions.php, finance.php,
│                      header.php, sidebar.php, footer.php
├── assets/            css/style.css (theme), js/app.js, images/
├── index.php, login.php, logout.php, dashboard.php
├── guest-billing.php, folio-verification.php, invoices.php, receipts.php
├── payments.php, payment-verification.php, deposits.php, refunds.php,
│   discounts.php, billing-adjustments.php
├── expenses.php, suppliers.php, accounts-payable.php, accounts-receivable.php,
│   purchase-payments.php, payroll-expenses.php
├── reports.php, budgets.php, cash-reconciliation.php, payment-reconciliation.php,
│   audit-trail.php, period-closing.php, financial-settings.php, settings.php
└── database.sql       schema, views, triggers and sample data
```

All colors are CSS variables at the top of `assets/css/style.css`.

## 5. Database overview (33 tables, 5 views)

| Group | Tables |
|---|---|
| Access control | `roles`, `permissions`, `role_permissions`, `users` |
| Hotel demo data | `guests`, `rooms`, `reservations` |
| Billing | `guest_folios`, `folio_charges`, `invoices`, `invoice_items` |
| Payments | `payments`, `payment_verifications`, `receipts`, `guest_deposits`, `deposit_applications`, `refunds`, `discounts`, `billing_adjustments` |
| Expenses and payables | `expense_categories`, `expenses`, `suppliers`, `supplier_invoices`, `supplier_payments`, `payroll_expenses`, `budgets` |
| Controls | `financial_settings`, `tax_service_rates`, `document_sequences`, `financial_periods`, `cash_reconciliations`, `payment_reconciliations`, `audit_logs` |
| Views | `v_invoice_balances`, `v_accounts_receivable`, `v_supplier_balances`, `v_revenue_events`, `v_expense_events` |

### Main relationships

- A **role** has many **users** and many **permissions** (`role_permissions`).
- A **guest** has **reservations** for **rooms**. A reservation can have one **folio**; a walk-in folio has no reservation.
- A **folio** has many **charges**. An **invoice** is created from a folio, and each **invoice item** points back to its charge.
- An **invoice** has many **payments** (partial payments), and a verified payment can have one **receipt**.
- An invoice can have many **discounts** and **billing adjustments**. Both need approval, and the original total is kept.
- A **deposit** can be applied to invoices of the same guest (`deposit_applications`) or refunded (`refunds`).
- A **refund** points to a payment or a deposit; it never deletes them.
- A **supplier** has many **supplier invoices**, each with many **supplier payments**.
- Invoices, payments, expenses, supplier payments and payroll carry a `period_id` linking to **financial periods**, which is how closing locks them.
- **Audit logs** record who did what. Database triggers block any change to them, and block deleting invoices, payments, receipts and refunds.

## 6. Financial rules

| Rule | Where |
|---|---|
| Service charge = taxable subtotal × service rate; tax = (taxable subtotal + service charge) × tax rate | `calc_invoice_totals()` |
| Invoice total = subtotal + service + tax − approved discounts ± approved adjustments | `recalc_invoice_total()` |
| Outstanding balance = total − (verified payments − completed payment refunds) − deposits applied | `v_invoice_balances` |
| Remaining deposit = received − applied − refunded | generated column |
| Cash discrepancy = counted cash − expected cash | generated column |
| Budget remaining = budget − actual eligible expenses | `budgets.php` |
| Net result = recognized revenue − recognized expenses | `reports.php`, dashboard |

**Revenue** is cash basis: a verified payment counts on its payment date and a completed refund of a payment reduces it. A deposit is not revenue until applied to an invoice, and a deposit refund never reduces revenue.
**Expenses:** operating expenses on the expense date, supplier invoices only when paid, payroll on the pay-period end date. Voided entries are excluded. Nothing is counted twice.
**Currency:** Philippine peso (₱). Rates are saved on each invoice, so a rate change never alters old invoices.

## 7. Security built in

- Passwords use `password_hash()` and `password_verify()`; login locks for one minute after 5 failed attempts; sessions expire after 30 minutes of inactivity.
- Every database query uses prepared statements; all output is escaped; every form has a CSRF token.
- Role-based access is checked on every page and every action. Payroll is restricted.
- Segregation of duties: nobody can verify a payment they recorded or approve a refund, discount or adjustment they requested.
- Card numbers and CVV codes are never stored (only card type and last 4 digits); references that look like card numbers are rejected.
- Sensitive actions are written to the audit trail.

## 8. Testing checklist (all 34 features)

Log in as the user shown. Tick each item when it works.

| # | Feature | Log in as | Test |
|---|---|---|---|
| 1 | Dashboard | any | Cards, 3 charts, summary and recent activity load; changing the month changes the figures |
| 2 | Guest billing | staff | Search and filter bills; open `FOL-2026-0005`; add and remove a charge; create a bill from reservation `RSV-2026-0007` |
| 3 | Folio verification | staff | Verify one pending charge, flag another with a note, bulk-verify, confirm invoiced charges are locked |
| 4 | Invoice generation | staff | Make an invoice from a folio whose charges are all verified; open it and use Print |
| 5 | Invoice numbering | staff | New invoice gets the next `INV-2026-####`; cancel it and confirm the number is not reused |
| 6 | Payment recording | staff | Record a payment; try an amount above the balance and see the error |
| 7 | Cash payments | staff | Record cash with a cashier name |
| 8 | Card payments | staff | Record a card payment with only the last 4 digits; try a 16-digit reference and see it rejected |
| 9 | GCash and Maya | staff | Record both with reference numbers; reuse a reference and see the error |
| 10 | Bank transfers | staff | Record a transfer with bank name and reference |
| 11 | Payment verification | manager | Verify and reject payments; confirm `staff` cannot verify; invoice status changes |
| 12 | Partial payments | staff, manager | Pay `INV-2026-0002` in two parts; check the history on the invoice page |
| 13 | Outstanding balances | staff | Payments > Outstanding balances shows unpaid, partially paid and overdue (`INV-2026-0003`) |
| 14 | Guest deposits | staff | Record a deposit; apply it to an invoice of the same guest; open the deposit detail |
| 15 | Refunds | staff, manager | Request, approve (as manager), complete with a reference; confirm the original payment remains |
| 16 | Discounts | staff, manager | A discount of ₱300 or less is automatic; a larger one needs the manager; approve the pending ₱576 on `INV-2026-0002` before verifying its pending transfer |
| 17 | Billing adjustments | staff, manager | Request an adjustment; the manager approves; the invoice total changes and the history is kept |
| 18 | Receipts | staff | Issue a receipt for a verified payment, print it, and confirm only one receipt per payment |
| 19 | Expenses | staff | Record an expense, filter, void it with a reason; add a category |
| 20 | Supplier invoices | staff | Record an invoice; a duplicate number is refused; cancel an unpaid one |
| 21 | Accounts payable | staff, manager | Ageing, overdue (`LINEN-1042`) and by-supplier totals |
| 22 | Accounts receivable | staff, manager | By customer, drill-down, and ageing |
| 23 | Purchase payments | staff | Pay a supplier invoice in part, then in full; the balance and status update |
| 24 | Payroll expenses | manager | Record and void an entry; log in as `staff` and confirm there is no Payroll menu |
| 25 | Tax and service charge | admin | Add a new rate; confirm old invoices do not change and new ones use it |
| 26 | Revenue reporting | manager | Generate daily, weekly, monthly and yearly; filter by method and source |
| 27 | Expense reporting | manager | By period, category and supplier |
| 28 | Profit and loss | manager | Income, expenses and net result match the dashboard for the same period |
| 29 | Cash reconciliation | staff | Expected cash is calculated; record a short count; add an explanation |
| 30 | Payment-provider reconciliation | manager | Review the sample lines; verify the pending transfer, then use Match again; import a CSV |
| 31 | Audit trail | manager | Every action above appears; there is no edit or delete control |
| 32 | Period closing | manager | Review warnings; close September (reopen it first with a written authorization); confirm a record dated in a closed period is refused |
| 33 | Report exports | manager | Export CSV; print the layout; confirm it shows the period, title, totals and generation date |
| 34 | Budget monitoring | staff | October budgets; the Housekeeping budget shows an overspending warning |

Also check: log in as each role and confirm the menu shows only what that role may use; try opening `payroll-expenses.php` as `staff` (access denied); resize the browser to phone width.

## 9. Limitations

- **Not yet run.** Expect to fix small errors on the first run.
- GCash, Maya, card and bank payments are recorded **manually**. There is no payment-gateway or bank integration; reconciliation uses manual lines or CSV files.
- PDF output uses the browser's "Save as PDF" from the print layout. There is no server-side PDF generator.
- `guests`, `rooms` and `reservations` are demo tables, ready to be replaced by a real reservation module.
- A supplier payment cannot be reversed in the system once saved; correct such a mistake through a documented period reopening and a new entry.
- Role permission changes apply to users at their next login.
- No email notifications, and no individual salary records (payroll holds department totals only).

---

## Revision 2 (professor's revisions) - what was added

**Run the migration first.** Back up the database (phpMyAdmin > `hotel_finance_db` > Export > Export). Then click `hotel_finance_db` > **Import** > choose `migrations/revision-2.sql` > Import. It only adds tables, columns, settings and permissions. It is safe to run twice. Fresh installs already include it at the end of `database.sql`. Until it is run, the new pages show a friendly "Database update needed" message instead of errors.

| Revision | Where | Notes |
|---|---|---|
| Hourly guest billing | Guest Billing, Tax and Charges | Room charge = billable hours x the room's hourly rate. Hourly rates, the service price list and the billing rules are entered by an administrator under **Administration > Tax and Charges**. Nothing is guessed. Rates are before tax; service charge and tax are added once, by the same function, on the guest-bill summary and on the invoice. |
| Payroll automation | Accounts > Payroll | Employees with their own pay arrangement and rate, pay periods with start and end dates, automatic calculation, one record per employee and period, Draft > Verified > Paid. Paid payroll counts as an expense. |
| Export / Download | Reports | One control with "Print / Save as PDF" and "Export as CSV". The CSV keeps the screen filters. |
| Budget shortfalls | Administration > Budget Monitoring | Allocated, actual, remaining, overspent amount and percent, warnings, review notes, and change requests that need approval. History is kept. |
| Audit Trail window | Administration > Audit Trail | The View button opens a readable window. |
| Hover menus | Left menu | Five groups; hover on a computer, tap on a touch screen. |
| Notification bell | Top bar, beside the user's name | Built from real database situations, with per-user read status. It refreshes when a page loads (at most every 20 seconds); there is no live push. |

**Policies that still need your professor's decision** (proposed defaults are marked in the settings and can be changed): partial-hour rounding, grace minutes, minimum hours, whether rates already include tax, whether the 10% service charge applies, payroll periods per month, deductions, who approves payroll and budget changes, and whether spending over a budget should be blocked. Today it is **not** blocked: it raises alerts and needs an approved change request.

**New permissions** (added by the migration): `payroll.configure` (administrator), `payroll.verify` (manager and administrator), `payroll.pay` (administrator), `budgets.approve` (manager and administrator). Edit them under Administration > Users and Roles > Role permissions.

---

## Revision 3 - receipt name and Excel-ready CSV

- **Receipt, invoice and report headers** now show the official name **Account and Finance Management** with the existing logo icon, and the address and TIN from Tax and Charges underneath. The `hotel_name` setting is unchanged in the database but is no longer printed. Receipts have one format (the print layout); "Save as PDF" is the browser's own, so it shows the same header. The receipt now also lists the invoice subtotal, service charge, tax, discount and adjustments above the amount paid.
- **CSV exports** (Export / Download > Export as CSV) export **every record that matches the filters, not only the page you see**: Guest Billing, Invoices, Payments (and Outstanding balances), Receipts (and payments without a receipt), Expenses, and the three Reports. Each file is one tidy table: title, generated time, filters used, record count, a blank row, column headings, the rows, and a TOTAL row. Files are UTF-8 with a BOM, use CRLF line endings, quote commas, quotation marks and line breaks, show dates as YYYY-MM-DD and amounts with two decimals (no symbol, so Excel treats them as numbers). Names look like `payments_report_2026-10-24.csv`. CSV is plain text: no colours, no cell formatting and no extra sheets.
- Export needs the `exports.generate` permission. Payroll in the Expense report is shown as department totals only, never individual salaries.
