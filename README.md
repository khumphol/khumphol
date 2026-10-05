# Aleanor Cloud

A course marketplace. Instructors apply and an admin approves them. Instructors then build courses and submit them for review, and an admin publishes them. Students buy courses and learn. Revenue is split automatically using rate rules that can be set at three levels.

This is a sibling app to `aleanor_ai`. It follows the same conventions: procedural PHP, `?p=` routing, `core/*.core.php` modules, `config.local.php`, bcrypt passwords, CSRF fields and Omise/Stripe. It has its own database, `aleanor_cloud`.

## Run on MAMP

```bash
bash sql/install.sh                 # creates the aleanor_cloud DB, loads the schema and seed (MySQL 8889 root/root)
cp core/config.local.example.php core/config.local.php   # skip if the file already exists
```

Open <http://localhost:8888/2026/aleanor/aleanor_cloud/>

| Area | URL |
|---|---|
| Public site | `index.php` |
| Instructor | `instructor/` |
| Admin | `admin/` |
| Webhook | `api/pay-webhook.php?secret=…` |

Test accounts (password `test1234`): `admin@aleanor.test`, `teacher@aleanor.test`, `student@aleanor.test`.

`bash sql/install.sh --reset` drops the database and recreates it.
`php sql/demo.php` adds demo data: 2 more published courses, a 20% rate on the Excel course, and 2 sample sales with their revenue split. Screenshots are in `docs/screenshots/`.

**Payments:** with `APP_ENV=dev`, the default is a **mock** gateway, so you can test the full flow without keys. For production, set `APP_ENV=prod` in `config.local.php`. Then choose Omise or Stripe under Admin → Settings and set the Omise/Stripe webhook to the URL shown on that page.

**Cron (daily):** `php cron/release-earnings.php` moves earnings that have passed the hold period from held to available.

## Tests

```bash
/Applications/MAMP/bin/php/php8.4.1/bin/php tests/run.php          # unit + integration (rolls back, no leftover data)
/Applications/MAMP/bin/php/php8.4.1/bin/php tests/run.php --unit   # pure calculations only, no DB
```

## Revenue share rules

- **Rate lookup:** the most specific rule wins, `course > instructor > global`. A rule only applies while the time of payment falls inside its `starts_at`/`ends_at` window (`ends_at` is exclusive). If no rule matches, the app uses `settings.default_platform_rate`.
- **Calculation (done in satang, no floats):**
  - `net = paid − gateway_fee`
  - `platform = round(net × rate)`
  - `instructor = net − platform`

  Example: 1000 THB with a 3.65% fee and a 30% rate gives fee 36.50, net 963.50, platform 289.05 and instructor 674.45.
- **Gateway fee:** taken from Omise (`fee + fee_vat`) when available. Otherwise it is estimated from `gateway_fee_rate`. For multi-item orders, the fee is allocated in proportion to each item's paid amount.
- **Instructor coupons:** the discount comes off the price before the split.
- **Platform coupons:** the instructor gets the same share as a full-price sale, and the platform absorbs the discount (its share can go negative).
- **Snapshots:** `platform_rate`, `rule_id` and all amounts are frozen on `order_items` at payment time. Changing a rate later does not affect past sales.
- **Rule history:** setting a new rate closes any open rule at the new rule's start time and keeps the history. Every change is written to `audit_logs`.

## Ledger

`instructor_ledger` is append-only.

- A `sale` row starts as `held` and is released after `earnings_hold_days` (14).
- A `refund` row within the hold period is also held, so it cancels the sale out. After release, it is deducted from the available balance.
- `confirmPaid()` does everything in one transaction: lock the order, then write `order_items`, the ledger rows, the enrollment and the coupon count. It is idempotent: the order is locked `FOR UPDATE` and `gateway_ref` is unique, so repeated webhook calls or page reloads cannot record a sale twice.

## Structure

```
core/       config, db (mysqli prepared + db_tx), app helpers/auth, gateway, router
services/   RevenueShareService, LedgerService, OrderService   ← all money logic lives here
pages/      public/ instructor/ admin/   (the page runs first, then gets wrapped in views/layout.php)
sql/        001_schema.sql, 002_seed.sql, install.sh
tests/run.php
```

## Done (phases 1–2) / still to do

Done:
- Signup and login
- Instructor applications and approval
- Course, section and lesson CRUD
- Submit for review → approve, reject or unpublish
- Learning page with progress tracking and reviews
- Checkout with coupons (Omise / Stripe / mock)
- Webhook
- Revenue share rules at all 3 levels, with history
- Ledger, hold period and release cron
- Full refund per order item
- Ledger adjustments
- Reports (by month, instructor and course) with CSV export
- Settings
- Audit log

Still to do (phases 3–4):
- Monthly payouts: the `payouts` and `payout_items` tables exist, but there is no UI or PayoutService yet
- Partial refunds
- Calling the gateway refund API directly
- Cart with multiple courses (the tables already support it)
- Uploaded video files and signed URLs
- Email notifications
- Pretty URLs
- Certificates
