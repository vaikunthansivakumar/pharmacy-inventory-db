<?php
/* ============================================================
   payment.php  -  Record payments against invoices.
   R6: stock is deducted only once an invoice is paid in full.
   R8: the payer need not be the customer who placed the order,
   so this page lets a pharmacist pick any customer as the payer.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('pharmacist');

$PAYMENT_METHODS = ['CASH', 'CARD', 'ONLINE'];   // must match the PAYMENT.method ENUM
$PAYMENT_STATUSES = ['PENDING', 'PAID', 'FAILED', 'REFUNDED'];   // must match the PAYMENT.status ENUM

/* ============================================================
   RECORD A PAYMENT
   Everything below must happen together or not at all:
     1. the PAYMENT row is inserted, with whoever was picked as
        payer (may differ from the customer who placed the order)
     2. IF that payment finishes off the invoice:
          a. stock is deducted for every line of the order (R6)
          b. the order moves to READY
   A transaction guarantees "together or not at all", and locking
   the invoice row stops two pharmacists from both completing it.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {

    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $payerId   = (int)($_POST['payer_id'] ?? 0);
    $method    = $_POST['method'] ?? '';

    // Money is handled in integer cents from here on, so a rounding
    // error in the float amount can never sneak a payment past the
    // balance check.
    $amountCents = (int) round(((float)($_POST['amount'] ?? 0)) * 100);

    try {
        $pdo->beginTransaction();

        // Lock the invoice (and its order) so two pharmacists cannot
        // both record the payment that completes it.
        $st = $pdo->prepare(
            "SELECT i.invoice_id, i.order_id, i.total_amount, co.customer_id,
                    co.status AS order_status
               FROM INVOICE i
               JOIN CUSTOMER_ORDER co ON co.order_id = i.order_id
              WHERE i.invoice_id = ?
              FOR UPDATE"
        );
        $st->execute([$invoiceId]);
        $invoice = $st->fetch();

        if (!$invoice) {
            throw new RuntimeException('That invoice does not exist.');
        }

        // Only a CONFIRMED order can take payment: PENDING has no
        // invoice yet, READY/COLLECTED are already paid in full, and
        // CANCELLED is dead - paying it would resurrect a dead order.
        if ($invoice['order_status'] !== 'CONFIRMED') {
            throw new RuntimeException(
                'Order #' . $invoice['order_id'] . ' is '
                . $invoice['order_status'] . ', not CONFIRMED, so it cannot take payment.'
            );
        }

        if (!in_array($method, $PAYMENT_METHODS, true)) {
            throw new RuntimeException('Choose a valid payment method.');
        }

        // Never trust the posted payer id - it must be a real customer.
        $st = $pdo->prepare("SELECT user_id FROM CUSTOMER WHERE user_id = ?");
        $st->execute([$payerId]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Choose a valid payer.');
        }

        // fn_invoice_balance(invoice_id) = total_amount minus every PAID
        // payment against it - the same rule this page used to compute
        // inline, now the single source of truth (payment.php's two report
        // queries below use it too).
        $st = $pdo->prepare("SELECT fn_invoice_balance(?)");
        $st->execute([$invoiceId]);
        $balanceCents = (int) round(((float)$st->fetchColumn()) * 100);

        if ($amountCents <= 0) {
            throw new RuntimeException('Enter an amount greater than zero.');
        }
        if ($amountCents > $balanceCents) {
            throw new RuntimeException(
                'That amount is more than the outstanding balance of Rs. '
                . number_format($balanceCents / 100, 2) . '.'
            );
        }

        $amount = $amountCents / 100;

        $pdo->prepare(
            "INSERT INTO PAYMENT (invoice_id, payer_id, amount, payment_date, method, status)
             VALUES (?, ?, ?, NOW(), ?, 'PAID')"
        )->execute([$invoiceId, $payerId, $amount, $method]);

        $fullyPaid = ($amountCents >= $balanceCents);

        if ($fullyPaid) {
            // R6 in code: the invoice is settled, so NOW stock moves.
            // sp_settle_invoice_stock loops over every ORDER_ITEM line for
            // this order, deducting stock with the same guarded UPDATE this
            // block used to run in PHP, and moves the order to READY. On a
            // shortage it SIGNALs; PDO surfaces that as a PDOException whose
            // errorInfo[2] is the plain message the procedure set, which we
            // re-throw as a RuntimeException so the existing catch below
            // still rolls back the whole transaction, PAYMENT row included.
            try {
                $pdo->prepare("CALL sp_settle_invoice_stock(?)")
                    ->execute([$invoice['order_id']]);
            } catch (PDOException $e) {
                throw new RuntimeException($e->errorInfo[2] ?? $e->getMessage());
            }
        }

        $pdo->commit();

        set_flash('success',
            "Payment of Rs. " . number_format($amount, 2)
            . " recorded for invoice #$invoiceId."
            . ($fullyPaid
                ? ' Invoice fully paid - stock deducted and order marked READY.'
                : ' Balance remaining: Rs. '
                  . number_format(($balanceCents - $amountCents) / 100, 2) . '.'));

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: payment.php');
    exit;
}

/* ============================================================
   CORRECT A PAYMENT'S METHOD
   This is a correction, not a rewrite of history: no money moves,
   the invoice balance is unchanged, and the payment's place in
   that balance (its amount and status) is untouched - only the
   recorded instrument is corrected, e.g. the pharmacist typed
   CASH when the payer actually tapped a CARD. A genuine reversal
   of money already received would need a refund/void flow, which
   is deliberately out of scope here (see medicines.php's own
   "hard delete vs discontinue" split for the same kind of
   distinction between correcting a record and undoing history).
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_method') {

    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $newMethod = $_POST['method'] ?? '';

    try {
        $pdo->beginTransaction();

        // Lock the payment row first, same as every other value-changing
        // action on this page locks its row before reading and writing it.
        $st = $pdo->prepare("SELECT payment_id FROM PAYMENT WHERE payment_id = ? FOR UPDATE");
        $st->execute([$paymentId]);

        if (!$st->fetchColumn()) {
            throw new RuntimeException('That payment does not exist.');
        }

        if (!in_array($newMethod, $PAYMENT_METHODS, true)) {
            throw new RuntimeException('Choose a valid payment method.');
        }

        // Only method is named here. amount, status, payer_id, invoice_id
        // and payment_date are deliberately absent from this UPDATE -
        // naming them would let this "correction" quietly rewrite money
        // already recorded instead of just the instrument it arrived on.
        $pdo->prepare(
            "UPDATE PAYMENT SET method = ? WHERE payment_id = ?"
        )->execute([$newMethod, $paymentId]);

        $pdo->commit();
        set_flash('success', "Payment #$paymentId corrected to $newMethod.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: payment.php');
    exit;
}

/* ---------- confirmation card for the method correction above,
   same GET-then-POST pattern as the delete confirmation in
   medicines.php: a GET link shows the card, a real POST button
   inside it goes ahead, and a plain link backs out. No JavaScript. ---------- */
$confirmCorrect = null;
if (isset($_GET['correct'])) {
    $st = $pdo->prepare(
        "SELECT p.payment_id, p.invoice_id, p.amount, p.method, p.status,
                payer.name AS payer_name
           FROM PAYMENT     p
           JOIN SYSTEM_USER payer ON payer.user_id = p.payer_id
          WHERE p.payment_id = ?"
    );
    $st->execute([(int)$_GET['correct']]);
    $confirmCorrect = $st->fetch();
}

/* ============================================================
   FOCUS ON ONE INVOICE
   Carried by the Take payment link on pharmacist.php, so a
   pharmacist lands here on exactly the invoice they clicked
   instead of having to find it again in the full list. This is an
   id lookup, not a text search, so it is matched exactly
   (i.invoice_id = ?, bound as a parameter) rather than reusing the
   iq LIKE search below - LIKE '%14%' would also match invoice 140
   or any customer whose name happens to contain "14". Cast with
   (int); 0 or a missing value means "no filter", the same way
   ?cancel= and ?correct= are treated elsewhere on this site. If
   both invoice and iq are present, invoice wins - it came from a
   deliberate click, not a typed search.
   ============================================================ */
$focusInvoice = (int)($_GET['invoice'] ?? 0);

$focusInfo = null;
if ($focusInvoice > 0) {
    // fn_invoice_balance folds the PAYMENT join and GROUP BY this query
    // used to need into one call.
    $st = $pdo->prepare(
        "SELECT   i.invoice_id, i.order_id, i.total_amount, co.status AS order_status,
                  i.total_amount - fn_invoice_balance(i.invoice_id) AS amount_paid
           FROM INVOICE        i
           JOIN CUSTOMER_ORDER co ON co.order_id = i.order_id
          WHERE i.invoice_id = ?"
    );
    $st->execute([$focusInvoice]);
    $focusInfo = $st->fetch();
}

/* ============================================================
   SEARCH - invoices with a balance due
   Text box matches either the invoice number or the ordering
   customer's name. The user value is bound as a parameter (never
   concatenated into the SQL text), with the % wildcards attached
   to the value rather than the query - that is what stops SQL
   injection.
   ============================================================ */
$outSearch = trim($_GET['iq'] ?? '');

$outWhere  = ["co.status = 'CONFIRMED'"];   // unchanged: only CONFIRMED orders
$outParams = [];

if ($focusInvoice > 0) {
    $outWhere[]  = "i.invoice_id = ?";
    $outParams[] = $focusInvoice;
} elseif ($outSearch !== '') {
    $outWhere[]  = "(i.invoice_id LIKE ? OR su.name LIKE ?)";
    $outParams[] = '%' . $outSearch . '%';
    $outParams[] = '%' . $outSearch . '%';
}

/* ---------- invoices with a balance still owing -------------- */
$outstandingStmt = $pdo->prepare(
    "SELECT   i.invoice_id, i.order_id, i.total_amount,
              co.customer_id, su.name AS customer_name,
              i.total_amount - fn_invoice_balance(i.invoice_id) AS amount_paid,
              fn_invoice_balance(i.invoice_id) AS balance_due
       FROM INVOICE        i
       JOIN CUSTOMER_ORDER co ON co.order_id = i.order_id
       JOIN SYSTEM_USER    su ON su.user_id  = co.customer_id
      WHERE " . implode(' AND ', $outWhere) . "
        AND fn_invoice_balance(i.invoice_id) > 0
      ORDER BY i.invoice_id"
);
$outstandingStmt->execute($outParams);
$outstanding = $outstandingStmt->fetchAll();

/* the lines of every outstanding invoice's order, grouped by order_id -
   same pattern as $pendingItems in pharmacist.php. Shown on the invoice
   card so it's clear exactly what's being paid for, without switching
   to the customer's own "My orders" view. */
$outstandingItems = [];
if ($outstanding) {
    $orderIds = array_column($outstanding, 'order_id');
    $marks = implode(',', array_fill(0, count($orderIds), '?'));
    $st = $pdo->prepare(
        "SELECT oi.order_id, m.name, oi.quantity, oi.unit_price
           FROM ORDER_ITEM oi
           JOIN MEDICINE   m ON m.medicine_id = oi.medicine_id
          WHERE oi.order_id IN ($marks)
          ORDER BY m.name"
    );
    $st->execute($orderIds);
    foreach ($st->fetchAll() as $row) {
        $outstandingItems[$row['order_id']][] = $row;
    }
}

/* every ACTIVE customer, for the payer dropdown - R8 lets it be anyone,
   but a deactivated account must not be usable going forward. A
   deactivated customer still appears further down wherever their own
   past orders/invoices/payments are shown - only this "who is paying
   right now" picker excludes them. */
$customers = $pdo->query(
    "SELECT su.user_id, su.name
       FROM SYSTEM_USER su
       JOIN CUSTOMER     c ON c.user_id = su.user_id
      WHERE su.is_active = 1
      ORDER BY su.name"
)->fetchAll();

/* ============================================================
   SEARCH - recent payments
   Text box matches either the payer's name or the ordering
   customer's name; dropdown filters by method. When a search is
   active the row limit is raised from 10 to 25 so a search is not
   silently truncated by the LIMIT - $limit itself is never user
   input, only ever one of the two fixed numbers below, so it is
   safe to place directly in the SQL text.
   ============================================================ */
$paySearch = trim($_GET['rq'] ?? '');
$payMethod = $_GET['rmethod'] ?? '';
$payStatus = $_GET['rstatus'] ?? '';

$payWhere  = [];
$payParams = [];

if ($paySearch !== '') {
    $payWhere[]  = "(payer.name LIKE ? OR orderer.name LIKE ?)";
    $payParams[] = '%' . $paySearch . '%';
    $payParams[] = '%' . $paySearch . '%';
}
if (in_array($payMethod, $PAYMENT_METHODS, true)) {
    $payWhere[]  = "p.method = ?";
    $payParams[] = $payMethod;
}
if (in_array($payStatus, $PAYMENT_STATUSES, true)) {
    $payWhere[]  = "p.status = ?";
    $payParams[] = $payStatus;
}

$paySearchActive = ($paySearch !== '' || $payMethod !== '' || $payStatus !== '');
$recentLimit      = $paySearchActive ? 25 : 10;

/* ---------- recent payments, payer next to orderer ------------ */
$recentStmt = $pdo->prepare(
    "SELECT   p.payment_id, p.invoice_id, p.amount, p.payment_date,
              p.method, p.status,
              p.payer_id, co.customer_id,
              payer.name   AS payer_name,
              orderer.name AS orderer_name
       FROM PAYMENT        p
       JOIN INVOICE        i       ON i.invoice_id    = p.invoice_id
       JOIN CUSTOMER_ORDER co      ON co.order_id     = i.order_id
       JOIN SYSTEM_USER    payer   ON payer.user_id   = p.payer_id
       JOIN SYSTEM_USER    orderer ON orderer.user_id = co.customer_id"
       . ($payWhere ? " WHERE " . implode(' AND ', $payWhere) : "") . "
      ORDER BY p.payment_date DESC, p.payment_id DESC
      LIMIT $recentLimit"
);
$recentStmt->execute($payParams);
$recent = $recentStmt->fetchAll();

page_header('Record payments');
show_flash();
?>

<h2>Invoices with a balance due</h2>
<form class="searchbar" method="get" action="payment.php">
    <input type="text" name="iq" placeholder="Search invoice number or customer name"
           value="<?= h($outSearch) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="payment.php">Clear</a>
</form>
<p class="result-count">
    <?php if ($focusInvoice > 0): ?>
        Narrowed to invoice #<?= h($focusInvoice) ?>
        &middot; <a class="clear" href="payment.php">Show every outstanding invoice</a>
    <?php else: ?>
        <?= count($outstanding) ?> invoice<?= count($outstanding) === 1 ? '' : 's' ?>
        <?php if ($outSearch !== ''): ?>matching '<?= h($outSearch) ?>'<?php endif; ?>
    <?php endif; ?>
</p>
<?php if ($focusInvoice > 0 && !$outstanding): ?>
    <?php if (!$focusInfo): ?>
        <div class="card muted">Invoice #<?= h($focusInvoice) ?> does not exist.</div>
    <?php elseif ($focusInfo['order_status'] !== 'CONFIRMED'): ?>
        <div class="card muted">
            Invoice #<?= h($focusInvoice) ?>'s order is <?= h($focusInfo['order_status']) ?>,
            not CONFIRMED, so it cannot take payment.
        </div>
    <?php else: ?>
        <div class="card good">
            Invoice #<?= h($focusInvoice) ?> has nothing due - it is already fully paid.
        </div>
    <?php endif; ?>
<?php elseif (!$outstanding): ?>
    <div class="card <?= $outSearch !== '' ? 'muted' : 'good' ?>">
        <?= $outSearch !== '' ? 'No invoices match your search.' : 'Every invoice is fully paid.' ?>
    </div>
<?php else: ?>
    <?php foreach ($outstanding as $inv): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Invoice #<?= h($inv['invoice_id']) ?></strong>
                &middot; Order #<?= h($inv['order_id']) ?>
                &middot; ordered by <?= h($inv['customer_name']) ?>
            </p>

            <?php if (!empty($outstandingItems[$inv['order_id']])): ?>
                <table style="margin-bottom:10px">
                    <tr>
                        <th>Medicine</th><th class="num">Qty</th>
                        <th class="num">Unit price</th><th class="num">Line total</th>
                    </tr>
                    <?php foreach ($outstandingItems[$inv['order_id']] as $it): ?>
                        <tr>
                            <td><?= h($it['name']) ?></td>
                            <td class="num"><?= h($it['quantity']) ?></td>
                            <td class="num"><?= number_format($it['unit_price'], 2) ?></td>
                            <td class="num">
                                <?= number_format($it['quantity'] * $it['unit_price'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <table>
                <tr>
                    <th class="num">Invoice total</th>
                    <th class="num">Paid so far</th>
                    <th class="num">Balance due</th>
                </tr>
                <tr>
                    <td class="num"><?= number_format($inv['total_amount'], 2) ?></td>
                    <td class="num"><?= number_format($inv['amount_paid'], 2) ?></td>
                    <td class="num low"><?= number_format($inv['balance_due'], 2) ?></td>
                </tr>
            </table>

            <form method="post" action="payment.php" style="margin-top:12px">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="invoice_id" value="<?= h($inv['invoice_id']) ?>">

                <label for="amount-<?= h($inv['invoice_id']) ?>">Amount (Rs.)</label>
                <input type="number" step="0.01" min="0.01"
                       max="<?= h(number_format($inv['balance_due'], 2, '.', '')) ?>"
                       id="amount-<?= h($inv['invoice_id']) ?>" name="amount"
                       value="<?= h(number_format($inv['balance_due'], 2, '.', '')) ?>">
                <p class="muted small" style="margin:2px 0 0">Reduce this to record a part payment.</p>

                <label for="method-<?= h($inv['invoice_id']) ?>">Method</label>
                <select id="method-<?= h($inv['invoice_id']) ?>" name="method">
                    <?php foreach ($PAYMENT_METHODS as $m): ?>
                        <option value="<?= h($m) ?>"><?= h($m) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="payer-<?= h($inv['invoice_id']) ?>">Paid by</label>
                <select id="payer-<?= h($inv['invoice_id']) ?>" name="payer_id">
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= h($c['user_id']) ?>"
                            <?= $c['user_id'] == $inv['customer_id'] ? 'selected' : '' ?>>
                            <?= h($c['name']) ?><?= $c['user_id'] == $inv['customer_id']
                                ? ' (ordered this)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <p style="margin-top:12px">
                    <button type="submit" class="btn btn-green">Record payment</button>
                </p>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($confirmCorrect): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Correct payment #<?= h($confirmCorrect['payment_id']) ?>?</strong>
            &middot; Invoice #<?= h($confirmCorrect['invoice_id']) ?>
            &middot; Rs. <?= number_format($confirmCorrect['amount'], 2) ?>
            &middot; paid by <?= h($confirmCorrect['payer_name']) ?>
        </p>
        <p class="muted" style="margin-bottom:12px">
            This only corrects which instrument was recorded - currently
            <?= h($confirmCorrect['method']) ?>. The amount, status, payer
            and invoice are unaffected, and no balance changes.
        </p>
        <form method="post" action="payment.php">
            <input type="hidden" name="action" value="update_method">
            <input type="hidden" name="payment_id" value="<?= h($confirmCorrect['payment_id']) ?>">
            <label for="correct-method">Correct method</label>
            <select id="correct-method" name="method">
                <?php foreach ($PAYMENT_METHODS as $m): ?>
                    <option value="<?= h($m) ?>" <?= $confirmCorrect['method'] === $m ? 'selected' : '' ?>>
                        <?= h($m) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p style="margin-top:12px">
                <button type="submit" class="btn btn-green">Save correction</button>
                <a class="btn btn-light" href="<?= action_link('correct', '') ?>">Cancel</a>
            </p>
        </form>
    </div>
<?php elseif (isset($_GET['correct'])): ?>
    <div class="card muted">That payment does not exist.</div>
<?php endif; ?>

<h2>Recent payments</h2>
<form class="searchbar" method="get" action="payment.php">
    <input type="text" name="rq" placeholder="Search payer or customer name"
           value="<?= h($paySearch) ?>">
    <input type="hidden" name="rmethod" value="<?= h($payMethod) ?>">
    <input type="hidden" name="rstatus" value="<?= h($payStatus) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="payment.php">Clear</a>
</form>
<div class="filter-links">
    <span class="filter-label">Method:</span>
    <?php if ($payMethod === ''): ?>
        <span class="current">All methods</span>
    <?php else: ?>
        <a href="<?= filter_link('rmethod', '') ?>">All methods</a>
    <?php endif; ?>
    <?php foreach ($PAYMENT_METHODS as $m): ?>
        <?php if ($payMethod === $m): ?>
            <span class="current"><?= h(ucfirst(strtolower($m))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('rmethod', $m) ?>"><?= h(ucfirst(strtolower($m))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<div class="filter-links">
    <span class="filter-label">Status:</span>
    <?php if ($payStatus === ''): ?>
        <span class="current">All statuses</span>
    <?php else: ?>
        <a href="<?= filter_link('rstatus', '') ?>">All statuses</a>
    <?php endif; ?>
    <?php foreach ($PAYMENT_STATUSES as $s): ?>
        <?php if ($payStatus === $s): ?>
            <span class="current"><?= h(ucfirst(strtolower($s))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('rstatus', $s) ?>"><?= h(ucfirst(strtolower($s))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($recent) ?> payment<?= count($recent) === 1 ? '' : 's' ?>
    <?php if ($paySearch !== ''): ?>matching '<?= h($paySearch) ?>'<?php endif; ?>
</p>
<?php if (!$recent): ?>
    <div class="card muted">
        <?= $paySearchActive ? 'No payments match your search.' : 'No payments recorded yet.' ?>
    </div>
<?php else: ?>
    <table>
        <tr>
            <th>Payment</th><th>Invoice</th><th class="num">Amount</th>
            <th>Method</th><th>Status</th><th>Paid by</th><th>Ordered by</th><th></th>
        </tr>
        <?php foreach ($recent as $p): ?>
            <tr>
                <td>#<?= h($p['payment_id']) ?></td>
                <td>#<?= h($p['invoice_id']) ?></td>
                <td class="num"><?= number_format($p['amount'], 2) ?></td>
                <td><?= h($p['method']) ?></td>
                <td><?= h($p['status']) ?></td>
                <td><?= h($p['payer_name']) ?></td>
                <td>
                    <?= h($p['orderer_name']) ?>
                    <?php if ($p['payer_id'] != $p['customer_id']): ?>
                        <span class="muted">(different payer)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn btn-small"
                       href="<?= action_link('correct', (string)$p['payment_id']) ?>">Correct method</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php page_footer(); ?>
