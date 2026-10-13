# Water Station Record Management System (PHP 8 + MySQL)

Single-owner record system for a water refilling station: sales/orders with receipts, deliveries, inventory, expenses and reports.

> **Status: written but not yet executed.** This code was produced in an environment with no PHP or MySQL, so it has
> never been run or linted. Work through the **Manual test checklist** below before using it for real business records,
> and expect to fix a few small bugs. See **Limitations**.

## 1. Architecture

Plain PHP (no framework), one PHP file per module, shared helpers in `includes/`.

```
waterstation/
├── index.php, login.php, logout.php     entry points
├── config/        .env (secrets, you create it), .env.example, .htaccess (deny web access)
├── includes/
│   ├── bootstrap.php   env, PDO, session, CSRF, auth, audit log, pagination, uploads, CSV/PDF helpers
│   ├── logic.php       business rules: totals, status rules, stock deduction, delivery->order sync (unit-tested)
│   └── layout.php      sidebar layout, badges, confirm-dialog form helper
├── modules/       dashboard, customers, products, orders, receipt, deliveries, inventory, expenses, reports, settings, password
├── assets/        css/app.css, js/app.js (Bootstrap 5 + Chart.js come from a CDN)
├── database/      schema.sql, seed.sql (DEMO data)
├── uploads/       delivery photos and expense receipts (PHP execution blocked)
├── backups/       put your SQL dumps here (web access blocked)
└── tests/         PHPUnit tests
```

**Request flow:** every module calls `require_login()`, validates CSRF on POST, runs prepared-statement queries, writes an
`audit_logs` row for important actions, then redirects (PRG pattern). All multi-record operations run in DB transactions.

**Database relationships**

```
customers 1──* orders 1──* order_items *──1 products *──0..1 inventory_items
orders 1──0..1 deliveries *──0..1 riders
inventory_items 1──* inventory_transactions *──0..1 orders
users 1──* orders / inventory_transactions / audit_logs        expenses, system_settings: standalone
```
* `order_items.unit_price` and `product_name` are snapshots, so changing a product price never alters past orders.
* `orders.receipt_number` is UNIQUE and derived from the order number, so one order can only ever have one receipt number.
* `products.inventory_item_id` links a tracked product to the stock it consumes (e.g. "New container" -> "Full gallons").

## 2. Install on XAMPP / Laragon (local)

1. Install XAMPP or Laragon with **PHP 8.0+**. Start Apache and MySQL.
2. Copy this folder to `C:\xampp\htdocs\waterstation` (Laragon: `C:\laragon\www\waterstation`).
3. Open phpMyAdmin -> create a database `water_station` (collation `utf8mb4_general_ci`/`utf8mb4_unicode_ci`).
4. Select it -> **Import** -> `database/schema.sql`.
5. Copy `config/.env.example` to `config/.env` and set `DB_NAME`, `DB_USER`, `DB_PASS` (XAMPP default: user `root`, empty password).
6. (For real PDF files) install Composer, then in the project folder run `composer install --no-dev`. Without this, the
   "PDF" buttons open a print-friendly page and you use the browser's **Save as PDF**.
7. Browse to `http://localhost/waterstation/`. Log in with **admin / ChangeMe@123**; you are forced to change the password.
8. Optional demo data: **after the first login**, import `database/seed.sql` (it is labeled DEMO; skip it for production).

Note: Bootstrap, Bootstrap Icons and Chart.js load from the jsDelivr CDN, so the browser needs internet access.

## 3. Install on cPanel shared hosting

1. cPanel -> **MySQL Databases**: create database + user, add the user to the database with ALL PRIVILEGES.
2. phpMyAdmin -> select the database -> Import `database/schema.sql`.
3. cPanel -> **Select PHP Version**: choose 8.0+; enable `pdo_mysql`, `mbstring`, `fileinfo`.
4. Upload the project (File Manager or FTP) into `public_html/station` (or a subdomain root). Upload the `.htaccess` files too (hidden files).
5. Create `config/.env` from `.env.example` with the cPanel database name/user/password (names are usually prefixed `cpuser_`). Set `APP_DEBUG=0`.
6. Run `composer install --no-dev` on your PC and upload the generated `vendor/` folder (or use cPanel's Terminal if available).
7. Make sure `uploads/` and its subfolders are writable (permissions 755; 775 if uploads fail).
8. Visit your URL, log in with admin / ChangeMe@123, change the password. Turn on HTTPS (AutoSSL) before real use.

If `config/` is reachable from the browser (e.g. you can open `/config/.env`), your host ignores `.htaccess`;
move the project outside `public_html` or ask the host to block it.

## 4. Backup and restore

**phpMyAdmin:** select the database -> Export -> Quick, SQL -> Go (saves a `.sql` file). Restore: select the (empty) database ->
Import -> choose the file. **Also copy the `uploads/` folder** (photos are not in the database).

**mysqldump** (Command Prompt/Terminal; XAMPP: `C:\xampp\mysql\bin`):
```
mysqldump -u root -p --single-transaction --routines water_station > backups/water_station_2026-01-31.sql
mysql -u root -p water_station < backups/water_station_2026-01-31.sql
```
Recommended: back up daily (cPanel -> Cron Jobs or Windows Task Scheduler), keep copies off the computer (USB / cloud),
and **test a restore** on a spare database at least once.

## 5. Testing

**Automated** (needs `composer install` with dev dependencies):
```
composer install
vendor/bin/phpunit --testsuite Unit          # totals, discount rules, order status rules, delivery sync
# DB integration (stock deduction): create an empty DB water_station_test, import schema.sql, then:
TEST_DB_DSN="mysql:host=127.0.0.1;dbname=water_station_test;charset=utf8mb4" TEST_DB_USER=root TEST_DB_PASS= vendor/bin/phpunit --testsuite Integration
```
Integration tests roll back everything they write.

**Manual checklist** (tick each box; this is the workflow list from the specification)
- [ ] Login with admin / ChangeMe@123 -> forced to change password -> wrong current password is rejected -> logout/login works.
- [ ] Register a customer (CUST-0001 generated); edit it; search for it.
- [ ] Products: add a product, change its price; confirm an old order still shows the old price. Deactivate it; it vanishes from New Sale.
- [ ] Inventory: add "Full gallons" with 10, set minimum 3; link a tracked product ("New container") to it.
- [ ] New Sale: quick-add 2x Purified refill + 1x New container, discount ₱10 -> total is correct -> Save with "Paid & released" ticked.
- [ ] Receipt: open the receipt, print it, download PDF; receipt number is unique and shows the processing admin.
- [ ] Stock: Full gallons dropped by 1; Inventory -> History shows an "Order" movement.
- [ ] Complete an order with insufficient stock -> blocked with a clear message; stock stays unchanged (never negative).
- [ ] Cancel an order (confirm dialog) -> disappears from dashboard sales and sales reports; cannot be reopened.
- [ ] Delivery: schedule for a Pending order -> Preparing (order becomes Processing) -> Out for Delivery -> Mark as Delivered with name (+photo) -> order becomes Completed and stock deducts.
- [ ] Delivery: mark Failed -> order returns to Processing; reschedule.
- [ ] Expense: add one with a receipt photo; filter by date/category; edit; delete (confirm).
- [ ] Dashboard: cards and charts match the records you entered (compare with Reports).
- [ ] Reports: generate each report for a date range; CSV opens in Excel; PDF/print works; totals match the screen.
- [ ] Log out/in and refresh: data persists. Settings page: change station name -> appears on receipts. Audit log lists your actions.
- [ ] Security spot checks: open a module while logged out -> redirected to login; submit a form with a bad/missing CSRF token -> rejected; try `<script>` in a customer name -> shown as text.

## 6. Assumptions made where the spec was ambiguous

* **"Sales" = Completed orders only.** Pending/Processing orders are not counted as revenue (even though customers pay at the time of sale)
  until you complete them. Cancelled orders never count. Dashboard, reports and net income all use this same rule.
* **Completed and Cancelled are final** (no reopening, no cancelling a completed sale) because stock has already been deducted. Mistakes: record a correcting stock-in or contact whoever maintains the system.
* **Stock deduction only on completion**, once per order, and only for products with stock tracking turned on and linked to an inventory item. Refills deduct nothing by default (water is not tracked).
* **Delivery fee** on the delivery record is informational; to charge it, add the "Delivery fee" product to the order.
* **Delivery -> order sync:** Preparing -> Processing; Out for Delivery -> Out for Delivery; Delivered -> Completed (+ stock deduction); Failed -> Processing. Scheduled changes nothing.
* **One delivery per order** (reschedule a failed one instead of creating a second).
* **Order editing:** items, customer, payment and discount can be edited while the order is open; existing lines keep their original price. Completed/cancelled orders: notes only.
* **Walk-in:** an order without a customer is an anonymous walk-in; optionally register the customer from the order form.
* **Receipt "staff"** is the logged-in admin's display name (single account).
* Default admin credentials are created on first visit to the login page if no user exists.
* Sorting by clicking table headers sorts the rows on the current page; search/filter/pagination are server-side.

## 7. Limitations and unfinished items

* **Not executed or tested by the author** (no PHP/MySQL available when written). Treat as a first draft until the manual checklist passes.
* Real PDF files need `composer install` (Dompdf). Without it, PDF buttons fall back to the browser's print dialog.
* UI libraries load from a CDN (needs internet); not bundled for offline use.
* Login throttling is session-based (weak against scripted attacks); add server-level protection or fail2ban if exposed publicly.
* No password reset by email: if the password is lost, replace `users.password_hash` via phpMyAdmin with a hash from `password_hash()`.
* No refunds, returns, partial payments, credit/debt, rented containers, multiple users/roles (out of scope by design).
* Cancelling an already-completed order is not supported; stock adjustments are via manual Stock in/out.
* Inventory quantities may be decimals; the product -> inventory link deducts exactly the ordered quantity (1 unit per item).
* Charts show completed orders only; the week starts on Monday.
* Photo uploads accept JPG/PNG/WEBP up to 3 MB; uploaded images are publicly reachable by direct URL (random file names).
* Single-currency (₱), single-timezone (Asia/Manila), English only.
