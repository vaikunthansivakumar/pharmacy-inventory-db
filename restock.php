<?php
/* ============================================================
   restock.php  -  Raise restock orders on suppliers, receive
   them into stock, and manage them while they are still open.

   RESTOCK_ORDER/RESTOCK_ITEM is the supply-side twin of
   CUSTOMER_ORDER/ORDER_ITEM: both are many-to-many relationships
   between a parent document and MEDICINE, resolved the same way -
   a junction table with a composite (parent_id, medicine_id)
   primary key that also carries its own attributes (quantity plus
   a price/cost). The "raise an order" form below is built exactly
   like customer.php's ordering form for that reason: a dropdown
   for the other party, a table of medicines with a quantity box
   per row, keep only the rows with a positive quantity. The
   difference is direction and timing: a customer order takes
   stock OUT once its invoice is paid; a restock order brings stock
   IN once it is marked RECEIVED. Creating either document never
   moves stock by itself - only the completing event does.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('admin');

$VALID_RESTOCK_STATUSES = ['PLACED', 'RECEIVED', 'CANCELLED'];

/* form state for the "raise a new restock order" section, filled
   in below either from a failed submission or from the query
   string (the low-stock alert link from admin.php). */
$createErrors   = [];
$formSupplierId = 0;
$formQty        = [];
$formCost       = [];

/* ============================================================
   RAISE A NEW RESTOCK ORDER
   One transaction: the RESTOCK_ORDER row, then one RESTOCK_ITEM
   row per line - either the whole order and all its lines are
   saved, or none of it, exactly like placing a customer order.
   Stock is NOT touched here - ordering from a supplier is not the
   same as receiving the goods (see receive_restock below).
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_restock') {

    $supplierId = (int)($_POST['supplier_id'] ?? 0);

    // Never trust the posted supplier id - it must be a real, active
    // supplier (an inactive one must not be usable for a new order).
    $supStmt = $pdo->prepare("SELECT name FROM SUPPLIER WHERE supplier_id = ? AND is_active = 1");
    $supStmt->execute([$supplierId]);
    $supplierName = $supStmt->fetchColumn();

    $qtyPosted  = $_POST['qty']  ?? [];
    $costPosted = $_POST['cost'] ?? [];

    // Keep only the rows where a positive quantity was entered, same
    // as customer.php's order form.
    $wanted      = [];
    $invalidCost = false;
    foreach ($qtyPosted as $medicineId => $qty) {
        $qty = (int)$qty;
        if ($qty <= 0) {
            continue;
        }
        $costRaw = trim($costPosted[$medicineId] ?? '');
        if (!is_numeric($costRaw) || (float)$costRaw < 0) {
            $invalidCost = true;
            continue;
        }
        $wanted[(int)$medicineId] = ['qty' => $qty, 'cost' => (float)$costRaw];
    }

    $errors = [];
    if (!$supplierName) {
        $errors[] = 'Choose a valid, active supplier.';
    }
    if ($invalidCost) {
        $errors[] = 'Unit cost must be a number that is not negative for every line with a quantity.';
    }
    if (!$wanted && !$invalidCost) {
        $errors[] = 'Enter a quantity for at least one medicine.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO RESTOCK_ORDER (admin_id, supplier_id, restock_date, status)
                 VALUES (?, ?, CURDATE(), 'PLACED')"
            )->execute([current_user_id(), $supplierId]);

            $restockId = (int)$pdo->lastInsertId();

            // Never trust a posted medicine id either - re-check each one
            // against the database rather than trusting the hidden form.
            $medStmt = $pdo->prepare(
                "SELECT name FROM MEDICINE WHERE medicine_id = ? AND is_active = 1"
            );
            $lineStmt = $pdo->prepare(
                "INSERT INTO RESTOCK_ITEM (restock_id, medicine_id, quantity, unit_cost)
                 VALUES (?, ?, ?, ?)"
            );

            foreach ($wanted as $medicineId => $line) {
                $medStmt->execute([$medicineId]);
                if (!$medStmt->fetchColumn()) {
                    throw new RuntimeException('One of the selected medicines no longer exists or is discontinued.');
                }
                $lineStmt->execute([$restockId, $medicineId, $line['qty'], $line['cost']]);
            }

            $pdo->commit();
            set_flash('success',
                "Restock order #$restockId placed with " . count($wanted)
                . " line(s). Stock will only rise once it is marked received.");
            header('Location: restock.php');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() ?: 'Could not save the restock order. Please try again.';
        }
    }

    // Validation (or the transaction) failed - fall through to
    // re-render the form with what was typed, instead of losing it.
    $createErrors   = $errors;
    $formSupplierId = $supplierId;
    $formQty        = $qtyPosted;
    $formCost       = $costPosted;
}

/* ============================================================
   MARK RECEIVED
   One transaction, locking the restock order row with FOR UPDATE
   so it cannot be received twice at once, then for every line ADD
   the quantity to MEDICINE.quantity_in_stock, then set the status
   to RECEIVED.

   Stock going UP cannot fail the way stock going DOWN can - there
   is no lower bound it could cross - so unlike payment.php's
   deduction (UPDATE ... WHERE quantity_in_stock >= ?), this UPDATE
   needs no guard clause. That asymmetry between receiving and
   selling is deliberate, not an oversight.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_restock') {

    $id = (int)($_POST['restock_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT status FROM RESTOCK_ORDER WHERE restock_id = ? FOR UPDATE");
        $st->execute([$id]);
        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('That restock order does not exist.');
        }
        if ($status !== 'PLACED') {
            throw new RuntimeException("Restock order #$id is already $status.");
        }

        $st = $pdo->prepare("SELECT medicine_id, quantity FROM RESTOCK_ITEM WHERE restock_id = ?");
        $st->execute([$id]);
        $lines = $st->fetchAll();

        if (!$lines) {
            throw new RuntimeException('That restock order has no items.');
        }

        $add = $pdo->prepare(
            "UPDATE MEDICINE SET quantity_in_stock = quantity_in_stock + ? WHERE medicine_id = ?"
        );
        foreach ($lines as $line) {
            $add->execute([$line['quantity'], $line['medicine_id']]);
        }

        $pdo->prepare("UPDATE RESTOCK_ORDER SET status = 'RECEIVED' WHERE restock_id = ?")->execute([$id]);

        $pdo->commit();

        // Report how many of the restocked medicines are now back above
        // their reorder threshold, now that the update above has committed.
        $ids   = array_column($lines, 'medicine_id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $aboveStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM MEDICINE
              WHERE medicine_id IN ($marks) AND quantity_in_stock >= reorder_threshold"
        );
        $aboveStmt->execute($ids);
        $aboveCount = (int)$aboveStmt->fetchColumn();

        set_flash('success',
            "Restock order #$id received. " . count($lines) . " medicine(s) restocked; "
            . "$aboveCount of them are now back above their reorder threshold.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: restock.php');
    exit;
}

/* ============================================================
   EDIT LINES OF A PLACED ORDER
   update_restock_item / delete_restock_item / add_restock_item
   are the update and delete for RESTOCK_ITEM. All three re-read
   and lock the parent RESTOCK_ORDER first: only while it is still
   PLACED can its lines change. Once RECEIVED, its stock effect has
   already happened and reversing that needs a stock adjustment
   process, which is out of scope, so editing is refused outright.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_restock_item') {

    $restockId  = (int)($_POST['restock_id'] ?? 0);
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    $qty        = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $costRaw    = trim($_POST['unit_cost'] ?? '');

    $errs = [];
    if ($qty === false) {
        $errs[] = 'Quantity must be a whole number greater than zero.';
    }
    if (!is_numeric($costRaw) || (float)$costRaw < 0) {
        $errs[] = 'Unit cost must be a number that is not negative.';
    }

    if (!$errs) {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("SELECT status FROM RESTOCK_ORDER WHERE restock_id = ? FOR UPDATE");
            $st->execute([$restockId]);
            $status = $st->fetchColumn();

            if ($status === false) {
                throw new RuntimeException('That restock order does not exist.');
            }
            if ($status !== 'PLACED') {
                throw new RuntimeException("Restock order #$restockId is $status and can no longer be edited.");
            }

            $upd = $pdo->prepare(
                "UPDATE RESTOCK_ITEM SET quantity = ?, unit_cost = ?
                  WHERE restock_id = ? AND medicine_id = ?"
            );
            $upd->execute([$qty, (float)$costRaw, $restockId, $medicineId]);

            if ($upd->rowCount() === 0) {
                throw new RuntimeException('That line no longer exists on this order.');
            }

            $pdo->commit();
            set_flash('success', "Line updated on restock order #$restockId.");

        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('error', $e->getMessage());
        }
    } else {
        set_flash('error', implode(' ', $errs));
    }

    header("Location: restock.php?view=$restockId");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_restock_item') {

    $restockId  = (int)($_POST['restock_id'] ?? 0);
    $medicineId = (int)($_POST['medicine_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT status FROM RESTOCK_ORDER WHERE restock_id = ? FOR UPDATE");
        $st->execute([$restockId]);
        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('That restock order does not exist.');
        }
        if ($status !== 'PLACED') {
            throw new RuntimeException("Restock order #$restockId is $status and can no longer be edited.");
        }

        $del = $pdo->prepare("DELETE FROM RESTOCK_ITEM WHERE restock_id = ? AND medicine_id = ?");
        $del->execute([$restockId, $medicineId]);

        if ($del->rowCount() === 0) {
            throw new RuntimeException('That line no longer exists on this order.');
        }

        $pdo->commit();
        set_flash('success', "Line removed from restock order #$restockId.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header("Location: restock.php?view=$restockId");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_restock_item') {

    $restockId  = (int)($_POST['restock_id'] ?? 0);
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    $qty        = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $costRaw    = trim($_POST['unit_cost'] ?? '');

    $errs = [];
    if ($qty === false) {
        $errs[] = 'Quantity must be a whole number greater than zero.';
    }
    if (!is_numeric($costRaw) || (float)$costRaw < 0) {
        $errs[] = 'Unit cost must be a number that is not negative.';
    }

    if (!$errs) {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("SELECT status FROM RESTOCK_ORDER WHERE restock_id = ? FOR UPDATE");
            $st->execute([$restockId]);
            $status = $st->fetchColumn();

            if ($status === false) {
                throw new RuntimeException('That restock order does not exist.');
            }
            if ($status !== 'PLACED') {
                throw new RuntimeException("Restock order #$restockId is $status and can no longer be edited.");
            }

            // Never trust the posted medicine id - it must be real and active.
            $medStmt = $pdo->prepare("SELECT name FROM MEDICINE WHERE medicine_id = ? AND is_active = 1");
            $medStmt->execute([$medicineId]);
            $medName = $medStmt->fetchColumn();
            if (!$medName) {
                throw new RuntimeException('Choose a valid, active medicine.');
            }

            $pdo->prepare(
                "INSERT INTO RESTOCK_ITEM (restock_id, medicine_id, quantity, unit_cost)
                 VALUES (?, ?, ?, ?)"
            )->execute([$restockId, $medicineId, $qty, (float)$costRaw]);

            $pdo->commit();
            set_flash('success', "$medName added to restock order #$restockId.");

        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((int)$e->errorInfo[1] === 1062) {
                // Composite primary key (restock_id, medicine_id) already
                // has a row - the medicine is already a line on this order.
                set_flash('error', 'That medicine is already on this order - edit its existing line instead.');
            } else {
                set_flash('error', 'Could not add that line. Please try again.');
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('error', $e->getMessage());
        }
    } else {
        set_flash('error', implode(' ', $errs));
    }

    header("Location: restock.php?view=$restockId");
    exit;
}

/* ============================================================
   DELETE A PLACED ORDER
   Guarded single-statement DELETE, same idea as the guarded
   UPDATEs elsewhere: it only succeeds if the order is still
   PLACED. RESTOCK_ITEM is ON DELETE CASCADE, so its lines go with
   it - no separate DELETE needed for them. A RECEIVED order must
   never be deletable: its stock has already been added, and
   quietly deleting the record would leave that stock unexplained.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_restock_order') {

    $id = (int)($_POST['restock_id'] ?? 0);

    $st = $pdo->prepare("DELETE FROM RESTOCK_ORDER WHERE restock_id = ? AND status = 'PLACED'");
    $st->execute([$id]);

    if ($st->rowCount() > 0) {
        set_flash('success', "Restock order #$id deleted.");
    } else {
        $chk = $pdo->prepare("SELECT status FROM RESTOCK_ORDER WHERE restock_id = ?");
        $chk->execute([$id]);
        $status = $chk->fetchColumn();
        set_flash('error',
            $status === false
                ? 'That restock order no longer exists.'
                : "Restock order #$id is $status and can no longer be deleted.");
    }

    header('Location: restock.php');
    exit;
}

/* ---------- cancel a PLACED order --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_restock') {

    $id = (int)($_POST['restock_id'] ?? 0);

    $st = $pdo->prepare("UPDATE RESTOCK_ORDER SET status = 'CANCELLED' WHERE restock_id = ? AND status = 'PLACED'");
    $st->execute([$id]);

    if ($st->rowCount() > 0) {
        set_flash('success', "Restock order #$id cancelled. No stock was ever added for it.");
    } else {
        $chk = $pdo->prepare("SELECT status FROM RESTOCK_ORDER WHERE restock_id = ?");
        $chk->execute([$id]);
        $status = $chk->fetchColumn();
        set_flash('error',
            $status === false
                ? 'That restock order no longer exists.'
                : "Restock order #$id is $status and can no longer be cancelled.");
    }

    header('Location: restock.php');
    exit;
}

/* ============================================================
   CONFIRMATION STEPS - delete and cancel
   Same GET-confirm then POST-act pattern as everywhere else: a
   GET link shows a confirmation card with a real POST button to
   go ahead, and a plain link to back out. No JavaScript.
   ============================================================ */
$confirmDeleteRestock = null;
if (isset($_GET['delete'])) {
    $st = $pdo->prepare(
        "SELECT ro.restock_id, ro.status, sup.name AS supplier_name
           FROM RESTOCK_ORDER ro JOIN SUPPLIER sup ON sup.supplier_id = ro.supplier_id
          WHERE ro.restock_id = ?"
    );
    $st->execute([(int)$_GET['delete']]);
    $confirmDeleteRestock = $st->fetch();
}

$confirmCancelRestock = null;
if (isset($_GET['cancel'])) {
    $st = $pdo->prepare(
        "SELECT ro.restock_id, ro.status, sup.name AS supplier_name
           FROM RESTOCK_ORDER ro JOIN SUPPLIER sup ON sup.supplier_id = ro.supplier_id
          WHERE ro.restock_id = ?"
    );
    $st->execute([(int)$_GET['cancel']]);
    $confirmCancelRestock = $st->fetch();
}

/* ============================================================
   DATA FOR "RAISE A NEW RESTOCK ORDER"
   ============================================================ */
$activeSuppliers = $pdo->query(
    "SELECT supplier_id, name FROM SUPPLIER WHERE is_active = 1 ORDER BY name"
)->fetchAll();

// Most recent unit_cost per medicine, so the admin is not retyping known
// prices - restock_id only ever increases, so MAX(restock_id) per
// medicine is its most recent RESTOCK_ITEM row, just as reliable here as
// sorting by restock_date and simpler to write.
$lastCosts = $pdo->query(
    "SELECT ri.medicine_id, ri.unit_cost
       FROM RESTOCK_ITEM ri
       JOIN (SELECT medicine_id, MAX(restock_id) AS max_id
               FROM RESTOCK_ITEM
              GROUP BY medicine_id) latest
         ON latest.medicine_id = ri.medicine_id AND latest.max_id = ri.restock_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);

/* Pre-select a medicine and suggested quantity, arriving from a
   low-stock alert link on admin.php. */
$preselectMedicineId = (int)($_GET['medicine_id'] ?? 0);
$preselectQty         = (int)($_GET['qty'] ?? 0);

// If nothing has already been typed (no failed submission) and the
// alert named a medicine, also preselect the supplier that most
// recently supplied it - only if that supplier is still active,
// since the dropdown below only ever lists active suppliers.
if (!$formSupplierId && $preselectMedicineId) {
    $st = $pdo->prepare(
        "SELECT sup.supplier_id
           FROM RESTOCK_ITEM ri
           JOIN RESTOCK_ORDER ro ON ro.restock_id = ri.restock_id
           JOIN SUPPLIER      sup ON sup.supplier_id = ro.supplier_id
          WHERE ri.medicine_id = ? AND sup.is_active = 1
          ORDER BY ri.restock_id DESC
          LIMIT 1"
    );
    $st->execute([$preselectMedicineId]);
    $formSupplierId = (int)($st->fetchColumn() ?: 0);
}

/* medicine search box above the medicine table */
$mq = trim($_GET['mq'] ?? '');
$medWhere  = ['m.is_active = 1'];
$medParams = [];
if ($mq !== '') {
    $medWhere[]  = "(m.name LIKE ? OR m.category LIKE ?)";
    $medParams[] = '%' . $mq . '%';
    $medParams[] = '%' . $mq . '%';
}
$medStmt = $pdo->prepare(
    "SELECT medicine_id, name, category, quantity_in_stock, reorder_threshold
       FROM MEDICINE m
      WHERE " . implode(' AND ', $medWhere) . "
      ORDER BY name"
);
$medStmt->execute($medParams);
$medicines = $medStmt->fetchAll();

/* ============================================================
   SEARCH - existing restock orders
   By restock id, supplier name, or a medicine the order contains;
   plus a status dropdown. "Contains a medicine" is expressed with
   EXISTS rather than a JOIN+DISTINCT: the main query already GROUPs
   by restock order for the lines_count/total_cost aggregates below,
   and joining RESTOCK_ITEM/MEDICINE again just to search would
   multiply rows before that aggregation - EXISTS reads more clearly
   here since it needs none of that.
   ============================================================ */
$sq      = trim($_GET['sq'] ?? '');
$sstatus = $_GET['sstatus'] ?? '';

$roWhere  = [];
$roParams = [];

if ($sq !== '') {
    $roWhere[]  = "(ro.restock_id LIKE ? OR sup.name LIKE ? OR EXISTS (
                      SELECT 1 FROM RESTOCK_ITEM ri2
                      JOIN MEDICINE m2 ON m2.medicine_id = ri2.medicine_id
                     WHERE ri2.restock_id = ro.restock_id AND m2.name LIKE ?
                   ))";
    $roParams[] = '%' . $sq . '%';
    $roParams[] = '%' . $sq . '%';
    $roParams[] = '%' . $sq . '%';
}
if (in_array($sstatus, $VALID_RESTOCK_STATUSES, true)) {
    $roWhere[]  = "ro.status = ?";
    $roParams[] = $sstatus;
}

$roStmt = $pdo->prepare(
    "SELECT   ro.restock_id, ro.restock_date, ro.status, sup.name AS supplier_name,
              COUNT(ri.medicine_id)                         AS lines_count,
              COALESCE(SUM(ri.quantity * ri.unit_cost), 0)  AS total_cost
       FROM      RESTOCK_ORDER ro
       JOIN      SUPPLIER      sup ON sup.supplier_id = ro.supplier_id
       LEFT JOIN RESTOCK_ITEM  ri  ON ri.restock_id  = ro.restock_id
      " . ($roWhere ? "WHERE " . implode(' AND ', $roWhere) : "") . "
      GROUP BY ro.restock_id, ro.restock_date, ro.status, sup.name
      ORDER BY ro.restock_id DESC"
);
$roStmt->execute($roParams);
$restockOrders = $roStmt->fetchAll();

/* ---------- detail of one order, if "View" was clicked ------- */
$viewId    = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewOrder = null;
$viewLines = [];
$addableMedicines = [];

if ($viewId) {
    $st = $pdo->prepare(
        "SELECT ro.restock_id, ro.restock_date, ro.status, sup.name AS supplier_name
           FROM RESTOCK_ORDER ro JOIN SUPPLIER sup ON sup.supplier_id = ro.supplier_id
          WHERE ro.restock_id = ?"
    );
    $st->execute([$viewId]);
    $viewOrder = $st->fetch();

    if ($viewOrder) {
        $st = $pdo->prepare(
            "SELECT ri.medicine_id, m.name, ri.quantity, ri.unit_cost,
                    ri.quantity * ri.unit_cost AS line_total
               FROM RESTOCK_ITEM ri JOIN MEDICINE m ON m.medicine_id = ri.medicine_id
              WHERE ri.restock_id = ?
              ORDER BY m.name"
        );
        $st->execute([$viewId]);
        $viewLines = $st->fetchAll();

        if ($viewOrder['status'] === 'PLACED') {
            $existingIds = array_column($viewLines, 'medicine_id');
            foreach ($pdo->query("SELECT medicine_id, name FROM MEDICINE WHERE is_active = 1 ORDER BY name")->fetchAll() as $m) {
                if (!in_array($m['medicine_id'], $existingIds, true)) {
                    $addableMedicines[] = $m;
                }
            }
        }
    }
}

page_header('Restock orders');
show_flash();
?>

<?php if ($confirmDeleteRestock): ?>
    <?php if ($confirmDeleteRestock['status'] === 'PLACED'): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Delete restock order #<?= h($confirmDeleteRestock['restock_id']) ?>?</strong>
                &middot; <?= h($confirmDeleteRestock['supplier_name']) ?>
            </p>
            <p class="muted" style="margin-bottom:12px">
                All of its lines are deleted with it. This cannot be undone.
            </p>
            <form method="post" action="restock.php" style="display:inline">
                <input type="hidden" name="action" value="delete_restock_order">
                <input type="hidden" name="restock_id" value="<?= h($confirmDeleteRestock['restock_id']) ?>">
                <button type="submit" class="btn btn-green">Yes, delete it</button>
            </form>
            <a class="btn btn-light" href="<?= action_link('delete', '') ?>">No, keep it</a>
        </div>
    <?php else: ?>
        <div class="card muted">
            Restock order #<?= h($confirmDeleteRestock['restock_id']) ?> is
            <?= h($confirmDeleteRestock['status']) ?> and can no longer be deleted.
        </div>
    <?php endif; ?>
<?php elseif (isset($_GET['delete'])): ?>
    <div class="card muted">That restock order no longer exists.</div>
<?php endif; ?>

<?php if ($confirmCancelRestock): ?>
    <?php if ($confirmCancelRestock['status'] === 'PLACED'): ?>
        <div class="card">
            <p style="margin:0 0 10px">
                <strong>Cancel restock order #<?= h($confirmCancelRestock['restock_id']) ?>?</strong>
                &middot; <?= h($confirmCancelRestock['supplier_name']) ?>
            </p>
            <p class="muted" style="margin-bottom:12px">
                The record is kept but no stock is added for it. This cannot be undone.
            </p>
            <form method="post" action="restock.php" style="display:inline">
                <input type="hidden" name="action" value="cancel_restock">
                <input type="hidden" name="restock_id" value="<?= h($confirmCancelRestock['restock_id']) ?>">
                <button type="submit" class="btn btn-green">Yes, cancel this order</button>
            </form>
            <a class="btn btn-light" href="<?= action_link('cancel', '') ?>">No, keep it</a>
        </div>
    <?php else: ?>
        <div class="card muted">
            Restock order #<?= h($confirmCancelRestock['restock_id']) ?> is
            <?= h($confirmCancelRestock['status']) ?> and can no longer be cancelled.
        </div>
    <?php endif; ?>
<?php elseif (isset($_GET['cancel'])): ?>
    <div class="card muted">That restock order no longer exists.</div>
<?php endif; ?>

<?php if ($viewId): ?>
    <?php if (!$viewOrder): ?>
        <div class="card muted">That restock order no longer exists.</div>
    <?php else: ?>
        <h2>Restock order #<?= h($viewOrder['restock_id']) ?> &mdash; <?= h($viewOrder['supplier_name']) ?></h2>
        <p class="muted" style="margin-bottom:10px">
            <?= h(date('d M Y', strtotime($viewOrder['restock_date']))) ?>
            &middot; <span class="pill pill-<?= h($viewOrder['status']) ?>"><?= h($viewOrder['status']) ?></span>
        </p>

        <?php $sum = 0; foreach ($viewLines as $ln) { $sum += $ln['line_total']; } ?>

        <?php if ($viewOrder['status'] === 'PLACED'): ?>
            <table>
                <tr><th>Medicine</th><th class="num">Line total (Rs.)</th><th>Quantity / unit cost</th></tr>
                <?php foreach ($viewLines as $ln): ?>
                    <tr>
                        <td><?= h($ln['name']) ?></td>
                        <td class="num"><?= number_format($ln['line_total'], 2) ?></td>
                        <td>
                            <form method="post" action="restock.php"
                                  style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <input type="hidden" name="action" value="update_restock_item">
                                <input type="hidden" name="restock_id" value="<?= h($viewOrder['restock_id']) ?>">
                                <input type="hidden" name="medicine_id" value="<?= h($ln['medicine_id']) ?>">
                                <input class="qty" type="number" min="1" name="quantity" value="<?= h($ln['quantity']) ?>">
                                <input class="qty" type="number" min="0" step="0.01" name="unit_cost" value="<?= h($ln['unit_cost']) ?>">
                                <button type="submit" class="btn btn-small">Save</button>
                            </form>
                            <form method="post" action="restock.php" style="display:inline">
                                <input type="hidden" name="action" value="delete_restock_item">
                                <input type="hidden" name="restock_id" value="<?= h($viewOrder['restock_id']) ?>">
                                <input type="hidden" name="medicine_id" value="<?= h($ln['medicine_id']) ?>">
                                <button type="submit" class="btn btn-small">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr><th colspan="2" class="num">Total</th><th>Rs. <?= number_format($sum, 2) ?></th></tr>
            </table>

            <?php if ($addableMedicines): ?>
                <h2>Add a medicine to this order</h2>
                <div class="card">
                    <form method="post" action="restock.php">
                        <input type="hidden" name="action" value="add_restock_item">
                        <input type="hidden" name="restock_id" value="<?= h($viewOrder['restock_id']) ?>">

                        <label for="add_medicine_id">Medicine</label>
                        <select id="add_medicine_id" name="medicine_id">
                            <?php foreach ($addableMedicines as $m): ?>
                                <option value="<?= h($m['medicine_id']) ?>"><?= h($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="add_quantity">Quantity</label>
                        <input type="number" id="add_quantity" name="quantity" min="1" value="1">

                        <label for="add_unit_cost">Unit cost (Rs.)</label>
                        <input type="number" id="add_unit_cost" name="unit_cost" min="0" step="0.01" value="0.00">

                        <p style="margin-top:12px">
                            <button type="submit" class="btn btn-green">Add line</button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <table>
                <tr><th>Medicine</th><th class="num">Quantity</th><th class="num">Unit cost</th><th class="num">Line total</th></tr>
                <?php foreach ($viewLines as $ln): ?>
                    <tr>
                        <td><?= h($ln['name']) ?></td>
                        <td class="num"><?= h($ln['quantity']) ?></td>
                        <td class="num"><?= number_format($ln['unit_cost'], 2) ?></td>
                        <td class="num"><?= number_format($ln['line_total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><th colspan="3" class="num">Total</th><th class="num">Rs. <?= number_format($sum, 2) ?></th></tr>
            </table>
            <?php if ($viewOrder['status'] === 'RECEIVED'): ?>
                <div class="card muted">
                    This order has already been received and its stock added to MEDICINE -
                    it can no longer be edited or deleted. Reversing that would need a stock
                    adjustment process, which is out of scope here.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p style="margin-top:12px"><a href="<?= action_link('view', '') ?>" class="btn btn-small">Close</a></p>
    <?php endif; ?>
<?php endif; ?>

<h2>Raise a new restock order</h2>
<form class="searchbar" method="get" action="restock.php">
    <input type="text" name="mq" placeholder="Search medicine name or category" value="<?= h($mq) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="restock.php">Clear</a>
</form>
<?php foreach ($createErrors as $err): ?>
    <p class="alert alert-error"><?= h($err) ?></p>
<?php endforeach; ?>
<?php if (!$activeSuppliers): ?>
    <div class="card muted">No active suppliers - add one on the Suppliers page first.</div>
<?php elseif (!$medicines): ?>
    <div class="card muted">No medicines match your search.</div>
<?php else: ?>
    <form method="post" action="restock.php">
        <input type="hidden" name="action" value="create_restock">

        <label for="supplier_id">Supplier</label>
        <select id="supplier_id" name="supplier_id" required>
            <option value="">&mdash; choose a supplier &mdash;</option>
            <?php foreach ($activeSuppliers as $s): ?>
                <option value="<?= h($s['supplier_id']) ?>"
                    <?= $formSupplierId === (int)$s['supplier_id'] ? 'selected' : '' ?>>
                    <?= h($s['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <table style="margin-top:14px">
            <tr>
                <th>Medicine</th><th>Category</th>
                <th class="num">In stock</th><th class="num">Threshold</th>
                <th class="num">Quantity</th><th class="num">Unit cost (Rs.)</th>
            </tr>
            <?php foreach ($medicines as $m):
                $mid     = (int)$m['medicine_id'];
                $qtyVal  = $formQty[$mid]  ?? ($preselectMedicineId === $mid ? $preselectQty : 0);
                $costVal = $formCost[$mid] ?? ($lastCosts[$mid] ?? '');
            ?>
                <tr>
                    <td><?= h($m['name']) ?></td>
                    <td class="muted"><?= h($m['category']) ?></td>
                    <td class="num"><?= h($m['quantity_in_stock']) ?></td>
                    <td class="num"><?= h($m['reorder_threshold']) ?></td>
                    <td class="num">
                        <input class="qty" type="number" min="0" name="qty[<?= h($mid) ?>]" value="<?= h($qtyVal) ?>">
                    </td>
                    <td class="num">
                        <input class="qty" type="number" min="0" step="0.01" name="cost[<?= h($mid) ?>]" value="<?= h($costVal) ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:14px">
            <button type="submit" class="btn btn-green">Raise restock order</button>
        </p>
    </form>
<?php endif; ?>

<h2>Existing restock orders</h2>
<form class="searchbar" method="get" action="restock.php">
    <input type="text" name="sq" placeholder="Search restock ID, supplier, or medicine"
           value="<?= h($sq) ?>">
    <input type="hidden" name="sstatus" value="<?= h($sstatus) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="restock.php">Clear</a>
</form>
<div class="filter-links">
    <?php if ($sstatus === ''): ?>
        <span class="current">All statuses</span>
    <?php else: ?>
        <a href="<?= filter_link('sstatus', '') ?>">All statuses</a>
    <?php endif; ?>
    <?php foreach ($VALID_RESTOCK_STATUSES as $vs): ?>
        <?php if ($sstatus === $vs): ?>
            <span class="current"><?= h(ucfirst(strtolower($vs))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('sstatus', $vs) ?>"><?= h(ucfirst(strtolower($vs))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($restockOrders) ?> restock order<?= count($restockOrders) === 1 ? '' : 's' ?>
    <?php if ($sq !== ''): ?>matching '<?= h($sq) ?>'<?php endif; ?>
</p>
<?php if (!$restockOrders): ?>
    <div class="card muted">No restock orders match your search.</div>
<?php else: ?>
    <table>
        <tr>
            <th>ID</th><th>Supplier</th><th>Date</th><th>Status</th>
            <th class="num">Lines</th><th class="num">Total cost (Rs.)</th><th></th>
        </tr>
        <?php foreach ($restockOrders as $ro): ?>
            <tr>
                <td>#<?= h($ro['restock_id']) ?></td>
                <td><?= h($ro['supplier_name']) ?></td>
                <td><?= h(date('d M Y', strtotime($ro['restock_date']))) ?></td>
                <td><span class="pill pill-<?= h($ro['status']) ?>"><?= h($ro['status']) ?></span></td>
                <td class="num"><?= h($ro['lines_count']) ?></td>
                <td class="num"><?= number_format((float)$ro['total_cost'], 2) ?></td>
                <td>
                    <a class="btn btn-small" href="<?= $viewId === (int)$ro['restock_id']
                            ? action_link('view', '')
                            : action_link('view', (string)$ro['restock_id']) ?>">
                        <?= $viewId === (int)$ro['restock_id'] ? 'Hide' : 'View' ?>
                    </a>
                    <?php if ($ro['status'] === 'PLACED'): ?>
                        <form method="post" action="restock.php" style="display:inline">
                            <input type="hidden" name="action" value="receive_restock">
                            <input type="hidden" name="restock_id" value="<?= h($ro['restock_id']) ?>">
                            <button type="submit" class="btn btn-small">Mark received</button>
                        </form>
                        <a class="btn btn-small" href="<?= action_link('cancel', (string)$ro['restock_id']) ?>">Cancel</a>
                        <a class="btn btn-small" href="<?= action_link('delete', (string)$ro['restock_id']) ?>">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php page_footer(); ?>
