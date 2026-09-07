<?php
/* ============================================================
   admin.php  -  Low stock alerts, sales reports, and the
   stock integrity check. This page is the demo centrepiece.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('admin');

/* ============================================================
   SEARCH - low stock and expiring tables
   One shared search box filters both tables by medicine name; the
   expiring table also takes a horizon dropdown (30/60/90 days),
   replacing the fixed 90 that used to be hard-coded. As elsewhere,
   every user value is bound as a parameter rather than glued into
   the SQL text, with the % wildcards attached to the value - that
   is what stops SQL injection.
   ============================================================ */
$sq   = trim($_GET['sq'] ?? '');
$validDaysOptions = [30, 60, 90];
$days = (int)($_GET['days'] ?? 90);
if (!in_array($days, $validDaysOptions, true)) {
    $days = 90;
}

/* ---------- date range for the daily sales report below ----
   Lives in the same search box/querystring as sq and days above,
   but only ever affects the daily sales table further down - sq
   and days are untouched by it. Blank stays blank (open-ended),
   and a value that is not a real calendar date is treated the
   same as blank rather than raising an error - same rule
   medicines.php uses for its optional expiry_date field.
   ------------------------------------------------------------ */
$dateFrom = trim($_GET['dfrom'] ?? '');
$dateTo   = trim($_GET['dto'] ?? '');

$fromCheck = DateTime::createFromFormat('Y-m-d', $dateFrom);
if ($dateFrom === '' || !$fromCheck || $fromCheck->format('Y-m-d') !== $dateFrom) {
    $dateFrom = '';
}
$toCheck = DateTime::createFromFormat('Y-m-d', $dateTo);
if ($dateTo === '' || !$toCheck || $toCheck->format('Y-m-d') !== $dateTo) {
    $dateTo = '';
}

// Given the wrong way round, swap rather than return an empty table -
// the visitor almost certainly meant a range, not nothing at all.
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

/* ---------- Q1: low stock ---------------------------------- */
// A discontinued medicine will never be reordered, so it should not
// raise a low-stock alert - that alert exists to prompt restocking a
// medicine that is still sold. See medicines.php for is_active.
$lowWhere  = ['quantity_in_stock < reorder_threshold', 'is_active = 1'];
$lowParams = [];
if ($sq !== '') {
    $lowWhere[]  = "name LIKE ?";
    $lowParams[] = '%' . $sq . '%';
}
$lowStmt = $pdo->prepare(
    "SELECT medicine_id, name, quantity_in_stock, reorder_threshold,
            reorder_threshold - quantity_in_stock AS shortfall
       FROM MEDICINE
      WHERE " . implode(' AND ', $lowWhere) . "
      ORDER BY shortfall DESC"
);
$lowStmt->execute($lowParams);
$lowStock = $lowStmt->fetchAll();

/* ---------- last supplier per medicine ---------------------
   For the "last supplier" column below: the supplier on the most
   recent RESTOCK_ITEM/RESTOCK_ORDER for each medicine. restock_id
   only ever increases, so MAX(restock_id) per medicine picks out
   its latest restock line - see the identical trick in restock.php.
   Built once as an id => name map rather than per-row, so the low
   stock table below is one extra query, not one per row. ---------- */
$lastSuppliers = $pdo->query(
    "SELECT ri.medicine_id, sup.name AS supplier_name
       FROM RESTOCK_ITEM  ri
       JOIN (SELECT medicine_id, MAX(restock_id) AS max_id
               FROM RESTOCK_ITEM
              GROUP BY medicine_id) latest
         ON latest.medicine_id = ri.medicine_id AND latest.max_id = ri.restock_id
       JOIN RESTOCK_ORDER ro  ON ro.restock_id  = ri.restock_id
       JOIN SUPPLIER      sup ON sup.supplier_id = ro.supplier_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);

/* ---------- Q2: expiring soon ------------------------------ */
// Deliberately NOT filtered by is_active, unlike low stock above: a
// discontinued medicine will not be reordered, but stock already sitting
// on the shelf can still expire and need disposing of, so it should stay
// visible here even after it is discontinued.
$expWhere  = ['expiry_date IS NOT NULL', 'expiry_date <= CURDATE() + INTERVAL ? DAY'];
$expParams = [$days];
if ($sq !== '') {
    $expWhere[]  = "name LIKE ?";
    $expParams[] = '%' . $sq . '%';
}
$expStmt = $pdo->prepare(
    "SELECT medicine_id, name, expiry_date, quantity_in_stock,
            DATEDIFF(expiry_date, CURDATE()) AS days_left
       FROM MEDICINE
      WHERE " . implode(' AND ', $expWhere) . "
      ORDER BY expiry_date"
);
$expStmt->execute($expParams);
$expiring = $expStmt->fetchAll();

/* ---------- Q11: stock integrity check --------------------- */
$mismatches = $pdo->query(
    "SELECT m.medicine_id, m.name,
            m.quantity_in_stock                            AS stored_stock,
            COALESCE(r.received, 0) - COALESCE(o.sold, 0)  AS calculated_stock
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
            <> COALESCE(r.received, 0) - COALESCE(o.sold, 0)"
)->fetchAll();

/* ---------- Q8: best sellers ------------------------------- */
$bestSellers = $pdo->query(
    "SELECT m.medicine_id, m.name,
            SUM(oi.quantity)                  AS units_sold,
            SUM(oi.quantity * oi.unit_price)  AS revenue
       FROM ORDER_ITEM     oi
       JOIN CUSTOMER_ORDER co ON co.order_id   = oi.order_id
       JOIN MEDICINE       m  ON m.medicine_id = oi.medicine_id
      WHERE co.status IN ('READY','COLLECTED')
      GROUP BY m.medicine_id, m.name
      ORDER BY revenue DESC
      LIMIT 5"
)->fetchAll();

/* ---------- Q7: unpaid invoices ----------------------------
   This is a debt report: money the pharmacy is still owed. Joining
   only INVOICE to PAYMENT answered a slightly different question -
   "which invoices are short" - and a cancelled order's invoice is
   short forever, because cancelling it is exactly what makes it
   unpayable. It would sit here as permanent, uncollectable debt.
   So CUSTOMER_ORDER is joined in and CANCELLED excluded.

   Only CANCELLED - deliberately not "everything except CONFIRMED".
   An order that went READY or COLLECTED and was never fully paid is
   real money still owed; dropping it would understate the debt.

   payment.php runs a stricter version of this same idea, keeping
   only CONFIRMED orders, and the two are not meant to agree. That
   one answers "what can I take money for right now", and a payment
   can only be recorded against a CONFIRMED order. This one answers
   "what are we still owed", which includes debt that cannot be
   collected on that screen today. Same tables, different question.
   ------------------------------------------------------------ */
$outstanding = $pdo->query(
    "SELECT i.invoice_id, i.order_id, i.total_amount,
            COALESCE(SUM(CASE WHEN p.status = 'PAID'
                              THEN p.amount END), 0) AS amount_paid,
            i.total_amount
              - COALESCE(SUM(CASE WHEN p.status = 'PAID'
                                  THEN p.amount END), 0) AS balance_due
       FROM      INVOICE        i
       JOIN      CUSTOMER_ORDER co ON co.order_id  = i.order_id
       LEFT JOIN PAYMENT        p  ON p.invoice_id = i.invoice_id
      WHERE co.status <> 'CANCELLED'
      GROUP BY i.invoice_id, i.order_id, i.total_amount
     HAVING balance_due > 0
      ORDER BY i.invoice_id"
)->fetchAll();

/* ---------- Q13: daily sales ------------------------------- */
$dailyWhere  = ["co.status IN ('READY','COLLECTED')"];
$dailyParams = [];

if ($dateFrom !== '' && $dateTo !== '') {
    $dailyWhere[]  = "DATE(co.order_date) BETWEEN ? AND ?";
    $dailyParams[] = $dateFrom;
    $dailyParams[] = $dateTo;
} elseif ($dateFrom !== '') {
    $dailyWhere[]  = "DATE(co.order_date) >= ?";
    $dailyParams[] = $dateFrom;
} elseif ($dateTo !== '') {
    $dailyWhere[]  = "DATE(co.order_date) <= ?";
    $dailyParams[] = $dateTo;
}

$dailyStmt = $pdo->prepare(
    "SELECT DATE(co.order_date)               AS sale_date,
            COUNT(DISTINCT co.order_id)       AS orders,
            SUM(oi.quantity)                  AS units,
            SUM(oi.quantity * oi.unit_price)  AS revenue
       FROM CUSTOMER_ORDER co
       JOIN ORDER_ITEM     oi ON oi.order_id = co.order_id
      WHERE " . implode(' AND ', $dailyWhere) . "
      GROUP BY DATE(co.order_date)
      ORDER BY sale_date DESC"
);
$dailyStmt->execute($dailyParams);
$daily = $dailyStmt->fetchAll();

// For the heading below: describe whichever end(s) of the range are set.
$dailyRangeLabel = '';
if ($dateFrom !== '' && $dateTo !== '') {
    $dailyRangeLabel = ', ' . date('j M Y', strtotime($dateFrom))
                      . ' – ' . date('j M Y', strtotime($dateTo));
} elseif ($dateFrom !== '') {
    $dailyRangeLabel = ', from ' . date('j M Y', strtotime($dateFrom));
} elseif ($dateTo !== '') {
    $dailyRangeLabel = ', up to ' . date('j M Y', strtotime($dateTo));
}

page_header('Administrator dashboard');
show_flash();
?>

<h2>Stock integrity check</h2>
<?php if (!$mismatches): ?>
    <div class="alert alert-success">
        <strong>No discrepancies.</strong>
        Stock recalculated from received restock items minus completed sales
        (paid invoices, stock already deducted) matches the stored figure for all
        <?= (int)$pdo->query("SELECT COUNT(*) FROM MEDICINE")->fetchColumn() ?>
        medicines.
    </div>
<?php else: ?>
    <div class="alert alert-error">
        <?= count($mismatches) ?> medicine(s) disagree with the transaction
        history.
    </div>
    <table>
        <tr><th>ID</th><th>Medicine</th>
            <th class="num">Stored</th><th class="num">Calculated</th></tr>
        <?php foreach ($mismatches as $m): ?>
            <tr>
                <td><?= h($m['medicine_id']) ?></td>
                <td><?= h($m['name']) ?></td>
                <td class="num low"><?= h($m['stored_stock']) ?></td>
                <td class="num"><?= h($m['calculated_stock']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Low stock alerts</h2>
<form class="searchbar" method="get" action="admin.php">
    <input type="text" name="sq" placeholder="Search medicine name"
           value="<?= h($sq) ?>">
    <input type="hidden" name="days" value="<?= h($days) ?>">
    <input type="date" name="dfrom" title="Sales from" value="<?= h($dateFrom) ?>">
    <span class="muted small">to</span>
    <input type="date" name="dto" title="Sales to" value="<?= h($dateTo) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="admin.php">Clear</a>
</form>
<p class="muted" style="font-size:13px;margin-bottom:10px">
    This search filters both the low stock and expiring tables below;
    the horizon dropdown only affects the expiring table, and the date
    range only affects the daily sales table further down.
</p>
<p class="result-count">
    <?= count($lowStock) ?> medicine<?= count($lowStock) === 1 ? '' : 's' ?>
    <?php if ($sq !== ''): ?>matching '<?= h($sq) ?>'<?php endif; ?>
</p>
<?php if (!$lowStock): ?>
    <div class="card muted">
        <?= $sq !== ''
            ? 'No low-stock medicines match your search.'
            : 'Every medicine is above its reorder threshold.' ?>
    </div>
<?php else: ?>
    <table>
        <tr>
            <th>ID</th><th>Medicine</th>
            <th class="num">In stock</th><th class="num">Threshold</th>
            <th class="num">Shortfall</th><th>Last supplier</th><th></th>
        </tr>
        <?php foreach ($lowStock as $m):
            // Simple heuristic, not a forecast: suggest topping up to
            // roughly twice the reorder threshold, so the medicine does
            // not immediately fall low again the next time it sells.
            $suggestedQty = ($m['reorder_threshold'] * 2) - $m['quantity_in_stock'];
        ?>
            <tr>
                <td><?= h($m['medicine_id']) ?></td>
                <td><?= h($m['name']) ?></td>
                <td class="num low"><?= h($m['quantity_in_stock']) ?></td>
                <td class="num"><?= h($m['reorder_threshold']) ?></td>
                <td class="num"><?= h($m['shortfall']) ?></td>
                <td><?= isset($lastSuppliers[$m['medicine_id']])
                        ? h($lastSuppliers[$m['medicine_id']])
                        : '<span class="muted">—</span>' ?></td>
                <td>
                    <a class="btn btn-small"
                       href="restock.php?medicine_id=<?= h($m['medicine_id']) ?>&qty=<?= h($suggestedQty) ?>">
                        Restock
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:13px">
        These are derived by comparing stock against the reorder threshold.
        No alert is stored in the database.
    </p>
<?php endif; ?>

<div class="filter-links">
    <?php foreach ($validDaysOptions as $d): ?>
        <?php if ($days === $d): ?>
            <span class="current">Within <?= h($d) ?> days</span>
        <?php else: ?>
            <a href="<?= filter_link('days', $d === 90 ? '' : (string)$d) ?>">Within <?= h($d) ?> days</a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<h2>Expiring within <?= h($days) ?> days</h2>
<p class="result-count">
    <?= count($expiring) ?> medicine<?= count($expiring) === 1 ? '' : 's' ?>
    <?php if ($sq !== ''): ?>matching '<?= h($sq) ?>'<?php endif; ?>
</p>
<?php if (!$expiring): ?>
    <div class="card muted">
        <?= $sq !== '' ? 'No expiring medicines match your search.' : 'Nothing expiring soon.' ?>
    </div>
<?php else: ?>
    <table>
        <tr><th>Medicine</th><th>Expiry date</th>
            <th class="num">Days left</th><th class="num">In stock</th></tr>
        <?php foreach ($expiring as $m): ?>
            <tr>
                <td><?= h($m['name']) ?></td>
                <td><?= h(date('d M Y', strtotime($m['expiry_date']))) ?></td>
                <td class="num <?= $m['days_left'] < 45 ? 'low' : '' ?>">
                    <?= h($m['days_left']) ?></td>
                <td class="num"><?= h($m['quantity_in_stock']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Top 5 medicines by revenue</h2>
<table>
    <tr><th>Medicine</th><th class="num">Units sold</th>
        <th class="num">Revenue (Rs.)</th></tr>
    <?php foreach ($bestSellers as $m): ?>
        <tr>
            <td><?= h($m['name']) ?></td>
            <td class="num"><?= h($m['units_sold']) ?></td>
            <td class="num"><?= number_format($m['revenue'], 2) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Outstanding invoices</h2>
<?php if (!$outstanding): ?>
    <!-- "on an order that still stands" because cancelled orders are
         excluded above: their invoices can never be paid off, so an
         unpaid one left behind by a cancellation is not a debt and
         must not make this claim read as false. -->
    <div class="card good">Every invoice on an order that still stands is fully paid.</div>
<?php else: ?>
    <table>
        <tr><th>Invoice</th><th>Order</th>
            <th class="num">Total</th><th class="num">Paid</th>
            <th class="num">Balance due</th></tr>
        <?php foreach ($outstanding as $i): ?>
            <tr>
                <td>#<?= h($i['invoice_id']) ?></td>
                <td>#<?= h($i['order_id']) ?></td>
                <td class="num"><?= number_format($i['total_amount'], 2) ?></td>
                <td class="num"><?= number_format($i['amount_paid'], 2) ?></td>
                <td class="num low"><?= number_format($i['balance_due'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Daily sales<?= h($dailyRangeLabel) ?></h2>
<?php if (!$daily): ?>
    <div class="card muted">
        No sales in this range.
    </div>
<?php else: ?>
    <table>
        <tr><th>Date</th><th class="num">Orders</th>
            <th class="num">Units</th><th class="num">Revenue (Rs.)</th></tr>
        <?php foreach ($daily as $d): ?>
            <tr>
                <td><?= h(date('d M Y', strtotime($d['sale_date']))) ?></td>
                <td class="num"><?= h($d['orders']) ?></td>
                <td class="num"><?= h($d['units']) ?></td>
                <td class="num"><?= number_format($d['revenue'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php page_footer(); ?>
