# Aleanor Cloud

Aleanor Cloud is a course marketplace:
- Anyone can apply to be an instructor, and an admin approves them.
- Instructors build courses and submit them for review, then an admin publishes them.
- Students buy courses (one at a time or through a cart) and learn.
- Revenue is split automatically: rules can be set at three levels, instructor earnings sit in a hold period, then get paid out monthly.

It is a sibling app to `aleanor_ai` and uses the same conventions: procedural PHP, `?p=` routing, `core/*.core.php`, `config.local.php`, bcrypt, CSRF, Omise/Stripe. The back office copies aleanor_ai's dashboard look (sidebar, topbar, theme, components). Aleanor Cloud has its own database. It never touches the source apps or their databases.

## Features

| Area | Features |
|---|---|
| Public site | Course catalog with search, course page with free preview lessons, cart for several courses, checkout with coupons and tax-invoice details, learning page with progress tracking, reviews, certificates and a public verification page, receipts (with VAT when enabled), my courses |
| Instructor (`/instructor`) | Dashboard, courses (sections, lessons, price, submit for review), lesson types (video/text/**Indy**/**Docs**/**Playground**), **Aleanor Docs** (Write/Grid/Present, folders, trash, quota), **Aleanor Indy** (branching video), students and their progress, coupons, earnings and payouts, profile, bank account and tax details, a **VC** room per course |
| Admin (`/admin`) | Dashboard; course review; instructor approval with a per-instructor rate; members; orders and refunds (partial refunds allowed, and refunds through Omise/Stripe); monthly instructor payouts; revenue share rules at 3 levels with history; reports and CSV. The **Admin hub** has: instructor permissions (checkbox matrix), modules on/off, settings, platform coupons, tax and receipts, email and outbox, Playground & VC, and the audit log |

## Run on MAMP (development)

```bash
cp core/config.local.example.php core/config.local.php     # set APP_ENV=dev and the database (MAMP root/root)
bash sql/install.sh                                         # creates the aleanor_cloud database and runs sql/0*.sql in order
/Applications/MAMP/bin/php/php8.4.1/bin/php sql/demo.php     # optional: extra courses and sample sales
```

Open <http://localhost:8888/2026/aleanor/aleanor_cloud/>. Test accounts (password `test1234`): `admin@aleanor.test`, `teacher@aleanor.test`, `student@aleanor.test`.

`APP_ENV=dev` turns on the **mock gateway**, so you can test the whole purchase flow without keys.

## Tests

```bash
php tests/run.php        # money, revenue share, rule precedence, cart/coupons, partial refunds, payouts, VAT, permissions, SSO/JWT, throttling
php tests/indy_test.php  # Indy
php tests/docs_test.php  # Docs
```

The integration tests run inside a transaction that is rolled back, so they leave no data behind.

## Deploy (production)

1. Upload all files except `core/config.local.php`, `uploads/*` and `storage/logs/*`. The web root must allow `.htaccess`: it blocks `core/`, `services/`, `sql/`, `tests/`, `cron/`, `views/`, `pages/` and `storage/`, and stops scripts from running in `uploads/`. On nginx, write equivalent rules yourself.
2. Create `core/config.local.php` from `config.local.example.php`. Set the real DB values and `APP_ENV=prod`. The shipped `config.core.php` defaults to `prod` with an empty DB password.
3. Import `sql/001_schema.sql`, then `002`–`007` in numeric order. Skip the test accounts in `002_seed.sql` on production: create an admin yourself and delete or suspend those 3 users.
4. Make `uploads/` and `storage/logs/` writable by the web server. Errors are written to `storage/logs/php-YYYY-MM.log` and are not shown to users.
5. Use HTTPS. The session cookie `ACSESS` is automatically `Secure`, `HttpOnly` and `SameSite=Lax`.
6. Under Admin → Settings:
   - Set `site_base_url`.
   - Choose Omise or Stripe and enter the keys.
   - Set the webhook secret, then register the webhook URL shown on that page with Omise/Stripe.
7. Under Admin → Email, configure SMTP. Under Admin → Tax & receipts, configure VAT, seller details and withholding tax.

### Cron

```cron
5 0 * * *    php /path/to/aleanor_cloud/cron/release-earnings.php   # release earnings once the hold period ends (default 14 days)
*/5 * * * *  php /path/to/aleanor_cloud/cron/send-mail.php          # send queued emails
0 * * * *    php /path/to/aleanor_cloud/cron/expire-orders.php      # orders unpaid for 24h → failed (releases coupon uses)
```

Docs trash is auto-purged after the number of days in `docs_trash_days`.

## Money rules (summary)

- **Rate:** the most specific rule wins (course > instructor > global), applied at the time of payment. Changing a rate never edits the old rule: the old rule is closed and a new one is created, and every change is written to `audit_logs`.
- **Per sale:**
  - `net = paid − gateway_fee`
  - `platform = round(net × rate)`
  - `instructor = net − platform`
  - Amounts are calculated in satang, and all values are frozen on `order_items`.
  - Example: 1000 THB with a 3.65% fee and a 30% rate gives 289.05 to the platform and 674.45 to the instructor.
- **Coupons:**
  - An instructor coupon reduces the price before the split.
  - A platform coupon pays the instructor as if the course sold at full price.
  - On a cart, the discount is spread across items in proportion to their prices.
- **Refunds:**
  - Partial refunds are allowed, several times per item.
  - The instructor's share is reversed in proportion; the final refund takes whatever is left, so the totals match exactly.
  - A full refund revokes access to the course.
  - Refunds can be sent through Omise/Stripe. The gateway is called first; if it fails, nothing is recorded.
- **Ledger:** append-only.
  - A sale starts in `held` and moves to `available` once the hold period ends (via cron).
  - A refund made during the hold period cancels out inside the hold.
- **Payouts:** a monthly run adds up the available balance up to the end of the month, if it reaches the minimum (default 500).
  - Withholding tax (default 3%) is deducted.
  - A `payout` row is written to the ledger.
  - There is a CSV for the bank transfer, a slip upload and "mark paid", which emails the instructor.
  - Cancelling a run puts the balance back.
- **Order confirmation:** done in one transaction. It writes `order_items`, the ledger, the enrollment, the coupon, the receipt number and the email queue. It is idempotent (`FOR UPDATE` lock + unique `gateway_ref`).

## Instructor permissions

- The registry lives in `services/PermissionService.php` and has 12 features.
- **Resolution order:**
  1. A per-instructor override.
  2. The default for all instructors (`instructor_id NULL`).
  3. The default in the registry.

  A feature that belongs to a module that is switched off is always off.
- **Enforcement:**
  - The router (`core/router.core.php`) returns 403 server-side.
  - Menus hide items the instructor can't use.
  - Some features are also checked inside pages: setting prices, submitting for review, lesson types and VC.

## External modules

| Module | Approach |
|---|---|
| **Aleanor Indy** | Ported into this app. Tables `indy_*`, player in `assets/indy/`. |
| **Aleanor Docs** | Ported into this app. Tables `docs_*`; the import/export cores were copied from aleanor_ai into `core/docs/`. |
| **Aleanor Playground** | Separate app, linked by SSO ticket (same format as aleanor_ai). Ticket issuer: `playground.php`. API for Playground: `api/playground.php` (`X-Api-Key`). |
| **Aleanor VC** | Separate app (Node + LiveKit), linked by a 5-minute HS256 JWT (`vc.php?course=`). One room per course; only enrolled students, the instructor and admins can enter. |

See `docs/integration-plan.md` for what each module is and why it was integrated this way.

## Structure

```
core/       config, db (mysqli prepared + db_tx), app helpers/auth/throttle, gateway, mail (SMTP), router + menus
services/   RevenueShare, Ledger, Order, Payout, Mail, Permission, Certificate, Integration, Indy, Docs
pages/      public/ instructor/ admin/   — the shell is views/layout.php (public) and views/dashboard.php (back office)
api/        pay-webhook, playground (SSO), indy, docs
sql/        001…007 migrations + install.sh + demo.php
cron/       release-earnings, send-mail
```
