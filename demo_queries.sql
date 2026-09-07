-- ============================================================================
-- Pharmacy Management System - Group 06
-- Queries to run live in the phpMyAdmin SQL tab
--
-- Open this file, copy one query at a time (between the ---- markers),
-- paste into phpMyAdmin > pharmacy_db > SQL, and press Go.
-- Run them one at a time. Do not run the whole file at once.
--
-- All ten were tested against pharmacy_db.sql as exported 7 Sep 2026 17:55,
-- on MariaDB 10.11. The "Expect" lines below are what they actually returned,
-- not what they ought to return.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. THERE IS NO ROLE COLUMN - THE ROLE IS DERIVED
--    Same three-join lookup that auth.php runs at login.
--    Expect: 11 rows, every one with a role. Users 16 and 17 come back Admin,
--    14 comes back Customer - the test accounts are classified like any other.
-- ----------------------------------------------------------------------------

SELECT u.user_id, u.name,
       CASE WHEN a.user_id IS NOT NULL THEN 'Admin'
            WHEN p.user_id IS NOT NULL THEN 'Pharmacist'
            WHEN c.user_id IS NOT NULL THEN 'Customer'
       END AS derived_role
  FROM SYSTEM_USER u
  LEFT JOIN ADMIN      a ON a.user_id = u.user_id
  LEFT JOIN PHARMACIST p ON p.user_id = u.user_id
  LEFT JOIN CUSTOMER   c ON c.user_id = u.user_id
 ORDER BY u.user_id;


-- ----------------------------------------------------------------------------
-- 2. THE USER SPECIALIZATION IS DISJOINT AND TOTAL
--    Anyone in two subclasses, or in none, would appear here.
--    Expect: ZERO ROWS - phpMyAdmin shows "MySQL returned an empty result set".
--    Say what it will return BEFORE you run it.
-- ----------------------------------------------------------------------------

SELECT u.user_id, u.name
  FROM SYSTEM_USER u
  LEFT JOIN ADMIN      a ON a.user_id = u.user_id
  LEFT JOIN PHARMACIST p ON p.user_id = u.user_id
  LEFT JOIN CUSTOMER   c ON c.user_id = u.user_id
 WHERE (a.user_id IS NOT NULL)
     + (p.user_id IS NOT NULL)
     + (c.user_id IS NOT NULL) <> 1;


-- ----------------------------------------------------------------------------
-- 2b. THE MEDICINE SPECIALIZATION IS DISJOINT BUT PARTIAL
--     The contrast with query 2. Shows the two medicines in neither subclass.
--     Expect: 12 rows. Rows 11 and 12 show med_type NULL and subclass 'neither'.
--     Every other row shows OTC or Rx with its level.
-- ----------------------------------------------------------------------------

SELECT m.medicine_id, m.name, m.med_type,
       CASE WHEN r.medicine_id IS NOT NULL THEN CONCAT('Rx - ', r.prescription_required_level)
            WHEN o.medicine_id IS NOT NULL THEN 'OTC'
            ELSE 'neither'
       END AS subclass
  FROM MEDICINE m
  LEFT JOIN PRESCRIPTION_MEDICINE r ON r.medicine_id = m.medicine_id
  LEFT JOIN OTC_MEDICINE          o ON o.medicine_id = m.medicine_id
 ORDER BY m.medicine_id;


-- ----------------------------------------------------------------------------
-- 3a. STOCK INTEGRITY CHECK - THE FULL PICTURE
--     Run this one FIRST. Shows all 12 medicines side by side so there is
--     something to point at.
--     Expect: 12 rows, stored_stock equal to calculated_stock on every one.
--     e.g. Paracetamol 4895 = 5000 received - 105 sold.
-- ----------------------------------------------------------------------------

SELECT m.medicine_id, m.name,
       m.quantity_in_stock                           AS stored_stock,
       COALESCE(r.received,0)                        AS received,
       COALESCE(o.sold,0)                            AS sold,
       COALESCE(r.received,0) - COALESCE(o.sold,0)   AS calculated_stock
  FROM MEDICINE m
  LEFT JOIN (SELECT ri.medicine_id, SUM(ri.quantity) AS received
               FROM RESTOCK_ITEM  ri
               JOIN RESTOCK_ORDER ro ON ro.restock_id = ri.restock_id
              WHERE ro.status = 'RECEIVED'
              GROUP BY ri.medicine_id) r ON r.medicine_id = m.medicine_id
  LEFT JOIN (SELECT oi.medicine_id, SUM(oi.quantity) AS sold
               FROM ORDER_ITEM     oi
               JOIN CUSTOMER_ORDER co ON co.order_id = oi.order_id
              WHERE co.status IN ('READY','COLLECTED')
              GROUP BY oi.medicine_id) o ON o.medicine_id = m.medicine_id
 ORDER BY m.medicine_id;


-- ----------------------------------------------------------------------------
-- 3b. STOCK INTEGRITY CHECK - THE EXCEPTION REPORT
--     Identical to the query in admin.php. Returns only rows that disagree.
--     Expect: ZERO ROWS. This is the version admin.php runs, so an empty result
--     here is the same green tick the application's report shows.
-- ----------------------------------------------------------------------------

SELECT m.medicine_id, m.name,
       m.quantity_in_stock                           AS stored_stock,
       COALESCE(r.received,0) - COALESCE(o.sold,0)   AS calculated_stock
  FROM MEDICINE m
  LEFT JOIN (SELECT ri.medicine_id, SUM(ri.quantity) AS received
               FROM RESTOCK_ITEM  ri
               JOIN RESTOCK_ORDER ro ON ro.restock_id = ri.restock_id
              WHERE ro.status = 'RECEIVED'
              GROUP BY ri.medicine_id) r ON r.medicine_id = m.medicine_id
  LEFT JOIN (SELECT oi.medicine_id, SUM(oi.quantity) AS sold
               FROM ORDER_ITEM     oi
               JOIN CUSTOMER_ORDER co ON co.order_id = oi.order_id
              WHERE co.status IN ('READY','COLLECTED')
              GROUP BY oi.medicine_id) o ON o.medicine_id = m.medicine_id
 WHERE m.quantity_in_stock
       <> COALESCE(r.received,0) - COALESCE(o.sold,0);


-- ----------------------------------------------------------------------------
-- 4. A DELETE THE DATABASE REFUSES
--    SAFE TO RUN. Fails with error 1451, a foreign key constraint violation,
--    because ORDER_ITEM and RESTOCK_ITEM both reference MEDICINE with
--    ON DELETE RESTRICT. Nothing is changed - MEDICINE still has 12 rows after.
--    The refusal is the point. Verified error text:
--
--      #1451 - Cannot delete or update a parent row: a foreign key constraint
--      fails (`pharmacy_db`.`ORDER_ITEM`, CONSTRAINT `fk_orderitem_medicine`
--      FOREIGN KEY (`medicine_id`) REFERENCES `MEDICINE` (`medicine_id`)
--      ON UPDATE CASCADE)
-- ----------------------------------------------------------------------------

DELETE FROM MEDICINE WHERE medicine_id = 1;   -- Paracetamol 500mg


-- ----------------------------------------------------------------------------
-- 4b. WHY IT REFUSED - the rows that are protecting it
--     Run this straight after 4 to show what the constraint is defending.
--     Expect: 7 rows - sold on orders 1, 4, 15, 16, 18 and purchased on
--     restocks 1 and 4. Five orders and two purchases is why it refused.
-- ----------------------------------------------------------------------------

SELECT 'sold on order' AS reference, oi.order_id AS id, oi.quantity
  FROM ORDER_ITEM oi WHERE oi.medicine_id = 1
UNION ALL
SELECT 'purchased on restock', ri.restock_id, ri.quantity
  FROM RESTOCK_ITEM ri WHERE ri.medicine_id = 1;


-- ----------------------------------------------------------------------------
-- 5. INVOICE 6 - THE TWO-PAYMENT CASE SHE WILL ASK ABOUT
--    One PENDING, one PAID, and a payer who is not the customer who ordered.
--    This is business rule R8 and the reason PAID_BY is 1:N.
--    Expect: 2 rows, both invoice 6, both Rs. 720, ordered_by Divigsharan V.
--    Payment 7 CASH PENDING paid_by Divigsharan V;
--    payment 10 ONLINE PAID paid_by Alaxan S. Only the PAID one settles it.
-- ----------------------------------------------------------------------------

SELECT i.invoice_id, i.total_amount,
       buyer.name  AS ordered_by,
       p.payment_id, p.amount, p.method, p.status,
       payer.name  AS paid_by
  FROM INVOICE i
  JOIN CUSTOMER_ORDER co   ON co.order_id = i.order_id
  JOIN SYSTEM_USER    buyer ON buyer.user_id = co.customer_id
  LEFT JOIN PAYMENT   p     ON p.invoice_id = i.invoice_id
  LEFT JOIN SYSTEM_USER payer ON payer.user_id = p.payer_id
 WHERE i.invoice_id = 6;


-- ----------------------------------------------------------------------------
-- 6. THE SIXTEEN FOREIGN KEYS AND WHAT EACH DOES ON DELETE
--    Useful if she asks for the count rather than the Designer diagram.
--    Expect: 16 rows, and grouping them gives exactly 9 CASCADE, 6 RESTRICT
--    and 1 SET NULL. The single SET NULL is fk_order_pharmacist.
--    Note: the submitted scope document says five foreign keys use RESTRICT.
--    It is six. If she counts, agree with the database, not the document.
-- ----------------------------------------------------------------------------

SELECT rc.constraint_name,
       rc.table_name        AS child_table,
       rc.referenced_table_name AS parent_table,
       rc.delete_rule
  FROM information_schema.referential_constraints rc
 WHERE rc.constraint_schema = 'pharmacy_db'
 ORDER BY rc.delete_rule, rc.table_name;


-- ----------------------------------------------------------------------------
-- 7. SEARCH IS PARAMETERISED - THE INJECTION PAYLOAD RETURNS NOTHING
--    This is what the application sends when someone types
--        ' OR '1'='1
--    into the medicine search box. The payload is bound as a value, so it is
--    searched for literally as text.
--    Expect: ZERO ROWS, not all 12. The payload is matched as literal text,
--    so nothing is called Paracetamol' OR '1'='1.
-- ----------------------------------------------------------------------------

SELECT medicine_id, name FROM MEDICINE
 WHERE name LIKE CONCAT('%', ''' OR ''1''=''1', '%');
