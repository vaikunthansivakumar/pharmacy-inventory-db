<?php
/* ============================================================
   customer.php  -  Browse medicines, place an order, see history.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('customer');

$me = current_user_id();

/* ---------- placing an order ------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {

    // Keep only the medicines where a positive quantity was typed.
    $wanted = [];
    foreach ($_POST['qty'] ?? [] as $medicineId => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $wanted[(int)$medicineId] = $qty;
        }
    }

    if (!$wanted) {
        set_flash('error', 'Enter a quantity for at least one medicine.');
        header('Location: customer.php');
        exit;
    }

    try {
        /* A transaction: either the order and ALL of its lines are
           saved, or nothing is. Without this you could end up with
           an order that has no items in it. */
        $pdo->beginTransaction();

        $pdo->prepare(
            "INSERT INTO CUSTOMER_ORDER (customer_id, pharmacist_id, order_date, status)
             VALUES (?, NULL, NOW(), 'PENDING')"
        )->execute([$me]);

        $orderId = (int)$pdo->lastInsertId();

        $priceStmt = $pdo->prepare(
            "SELECT price, quantity_in_stock, name FROM MEDICINE WHERE medicine_id = ?"
        );
        $lineStmt = $pdo->prepare(
            "INSERT INTO ORDER_ITEM (order_id, medicine_id, quantity, unit_price)
             VALUES (?, ?, ?, ?)"
        );

        foreach ($wanted as $medicineId => $qty) {
            $priceStmt->execute([$medicineId]);
            $med = $priceStmt->fetch();

            if (!$med) {
                throw new RuntimeException('That medicine no longer exists.');
            }
            if ($qty > $med['quantity_in_stock']) {
                throw new RuntimeException(
                    'Only ' . $med['quantity_in_stock'] . ' units of '
                    . $med['name'] . ' are in stock.'
                );
            }

            // The price is copied INTO the order line here, so a later
            // price change cannot rewrite this customer's history.
            $lineStmt->execute([$orderId, $medicineId, $qty, $med['price']]);
        }

        $pdo->commit();
        set_flash('success', "Order #$orderId placed. A pharmacist will confirm it shortly.");

    } catch (Throwable $e) {
        $pdo->rollBack();          // undo everything
        set_flash('error', 'Order not placed: ' . $e->getMessage());
    }

    header('Location: customer.php');
    exit;
}

/* ============================================================
   CANCEL A PENDING ORDER
   R6 says stock is only deducted once an invoice is paid in full.
   A PENDING order has no invoice at all yet, so no stock has ever
   moved for it - cancelling here never has to give anything back.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {

    $orderId = (int)($_POST['order_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Lock the row so a pharmacist cannot confirm it in the same
        // instant the customer is cancelling it.
        $pdo->prepare(
            "SELECT order_id FROM CUSTOMER_ORDER WHERE order_id = ? FOR UPDATE"
        )->execute([$orderId]);

        // All three conditions matter: customer_id stops one customer
        // cancelling another customer's order by editing the posted
        // id, and status = 'PENDING' stops the race with a confirm
        // that landed first. Nothing was deducted at PENDING, so
        // there is no stock to return.
        $st = $pdo->prepare(
            "UPDATE CUSTOMER_ORDER
                SET status = 'CANCELLED'
              WHERE order_id = ? AND customer_id = ? AND status = 'PENDING'"
        );
        $st->execute([$orderId, $me]);

        if ($st->rowCount() === 0) {
            throw new RuntimeException(
                "Order #$orderId could not be cancelled - it is not yours or is no longer pending."
            );
        }

        $pdo->commit();
        set_flash('success', "Order #$orderId cancelled.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: customer.php');
    exit;
}

/* ============================================================
   EDIT LINES OF A PENDING ORDER
   This is the update and delete for ORDER_ITEM: change a quantity,
   remove a line (quantity 0 does the same thing), or add a
   medicine that was not already on the order. All of it saved as
   one transaction - either every change is applied or none is.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_order_lines') {

    $orderId   = (int)($_POST['order_id'] ?? 0);
    $qtyPosted = $_POST['qty'] ?? [];

    try {
        $pdo->beginTransaction();

        // Lock the order row and re-check ownership AND status inside
        // the transaction - customer_id in the WHERE clause (not just a
        // check before showing the Edit link) stops one customer editing
        // another customer's order by posting its id, and FOR UPDATE
        // plus re-checking PENDING here stops a pharmacist confirming
        // this exact order in the same instant from being overwritten.
        $st = $pdo->prepare(
            "SELECT status FROM CUSTOMER_ORDER WHERE order_id = ? AND customer_id = ? FOR UPDATE"
        );
        $st->execute([$orderId, $me]);
        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException("Order #$orderId does not exist or is not yours.");
        }
        if ($status !== 'PENDING') {
            throw new RuntimeException("Order #$orderId is $status and can no longer be edited.");
        }

        $st = $pdo->prepare("SELECT medicine_id, quantity FROM ORDER_ITEM WHERE order_id = ? FOR UPDATE");
        $st->execute([$orderId]);
        $existing = [];
        foreach ($st->fetchAll() as $row) {
            $existing[(int)$row['medicine_id']] = (int)$row['quantity'];
        }

        $finalLines = $existing; // start from what is already there

        // Re-read every price (and stock, and active flag) from MEDICINE
        // at save time - never trust a posted price. Unlike a CONFIRMED
        // order, where unit_price is deliberately frozen at the moment
        // of sale (see pharmacist.php), a PENDING order has not been
        // sold yet, so editing it always uses today's catalogue price.
        $medStmt = $pdo->prepare(
            "SELECT name, price, quantity_in_stock, is_active FROM MEDICINE WHERE medicine_id = ?"
        );

        foreach ($qtyPosted as $medicineId => $qtyRaw) {
            $medicineId = (int)$medicineId;
            $qty        = (int)$qtyRaw;

            if ($qty < 0) {
                throw new RuntimeException('Quantity cannot be negative.');
            }

            // A quantity of zero removes the line - same as Remove.
            if ($qty === 0) {
                unset($finalLines[$medicineId]);
                continue;
            }

            $isExistingLine = array_key_exists($medicineId, $existing);

            $medStmt->execute([$medicineId]);
            $med = $medStmt->fetch();
            if (!$med) {
                throw new RuntimeException('One of the selected medicines no longer exists.');
            }

            // A discontinued medicine cannot be newly added. One already
            // on the order may stay, but cannot have its quantity raised.
            if (!$med['is_active'] && !$isExistingLine) {
                throw new RuntimeException($med['name'] . ' has been discontinued and cannot be added to this order.');
            }
            if (!$med['is_active'] && $isExistingLine && $qty > $existing[$medicineId]) {
                throw new RuntimeException($med['name'] . ' has been discontinued - its quantity cannot be increased.');
            }

            if ($qty > $med['quantity_in_stock']) {
                throw new RuntimeException(
                    'Only ' . $med['quantity_in_stock'] . ' units of ' . $med['name'] . ' are in stock.'
                );
            }

            $finalLines[$medicineId] = $qty;
        }

        // Removing the last line would leave an order with nothing in
        // it - that is not an edit, it is a cancellation, so send the
        // customer to the feature that already exists for that instead.
        if (!$finalLines) {
            throw new RuntimeException('An order must have at least one item - cancel the order instead if you want none of it.');
        }

        $delStmt = $pdo->prepare("DELETE FROM ORDER_ITEM WHERE order_id = ? AND medicine_id = ?");
        foreach ($existing as $medicineId => $oldQty) {
            if (!array_key_exists($medicineId, $finalLines)) {
                $delStmt->execute([$orderId, $medicineId]);
            }
        }

        $priceStmt = $pdo->prepare("SELECT price FROM MEDICINE WHERE medicine_id = ?");
        $updStmt   = $pdo->prepare(
            "UPDATE ORDER_ITEM SET quantity = ?, unit_price = ? WHERE order_id = ? AND medicine_id = ?"
        );
        $insStmt = $pdo->prepare(
            "INSERT INTO ORDER_ITEM (order_id, medicine_id, quantity, unit_price) VALUES (?, ?, ?, ?)"
        );

        foreach ($finalLines as $medicineId => $qty) {
            $priceStmt->execute([$medicineId]);
            $price = $priceStmt->fetchColumn();

            if (array_key_exists($medicineId, $existing)) {
                $updStmt->execute([$qty, $price, $orderId, $medicineId]);
            } else {
                $insStmt->execute([$orderId, $medicineId, $qty, $price]);
            }
        }

        // No stock moves here - R6 says stock is only deducted once the
        // invoice is paid in full, and a PENDING order has no invoice yet.
        $pdo->commit();
        set_flash('success', "Order #$orderId updated.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: customer.php');
    exit;
}

/* ============================================================
   SEARCH - available medicines
   Text box matches name OR category; dropdown filters by type.
   The WHERE clause is built as an array of conditions plus a
   parallel array of bound values, then joined with AND. Every
   value typed by the user goes in as a bound parameter rather
   than being glued into the SQL text - that is what stops SQL
   injection here, and the % wildcards belong to the VALUE, not
   the query string.
   ============================================================ */
$medSearch = trim($_GET['mq'] ?? '');
$medType   = $_GET['mtype'] ?? '';
$validMedTypes = ['PRESCRIPTION', 'OTC'];
$medTypeLabels = ['PRESCRIPTION' => 'Prescription', 'OTC' => 'Over the counter'];

// A discontinued medicine (is_active = 0) must not be orderable, even
// if old stock is still sitting in quantity_in_stock - see medicines.php.
$medWhere  = ['m.quantity_in_stock > 0', 'm.is_active = 1'];
$medParams = [];

if ($medSearch !== '') {
    $medWhere[]  = "(m.name LIKE ? OR m.category LIKE ?)";
    $medParams[] = '%' . $medSearch . '%';
    $medParams[] = '%' . $medSearch . '%';
}
if (in_array($medType, $validMedTypes, true)) {
    $medWhere[]  = "m.med_type = ?";
    $medParams[] = $medType;
}

$medStmt = $pdo->prepare(
    "SELECT   m.medicine_id, m.name, m.category, m.price,
              m.quantity_in_stock,
              COALESCE(m.med_type, 'GENERAL') AS med_type,
              p.prescription_required_level
       FROM      MEDICINE              m
       LEFT JOIN PRESCRIPTION_MEDICINE p ON p.medicine_id = m.medicine_id
      WHERE  " . implode(' AND ', $medWhere) . "
      ORDER BY m.name"
);
$medStmt->execute($medParams);
$medicines = $medStmt->fetchAll();

/* ============================================================
   SEARCH - my orders
   Text box matches the order number; dropdown filters by status.
   customer_id stays in $where alongside the search conditions,
   so a search can never widen the results past this customer's
   own orders.
   ============================================================ */
$orderSearch  = trim($_GET['oq'] ?? '');
$orderStatus  = $_GET['ostatus'] ?? '';
$validStatuses = ['PENDING', 'CONFIRMED', 'READY', 'COLLECTED', 'CANCELLED'];

// A date range on when the order was placed, alongside the text box and
// status dropdown. Blank stays blank (open-ended); anything that is not
// a real calendar date is treated the same as blank rather than erroring.
$orderFrom = trim($_GET['ofrom'] ?? '');
$orderTo   = trim($_GET['oto'] ?? '');

$fromCheck = DateTime::createFromFormat('Y-m-d', $orderFrom);
if ($orderFrom === '' || !$fromCheck || $fromCheck->format('Y-m-d') !== $orderFrom) {
    $orderFrom = '';
}
$toCheck = DateTime::createFromFormat('Y-m-d', $orderTo);
if ($orderTo === '' || !$toCheck || $toCheck->format('Y-m-d') !== $orderTo) {
    $orderTo = '';
}

// Given the wrong way round, swap rather than return an empty table.
if ($orderFrom !== '' && $orderTo !== '' && $orderFrom > $orderTo) {
    [$orderFrom, $orderTo] = [$orderTo, $orderFrom];
}

$orderWhere  = ['co.customer_id = ?'];
$orderParams = [$me];

if ($orderSearch !== '') {
    $orderWhere[]  = "co.order_id LIKE ?";
    $orderParams[] = '%' . $orderSearch . '%';
}
if (in_array($orderStatus, $validStatuses, true)) {
    $orderWhere[]  = "co.status = ?";
    $orderParams[] = $orderStatus;
}
if ($orderFrom !== '' && $orderTo !== '') {
    $orderWhere[]  = "DATE(co.order_date) BETWEEN ? AND ?";
    $orderParams[] = $orderFrom;
    $orderParams[] = $orderTo;
} elseif ($orderFrom !== '') {
    $orderWhere[]  = "DATE(co.order_date) >= ?";
    $orderParams[] = $orderFrom;
} elseif ($orderTo !== '') {
    $orderWhere[]  = "DATE(co.order_date) <= ?";
    $orderParams[] = $orderTo;
}

$ordersStmt = $pdo->prepare(
    "SELECT   co.order_id, co.order_date, co.status,
              COUNT(oi.medicine_id)                    AS lines_count,
              SUM(oi.quantity * oi.unit_price)         AS order_total,
              i.invoice_id
       FROM      CUSTOMER_ORDER co
       LEFT JOIN ORDER_ITEM     oi ON oi.order_id = co.order_id
       LEFT JOIN INVOICE        i  ON i.order_id  = co.order_id
      WHERE  " . implode(' AND ', $orderWhere) . "
      GROUP BY co.order_id, co.order_date, co.status, i.invoice_id
      ORDER BY co.order_date DESC"
);
$ordersStmt->execute($orderParams);
$orders = $ordersStmt->fetchAll();

/* order awaiting cancel confirmation, if the customer clicked "Cancel".
   Reusing $orders (already WHERE customer_id = ?) instead of a fresh
   query means a cancel=<id> for someone else's order simply matches
   nothing here - no separate ownership check needed to show this. */
$cancelOrder = isset($_GET['cancel']) ? (int)$_GET['cancel'] : 0;
$cancelInfo  = null;
foreach ($orders as $o) {
    if ((int)$o['order_id'] === $cancelOrder) {
        $cancelInfo = $o;
        break;
    }
}

/* items of one order, if the customer clicked "view" */
$viewOrder = isset($_GET['order']) ? (int)$_GET['order'] : 0;
$viewItems = [];
if ($viewOrder) {
    $st = $pdo->prepare(
        "SELECT m.name, oi.quantity, oi.unit_price,
                oi.quantity * oi.unit_price AS line_total
           FROM ORDER_ITEM oi
           JOIN MEDICINE   m  ON m.medicine_id = oi.medicine_id
           JOIN CUSTOMER_ORDER co ON co.order_id = oi.order_id
          WHERE oi.order_id = ? AND co.customer_id = ?
          ORDER BY m.name"
    );
    $st->execute([$viewOrder, $me]);      // customer_id check = no peeking
    $viewItems = $st->fetchAll();
}

/* order awaiting editing, if the customer clicked "Edit". customer_id is
   part of the query itself (not just a check before showing the link),
   same as $viewItems above - a not-owned order_id simply matches nothing. */
$editOrderId = isset($_GET['editorder']) ? (int)$_GET['editorder'] : 0;
$editOrder   = null;
$editLines   = [];
if ($editOrderId) {
    $st = $pdo->prepare(
        "SELECT order_id, status FROM CUSTOMER_ORDER WHERE order_id = ? AND customer_id = ?"
    );
    $st->execute([$editOrderId, $me]);
    $editOrder = $st->fetch();

    if ($editOrder && $editOrder['status'] === 'PENDING') {
        $st = $pdo->prepare(
            "SELECT oi.medicine_id, m.name, oi.quantity, oi.unit_price, m.quantity_in_stock
               FROM ORDER_ITEM oi JOIN MEDICINE m ON m.medicine_id = oi.medicine_id
              WHERE oi.order_id = ?
              ORDER BY m.name"
        );
        $st->execute([$editOrderId]);
        $editLines = $st->fetchAll();
    }
}

// Medicines that can be newly added to this order: the exact same
// search-filtered $medicines list used for "Available medicines" below
// (reusing its search box and query, per the task), minus whatever is
// already a line on this order.
$editLineIds = array_column($editLines, 'medicine_id');
$addableToEdit = ($editOrder && $editOrder['status'] === 'PENDING')
    ? array_filter($medicines, fn($m) => !in_array($m['medicine_id'], $editLineIds, true))
    : [];

page_header('Browse and order medicines');
show_flash();
?>

<?php if ($cancelOrder && $cancelInfo): ?>
    <?php if ($cancelInfo['status'] === 'PENDING'): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Cancel order #<?= h($cancelOrder) ?>?</strong>
                &middot; <?= h($cancelInfo['lines_count']) ?> item(s)
                &middot; Rs. <?= number_format((float)$cancelInfo['order_total'], 2) ?>
            </p>
            <p class="muted" style="margin-bottom:12px">This cannot be undone.</p>
            <form method="post" action="customer.php" style="display:inline">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" value="<?= h($cancelOrder) ?>">
                <button type="submit" class="btn btn-green">Yes, cancel this order</button>
            </form>
            <a class="btn btn-light" href="<?= action_link('cancel', '') ?>">No, keep it</a>
        </div>
    <?php else: ?>
        <div class="card muted">
            Order #<?= h($cancelOrder) ?> is <?= h($cancelInfo['status']) ?>
            and can no longer be cancelled.
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($viewOrder && $viewItems): ?>
    <h2>Order #<?= h($viewOrder) ?> &mdash; items</h2>
    <table>
        <tr>
            <th>Medicine</th><th class="num">Qty</th>
            <th class="num">Unit price</th><th class="num">Line total</th>
        </tr>
        <?php $sum = 0; foreach ($viewItems as $it): $sum += $it['line_total']; ?>
            <tr>
                <td><?= h($it['name']) ?></td>
                <td class="num"><?= h($it['quantity']) ?></td>
                <td class="num"><?= number_format($it['unit_price'], 2) ?></td>
                <td class="num"><?= number_format($it['line_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th colspan="3" class="num">Total</th>
            <th class="num">Rs. <?= number_format($sum, 2) ?></th>
        </tr>
    </table>
    <p style="margin-top:12px"><a href="<?= action_link('order', '') ?>" class="btn btn-small">Back</a></p>
<?php endif; ?>

<?php if ($editOrderId): ?>
    <?php if (!$editOrder): ?>
        <div class="card muted">That order does not exist or is not yours.</div>
    <?php elseif ($editOrder['status'] !== 'PENDING'): ?>
        <div class="card muted">
            Order #<?= h($editOrderId) ?> is <?= h($editOrder['status']) ?> and can no longer be edited.
        </div>
    <?php else: ?>
        <h2>Edit order #<?= h($editOrderId) ?></h2>
        <p class="muted" style="margin-bottom:12px">
            Setting a quantity to zero removes that line - the same as Remove.
            Removing every line is not allowed; cancel the order instead if
            that is what you mean.
        </p>
        <form method="post" action="customer.php">
            <input type="hidden" name="action" value="edit_order_lines">
            <input type="hidden" name="order_id" value="<?= h($editOrderId) ?>">

            <table>
                <tr><th>Medicine</th><th class="num">In stock</th><th class="num">Quantity</th><th></th></tr>
                <?php foreach ($editLines as $ln): ?>
                    <tr>
                        <td><?= h($ln['name']) ?></td>
                        <td class="num"><?= h($ln['quantity_in_stock']) ?></td>
                        <td class="num">
                            <input class="qty" type="number" min="0"
                                   name="qty[<?= h($ln['medicine_id']) ?>]" value="<?= h($ln['quantity']) ?>">
                        </td>
                        <td class="muted small">was Rs. <?= number_format($ln['unit_price'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php if ($addableToEdit): ?>
                <h3 style="margin-top:20px;font-size:14px;color:var(--accent)">Add another medicine</h3>
                <table>
                    <tr>
                        <th>Medicine</th><th>Category</th>
                        <th class="num">Price (Rs.)</th><th class="num">In stock</th>
                        <th class="num">Quantity</th>
                    </tr>
                    <?php foreach ($addableToEdit as $m): ?>
                        <tr>
                            <td><?= h($m['name']) ?></td>
                            <td class="muted"><?= h($m['category']) ?></td>
                            <td class="num"><?= number_format($m['price'], 2) ?></td>
                            <td class="num"><?= h($m['quantity_in_stock']) ?></td>
                            <td class="num">
                                <input class="qty" type="number" min="0"
                                       max="<?= h($m['quantity_in_stock']) ?>"
                                       name="qty[<?= h($m['medicine_id']) ?>]" value="0">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <p class="muted small" style="margin-top:6px">
                    Only medicines matching the search below are listed here -
                    clear the search to see everything you could add.
                </p>
            <?php endif; ?>

            <p style="margin-top:14px">
                <button type="submit" class="btn btn-green">Save changes</button>
                <a class="btn btn-light" href="<?= action_link('editorder', '') ?>">Cancel</a>
            </p>
        </form>
    <?php endif; ?>
<?php endif; ?>

<h2>Available medicines</h2>
<form class="searchbar" method="get" action="customer.php">
    <input type="text" name="mq" placeholder="Search name or category"
           value="<?= h($medSearch) ?>">
    <input type="hidden" name="mtype" value="<?= h($medType) ?>">
    <?php if ($editOrderId): ?>
        <input type="hidden" name="editorder" value="<?= h($editOrderId) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="customer.php<?= $editOrderId ? '?editorder=' . h($editOrderId) : '' ?>">Clear</a>
</form>
<div class="filter-links">
    <span class="filter-label">Type:</span>
    <?php if ($medType === ''): ?>
        <span class="current">All types</span>
    <?php else: ?>
        <a href="<?= filter_link('mtype', '') ?>">All types</a>
    <?php endif; ?>
    <?php foreach ($validMedTypes as $vt): ?>
        <?php if ($medType === $vt): ?>
            <span class="current"><?= h($medTypeLabels[$vt]) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('mtype', $vt) ?>"><?= h($medTypeLabels[$vt]) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($medicines) ?> medicine<?= count($medicines) === 1 ? '' : 's' ?>
    <?php if ($medSearch !== ''): ?>matching '<?= h($medSearch) ?>'<?php endif; ?>
</p>
<?php if (!$medicines): ?>
    <div class="card muted">No medicines match your search.</div>
<?php else: ?>
    <form method="post" action="customer.php">
        <input type="hidden" name="action" value="place_order">
        <table>
            <tr>
                <th>Medicine</th><th>Category</th><th>Type</th>
                <th class="num">Price (Rs.)</th><th class="num">In stock</th>
                <th class="num">Quantity</th>
            </tr>
            <?php foreach ($medicines as $m): ?>
                <tr>
                    <td><?= h($m['name']) ?></td>
                    <td class="muted"><?= h($m['category']) ?></td>
                    <td>
                        <?= h($m['med_type']) ?>
                        <?php if ($m['prescription_required_level']): ?>
                            <span class="muted">
                                (<?= h($m['prescription_required_level']) ?>)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= number_format($m['price'], 2) ?></td>
                    <td class="num"><?= h($m['quantity_in_stock']) ?></td>
                    <td class="num">
                        <input class="qty" type="number" min="0"
                               max="<?= h($m['quantity_in_stock']) ?>"
                               name="qty[<?= h($m['medicine_id']) ?>]" value="0">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:14px">
            <button type="submit" class="btn btn-green">Place order</button>
        </p>
    </form>
<?php endif; ?>

<h2>My orders</h2>
<form class="searchbar" method="get" action="customer.php">
    <input type="text" name="oq" placeholder="Search order number"
           value="<?= h($orderSearch) ?>">
    <input type="hidden" name="ostatus" value="<?= h($orderStatus) ?>">
    <input type="date" name="ofrom" title="Ordered from" value="<?= h($orderFrom) ?>">
    <span class="muted small">to</span>
    <input type="date" name="oto" title="Ordered to" value="<?= h($orderTo) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="customer.php">Clear</a>
</form>
<div class="filter-links">
    <?php if ($orderStatus === ''): ?>
        <span class="current">All statuses</span>
    <?php else: ?>
        <a href="<?= filter_link('ostatus', '') ?>">All statuses</a>
    <?php endif; ?>
    <?php foreach ($validStatuses as $vs): ?>
        <?php if ($orderStatus === $vs): ?>
            <span class="current"><?= h(ucfirst(strtolower($vs))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('ostatus', $vs) ?>"><?= h(ucfirst(strtolower($vs))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?>
    <?php if ($orderSearch !== ''): ?>matching '<?= h($orderSearch) ?>'<?php endif; ?>
</p>
<?php if (!$orders): ?>
    <div class="card muted">
        <?= ($orderSearch !== '' || $orderStatus !== '' || $orderFrom !== '' || $orderTo !== '')
            ? 'No orders match your search.'
            : 'You have not placed any orders yet.' ?>
    </div>
<?php else: ?>
    <table>
        <tr>
            <th>Order</th><th>Date</th><th>Status</th>
            <th class="num">Items</th><th class="num">Total (Rs.)</th>
            <th>Invoice</th><th></th>
        </tr>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td>#<?= h($o['order_id']) ?></td>
                <td><?= h(date('d M Y, H:i', strtotime($o['order_date']))) ?></td>
                <td><span class="pill pill-<?= h($o['status']) ?>">
                        <?= h($o['status']) ?></span></td>
                <td class="num"><?= h($o['lines_count']) ?></td>
                <td class="num"><?= number_format((float)$o['order_total'], 2) ?></td>
                <td><?= $o['invoice_id']
                        ? '#' . h($o['invoice_id'])
                        : '<span class="muted">not issued</span>' ?></td>
                <td>
                    <a class="btn btn-small"
                       href="<?= action_link('order', (string)$o['order_id']) ?>">View</a>
                    <?php if ($o['status'] === 'PENDING'): ?>
                        <a class="btn btn-small"
                           href="<?= action_link('editorder', (string)$o['order_id']) ?>">Edit</a>
                        <a class="btn btn-small"
                           href="<?= action_link('cancel', (string)$o['order_id']) ?>">Cancel</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php page_footer(); ?>
