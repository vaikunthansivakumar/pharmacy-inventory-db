# Pharmacy Inventory Management System

A database-driven pharmacy inventory system built for EC5070 (Database Management). Supports three user roles — Admin, Pharmacist, and Customer — each with their own permissions and workflows.

## Features

- **Admin** — manage staff, view reports, oversee suppliers and restocking
- **Pharmacist** — manage medicine catalogue, process orders, handle payments
- **Customer** — browse medicines, place orders, view order history

## Tech Stack

- **Backend:** PHP 8 with PDO (no framework)
- **Database:** MySQL (via phpMyAdmin)
- **Server:** XAMPP
- **Frontend:** Plain HTML/CSS (no JS framework)

## Database Design

- 14 tables, InnoDB engine
- 16 foreign keys (9 CASCADE, 6 RESTRICT, 1 SET NULL)
- Three-role access schema (Admin / Pharmacist / Customer)
- `RESTOCK_ITEM` junction table allows a single restock order to cover multiple medicines
- All list-view searches use bound parameters (SQL injection protected)

## Setup

1. Install [XAMPP](https://www.apachefriends.org/) and start Apache + MySQL
2. Clone this repo into your XAMPP `htdocs` folder:
   ```
   git clone https://github.com/vaikunthansivakumar/pharmacy-inventory-db.git
   ```
3. Import the database:
   - Open phpMyAdmin → Create a database named `pharmacy_db`
   - Import `pharmacy_db.sql` from this repo
4. Database credentials are set in `db.php` (defaults to XAMPP's `root` user with no password — edit if yours differs)
5. Visit `http://localhost/pharmacy-inventory-db` in your browser

## Team

- Vaikunthan S.
- Aathithyayan S.
- Shanjaie V

Group 06 — EC5070 Database Management, University of Jaffna, Faculty of Engineering
