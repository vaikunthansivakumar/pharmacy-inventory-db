<?php
/* ============================================================
   db.php  -  Database connection
   Every other page starts with:  require 'db.php';
   ============================================================ */

/* --- Development settings ---------------------------------
   These make PHP print errors to the page instead of showing
   a blank white screen. Keep them while you are building.
   ---------------------------------------------------------- */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$DB_HOST = 'localhost';
$DB_NAME = 'pharmacy_db';
$DB_USER = 'root';
$DB_PASS = '';          // XAMPP's root account has no password by default

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            // Show real errors instead of failing silently
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Return rows as associative arrays: $row['name']
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Use real prepared statements, not emulated ones
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
