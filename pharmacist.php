<?php
/* ============================================================
   pharmacist.php  -  Review pending orders, confirm them
   (which deducts stock and issues an invoice), mark collected.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('pharmacist');

$me = current_user_id();

/* ============================================================
   CONFIRM AN ORDER
   This is the most important operation in the whole system.
   Three things must happen together or not at all:
     1. stock is checked (but NOT reduced yet - R6 says stock is
        only deducted once the invoice is paid, see payment.php)
     2. the order is marked CONFIRMED and this pharmacist recorded
     3. an invoice is issued for the total
   A transaction guarantees "together or not at all".
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {

    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Lock the order row so two pharmacists cannot confirm it at once.
        $st = $pdo->prepare(
            "SELECT status FROM CUSTOMER_ORDER WHERE order_id = ? FOR UPDATE"
        );
        $st->execute([$orderId]);
        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('That order does not exist.');
        }
        if ($status !== 'PENDING') {
            throw new RuntimeException("Order #$orderId is already $status.");
        }

        // Read the lines, locking the medicine rows as we go.
        $st = $pdo->prepare(
            "SELECT oi.medicine_id, oi.quantity, oi.unit_price,
                    m.name, m.quantity_in_stock
               FROM ORDER_ITEM oi
               JOIN MEDICINE   m ON m.medicine_id = oi.medicine_id
              WHERE oi.order_id = ?
              FOR UPDATE"
        );
        $st->execute([$orderId]);
        $items = $st->fetchAll();

        if (!$items) {
            throw new RuntimeException('That order has no items.');
        }

        // Stock is only CHECKED here, not deducted - R6 says the
        // deduction happens once the invoice is paid (payment.php).
        $total = 0.0;

        foreach ($items as $it) {
            if ($it['quantity'] > $it['quantity_in_stock']) {
                throw new RuntimeException(
                    'Not enough stock of ' . $it['name'] . ': '
                    . $it['quantity'] . ' requested, only '
                    . $it['quantity_in_stock'] . ' available.'
                );
            }
            $total += $it['quantity'] * $it['unit_price'];
        }

        $pdo->prepare(
            "UPDATE CUSTOMER_ORDER
                SET status = 'CONFIRMED', pharmacist_id = ?
              WHERE order_id = ?"
        )->execute([$me, $orderId]);

        $pdo->prepare(
            "INSERT INTO INVOICE (order_id, total_amount, issue_date)
             VALUES (?, ?, NOW())"
        )->execute([$orderId, $total]);

        $invoiceId = (int)$pdo->lastInsertId();

        $pdo->commit();
        set_flash('success',
            "Order #$orderId confirmed. Invoice #$invoiceId issued for Rs. "
            . number_format($total, 2) . ". Stock will be deducted once it is paid in full.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: pharmacist.php');
    exit;
}

/* ---------- mark an order as collected --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'collect') {

    $orderId = (int)($_POST['order_id'] ?? 0);

    // Only READY orders can be collected: that is the status that
    // means "paid in full and stock already deducted" (R6). A
    // CONFIRMED order is still unpaid, so it cannot be handed over yet.
    $st = $pdo->prepare(
        "UPDATE CUSTOMER_ORDER
            SET status = 'COLLECTED'
          WHERE order_id = ? AND status = 'READY'"
    );
    $st->execute([$orderId]);

    set_flash($st->rowCount() ? 'success' : 'error',
        $st->rowCount()
            ? "Order #$orderId marked as collected."
            : "Order #$orderId could not be marked collected.");

    header('Location: pharmacist.php');
    exit;
}

/* ============================================================
   CANCEL A CONFIRMED ORDER
   R6 says stock is only deducted once an invoice is paid in full.
   A CONFIRMED order has no stock deducted yet, so cancelling one
   here never has to give anything back. READY is NOT reachable
   through this action: it is already paid and its stock already
   deducted, so undoing that needs a refund process, which is out
   of scope for this feature.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {

    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Lock the order row so a payment cannot be recorded against
        // it in the same instant it is being cancelled.
        $st = $pdo->prepare(
            "SELECT status FROM CUSTOMER_ORDER WHERE order_id = ? FOR UPDATE"
        );
        $st->execute([$orderId]);
        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('That order does not exist.');
        }
        if ($status !== 'CONFIRMED') {
            throw new RuntimeException(
                "Order #$orderId is $status, not CONFIRMED, so it cannot be cancelled this way."
            );
        }

        // A CONFIRMED order already has an invoice. If any payment has
        // been recorded against it - even a partial one - this is no
        // longer a plain cancellation, it needs a refund instead.
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM PAYMENT p
               JOIN INVOICE i ON i.invoice_id = p.invoice_id
              WHERE i.order_id = ?"
        );
        $st->execute([$orderId]);
        if ((int)$st->fetchColumn() > 0) {
            throw new RuntimeException(
                "Order #$orderId has already been part paid and must be handled as a refund, not a cancellation."
            );
        }

        // No stock to give back, and the invoice is left exactly as
        // it is - deleting a financial record would be wrong.
        // payment.php already refuses to take payment against an
        // order that is not CONFIRMED, so this invoice simply becomes
        // uncollectable from here on.
        $st = $pdo->prepare(
            "UPDATE CUSTOMER_ORDER
                SET status = 'CANCELLED'
              WHERE order_id = ? AND status = 'CONFIRMED'"
        );
        $st->execute([$orderId]);

        if ($st->rowCount() === 0) {
            throw new RuntimeException("Order #$orderId could not be cancelled.");
        }

        $pdo->commit();
        set_flash('success',
            "Order #$orderId cancelled. Its invoice remains on record but can no longer be paid.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: pharmacist.php');
    exit;
}

/* ============================================================
   REJECT A PENDING ORDER
   This is deliberately a separate action from cancel above, not a
   widened guard on it - the two are not the same operation. A
   PENDING order has no invoice yet, so it can have no payments
   recorded against it either: there is nothing financial to
   protect, and the transition is simply PENDING -> CANCELLED. A
   CONFIRMED order already has an invoice, which is why cancel must
   check for payments first and leaves the invoice standing as an
   uncollectable record. Applying that reasoning here would be
   wrong, since no invoice exists to leave behind.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject') {

    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Lock the order row so the customer cannot cancel or edit
        // it in the same instant it is being rejected.
        $st = $pdo->prepare(
            "SELECT status FROM CUSTOMER_ORDER WHERE order_id = ? FOR UPDATE"
        );
        $st->execute([$orderId]);
        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('That order does not exist.');
        }
        if ($status !== 'PENDING') {
            throw new RuntimeException(
                "Order #$orderId is $status, not PENDING, so it cannot be rejected this way."
            );
        }

        // No invoice to raise and no stock to touch - stock is only
        // deducted on full payment (R6), and a PENDING order has never
        // had any taken. pharmacist_id is stamped here exactly as
        // confirm does: R3 says an order is processed by one
        // pharmacist, and refusing an order is processing it.
        $st = $pdo->prepare(
            "UPDATE CUSTOMER_ORDER
                SET status = 'CANCELLED', pharmacist_id = ?
              WHERE order_id = ? AND status = 'PENDING'"
        );
        $st->execute([$me, $orderId]);

        if ($st->rowCount() === 0) {
            throw new RuntimeException("Order #$orderId could not be rejected.");
        }

        $pdo->commit();
        set_flash('success',
            "Order #$orderId rejected. No invoice was raised.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: pharmacist.php');
    exit;
}

/* ============================================================
   SEARCH - orders waiting for confirmation
   One box matches either the order number or the customer name.
   As with every search on this page, the value is bound as a
   parameter (never glued into the SQL text) and the % wildcards
   belong to the value, not the query - that is what stops SQL
   injection.
   ============================================================ */
$pendingSearch = trim($_GET['pq'] ?? '');

$pendingWhere  = ["co.status = 'PENDING'"];
$pendingParams = [];

if ($pendingSearch !== '') {
    $pendingWhere[]  = "(co.order_id LIKE ? OR su.name LIKE ?)";
    $pendingParams[] = '%' . $pendingSearch . '%';
    $pendingParams[] = '%' . $pendingSearch . '%';
}

/* ---------- data for the page ------------------------------ */
$pendingStmt = $pdo->prepare(
    "SELECT   co.order_id, co.order_date,
              su.name                            AS customer_name,
              SUM(oi.quantity * oi.unit_price)   AS order_total
       FROM CUSTOMER_ORDER co
       JOIN SYSTEM_USER    su ON su.user_id  = co.customer_id
       JOIN ORDER_ITEM     oi ON oi.order_id = co.order_id
      WHERE " . implode(' AND ', $pendingWhere) . "
      GROUP BY co.order_id, co.order_date, su.name
      ORDER BY co.order_date"
);
$pendingStmt->execute($pendingParams);
$pending = $pendingStmt->fetchAll();

/* the lines of every pending order, grouped by order */
$pendingItems = [];
if ($pending) {
    $ids  = array_column($pending, 'order_id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT oi.order_id, m.name, oi.quantity, oi.unit_price,
                m.quantity_in_stock
           FROM ORDER_ITEM oi
           JOIN MEDICINE   m ON m.medicine_id = oi.medicine_id
          WHERE oi.order_id IN ($marks)
          ORDER BY m.name"
    );
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $pendingItems[$row['order_id']][] = $row;
    }
}

/* order awaiting cancel confirmation, if the pharmacist clicked
   "Cancel order". payment_count decides which confirmation screen
   to show - see the HTML below. */
$cancelOrder = isset($_GET['cancel']) ? (int)$_GET['cancel'] : 0;
$cancelInfo  = null;
if ($cancelOrder) {
    $st = $pdo->prepare(
        "SELECT   co.order_id, co.status, su.name AS customer_name,
                  i.invoice_id, i.total_amount,
                  (SELECT COUNT(*) FROM PAYMENT p
                    WHERE p.invoice_id = i.invoice_id) AS payment_count
           FROM      CUSTOMER_ORDER co
           JOIN      SYSTEM_USER    su ON su.user_id = co.customer_id
           LEFT JOIN INVOICE        i  ON i.order_id = co.order_id
          WHERE co.order_id = ?"
    );
    $st->execute([$cancelOrder]);
    $cancelInfo = $st->fetch();
}

/* order awaiting reject confirmation, if the pharmacist clicked
   "Cancel order" on a still-pending order. */
$rejectOrder = isset($_GET['reject']) ? (int)$_GET['reject'] : 0;
$rejectInfo  = null;
if ($rejectOrder) {
    $st = $pdo->prepare(
        "SELECT   co.order_id, co.status, su.name AS customer_name
           FROM CUSTOMER_ORDER co
           JOIN SYSTEM_USER    su ON su.user_id = co.customer_id
          WHERE co.order_id = ?"
    );
    $st->execute([$rejectOrder]);
    $rejectInfo = $st->fetch();
}

/* ============================================================
   SEARCH - confirmed orders awaiting collection
   Same box-matches-order-or-name pattern, plus a status dropdown
   scoped to the two statuses this table ever shows.
   ============================================================ */
$activeSearch = trim($_GET['aq'] ?? '');
$activeStatus = $_GET['astatus'] ?? '';
$validActiveStatuses = ['CONFIRMED', 'READY'];

// A date range on when the order was placed, alongside the text box and
// status dropdown. Blank stays blank (open-ended); anything that is not
// a real calendar date is treated the same as blank rather than erroring.
$activeFrom = trim($_GET['afrom'] ?? '');
$activeTo   = trim($_GET['ato'] ?? '');

$fromCheck = DateTime::createFromFormat('Y-m-d', $activeFrom);
if ($activeFrom === '' || !$fromCheck || $fromCheck->format('Y-m-d') !== $activeFrom) {
    $activeFrom = '';
}
$toCheck = DateTime::createFromFormat('Y-m-d', $activeTo);
if ($activeTo === '' || !$toCheck || $toCheck->format('Y-m-d') !== $activeTo) {
    $activeTo = '';
}

// Given the wrong way round, swap rather than return an empty table.
if ($activeFrom !== '' && $activeTo !== '' && $activeFrom > $activeTo) {
    [$activeFrom, $activeTo] = [$activeTo, $activeFrom];
}

$activeWhere  = ["co.status IN ('CONFIRMED','READY')"];
$activeParams = [];

if ($activeSearch !== '') {
    $activeWhere[]  = "(co.order_id LIKE ? OR su.name LIKE ?)";
    $activeParams[] = '%' . $activeSearch . '%';
    $activeParams[] = '%' . $activeSearch . '%';
}
if (in_array($activeStatus, $validActiveStatuses, true)) {
    $activeWhere[]  = "co.status = ?";
    $activeParams[] = $activeStatus;
}
if ($activeFrom !== '' && $activeTo !== '') {
    $activeWhere[]  = "DATE(co.order_date) BETWEEN ? AND ?";
    $activeParams[] = $activeFrom;
    $activeParams[] = $activeTo;
} elseif ($activeFrom !== '') {
    $activeWhere[]  = "DATE(co.order_date) >= ?";
    $activeParams[] = $activeFrom;
} elseif ($activeTo !== '') {
    $activeWhere[]  = "DATE(co.order_date) <= ?";
    $activeParams[] = $activeTo;
}

$activeStmt = $pdo->prepare(
    "SELECT   co.order_id, co.order_date, co.status,
              su.name        AS customer_name,
              ph.name        AS pharmacist_name,
              i.invoice_id, i.total_amount
       FROM      CUSTOMER_ORDER co
       JOIN      SYSTEM_USER su ON su.user_id = co.customer_id
       LEFT JOIN SYSTEM_USER ph ON ph.user_id = co.pharmacist_id
       LEFT JOIN INVOICE     i  ON i.order_id = co.order_id
      WHERE " . implode(' AND ', $activeWhere) . "
      ORDER BY co.order_date"
);
$activeStmt->execute($activeParams);
$active = $activeStmt->fetchAll();

page_header('Pending orders');
show_flash();
?>

<?php if ($cancelOrder && $cancelInfo): ?>
    <?php if ($cancelInfo['status'] === 'CONFIRMED' && (int)$cancelInfo['payment_count'] === 0): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Cancel order #<?= h($cancelOrder) ?>?</strong>
                &middot; <?= h($cancelInfo['customer_name']) ?>
                &middot; Invoice #<?= h($cancelInfo['invoice_id']) ?>,
                Rs. <?= number_format($cancelInfo['total_amount'], 2) ?>
            </p>
            <p class="muted" style="margin-bottom:12px">
                The invoice stays on record but can no longer be paid. This cannot be undone.
            </p>
            <form method="post" action="pharmacist.php" style="display:inline">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="order_id" value="<?= h($cancelOrder) ?>">
                <button type="submit" class="btn btn-green">Yes, cancel this order</button>
            </form>
            <a class="btn btn-light" href="<?= action_link('cancel', '') ?>">No, keep it</a>
        </div>
    <?php elseif ($cancelInfo['status'] === 'CONFIRMED'): ?>
        <div class="card muted">
            Order #<?= h($cancelOrder) ?> has already been part paid and
            must be handled as a refund, not a cancellation.
        </div>
    <?php else: ?>
        <div class="card muted">
            Order #<?= h($cancelOrder) ?> is <?= h($cancelInfo['status']) ?>
            and cannot be cancelled this way.
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($rejectOrder && $rejectInfo): ?>
    <?php if ($rejectInfo['status'] === 'PENDING'): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Cancel order #<?= h($rejectOrder) ?>?</strong>
                &middot; <?= h($rejectInfo['customer_name']) ?>
            </p>
            <p class="muted" style="margin-bottom:12px">
                No invoice has been raised for this order. This cannot be undone.
            </p>
            <form method="post" action="pharmacist.php" style="display:inline">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="order_id" value="<?= h($rejectOrder) ?>">
                <button type="submit" class="btn btn-green">Yes, cancel this order</button>
            </form>
            <a class="btn btn-light" href="<?= action_link('reject', '') ?>">No, keep it</a>
        </div>
    <?php else: ?>
        <div class="card muted">
            Order #<?= h($rejectOrder) ?> is <?= h($rejectInfo['status']) ?>
            and cannot be rejected this way.
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2>Waiting for confirmation</h2>
<form class="searchbar" method="get" action="pharmacist.php">
    <input type="text" name="pq" placeholder="Search order number or customer name"
           value="<?= h($pendingSearch) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="pharmacist.php">Clear</a>
</form>
<p class="result-count">
    <?= count($pending) ?> order<?= count($pending) === 1 ? '' : 's' ?>
    <?php if ($pendingSearch !== ''): ?>matching '<?= h($pendingSearch) ?>'<?php endif; ?>
</p>
<?php if (!$pending): ?>
    <div class="card muted">
        <?= $pendingSearch !== '' ? 'No orders match your search.' : 'No orders are waiting. Nice.' ?>
    </div>
<?php else: ?>
    <?php foreach ($pending as $o): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Order #<?= h($o['order_id']) ?></strong>
                &middot; <?= h($o['customer_name']) ?>
                &middot; <span class="muted">
                    <?= h(date('d M Y, H:i', strtotime($o['order_date']))) ?>
                </span>
            </p>
            <table>
                <tr>
                    <th>Medicine</th><th class="num">Qty</th>
                    <th class="num">Unit price</th><th class="num">Line total</th>
                    <th class="num">Stock</th>
                </tr>
                <?php foreach ($pendingItems[$o['order_id']] ?? [] as $it):
                    $short = $it['quantity'] > $it['quantity_in_stock']; ?>
                    <tr>
                        <td><?= h($it['name']) ?></td>
                        <td class="num"><?= h($it['quantity']) ?></td>
                        <td class="num"><?= number_format($it['unit_price'], 2) ?></td>
                        <td class="num">
                            <?= number_format($it['quantity'] * $it['unit_price'], 2) ?>
                        </td>
                        <td class="num <?= $short ? 'low' : '' ?>">
                            <?= h($it['quantity_in_stock']) ?>
                            <?= $short ? ' (short)' : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th colspan="3" class="num">Order total</th>
                    <th class="num">Rs. <?= number_format((float)$o['order_total'], 2) ?></th>
                    <th></th>
                </tr>
            </table>
            <form method="post" action="pharmacist.php" style="margin-top:12px;display:inline">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="order_id" value="<?= h($o['order_id']) ?>">
                <button type="submit" class="btn btn-green">
                    Confirm order &amp; issue invoice
                </button>
            </form>
            <a class="btn btn-small" style="margin-top:12px"
               href="pharmacist.php?reject=<?= h($o['order_id']) ?>">Cancel order</a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Confirmed, awaiting collection</h2>
<form class="searchbar" method="get" action="pharmacist.php">
    <input type="text" name="aq" placeholder="Search order number or customer name"
           value="<?= h($activeSearch) ?>">
    <input type="hidden" name="astatus" value="<?= h($activeStatus) ?>">
    <input type="date" name="afrom" title="Ordered from" value="<?= h($activeFrom) ?>">
    <span class="muted small">to</span>
    <input type="date" name="ato" title="Ordered to" value="<?= h($activeTo) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="pharmacist.php">Clear</a>
</form>
<div class="filter-links">
    <?php if ($activeStatus === ''): ?>
        <span class="current">All statuses</span>
    <?php else: ?>
        <a href="<?= filter_link('astatus', '') ?>">All statuses</a>
    <?php endif; ?>
    <?php foreach ($validActiveStatuses as $vs): ?>
        <?php if ($activeStatus === $vs): ?>
            <span class="current"><?= h(ucfirst(strtolower($vs))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('astatus', $vs) ?>"><?= h(ucfirst(strtolower($vs))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($active) ?> order<?= count($active) === 1 ? '' : 's' ?>
    <?php if ($activeSearch !== ''): ?>matching '<?= h($activeSearch) ?>'<?php endif; ?>
</p>
<?php if (!$active): ?>
    <div class="card muted">
        <?= ($activeSearch !== '' || $activeStatus !== '' || $activeFrom !== '' || $activeTo !== '')
            ? 'No orders match your search.'
            : 'Nothing awaiting collection.' ?>
    </div>
<?php else: ?>
    <table>
        <tr>
            <th>Order</th><th>Customer</th><th>Confirmed by</th>
            <th>Status</th><th>Invoice</th>
            <th class="num">Total (Rs.)</th><th></th>
        </tr>
        <?php foreach ($active as $o): ?>
            <tr>
                <td>#<?= h($o['order_id']) ?></td>
                <td><?= h($o['customer_name']) ?></td>
                <td><?= h($o['pharmacist_name'] ?? '—') ?></td>
                <td><span class="pill pill-<?= h($o['status']) ?>">
                        <?= h($o['status']) ?></span></td>
                <td><?= $o['invoice_id'] ? '#' . h($o['invoice_id']) : '—' ?></td>
                <td class="num"><?= number_format((float)$o['total_amount'], 2) ?></td>
                <td>
                    <?php if ($o['status'] === 'READY'): ?>
                        <form method="post" action="pharmacist.php">
                            <input type="hidden" name="action" value="collect">
                            <input type="hidden" name="order_id"
                                   value="<?= h($o['order_id']) ?>">
                            <button type="submit" class="btn btn-small">
                                Mark collected
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="btn btn-small"
                           href="payment.php?invoice=<?= h($o['invoice_id']) ?>">Take payment</a>
                        <a class="btn btn-small"
                           href="<?= action_link('cancel', (string)$o['order_id']) ?>">Cancel order</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php page_footer(); ?>
