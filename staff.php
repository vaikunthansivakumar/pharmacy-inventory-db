<?php
/* ============================================================
   staff.php  -  Admin manages every user in the system: create
   (staff only - customers self-register via signup.php), edit,
   reset a password, deactivate/reactivate, and delete.

   The filename stayed staff.php but the page now covers every
   role, not just staff, so one screen shows every SYSTEM_USER row.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_role('admin');

$ALLOWED_ROLES = ['pharmacist', 'admin'];   // never insert whatever $_POST sends - create is staff-only

/* ============================================================
   Look up one user together with their role and role-specific
   fields, the same CASE-on-which-subclass-table-holds-the-row
   idiom login_user() uses in auth.php - the role is never stored
   as a column, only ever derived from which subclass table has
   this user_id.
   ============================================================ */
function find_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT su.user_id, su.name, su.email, su.is_active,
                CASE
                    WHEN a.user_id IS NOT NULL THEN 'admin'
                    WHEN p.user_id IS NOT NULL THEN 'pharmacist'
                    WHEN c.user_id IS NOT NULL THEN 'customer'
                END AS role,
                p.license_no, c.phone, c.address
           FROM      SYSTEM_USER su
           LEFT JOIN ADMIN      a ON a.user_id = su.user_id
           LEFT JOIN PHARMACIST p ON p.user_id = su.user_id
           LEFT JOIN CUSTOMER   c ON c.user_id = su.user_id
          WHERE su.user_id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/* Locks every currently-active admin row and counts them. Used by both
   delete and deactivate below, always as the last check before the
   action itself, inside the same transaction - see those two blocks
   for why the count has to be taken there and not earlier. */
function count_active_admins_locked(PDO $pdo): int
{
    $st = $pdo->query(
        "SELECT COUNT(*) FROM SYSTEM_USER su
           JOIN ADMIN a ON a.user_id = su.user_id
          WHERE su.is_active = 1
          FOR UPDATE"
    );
    return (int)$st->fetchColumn();
}

/* ============================================================
   CREATE - staff only (pharmacist or admin)
   Unchanged from before: a public signup can only ever create a
   customer (signup.php), so a staff account can only be created
   here, by someone who is already an admin.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_staff') {

    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = $_POST['role'] ?? '';
    $licenseNo = trim($_POST['license_no'] ?? '');

    $createErrors = [];
    if ($name === '') {
        $createErrors[] = 'Enter a name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $createErrors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $createErrors[] = 'Temporary password must be at least 8 characters.';
    }
    if (!in_array($role, $ALLOWED_ROLES, true)) {
        $createErrors[] = 'Choose a role.';
    }
    if ($role === 'pharmacist' && $licenseNo === '') {
        $createErrors[] = 'Enter a licence number for a pharmacist.';
    }

    if (!$createErrors) {
        $stage = 'user';
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO SYSTEM_USER (name, email, password) VALUES (?, ?, ?)"
            )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

            $userId = (int)$pdo->lastInsertId();
            $stage  = 'subclass';

            if ($role === 'pharmacist') {
                $pdo->prepare(
                    "INSERT INTO PHARMACIST (user_id, license_no) VALUES (?, ?)"
                )->execute([$userId, $licenseNo]);
            } else {
                $pdo->prepare(
                    "INSERT INTO ADMIN (user_id) VALUES (?)"
                )->execute([$userId]);
            }

            $pdo->commit();

            set_flash('success', ucfirst($role) . " account created for $name.");
            header('Location: staff.php');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((int)$e->errorInfo[1] === 1062) {
                $createErrors[] = $stage === 'user'
                    ? 'An account with that email already exists.'
                    : 'That licence number is already in use.';
            } else {
                $createErrors[] = 'Could not create the account. Please try again.';
            }
        }
    }
}

/* ============================================================
   EDIT
   Name and email for everyone, plus whichever role-specific
   fields that user's subclass table has. The role itself is never
   part of this form - see the comment above the read-only display
   further down for why.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {

    $editUserId = (int)($_POST['user_id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $licenseNo  = trim($_POST['license_no'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');

    $editErrors = [];
    $target = find_user($pdo, $editUserId);

    if (!$target) {
        $editErrors[] = 'That user no longer exists.';
    }
    if ($name === '') {
        $editErrors[] = 'Enter a name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $editErrors[] = 'Enter a valid email address.';
    }
    if ($target && $target['role'] === 'pharmacist' && $licenseNo === '') {
        $editErrors[] = 'Enter a licence number.';
    }

    if (!$editErrors) {
        // Same $stage trick as create_staff above, so the 1062 catch
        // below can tell a duplicate email (on SYSTEM_USER) apart from
        // a duplicate licence number (on PHARMACIST).
        $stage = 'user';
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                "UPDATE SYSTEM_USER SET name = ?, email = ? WHERE user_id = ?"
            )->execute([$name, $email, $editUserId]);

            $stage = 'subclass';

            if ($target['role'] === 'pharmacist') {
                $pdo->prepare(
                    "UPDATE PHARMACIST SET license_no = ? WHERE user_id = ?"
                )->execute([$licenseNo, $editUserId]);
            } elseif ($target['role'] === 'customer') {
                $pdo->prepare(
                    "UPDATE CUSTOMER SET phone = ?, address = ? WHERE user_id = ?"
                )->execute([$phone !== '' ? $phone : null, $address !== '' ? $address : null, $editUserId]);
            }
            // admin: no subclass fields to update.

            $pdo->commit();
            set_flash('success', "User \"$name\" updated.");
            header('Location: staff.php');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((int)$e->errorInfo[1] === 1062) {
                $editErrors[] = $stage === 'user'
                    ? 'That email is already in use by another account.'
                    : 'That licence number is already in use.';
            } else {
                $editErrors[] = 'Could not save changes. Please try again.';
            }
        }
    }
}

/* ============================================================
   PASSWORD RESET
   Same length rule as signup.php, hashed the same way. The plain
   password is never stored, logged, or echoed back anywhere below
   this block - only a flash message confirming it was changed.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {

    $targetId    = (int)($_POST['user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if (strlen($newPassword) < 8) {
        set_flash('error', 'New password must be at least 8 characters.');
    } else {
        $st = $pdo->prepare("UPDATE SYSTEM_USER SET password = ? WHERE user_id = ?");
        $st->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
        set_flash($st->rowCount() ? 'success' : 'error',
            $st->rowCount() ? 'Password changed for this user.' : 'That user no longer exists.');
    }

    header('Location: staff.php');
    exit;
}

/* ============================================================
   DELETE
   Hard delete first, inside a try/catch, exactly as medicines.php
   and suppliers.php do. A user with no history deletes cleanly -
   their subclass row goes with them via ON DELETE CASCADE from
   SYSTEM_USER. A user with history (CUSTOMER_ORDER.customer_id,
   PAYMENT.payer_id, or RESTOCK_ORDER.admin_id all RESTRICT) makes
   MySQL raise error 1451; catch it and offer Deactivate instead.

   Two safety rules sit in front of the delete itself: never your
   own account, and never the last active admin.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {

    $id = (int)($_POST['user_id'] ?? 0);

    if ($id === current_user_id()) {
        set_flash('error', 'You cannot delete your own account while signed in as it.');
        header('Location: staff.php');
        exit;
    }

    $nameStmt = $pdo->prepare("SELECT name FROM SYSTEM_USER WHERE user_id = ?");
    $nameStmt->execute([$id]);
    $userName = $nameStmt->fetchColumn();

    if ($userName === false) {
        set_flash('error', 'That user no longer exists.');
        header('Location: staff.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock the target row and find out whether it is a currently
        // active admin, as part of the same transaction as the count
        // below and the delete that follows - see count_active_admins_locked().
        $st = $pdo->prepare(
            "SELECT su.is_active, (a.user_id IS NOT NULL) AS is_admin
               FROM SYSTEM_USER su LEFT JOIN ADMIN a ON a.user_id = su.user_id
              WHERE su.user_id = ?
              FOR UPDATE"
        );
        $st->execute([$id]);
        $row = $st->fetch();

        if (!$row) {
            throw new RuntimeException('That user no longer exists.');
        }

        // The count of active admins can only be trusted if it is taken
        // under a lock inside THIS transaction. If it were checked
        // before beginTransaction(), two admins each deleting a
        // DIFFERENT admin at the same moment could both read "2 active
        // admins left, safe to proceed", both commit, and leave zero -
        // the classic time-of-check/time-of-use race. Locking the rows
        // the count depends on closes that window: the second delete
        // has to wait for the first to finish, and then re-reads a
        // count that already reflects it.
        if ($row['is_admin'] && (int)$row['is_active'] === 1) {
            if (count_active_admins_locked($pdo) <= 1) {
                throw new RuntimeException('This is the last active admin - the system must always have at least one.');
            }
        }

        $pdo->prepare("DELETE FROM SYSTEM_USER WHERE user_id = ?")->execute([$id]);

        $pdo->commit();
        set_flash('success', "User \"$userName\" deleted.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && (int)$e->errorInfo[1] === 1451) {
            set_flash('error',
                "\"$userName\" has activity in the system (orders, payments, or restock "
                . "orders) which cannot be rewritten, so they cannot be removed. "
                . "Deactivate them instead.");
        } else {
            set_flash('error', $e->getMessage() ?: 'Could not delete the user. Please try again.');
        }
    }

    header('Location: staff.php');
    exit;
}

/* ---------- deactivate / reactivate -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate_user') {

    $id = (int)($_POST['user_id'] ?? 0);

    if ($id === current_user_id()) {
        set_flash('error', 'You cannot deactivate your own account while signed in as it.');
        header('Location: staff.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare(
            "SELECT su.name, su.is_active, (a.user_id IS NOT NULL) AS is_admin
               FROM SYSTEM_USER su LEFT JOIN ADMIN a ON a.user_id = su.user_id
              WHERE su.user_id = ?
              FOR UPDATE"
        );
        $st->execute([$id]);
        $row = $st->fetch();

        if (!$row) {
            throw new RuntimeException('That user no longer exists.');
        }

        // Same race the delete above closes - see that comment.
        if ($row['is_admin'] && (int)$row['is_active'] === 1) {
            if (count_active_admins_locked($pdo) <= 1) {
                throw new RuntimeException('This is the last active admin - the system must always have at least one.');
            }
        }

        $pdo->prepare("UPDATE SYSTEM_USER SET is_active = 0 WHERE user_id = ?")->execute([$id]);

        $pdo->commit();
        set_flash('success',
            "\"{$row['name']}\" deactivated. They can no longer log in, but stay exactly "
            . "as they are on every past order, invoice, payment, or restock order.");

    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('error', $e->getMessage());
    }

    header('Location: staff.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reactivate_user') {
    $id = (int)($_POST['user_id'] ?? 0);
    $st = $pdo->prepare("UPDATE SYSTEM_USER SET is_active = 1 WHERE user_id = ?");
    $st->execute([$id]);
    set_flash($st->rowCount() ? 'success' : 'error',
        $st->rowCount() ? 'User reactivated. They can log in again.' : 'That user no longer exists.');
    header('Location: staff.php');
    exit;
}

/* ============================================================
   CONFIRMATION STEPS - delete and deactivate
   Same GET-confirm then POST-act pattern as everywhere else. The
   two safety rules are also surfaced here for a clear message
   before the button is even shown, though the POST handlers above
   are what actually enforce them.
   ============================================================ */
$confirmDelete   = null;
$blockedDelete   = null;
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId === current_user_id()) {
        $blockedDelete = 'You cannot delete your own account.';
    } else {
        $confirmDelete = find_user($pdo, $delId);
    }
}

$confirmDeactivate = null;
$blockedDeactivate = null;
if (isset($_GET['deactivate'])) {
    $deactId = (int)$_GET['deactivate'];
    if ($deactId === current_user_id()) {
        $blockedDeactivate = 'You cannot deactivate your own account.';
    } else {
        $row = find_user($pdo, $deactId);
        if ($row && !$row['is_active']) {
            $row = null; // already inactive - fall through to "no longer exists / already inactive"
        }
        if ($row && $row['role'] === 'admin') {
            $activeAdminCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM SYSTEM_USER su JOIN ADMIN a ON a.user_id = su.user_id WHERE su.is_active = 1"
            )->fetchColumn();
            if ($activeAdminCount <= 1) {
                $blockedDeactivate = 'This is the last active admin and cannot be deactivated - the system must always have at least one.';
                $row = null;
            }
        }
        $confirmDeactivate = $row;
    }
}

/* ---------- editing: pre-fill the form from the database ---- */
$form   = ['name' => '', 'email' => '', 'license_no' => '', 'phone' => '', 'address' => '', 'role' => ''];
$editId = 0;

if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editId = (int)$_GET['edit'];
    $row    = find_user($pdo, $editId);

    if (!$row) {
        set_flash('error', 'That user no longer exists.');
        header('Location: staff.php');
        exit;
    }

    $form = [
        'name'       => $row['name'],
        'email'      => $row['email'],
        'license_no' => $row['license_no'] ?? '',
        'phone'      => $row['phone'] ?? '',
        'address'    => $row['address'] ?? '',
        'role'       => $row['role'],
    ];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    // Validation (or the 1062 catch) failed above - keep what was typed
    // and which row it belongs to, and re-derive the role for display
    // (never trust a posted role - see the comment on the read-only
    // role field in the form below).
    $editId = (int)($_POST['user_id'] ?? 0);
    $form   = [
        'name'       => trim($_POST['name'] ?? ''),
        'email'      => trim($_POST['email'] ?? ''),
        'license_no' => trim($_POST['license_no'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
        'address'    => trim($_POST['address'] ?? ''),
        'role'       => $target['role'] ?? '',
    ];
}

/* ============================================================
   SEARCH - all users
   The base list is a UNION ALL of the three role branches (admin,
   pharmacist, customer). As in the old staff-only version of this
   page, the union is wrapped in a subquery and the search applied
   once outside it, rather than repeating the same WHERE (and
   binding the same parameters three times) in every branch.
   ============================================================ */
$sq      = trim($_GET['sq'] ?? '');
$srole   = $_GET['srole'] ?? '';
$sstatus = $_GET['sstatus'] ?? '';
$validRoles = ['admin', 'pharmacist', 'customer'];
$validUserStatuses = ['ACTIVE', 'DEACTIVATED'];

$listWhere  = [];
$listParams = [];

if ($sq !== '') {
    $listWhere[]  = "(name LIKE ? OR email LIKE ?)";
    $listParams[] = '%' . $sq . '%';
    $listParams[] = '%' . $sq . '%';
}
if (in_array($srole, $validRoles, true)) {
    $listWhere[]  = "role = ?";
    $listParams[] = $srole;
}
if ($sstatus === 'ACTIVE') {
    $listWhere[] = "is_active = 1";
} elseif ($sstatus === 'DEACTIVATED') {
    $listWhere[] = "is_active = 0";
}

$listStmt = $pdo->prepare(
    "SELECT * FROM (
        SELECT su.user_id, su.name, su.email, su.is_active,
               'admin' AS role, NULL AS license_no, NULL AS phone, NULL AS address
          FROM SYSTEM_USER su JOIN ADMIN a ON a.user_id = su.user_id
         UNION ALL
        SELECT su.user_id, su.name, su.email, su.is_active,
               'pharmacist' AS role, p.license_no, NULL AS phone, NULL AS address
          FROM SYSTEM_USER su JOIN PHARMACIST p ON p.user_id = su.user_id
         UNION ALL
        SELECT su.user_id, su.name, su.email, su.is_active,
               'customer' AS role, NULL AS license_no, c.phone, c.address
          FROM SYSTEM_USER su JOIN CUSTOMER c ON c.user_id = su.user_id
     ) all_users"
    . ($listWhere ? " WHERE " . implode(' AND ', $listWhere) : "")
    . " ORDER BY role, name"
);
$listStmt->execute($listParams);
$users = $listStmt->fetchAll();

page_header('Users');
show_flash();
?>

<?php if ($blockedDelete): ?>
    <div class="card muted"><?= h($blockedDelete) ?></div>
<?php elseif ($confirmDelete): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Delete <?= h($confirmDelete['name']) ?>?</strong>
            &middot; <?= h(ucfirst($confirmDelete['role'])) ?>
        </p>
        <p class="muted" style="margin-bottom:12px">
            This is a hard delete - it only succeeds if this user has no
            orders, payments, or restock orders on record. If they do, the
            database will refuse and you will be offered Deactivate
            instead. This cannot be undone.
        </p>
        <form method="post" action="staff.php" style="display:inline">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= h($confirmDelete['user_id']) ?>">
            <button type="submit" class="btn btn-green">Yes, delete it</button>
        </form>
        <a class="btn btn-light" href="<?= action_link('delete', '') ?>">No, keep it</a>
    </div>
<?php elseif (isset($_GET['delete'])): ?>
    <div class="card muted">That user no longer exists.</div>
<?php endif; ?>

<?php if ($blockedDeactivate): ?>
    <div class="card muted"><?= h($blockedDeactivate) ?></div>
<?php elseif ($confirmDeactivate): ?>
    <div class="card">
        <p style="margin:0 0 10px">
            <strong>Deactivate <?= h($confirmDeactivate['name']) ?>?</strong>
            &middot; <?= h(ucfirst($confirmDeactivate['role'])) ?>
        </p>
        <p class="muted" style="margin-bottom:12px">
            They will no longer be able to log in, and (for a customer)
            will disappear from the payer dropdown in payment.php - but
            every past order, invoice, payment, or restock order keeps
            them exactly as they were. You can reactivate them later.
        </p>
        <form method="post" action="staff.php" style="display:inline">
            <input type="hidden" name="action" value="deactivate_user">
            <input type="hidden" name="user_id" value="<?= h($confirmDeactivate['user_id']) ?>">
            <button type="submit" class="btn btn-green">Yes, deactivate it</button>
        </form>
        <a class="btn btn-light" href="<?= action_link('deactivate', '') ?>">No, keep it active</a>
    </div>
<?php elseif (isset($_GET['deactivate'])): ?>
    <div class="card muted">That user no longer exists or is already inactive.</div>
<?php endif; ?>

<h2>All users</h2>
<form class="searchbar" method="get" action="staff.php">
    <input type="text" name="sq" placeholder="Search name or email"
           value="<?= h($sq) ?>">
    <input type="hidden" name="srole" value="<?= h($srole) ?>">
    <input type="hidden" name="sstatus" value="<?= h($sstatus) ?>">
    <button type="submit" class="btn btn-small">Search</button>
    <a class="clear" href="staff.php">Clear</a>
</form>
<div class="filter-links">
    <span class="filter-label">Role:</span>
    <?php if ($srole === ''): ?>
        <span class="current">All roles</span>
    <?php else: ?>
        <a href="<?= filter_link('srole', '') ?>">All roles</a>
    <?php endif; ?>
    <?php foreach ($validRoles as $vr): ?>
        <?php if ($srole === $vr): ?>
            <span class="current"><?= h(ucfirst($vr)) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('srole', $vr) ?>"><?= h(ucfirst($vr)) ?></a>
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
    <?php foreach ($validUserStatuses as $vst): ?>
        <?php if ($sstatus === $vst): ?>
            <span class="current"><?= h(ucfirst(strtolower($vst))) ?></span>
        <?php else: ?>
            <a href="<?= filter_link('sstatus', $vst) ?>"><?= h(ucfirst(strtolower($vst))) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="result-count">
    <?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?>
    <?php if ($sq !== ''): ?>matching '<?= h($sq) ?>'<?php endif; ?>
</p>
<?php if (!$users): ?>
    <div class="card muted">No users match your search.</div>
<?php else: ?>
    <table>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Detail</th><th>Status</th><th></th></tr>
        <?php foreach ($users as $u):
            if ($u['role'] === 'pharmacist') {
                $detail = $u['license_no'] !== null ? $u['license_no'] : null;
            } elseif ($u['role'] === 'customer') {
                $bits   = array_filter([$u['phone'], $u['address']], fn($v) => $v !== null && $v !== '');
                $detail = $bits ? implode(', ', $bits) : null;
            } else {
                $detail = null;
            }
        ?>
            <tr>
                <td><?= h($u['name']) ?></td>
                <td><?= h($u['email']) ?></td>
                <td><?= h(ucfirst($u['role'])) ?></td>
                <td class="muted"><?= $detail !== null ? h($detail) : '—' ?></td>
                <td>
                    <?php if ($u['is_active']): ?>
                        Active
                    <?php else: ?>
                        <span class="low">Deactivated</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="btn btn-small" href="<?= action_link('edit', (string)$u['user_id']) ?>">Edit</a>
                    <?php if ($u['is_active']): ?>
                        <a class="btn btn-small" href="<?= action_link('deactivate', (string)$u['user_id']) ?>">Deactivate</a>
                    <?php else: ?>
                        <form method="post" action="staff.php" style="display:inline">
                            <input type="hidden" name="action" value="reactivate_user">
                            <input type="hidden" name="user_id" value="<?= h($u['user_id']) ?>">
                            <button type="submit" class="btn btn-small">Reactivate</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-small" href="<?= action_link('delete', (string)$u['user_id']) ?>">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php if ($editId): ?>
    <h2>Edit user</h2>
    <div class="card">
        <?php foreach ($editErrors ?? [] as $err): ?>
            <p class="alert alert-error"><?= h($err) ?></p>
        <?php endforeach; ?>

        <form method="post" action="staff.php">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" value="<?= h($editId) ?>">

            <label>Role</label>
            <p style="margin:4px 0 0">
                <?= h(ucfirst($form['role'])) ?>
                <span class="muted small">
                    (fixed - a user with any history cannot move between roles
                    without leaving foreign keys pointing at a role they no
                    longer hold; create a new account instead)
                </span>
            </p>

            <label for="name">Name</label>
            <input type="text" id="name" name="name" required value="<?= h($form['name']) ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required value="<?= h($form['email']) ?>">

            <?php if ($form['role'] === 'pharmacist'): ?>
                <label for="license_no">Licence number</label>
                <input type="text" id="license_no" name="license_no" required value="<?= h($form['license_no']) ?>">
            <?php elseif ($form['role'] === 'customer'): ?>
                <label for="phone">Phone</label>
                <input type="text" id="phone" name="phone" value="<?= h($form['phone']) ?>">

                <label for="address">Address</label>
                <input type="text" id="address" name="address" value="<?= h($form['address']) ?>">
            <?php endif; ?>

            <p style="margin-top:16px">
                <button type="submit" class="btn btn-green">Save changes</button>
                <a class="btn btn-light" href="<?= action_link('edit', '') ?>">Cancel</a>
            </p>
        </form>
    </div>

    <h2>Reset password</h2>
    <div class="card">
        <form method="post" action="staff.php">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" value="<?= h($editId) ?>">

            <label for="new_password">New temporary password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">

            <p style="margin-top:16px">
                <button type="submit" class="btn btn-green">Set new password</button>
            </p>
        </form>
    </div>
<?php endif; ?>

<h2>Add a staff member</h2>
<p class="muted" style="font-size:13px;margin-bottom:10px">
    Customers create their own accounts at signup - this only ever makes a
    pharmacist or admin account.
</p>
<div class="card">
    <?php foreach ($createErrors ?? [] as $err): ?>
        <p class="alert alert-error"><?= h($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="staff.php">
        <input type="hidden" name="action" value="create_staff">

        <label for="staff_name">Name</label>
        <input type="text" id="staff_name" name="name" required value="<?= h($name ?? '') ?>">

        <label for="staff_email">Email</label>
        <input type="email" id="staff_email" name="email" required value="<?= h($email ?? '') ?>">

        <label for="staff_password">Temporary password</label>
        <input type="password" id="staff_password" name="password" required minlength="8">

        <label for="staff_role">Role</label>
        <select id="staff_role" name="role">
            <option value="pharmacist" <?= ($role ?? '') === 'pharmacist' ? 'selected' : '' ?>>Pharmacist</option>
            <option value="admin" <?= ($role ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>

        <label for="staff_license_no">Licence number (pharmacist only)</label>
        <input type="text" id="staff_license_no" name="license_no" value="<?= h(($role ?? '') === 'pharmacist' ? ($licenseNo ?? '') : '') ?>">

        <button type="submit" class="btn btn-green">Create account</button>
    </form>
</div>

<?php page_footer(); ?>
