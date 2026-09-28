# Pharmacy Inventory Management System

A database-driven pharmacy inventory system built for EC5070 (Database Management), University of Jaffna, Faculty of Engineering. Supports three user roles — Admin, Pharmacist, and Customer — each with their own permissions and workflows, backed by a normalized MySQL schema with stored procedures, functions, a trigger, a view, and a scheduled event.

## Features

- **Admin** — manage pharmacist/customer staff accounts, oversee suppliers, create and receive restock orders, view operational reports (stock levels, low stock, expiring medicines, best sellers, outstanding invoices, daily sales)
- **Pharmacist** — manage the medicine catalogue (prescription and OTC), process customer orders, confirm/dispense, settle payments and print invoices
- **Customer** — browse the medicine catalogue, place orders, view order history, manage their own profile

All three roles authenticate through a single login (`SYSTEM_USER`) that resolves to a role-specific profile table, with page-level access control enforced in `auth.php`.

## Tech Stack

- **Backend:** PHP 8 with PDO (no framework)
- **Database:** MySQL (via phpMyAdmin), InnoDB engine
- **Server:** XAMPP
- **Frontend:** Plain HTML/CSS, zero JavaScript — all list-view filtering is done through server-rendered filter links, not client-side scripts

## Database Design

- **14 tables:** `SYSTEM_USER`, `ADMIN`, `PHARMACIST`, `CUSTOMER`, `MEDICINE`, `PRESCRIPTION_MEDICINE`, `OTC_MEDICINE`, `CUSTOMER_ORDER`, `ORDER_ITEM`, `INVOICE`, `PAYMENT`, `SUPPLIER`, `RESTOCK_ORDER`, `RESTOCK_ITEM`
- **16 foreign keys** (9 CASCADE, 6 RESTRICT, 1 SET NULL)
- Three-role access schema (Admin / Pharmacist / Customer), each a subtype of `SYSTEM_USER`
- `PRESCRIPTION_MEDICINE` / `OTC_MEDICINE` split medicines into two subtypes of `MEDICINE`
- `RESTOCK_ITEM` is a junction table, so a single restock order can cover multiple medicines
- All list-view searches use bound parameters (SQL injection protected)

### Custom database objects

| Object | Type | Purpose |
|---|---|---|
| `fn_invoice_balance` | Function | Computes an invoice's remaining balance on demand, replacing a calculation that used to be duplicated three times across `payment.php` |
| `trg_payment_no_overpay` | Trigger | Fires before a payment insert and blocks it from exceeding the invoice's outstanding balance |
| `sp_settle_invoice_stock` | Procedure | Settles a payment and deducts stock for every item on the order in one transaction, so stock is only ever reduced once payment actually clears |
| `v_medicine_type` | View | Derives whether a medicine is PRESCRIPTION or OTC by checking which subtype table it belongs to, instead of trusting a duplicated type flag on `MEDICINE` |
| `ev_deactivate_expired_medicines` | Event | Runs daily and automatically deactivates any medicine past its expiry date (requires MySQL's `event_scheduler` to be ON — XAMPP ships it OFF by default) |

### Key business rules

- Stock is only deducted when a payment settles an invoice (via `sp_settle_invoice_stock`), never at order placement — so a placed-but-unpaid order doesn't lock up stock
- A restock order can cover several medicines at once through `RESTOCK_ITEM`, rather than one order per medicine
- The person paying an invoice doesn't have to be the customer who placed the order — `PAYMENT` is linked to `CUSTOMER` independently of `CUSTOMER_ORDER`

## Project Structure

| File | Role |
|---|---|
| `db.php` | PDO database connection (every page starts with `require 'db.php'`) |
| `auth.php` | Session handling, login checks, role-based page access, and the `filter_link()` helper used for all list-view filters |
| `index.php` / `login.php` / `logout.php` / `signup.php` | Entry point, login, logout, customer self-registration |
| `denied.php` | Access-denied page for role violations |
| `admin.php` | Admin dashboard and reports |
| `staff.php` | Admin: manage pharmacist/customer accounts |
| `suppliers.php` | Admin: supplier CRUD |
| `restock.php` | Admin: create, update, and receive restock orders |
| `pharmacist.php` | Pharmacist dashboard and order processing |
| `medicines.php` | Pharmacist: medicine catalogue CRUD and search |
| `payment.php` | Pharmacist: billing, invoice line items, and payment settlement |
| `customer.php` | Customer: browse catalogue, place orders, order history |
| `profile.php` | Shared: view/edit the logged-in user's own profile |
| `style.css` | All styling |
| `pharmacy_db.sql` | Full database export — structure, foreign keys, custom objects, and sample data |

## Setup

1. Install [XAMPP](https://www.apachefriends.org/) and start Apache + MySQL
2. Clone this repo into your XAMPP `htdocs` folder:
   ```
   git clone https://github.com/vaikunthansivakumar/pharmacy-inventory-db.git
   ```
3. Import the database:
   - Open phpMyAdmin → Create a database named `pharmacy_db`
   - Import `pharmacy_db.sql` from this repo
   - In phpMyAdmin, go to Variables and confirm `event_scheduler` is `ON` — otherwise `ev_deactivate_expired_medicines` will never run
4. Database credentials are set in `db.php` (defaults to XAMPP's `root` user with no password — edit if yours differs)
5. Visit `http://localhost/pharmacy-inventory-db` in your browser

## Team

- Vaikunthan S.
- Aathithyayan S.
- Shanjaie V

Group 06 — EC5070 Database Management, University of Jaffna, Faculty of Engineering
