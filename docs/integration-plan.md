# Integration plan — aleanor_ai admin + Indy / Playground / Docs / VC → aleanor_cloud

None of the source apps or their databases are modified. Everything is re-implemented or copied into aleanor_cloud, which keeps its own database.

## Inventory

| Source | Actual location | What it is | Money involved | Approach in aleanor_cloud |
|---|---|---|---|---|
| aleanor_ai admin | `aleanor_ai/dashboard/index.php` | Shell layout: 260px sidebar, blurred topbar, uicons, Sarabun font, palette `--primary #6366f1`, plus components (page-header, card, stat-card, menu-card, badge, btn, modal). Menus come from the `menus` table (min_class + module), and modules are toggled with `mod_<key>`. | – | Copy the shell CSS and components, and write the menu as a PHP array (min role + module + permission). Admin hub: "ผู้ดูแลระบบ" menu-cards. |
| aleanor_indy | No standalone app. It is a module inside aleanor_ai (`core/indy.core.php`, `indy-edit.php`, player inside `view-course.php`). | Branching video: scenes, plus branch buttons that jump between scenes. | None | **Port it.** Tables `indy_projects`, `indy_scenes`, `indy_branches` (int ids), a scene editor in /instructor, and a player on the learning page (lesson type `indy`). |
| aleanor_docs (folder) | `aleanor/aleanor_docs` | A **static user manual** (33 HTML pages), not a runnable feature. | – | Link to it from admin "Help" (optional URL setting). |
| "Aleanor Docs" (feature) | Inside aleanor_ai (`document.core.php`, `grid/present/docx/xlsx/pptx` cores, `dashboard/document/*`) | Office suite: Write, Grid (spreadsheet + formulas), Present, folders, trash, quota | None | **Port it.** Copy the pure cores unchanged (grid/present/docx/xlsx/pptx and `grid-engine.js`). Rewrite the storage layer to use int ids. Instructor editors plus lesson type `doc`. Quota setting and trash. |
| aleanor_playground | `aleanor/aleanor_playground` (separate app, about 90 Three.js apps) | Interactive 3D mini-apps with scores | None | **SSO integration** (an HMAC ticket and an `sso_verify` endpoint, same scheme as aleanor_ai). Lesson type `playground` stores an assignment key. Settings: url, secret, enabled. |
| aleanor_vc | `aleanor/aleanor_vc` (PHP + Node socket.io + LiveKit + Vite client) | Multiplayer virtual classroom | Points only | **JWT launch link** (HS256, same as aleanor_ai `vcLaunchUrl`). Room per course `course-<id>`, gated by enrollment. The code itself cannot be ported because it needs a Node process and a LiveKit server. |

## Instructor permissions

- A feature registry in code: one key per function. Covers courses, coupons, earnings, indy, docs, playground, vc, certificates and course pricing.
- Table `instructor_permissions`: a row with `instructor_id NULL` is the default for every instructor; a row with an instructor id is an override for that person.
- Resolution order: override, then default, then the registry's built-in default.
- Admin page: a checkbox matrix. Default row uses on/off; per-instructor rows use inherit/on/off.
- Enforced in two places: menus (hidden) and server-side routes (blocked). Every change is written to `audit_logs`.

## Phase 3-4

| Item | Notes |
|---|---|
| PayoutService | Monthly runs, minimum amount, withholding tax, CSV for bank transfer, slip upload, mark paid, cancel |
| Refunds | Partial refunds, and refunds sent to Omise/Stripe |
| Cart | Multi-course cart; coupons are allocated per item |
| Email | Queue plus cron (SMTP code adapted from aleanor_ai `mail.core.php`) |
| Certificates | Issued at 100% progress; verify by serial number |
| Tax | VAT (price includes VAT) and receipt number on the receipt |
| Production pass | prod config, error log, security review, cron documentation, deploy steps |
