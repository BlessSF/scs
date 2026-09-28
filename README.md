# Service Charge Management System

A PHP + MySQL web app that replaces the STELLA service-charge spreadsheet:
duty/attendance tracking, automatic service-charge computation, deductions,
period summaries, and printable staff slips — with separate Admin and Staff
logins.

## How the numbers are calculated

For every day worked, the admin enters the day's **total service charge**
and checks off every staff member who was on duty. That amount is split
**evenly** among everyone checked (matches the "PER STAFF" column in the
original spreadsheet).

For a **period** (a date range you define — a month, a quarter, anything):

```
Total SC          = sum of each staff's daily split-share within the period
Management Share  = Total SC × period's management share % (default 20%)
Gross              = Total SC − Management Share
Net Contribution   = Gross − Damages/Charges − Cash Advance − Overcost/COGS
```

Deductions (damages, cash advance, overcost) are entered per staff, per
period, and feed straight into that staff member's printable slip.

## Requirements

- PHP 8.0+ with the `pdo_mysql` extension
- MySQL or MariaDB
- Any web server (XAMPP/WAMP/native Apache-PHP, or PHP's built-in server)

## Setup (XAMPP / local)

1. Copy the whole `scs` folder into your web root, e.g. `C:\xampp\htdocs\scs`
   (on Mac/Linux XAMPP: `/opt/lampp/htdocs/scs`).
2. Start Apache and MySQL from the XAMPP control panel.
3. Open **phpMyAdmin** (or the `mysql` CLI) and import
   `database/schema.sql`. This creates the `service_charge_db` database,
   all tables, and a default admin account.
4. Open `config/config.php` and confirm the DB credentials match your
   setup (defaults are XAMPP's out-of-the-box `root` with no password).
   Also check `BASE_URL` — it should match the folder name you used in
   step 1 (default is `/scs`).
5. Visit `http://localhost/scs/` in your browser.

**Default login:** username `admin`, password `admin123`
— change this password immediately (edit the staff/user record, or update
it directly in the `users` table using `password_hash()`).

A default branch, "Main Branch", is created with its own login too:
username `branch-main-branch`, password `main123`. Rename or delete it
from *Branches* like any other branch.

## Branches & branch accounts

Each branch has its own login account. Signing in with a branch's account
locks that session to that branch — its dashboard, staff list, duty
calendar, and periods only ever show that branch's data, and it can't view
or edit another branch's records even by editing the URL.

- **Add a branch** — go to *Branches → + Add Branch*. Leave the username
  and password blank to auto-generate them, or set your own. The
  credentials are shown once after saving — copy them before leaving the
  page.
- **Rename a branch or its account** — open *Branches → Edit*. Renaming
  the branch doesn't rename its login; change the username field
  separately if you want that too.
- **Reset a branch's password** — click *Reset Password* next to the
  branch in the list. A branch created before this login-account feature
  existed will show *Create Account* instead — click it once to give that
  branch a working login.
- **Remove a branch** — *Delete* removes the branch and its login account
  together. Branches with existing staff, duty days, or periods can't be
  deleted (mark them Inactive instead) so historical data is never lost.
- The original `admin` account, and any `cashier`/staff accounts made
  before this feature, are unaffected — they still see every branch.

## Everyday usage

1. **Staff** — add your staff list under *Staff*. Optionally give any staff
   member a username/password so they can log in and see their own
   attendance and slips.
2. **Duty Entry** — every day (or in batches), go to *Duty Entry*, click the
   date, enter the day's total service charge, and check off who worked.
3. **Periods** — create a *Period* (e.g. "JULY 2026" or "Q2 2026") with a
   start/end date and the management-share percentage for that cycle. The
   summary table computes itself automatically from the duty entries in
   that range.
4. **Deductions** — on the period summary page, click *Deductions* next to
   any staff member to record damages/charges, cash advance, or overcost
   for that cycle.
5. **Slips** — click *Slip* to view/print an individual payout slip. Staff
   members with a login can print their own from *My Slips*.

## Project structure

```
scs/
├── config/         DB connection, session bootstrap, auth guards
├── includes/       header/footer, shared business-logic functions
├── database/       schema.sql (import this first)
├── assets/css/     stylesheet
├── staff/          admin: staff CRUD + login-account management
├── duty/           admin: calendar + daily attendance/amount entry
├── periods/        admin: period create/list/summary view
├── deductions/      admin: per-staff, per-period deduction entry
├── slip/           printable slip (admin: any staff; staff: own only)
├── portal/         staff self-service: attendance history, my slips
├── settings.php    company name, currency symbol, default mgmt share %
├── login.php / logout.php / dashboard.php / index.php
```

## Security notes

- Passwords are hashed with PHP's `password_hash()` (bcrypt).
- All forms are protected with CSRF tokens.
- All database queries use prepared statements (PDO).
- Staff accounts can only view their own attendance/slips — enforced
  server-side, not just hidden in the UI.
- Change the default admin password before putting this on a shared or
  public server, and consider adding HTTPS if deploying beyond localhost.
