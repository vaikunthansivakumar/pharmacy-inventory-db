<?php
/* ============================================================
   suppliers.php  -  Admin CRUD for SUPPLIER, the far end of the
   supply side. A supplier only matters once it has a RESTOCK_ORDER
   against it - this page just keeps the master list clean.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('admin');

/* ============================================================
   CREATE
   Single table, single row - no transaction needed here, unlike
   medicines.php's create (which also has to write a subclass row).
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_supplier') {

    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_no'] ?? '');
    $errors  = [];

    if ($name === '') {
        $errors[] = 'Enter a supplier name.';
    }

    if (!$errors) {
        $pdo->prepare(
            "INSERT INTO SUPPLIER (name, contact_no, is_active) VALUES (?, ?, 1)"
        )->execute([$name, $contact !== '' ? $contact : null]);

        set_flash('success', "Supplier \"$name\" added.");
        header('Location: suppliers.php');
        exit;
    }
}

/* ---------- update ------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_supplier') {

    $editId  = (int)($_POST['supplier_id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_no'] ?? '');
    $errors  = [];

    if ($name === '') {
        $errors[] = 'Enter a supplier name.';
    }

    if (!$errors) {
        $pdo->prepare(
            "UPDATE SUPPLIER SET name = ?, contact_no = ? WHERE supplier_id = ?"
        )->execute([$name, $contact !== '' ? $contact : null, $editId]);

        set_flash('success', "Supplier \"$name\" updated.");
        header('Location: suppliers.php');
        exit;
    }
}

/* ============================================================
   DELETE
   Try the hard delete first inside a try/catch. A supplier that
   has never had a RESTOCK_ORDER raised against it deletes cleanly.
   fk_restock_supplier is ON DELETE RESTRICT, so a supplier with
   restock history makes MySQL raise error 1451 - the database
   refusing to let purchase history point at nothing. Catch that
   specific code and offer Deactivate instead, same shape as the
   medicine delete in medicines.php.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_supplier') {

    $id = (int)($_POST['supplier_id'] ?? 0);

    // Re-read the name ourselves for the flash message - never trust a
    // posted name for a supplier we are about to delete by id.
    $nameStmt = $pdo->prepare("SELECT name FROM SUPPLIER WHERE supplier_id = ?");
    $nameStmt->execute([$id]);
    $supName = $nameStmt->fetchColumn();

    if ($supName === false) {
        set_flash('error', 'That supplier no longer exists.');
    } else {
        try {
            $pdo->prepare("DELETE FROM SUPPLIER WHERE supplier_id = ?")->execute([$id]);
            set_flash('success', "Supplier \"$supName\" deleted.");
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1451) {
                set_flash('error',
                    "\"$supName\" has purchase history which cannot be rewritten, "
                    . "so it cannot be removed. Deactivate it instead - that hides "
                    . "it from new restock orders without touching past ones.");
            } else {
                set_flash('error', 'Could not delete the supplier. Please try again.');
            }
        }
    }

    header('Location: suppliers.php');
    exit;
}

/* ---------- deactivate / reactivate -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate_supplier') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    $st = $pdo->prepare("UPDATE SUPPLIER SET is_active = 0 WHERE supplier_id = ?");
    $st->execute([$id]);
    set_flash($st->rowCount() ? 'success' : 'error',
        $st->rowCount()
            ? 'Supplier deactivated. It will no longer appear when raising a new restock order, but stays on every past restock order.'
            : 'That supplier no longer exists.');
    header('Location: suppliers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reactivate_supplier') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    $st = $pdo->prepare("UPDATE SUPPLIER SET is_active = 1 WHERE supplier_id = ?");
    $st->execute([$id]);
    set_flash($st->rowCount() ? 'success' : 'error',
        $st->rowCount() ? 'Supplier reactivated. It is available for new restock orders again.' : 'That supplier no longer exists.');
    header('Location: suppliers.php');
    exit;
}

/* ============================================================
   CONFIRMATION STEPS - delete and deactivate
   Same GET-confirm then POST-act pattern as medicines.php: a GET
   link shows a confirmation card with a real POST button to go
   ahead, and a plain link to back out. No JavaScript.
   ============================================================ */
$confirmDelete = null;
if (isset($_GET['delete'])) {
    $st = $pdo->prepare("SELECT supplier_id, name FROM SUPPLIER WHERE supplier_id = ?");
    $st->execute([(int)$_GET['delete']]);
    $confirmDelete = $st->fetch();
}

$confirmDeactivate = null;
if (isset($_GET['deactivate'])) {
    $st = $pdo->prepare("SELECT supplier_id, name FROM SUPPLIER WHERE supplier_id = ? AND is_active = 1");
    $st->execute([(int)$_GET['deactivate']]);
    $confirmDeactivate = $st->fetch();
}

/* ---------- editing: pre-fill the form from the database ---- */
$errors = $errors ?? [];
$form   = ['name' => '', 'contact_no' => ''];
$editId = 0;

if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editId = (int)$_GET['edit'];
    $st = $pdo->prepare("SELECT * FROM SUPPLIER WHERE supplier_id = ?");
    $st->execute([$editId]);
    $row = $st->fetch();

    if (!$row) {
        set_flash('error', 'That supplier no longer exists.');
        header('Location: suppliers.php');
        exit;
    }

    $form = ['name' => $row['name'], 'contact_no' => $row['contact_no'] ?? ''];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create_supplier', 'update_supplier'], true)) {
    // Validation failed above - keep what was typed and, for an
    // update, which row it belongs to, so the form re-shows it.
    $editId = (int)($_POST['supplier_id'] ?? 0);
    $form   = ['name' => trim($_POST['name'] ?? ''), 'contact_no' => trim($_POST['contact_no'] ?? '')];
}

/* ============================================================
   SEARCH - all suppliers
   Text box matches name or contact number; dropdown filters by
   status (All / Active / Inactive). Same pattern as every other
   list page: conditions in an array, values bound as parameters,
   wildcards on the value rather than the query text.
   ============================================================ */
$sq      = trim($_GET['sq'] ?? '');
$sstatus = $_GET['sstatus'] ?? '';
$validSupplierStatuses = ['ACTIVE', 'INACTIVE'];

$listWhere  = [];
$listParams = [];

if ($sq !== '') {
    $listWhere[]  = "(s.name LIKE ? OR s.contact_no LIKE ?)";
    $listParams[] = '%' . $sq . '%';
    $listParams[] = '%' . $sq . '%';
}
if ($sstatus === 'ACTIVE') {
    $listWhere[] = "s.is_active = 1";
} elseif ($sstatus === 'INACTIVE') {
    $listWhere[] = "s.is_active = 0";
}

$listStmt = $pdo->prepare(
    "SELECT   s.supplier_id, s.name, s.contact_no, s.is_active,
              COUNT(ro.restock_id) AS order_count
       FROM      SUPPLIER      s
       LEFT JOIN RESTOCK_ORDER ro ON ro.supplier_id = s.supplier_id
      " . ($listWhere ? "WHERE " . implode(' AND ', $listWhere) : "") . "
      GROUP BY s.supplier_id, s.name, s.contact_no, s.is_active
      ORDER BY s.name"
);
$listStmt->execute($listParams);
$suppliers = $listStmt->fetchAll();

page_header('Suppliers');
show_flash();
?>

<?php if ($confirmDelete): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Delete <?= h($confirmDelete['name']) ?>?</strong>
        </p>
        <p class="muted" style="margin-bottom:12px">
            This is a hard delete - it only succeeds if this supplier has
            never had a restock order raised against it. If it has, the
            database will refuse and you will be offered Deactivate instead.
            This cannot be undone.
        </p>
        <form method="post" action="suppliers.php" style="display:inline">
            <input type="hidden" name="action" value="delete_supplier">
            <input type="hidden" name="supplier_id" value="<?= h($confirmDelete['supplier_id']) ?>">
            <button type="submit" class="btn btn-green">Yes, delete it</button>
        </form>
        <a class="btn btn-light" href="<?= action_link('delete', '') ?>">No, keep it</a>
    </div>
<?php elseif (isset($_GET['delete'])): ?>
    <div class="card muted">That supplier no longer exists.</div>
<?php endif; ?>

<?php if ($confirmDeactivate): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Deactivate <?= h($confirmDeactivate['name']) ?>?</strong>
        </p>
        <p class="muted" style="margin-bottom:12px">
            It will disappear from the supplier dropdown when raising a new
            restock order, but every past restock order keeps it exactly as
            it was. You can reactivate it later.
        </p>
        <form method="post" action="suppliers.php" style="display:inline">
            <input type="hidden" name="action" value="deactivate_supplier">
            <input type="hidden" name="supplier_id" value="<?= h($confirmDeactivate['supplier_id']) ?>">
            <button type="submit" class="btn btn-green">Yes, deactivate it</button>
        </form>
        <a class="btn btn-light" href="<?= action_link('deactivate', '') ?>">No, keep it active</a>
    </div>
<?php elseif (isset($_GET['deactivate'])): ?>
    <div class="card muted">That supplier no longer exists or is already inactive.</div>
<?php endif; ?>

<h2>All suppliers</h2>
<form class="searchbar" method="get" action="suppliers.php">
    <input type="text" name="sq" placeholder="Search name or contact number"
           value="<?= h($sq) ?>">
    <input type="hidden" name="sstatus" value="<?= h($sstatus) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="suppliers.php">Clear</a>
</form>
<div class="filter-links">
    <?php if ($sstatus === ''): ?>
        <span class="current">All statuses</span>
    <?php else: ?>
        <a href="<?= filter_link('sstatus', '') ?>">All statuses</a>
    <?php endif; ?>
    <?php foreach ($validSupplierStatuses as $vst): ?>
        <?php if ($sstatus === $vst): ?>
            <span class="current"><?= h(ucfirst(strtolower($vst))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('sstatus', $vst) ?>"><?= h(ucfirst(strtolower($vst))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($suppliers) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?>
    <?php if ($sq !== ''): ?>matching '<?= h($sq) ?>'<?php endif; ?>
</p>
<?php if (!$suppliers): ?>
    <div class="card muted">No suppliers match your search.</div>
<?php else: ?>
    <table>
        <tr>
            <th>Name</th><th>Contact number</th>
            <th class="num">Restock orders</th><th>Status</th><th></th>
        </tr>
        <?php foreach ($suppliers as $s): ?>
            <tr>
                <td><?= h($s['name']) ?></td>
                <td class="muted"><?= $s['contact_no'] !== null ? h($s['contact_no']) : '—' ?></td>
                <td class="num"><?= h($s['order_count']) ?></td>
                <td>
                    <?php if ($s['is_active']): ?>
                        Active
                    <?php else: ?>
                        <span class="low">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn btn-small" href="<?= action_link('edit', (string)$s['supplier_id']) ?>">Edit</a>
                    <?php if ($s['is_active']): ?>
                        <a class="btn btn-small" href="<?= action_link('deactivate', (string)$s['supplier_id']) ?>">Deactivate</a>
                    <?php else: ?>
                        <form method="post" action="suppliers.php" style="display:inline">
                            <input type="hidden" name="action" value="reactivate_supplier">
                            <input type="hidden" name="supplier_id" value="<?= h($s['supplier_id']) ?>">
                            <button type="submit" class="btn btn-small">Reactivate</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-small" href="<?= action_link('delete', (string)$s['supplier_id']) ?>">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2><?= $editId ? 'Edit supplier' : 'Add a supplier' ?></h2>
<div class="card">
    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= h($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="suppliers.php">
        <input type="hidden" name="action" value="<?= $editId ? 'update_supplier' : 'create_supplier' ?>">
        <?php if ($editId): ?>
            <input type="hidden" name="supplier_id" value="<?= h($editId) ?>">
        <?php endif; ?>

        <label for="name">Name</label>
        <input type="text" id="name" name="name" required value="<?= h($form['name']) ?>">

        <label for="contact_no">Contact number</label>
        <input type="text" id="contact_no" name="contact_no" value="<?= h($form['contact_no']) ?>">

        <p style="margin-top:16px">
            <button type="submit" class="btn btn-green">
                <?= $editId ? 'Save changes' : 'Add supplier' ?>
            </button>
            <?php if ($editId): ?>
                <a class="btn btn-light" href="<?= action_link('edit', '') ?>">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
</div>

<?php page_footer(); ?>
