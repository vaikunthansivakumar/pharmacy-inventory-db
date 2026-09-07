<?php
/* ============================================================
   medicines.php  -  Admin CRUD for MEDICINE, the centre of the
   whole system. Everything else (ordering, invoicing, restock,
   reporting) hangs off this table.

   MEDICINE has a DISJOINT, PARTIAL specialization into
   PRESCRIPTION_MEDICINE and OTC_MEDICINE:
     - disjoint: a medicine is in AT MOST ONE of the two subclass
       tables, never both.
     - partial:  a medicine may be in NEITHER. Digital Thermometer
       and Surgical Face Mask are real stocked items that are
       neither prescription nor over-the-counter - a device and a
       consumable, med_type NULL, no subclass row at all.
   So the type field offers THREE choices, not two: Prescription,
   Over the counter, and General item (med_type NULL).

   Nothing in the database enforces that MEDICINE.med_type agrees
   with which subclass table (if any) holds the row - that
   agreement is this page's job, every time a medicine is created
   or its type is changed. See "TYPE INTEGRITY" below.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('admin');

$VALID_TYPES  = ['PRESCRIPTION', 'OTC', 'GENERAL'];   // GENERAL = med_type NULL
$VALID_LEVELS = ['LOW', 'MEDIUM', 'HIGH'];             // prescription_required_level

/* ============================================================
   TYPE INTEGRITY
   Given a validated type and (for PRESCRIPTION) a validated
   level, make the subclass tables agree with MEDICINE.med_type
   for one medicine_id. Called from both create and update.

   The discriminator column (MEDICINE.med_type) and the subclass
   row must never disagree, since nothing in the database enforces
   that for us - there is no trigger or check constraint tying
   them together. The safe way to guarantee agreement on an UPDATE
   is not to work out what changed, but to unconditionally delete
   any existing subclass row (at most one can exist - the
   specialization is disjoint) and then insert the new one if the
   new type needs one. On a CREATE there is nothing to delete yet,
   so this is just the insert half.
   ============================================================ */
function sync_medicine_type(PDO $pdo, int $medicineId, string $type, string $level): void
{
    $pdo->prepare("DELETE FROM PRESCRIPTION_MEDICINE WHERE medicine_id = ?")->execute([$medicineId]);
    $pdo->prepare("DELETE FROM OTC_MEDICINE WHERE medicine_id = ?")->execute([$medicineId]);

    if ($type === 'PRESCRIPTION') {
        $pdo->prepare(
            "INSERT INTO PRESCRIPTION_MEDICINE (medicine_id, prescription_required_level) VALUES (?, ?)"
        )->execute([$medicineId, $level]);
    } elseif ($type === 'OTC') {
        $pdo->prepare(
            "INSERT INTO OTC_MEDICINE (medicine_id) VALUES (?)"
        )->execute([$medicineId]);
    }
    // GENERAL: no subclass row - med_type is NULL and that is the whole story.
}

/* ============================================================
   VALIDATE THE POSTED FIELDS
   Shared by create and update. Returns [errors, cleanValues].
   HTML "required"/"min" attributes are just a hint to the
   browser - a request can always be sent by hand, so every rule
   is re-checked here in PHP.
   ============================================================ */
function validate_medicine_fields(array $post, array $VALID_TYPES, array $VALID_LEVELS): array
{
    $errors = [];

    $name      = trim($post['name'] ?? '');
    $category  = trim($post['category'] ?? '');
    $priceRaw  = trim($post['price'] ?? '');
    $stockRaw  = trim($post['stock'] ?? '');
    $threshRaw = trim($post['threshold'] ?? '');
    $expiryRaw = trim($post['expiry_date'] ?? '');
    $type      = $post['type'] ?? '';
    $level     = $post['level'] ?? '';

    if ($name === '') {
        $errors[] = 'Enter a medicine name.';
    }

    if (!is_numeric($priceRaw) || (float)$priceRaw < 0) {
        $errors[] = 'Price must be a number that is not negative.';
    }

    // FILTER_VALIDATE_INT with min_range rejects "-1", "3.5" and "abc" alike.
    $stock = filter_var($stockRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($stock === false) {
        $errors[] = 'Stock must be a whole number that is not negative.';
    }

    $threshold = filter_var($threshRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($threshold === false) {
        $errors[] = 'Reorder threshold must be a whole number that is not negative.';
    }

    // Expiry is genuinely optional - a thermometer does not expire, and
    // two rows in the live data already have NULL here. Blank is fine;
    // anything typed must be a real calendar date.
    $expiry = null;
    if ($expiryRaw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $expiryRaw);
        if (!$d || $d->format('Y-m-d') !== $expiryRaw) {
            $errors[] = 'Expiry date must be a valid date, or left blank.';
        } else {
            $expiry = $expiryRaw;
        }
    }

    // Never trust the posted type string further than "is it one of
    // the three we know about" - it drives which table gets written to.
    if (!in_array($type, $VALID_TYPES, true)) {
        $errors[] = 'Choose a type.';
    }
    if ($type === 'PRESCRIPTION' && !in_array($level, $VALID_LEVELS, true)) {
        $errors[] = 'Choose a prescription level.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'name'      => $name,
            'category'  => $category,
            'price'     => $priceRaw,
            'stock'     => $stockRaw,
            'threshold' => $threshRaw,
            'expiry'    => $expiryRaw,
            'type'      => $type,
            'level'     => $level,
        ],
        'clean' => [
            'name'      => $name,
            'category'  => $category !== '' ? $category : null,
            'price'     => (float)$priceRaw,
            'stock'     => $stock,
            'threshold' => $threshold,
            'expiry'    => $expiry,
            'type'      => $type,
            'level'     => $level,
        ],
    ];
}

$errors = [];
$form   = [
    'name' => '', 'category' => '', 'price' => '', 'stock' => '',
    'threshold' => '', 'expiry' => '', 'type' => '', 'level' => '',
];
$editId = 0;

/* ============================================================
   CREATE
   One transaction: the MEDICINE row, then the subclass row (if
   the type needs one) - either both are saved or neither is.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_medicine') {

    $result = validate_medicine_fields($_POST, $VALID_TYPES, $VALID_LEVELS);
    $errors = $result['errors'];
    $form   = $result['values'];

    if (!$errors) {
        $c = $result['clean'];
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO MEDICINE
                    (name, category, price, quantity_in_stock, expiry_date,
                     reorder_threshold, med_type, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            )->execute([
                $c['name'], $c['category'], $c['price'], $c['stock'], $c['expiry'],
                $c['threshold'], $c['type'] === 'GENERAL' ? null : $c['type'],
            ]);

            $newId = (int)$pdo->lastInsertId();
            sync_medicine_type($pdo, $newId, $c['type'], $c['level']);

            $pdo->commit();
            set_flash('success', "Medicine \"{$c['name']}\" added.");
            header('Location: medicines.php');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Could not save the medicine. Please try again.';
        }
    }
}

/* ============================================================
   UPDATE
   Same transaction shape as create, plus TYPE INTEGRITY above
   to move the subclass row when the type changes. The posted
   medicine_id is never trusted for its own sake - it only
   selects which row to re-read and lock; every value written
   comes from validate_medicine_fields(), not from the client.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_medicine') {

    $editId = (int)($_POST['medicine_id'] ?? 0);
    $result = validate_medicine_fields($_POST, $VALID_TYPES, $VALID_LEVELS);
    $errors = $result['errors'];
    $form   = $result['values'];

    if (!$errors) {
        $c = $result['clean'];
        try {
            $pdo->beginTransaction();

            // Re-read the current row from the database (locking it)
            // rather than trusting anything about "the old price" that
            // might have travelled in the form - we only need it here
            // to word the flash message below.
            $st = $pdo->prepare("SELECT name, price FROM MEDICINE WHERE medicine_id = ? FOR UPDATE");
            $st->execute([$editId]);
            $old = $st->fetch();

            if (!$old) {
                throw new RuntimeException('That medicine no longer exists.');
            }

            // Editing price must NOT rewrite history: ORDER_ITEM stores
            // unit_price at the time of sale on purpose (see
            // customer.php), so this UPDATE only ever touches the
            // MEDICINE row - past ORDER_ITEM rows, invoices and their
            // totals are untouched no matter what happens here.
            $pdo->prepare(
                "UPDATE MEDICINE
                    SET name = ?, category = ?, price = ?, quantity_in_stock = ?,
                        expiry_date = ?, reorder_threshold = ?, med_type = ?
                  WHERE medicine_id = ?"
            )->execute([
                $c['name'], $c['category'], $c['price'], $c['stock'], $c['expiry'],
                $c['threshold'], $c['type'] === 'GENERAL' ? null : $c['type'], $editId,
            ]);

            sync_medicine_type($pdo, $editId, $c['type'], $c['level']);

            $pdo->commit();

            $priceChanged = abs((float)$old['price'] - $c['price']) > 0.001;
            set_flash('success',
                "Medicine \"{$c['name']}\" updated."
                . ($priceChanged
                    ? ' Past orders keep the price they were sold at - only new orders use the new price.'
                    : ''));
            header('Location: medicines.php');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage() ?: 'Could not save the medicine. Please try again.';
        }
    }
}

/* ============================================================
   DELETE
   Try the hard delete first. If MEDICINE has never been sold or
   restocked it deletes cleanly, and fk_otc_medicine/fk_presc_medicine
   are ON DELETE CASCADE, so its subclass row (if any) goes with
   it automatically - no separate DELETE needed for that part.

   If ORDER_ITEM or RESTOCK_ITEM references this medicine, those
   two foreign keys are ON DELETE RESTRICT, and MySQL raises error
   1451. That is the database refusing to let us silently erase
   sales or restock history - proof referential integrity is
   enforced, not a bug to work around. Catch that specific code
   and explain it, then point at Discontinue instead.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_medicine') {

    $id = (int)($_POST['medicine_id'] ?? 0);

    // Re-read the name ourselves for the flash message - never trust a
    // posted name for a medicine we are about to delete by id.
    $name = $pdo->prepare("SELECT name FROM MEDICINE WHERE medicine_id = ?");
    $name->execute([$id]);
    $medName = $name->fetchColumn();

    if ($medName === false) {
        set_flash('error', 'That medicine no longer exists.');
    } else {
        try {
            $pdo->prepare("DELETE FROM MEDICINE WHERE medicine_id = ?")->execute([$id]);
            set_flash('success', "Medicine \"$medName\" deleted.");
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1451) {
                set_flash('error',
                    "\"$medName\" has sales or restock history and cannot be removed "
                    . "without rewriting that history. Discontinue it instead - that "
                    . "hides it from new orders without touching past records.");
            } else {
                set_flash('error', 'Could not delete the medicine. Please try again.');
            }
        }
    }

    header('Location: medicines.php');
    exit;
}

/* ---------- discontinue / reactivate ------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discontinue_medicine') {
    $id = (int)($_POST['medicine_id'] ?? 0);
    $st = $pdo->prepare("UPDATE MEDICINE SET is_active = 0 WHERE medicine_id = ?");
    $st->execute([$id]);
    set_flash($st->rowCount() ? 'success' : 'error',
        $st->rowCount()
            ? 'Medicine discontinued. It will no longer appear for ordering or in low-stock alerts, but stays on every past order and invoice.'
            : 'That medicine no longer exists.');
    header('Location: medicines.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reactivate_medicine') {
    $id = (int)($_POST['medicine_id'] ?? 0);
    $st = $pdo->prepare("UPDATE MEDICINE SET is_active = 1 WHERE medicine_id = ?");
    $st->execute([$id]);
    set_flash($st->rowCount() ? 'success' : 'error',
        $st->rowCount() ? 'Medicine reactivated. It is available for ordering again.' : 'That medicine no longer exists.');
    header('Location: medicines.php');
    exit;
}

/* ============================================================
   CONFIRMATION STEPS - delete and discontinue
   Same pattern as the order cancel feature in customer.php: a
   GET link shows a confirmation card with a real POST button to
   go ahead, and a plain link to back out. No JavaScript.
   ============================================================ */
$confirmDelete = null;
if (isset($_GET['delete'])) {
    $st = $pdo->prepare("SELECT medicine_id, name FROM MEDICINE WHERE medicine_id = ?");
    $st->execute([(int)$_GET['delete']]);
    $confirmDelete = $st->fetch();
}

$confirmDiscontinue = null;
if (isset($_GET['discontinue'])) {
    $st = $pdo->prepare("SELECT medicine_id, name FROM MEDICINE WHERE medicine_id = ? AND is_active = 1");
    $st->execute([(int)$_GET['discontinue']]);
    $confirmDiscontinue = $st->fetch();
}

/* ---------- editing: pre-fill the form from the database ---- */
if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editId = (int)$_GET['edit'];
    $st = $pdo->prepare(
        "SELECT m.*, pm.prescription_required_level
           FROM MEDICINE m
           LEFT JOIN PRESCRIPTION_MEDICINE pm ON pm.medicine_id = m.medicine_id
          WHERE m.medicine_id = ?"
    );
    $st->execute([$editId]);
    $row = $st->fetch();

    if (!$row) {
        set_flash('error', 'That medicine no longer exists.');
        header('Location: medicines.php');
        exit;
    }

    $form = [
        'name'      => $row['name'],
        'category'  => $row['category'] ?? '',
        'price'     => $row['price'],
        'stock'     => $row['quantity_in_stock'],
        'threshold' => $row['reorder_threshold'],
        'expiry'    => $row['expiry_date'] ?? '',
        'type'      => $row['med_type'] ?? 'GENERAL',
        'level'     => $row['prescription_required_level'] ?? '',
    ];
}

/* ============================================================
   SEARCH - all medicines
   Text box matches name or category; dropdowns filter by type
   (All / Prescription / OTC / General) and by status (All /
   Active / Discontinued). Same pattern as every other list page:
   conditions in an array, values bound as parameters, wildcards
   on the value rather than the query text - that is what stops
   SQL injection.
   ============================================================ */
$sq      = trim($_GET['sq'] ?? '');
$stype   = $_GET['stype'] ?? '';
$sstatus = $_GET['sstatus'] ?? '';
$medTypeLabels      = ['PRESCRIPTION' => 'Prescription', 'OTC' => 'Over the counter', 'GENERAL' => 'General item'];
$validStockStatuses = ['ACTIVE', 'DISCONTINUED'];

$listWhere  = [];
$listParams = [];

if ($sq !== '') {
    $listWhere[]  = "(m.name LIKE ? OR m.category LIKE ?)";
    $listParams[] = '%' . $sq . '%';
    $listParams[] = '%' . $sq . '%';
}
if ($stype === 'PRESCRIPTION' || $stype === 'OTC') {
    $listWhere[]  = "m.med_type = ?";
    $listParams[] = $stype;
} elseif ($stype === 'GENERAL') {
    $listWhere[] = "m.med_type IS NULL";
}
if ($sstatus === 'ACTIVE') {
    $listWhere[] = "m.is_active = 1";
} elseif ($sstatus === 'DISCONTINUED') {
    $listWhere[] = "m.is_active = 0";
}

$listStmt = $pdo->prepare(
    "SELECT   m.medicine_id, m.name, m.category, m.price, m.quantity_in_stock,
              m.reorder_threshold, m.expiry_date, m.is_active,
              COALESCE(m.med_type, 'GENERAL') AS med_type,
              pm.prescription_required_level
       FROM      MEDICINE m
       LEFT JOIN PRESCRIPTION_MEDICINE pm ON pm.medicine_id = m.medicine_id
      " . ($listWhere ? "WHERE " . implode(' AND ', $listWhere) : "") . "
      ORDER BY m.name"
);
$listStmt->execute($listParams);
$medicines = $listStmt->fetchAll();

page_header('Medicines');
show_flash();
?>

<?php if ($confirmDelete): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Delete <?= h($confirmDelete['name']) ?>?</strong>
        </p>
        <p class="muted" style="margin-bottom:12px">
            This is a hard delete - it only succeeds if this medicine has
            never been sold or restocked. If it has, the database will
            refuse and you will be offered Discontinue instead. This cannot
            be undone.
        </p>
        <form method="post" action="medicines.php" style="display:inline">
            <input type="hidden" name="action" value="delete_medicine">
            <input type="hidden" name="medicine_id" value="<?= h($confirmDelete['medicine_id']) ?>">
            <button type="submit" class="btn btn-green">Yes, delete it</button>
        </form>
        <a class="btn btn-light" href="<?= action_link('delete', '') ?>">No, keep it</a>
    </div>
<?php elseif (isset($_GET['delete'])): ?>
    <div class="card muted">That medicine no longer exists.</div>
<?php endif; ?>

<?php if ($confirmDiscontinue): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Discontinue <?= h($confirmDiscontinue['name']) ?>?</strong>
        </p>
        <p class="muted" style="margin-bottom:12px">
            It will disappear from the customer ordering list and from
            low-stock alerts, but every past order, invoice and report
            keeps it exactly as it was. You can reactivate it later.
        </p>
        <form method="post" action="medicines.php" style="display:inline">
            <input type="hidden" name="action" value="discontinue_medicine">
            <input type="hidden" name="medicine_id" value="<?= h($confirmDiscontinue['medicine_id']) ?>">
            <button type="submit" class="btn btn-green">Yes, discontinue it</button>
        </form>
        <a class="btn btn-light" href="<?= action_link('discontinue', '') ?>">No, keep it active</a>
    </div>
<?php elseif (isset($_GET['discontinue'])): ?>
    <div class="card muted">That medicine no longer exists or is already discontinued.</div>
<?php endif; ?>

<h2>All medicines</h2>
<form class="searchbar" method="get" action="medicines.php">
    <input type="text" name="sq" placeholder="Search name or category"
           value="<?= h($sq) ?>">
    <input type="hidden" name="stype" value="<?= h($stype) ?>">
    <input type="hidden" name="sstatus" value="<?= h($sstatus) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="medicines.php">Clear</a>
</form>
<div class="filter-links">
    <span class="filter-label">Type:</span>
    <?php if ($stype === ''): ?>
        <span class="current">All types</span>
    <?php else: ?>
        <a href="<?= filter_link('stype', '') ?>">All types</a>
    <?php endif; ?>
    <?php foreach ($VALID_TYPES as $vt): ?>
        <?php if ($stype === $vt): ?>
            <span class="current"><?= h($medTypeLabels[$vt]) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('stype', $vt) ?>"><?= h($medTypeLabels[$vt]) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<div class="filter-links">
    <span class="filter-label">Status:</span>
    <?php if ($sstatus === ''): ?>
        <span class="current">All statuses</span>
    <?php else: ?>
        <a href="<?= filter_link('sstatus', '') ?>">All statuses</a>
    <?php endif; ?>
    <?php foreach ($validStockStatuses as $vst): ?>
        <?php if ($sstatus === $vst): ?>
            <span class="current"><?= h(ucfirst(strtolower($vst))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('sstatus', $vst) ?>"><?= h(ucfirst(strtolower($vst))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($medicines) ?> medicine<?= count($medicines) === 1 ? '' : 's' ?>
    <?php if ($sq !== ''): ?>matching '<?= h($sq) ?>'<?php endif; ?>
</p>
<?php if (!$medicines): ?>
    <div class="card muted">No medicines match your search.</div>
<?php else: ?>
    <table>
        <tr>
            <th>Name</th><th>Category</th><th>Type</th>
            <th class="num">Price (Rs.)</th><th class="num">Stock</th>
            <th class="num">Threshold</th><th>Expiry</th><th>Status</th><th></th>
        </tr>
        <?php foreach ($medicines as $m): ?>
            <tr>
                <td><?= h($m['name']) ?></td>
                <td class="muted"><?= h($m['category']) ?></td>
                <td>
                    <?= h($m['med_type']) ?>
                    <?php if ($m['prescription_required_level']): ?>
                        <span class="muted">(<?= h($m['prescription_required_level']) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="num"><?= number_format($m['price'], 2) ?></td>
                <td class="num"><?= h($m['quantity_in_stock']) ?></td>
                <td class="num"><?= h($m['reorder_threshold']) ?></td>
                <td><?= $m['expiry_date'] ? h(date('d M Y', strtotime($m['expiry_date']))) : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if ($m['is_active']): ?>
                        Active
                    <?php else: ?>
                        <span class="low">Discontinued</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn btn-small" href="<?= action_link('edit', (string)$m['medicine_id']) ?>">Edit</a>
                    <?php if ($m['is_active']): ?>
                        <a class="btn btn-small" href="<?= action_link('discontinue', (string)$m['medicine_id']) ?>">Discontinue</a>
                    <?php else: ?>
                        <form method="post" action="medicines.php" style="display:inline">
                            <input type="hidden" name="action" value="reactivate_medicine">
                            <input type="hidden" name="medicine_id" value="<?= h($m['medicine_id']) ?>">
                            <button type="submit" class="btn btn-small">Reactivate</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-small" href="<?= action_link('delete', (string)$m['medicine_id']) ?>">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2><?= $editId ? 'Edit medicine' : 'Add a medicine' ?></h2>
<div class="card">
    <?php foreach ($errors as $err): ?>
        <p class="alert alert-error"><?= h($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="medicines.php">
        <input type="hidden" name="action" value="<?= $editId ? 'update_medicine' : 'create_medicine' ?>">
        <?php if ($editId): ?>
            <input type="hidden" name="medicine_id" value="<?= h($editId) ?>">
        <?php endif; ?>

        <label for="name">Name</label>
        <input type="text" id="name" name="name" required value="<?= h($form['name']) ?>">

        <label for="category">Category</label>
        <input type="text" id="category" name="category" value="<?= h($form['category']) ?>">

        <label for="price">Price (Rs.)</label>
        <input type="number" id="price" name="price" step="0.01" min="0" required value="<?= h($form['price']) ?>">

        <label for="stock">Stock (units)</label>
        <input type="number" id="stock" name="stock" step="1" min="0" required value="<?= h($form['stock']) ?>">

        <label for="threshold">Reorder threshold</label>
        <input type="number" id="threshold" name="threshold" step="1" min="0" required value="<?= h($form['threshold']) ?>">

        <label for="expiry_date">Expiry date (leave blank if it does not expire)</label>
        <input type="date" id="expiry_date" name="expiry_date" value="<?= h($form['expiry']) ?>">

        <label for="type">Type</label>
        <select id="type" name="type">
            <option value="GENERAL" <?= $form['type'] === 'GENERAL' ? 'selected' : '' ?>>General item (not prescription, not OTC)</option>
            <option value="PRESCRIPTION" <?= $form['type'] === 'PRESCRIPTION' ? 'selected' : '' ?>>Prescription</option>
            <option value="OTC" <?= $form['type'] === 'OTC' ? 'selected' : '' ?>>Over the counter</option>
        </select>

        <label for="level">Prescription level (used only when type = Prescription)</label>
        <select id="level" name="level">
            <option value="">—</option>
            <?php foreach ($VALID_LEVELS as $lvl): ?>
                <option value="<?= h($lvl) ?>" <?= $form['level'] === $lvl ? 'selected' : '' ?>><?= h($lvl) ?></option>
            <?php endforeach; ?>
        </select>

        <p style="margin-top:16px">
            <button type="submit" class="btn btn-green">
                <?= $editId ? 'Save changes' : 'Add medicine' ?>
            </button>
            <?php if ($editId): ?>
                <a class="btn btn-light" href="<?= action_link('edit', '') ?>">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
</div>

<?php page_footer(); ?>
