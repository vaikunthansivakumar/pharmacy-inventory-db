<?php
/* ============================================================
   profile.php  -  "My profile": every signed-in role can view and
   edit their own details and change their own password here.

   require_login() only, not require_role() - every role is allowed
   here, each seeing and editing only their own record.
   ============================================================ */

require_once __DIR__ . '/auth.php';
require_login();

$me = current_user_id();

/* ============================================================
   ONE QUERY, THE SAME TRICK AS login_user() IN auth.php
   The role shown below is not read from $_SESSION and there is
   no "role" column anywhere to read it from even if we wanted
   to. It is derived a second time, straight from the database:
   which of the three disjoint subclass tables (ADMIN, PHARMACIST,
   CUSTOMER) this user_id appears in. This page is a second,
   independent demonstration that the specialization - not a
   stored flag - is what decides a user's role.
   ============================================================ */
function find_own_profile(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.name, u.email, u.is_active,
                CASE
                    WHEN a.user_id IS NOT NULL THEN 'admin'
                    WHEN p.user_id IS NOT NULL THEN 'pharmacist'
                    WHEN c.user_id IS NOT NULL THEN 'customer'
                END AS role,
                p.license_no,
                c.phone, c.address
           FROM      SYSTEM_USER u
           LEFT JOIN ADMIN      a ON a.user_id = u.user_id
           LEFT JOIN PHARMACIST p ON p.user_id = u.user_id
           LEFT JOIN CUSTOMER   c ON c.user_id = u.user_id
          WHERE u.user_id = ?"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$profile = find_own_profile($pdo, $me);

$profileErrors  = [];
$passwordErrors = [];

/* ============================================================
   EDIT OWN DETAILS

   THE RULE THAT MATTERS MOST: every query on this page takes its
   user_id from current_user_id() ($me), never from $_POST or $_GET.
   staff.php's update_user reads $_POST['user_id'] because an admin
   there is legitimately editing someone ELSE's record - that is the
   whole point of that page. This page has no such case: the only
   account a signed-in user may ever change here is their own. If
   this file is ever extended by copying staff.php's handlers, the
   first thing to change is exactly this - carrying a posted user_id
   over here would let any signed-in customer post another user's id
   and edit their record.

   Editable for every role: name, email. Editable for a customer
   only: phone, address. NOT editable by anyone here: license_no (a
   professional credential is not self-certified - see the read-only
   field below), is_active (a user cannot (de)activate themselves),
   and role/subclass membership (moving a user between subclass
   tables is deliberately not supported anywhere in this system).
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {

    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        $profileErrors[] = 'Enter your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileErrors[] = 'Enter a valid email address.';
    }

    if (!$profileErrors) {
        try {
            // Both tables move together or not at all, exactly as in
            // staff.php's update_user - a customer must never end up with
            // a saved name/email but stale phone/address, or vice versa.
            $pdo->beginTransaction();

            $pdo->prepare(
                "UPDATE SYSTEM_USER SET name = ?, email = ? WHERE user_id = ?"
            )->execute([$name, $email, $me]);

            if ($profile['role'] === 'customer') {
                $pdo->prepare(
                    "UPDATE CUSTOMER SET phone = ?, address = ? WHERE user_id = ?"
                )->execute([$phone !== '' ? $phone : null, $address !== '' ? $address : null, $me]);
            }
            // pharmacist: license_no is read-only on this page, nothing to
            // write. admin: no subclass fields to update.

            $pdo->commit();

            // The sidebar greeting reads $_SESSION['name'], set once at
            // login - without refreshing it here a changed name would
            // save correctly but the topbar would keep showing the old
            // one until the next login.
            $_SESSION['name'] = $name;

            set_flash('success', 'Profile updated.');
            header('Location: profile.php');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            // Unlike staff.php's update_user, this page never writes
            // license_no, and CUSTOMER.phone/address carry no UNIQUE
            // constraint - so the only way to hit MySQL error 1062 here
            // is the UNIQUE email on SYSTEM_USER. No $stage trick needed:
            // there is only one table a duplicate can come from.
            if ((int)$e->errorInfo[1] === 1062) {
                $profileErrors[] = 'That email is already in use.';
            } else {
                $profileErrors[] = 'Could not save changes. Please try again.';
            }
        }
    }

    // Sticky re-fill: what was typed survives a failed submit, instead of
    // reverting silently to the old database values.
    $profile['name']  = $name;
    $profile['email'] = $email;
    if ($profile['role'] === 'customer') {
        $profile['phone']   = $phone;
        $profile['address'] = $address;
    }
}

/* ============================================================
   CHANGE OWN PASSWORD
   Same length rule as signup.php, hashed the same way as everywhere
   else. Never echoed, logged, or re-filled - on any error every box
   below comes back empty rather than carrying a password forward.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $hashStmt = $pdo->prepare("SELECT password FROM SYSTEM_USER WHERE user_id = ?");
    $hashStmt->execute([$me]);
    $currentHash = $hashStmt->fetchColumn();

    // password_verify re-hashes what was typed using the salt stored inside
    // the stored hash, then compares - the same check login_user() makes.
    if (!password_verify($current, $currentHash)) {
        $passwordErrors[] = 'Your current password is not correct.';
    } elseif (strlen($new) < 8) {
        $passwordErrors[] = 'Password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $passwordErrors[] = 'Password and confirmation do not match.';
    }

    if (!$passwordErrors) {
        $pdo->prepare(
            "UPDATE SYSTEM_USER SET password = ? WHERE user_id = ?"
        )->execute([password_hash($new, PASSWORD_DEFAULT), $me]);

        // A password change is exactly the moment to invalidate any session
        // identifier that might have been captured earlier (over shoulder,
        // shared machine, a stale cookie) - the same reasoning login_user()
        // applies at login, via the same call.
        session_regenerate_id(true);

        set_flash('success', 'Password changed.');
        header('Location: profile.php');
        exit;
    }
}

page_header('My profile');
show_flash();
?>

<div class="card">
    <?php foreach ($profileErrors as $err): ?>
        <p class="alert alert-error"><?= h($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="profile.php">
        <input type="hidden" name="action" value="update_profile">

        <label>Role</label>
        <p style="margin:4px 0 0">
            <?= h(ucfirst($profile['role'])) ?>
            <span class="muted small">(fixed - moving between roles is not supported anywhere in this system)</span>
        </p>

        <label>Status</label>
        <p style="margin:4px 0 0">
            <?= $profile['is_active'] ? 'Active' : '<span class="low">Deactivated</span>' ?>
            <span class="muted small">(only an administrator can change this)</span>
        </p>

        <label for="name">Name</label>
        <input type="text" id="name" name="name" required value="<?= h($profile['name']) ?>">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required value="<?= h($profile['email']) ?>">

        <?php if ($profile['role'] === 'pharmacist'): ?>
            <label for="license_no">Licence number</label>
            <input type="text" id="license_no" value="<?= h($profile['license_no']) ?>" disabled>
            <p class="muted small" style="margin:4px 0 12px">
                A professional credential is not self-certified - only an
                administrator can change a licence number, from the Users
                screen (staff.php).
            </p>
        <?php elseif ($profile['role'] === 'customer'): ?>
            <label for="phone">Phone</label>
            <input type="text" id="phone" name="phone" value="<?= h($profile['phone'] ?? '') ?>">

            <label for="address">Address</label>
            <input type="text" id="address" name="address" value="<?= h($profile['address'] ?? '') ?>">
        <?php endif; ?>

        <p style="margin-top:16px">
            <button type="submit" class="btn btn-green">Save changes</button>
        </p>
    </form>
</div>

<h2>Change password</h2>
<div class="card">
    <?php foreach ($passwordErrors as $err): ?>
        <p class="alert alert-error"><?= h($err) ?></p>
    <?php endforeach; ?>

    <form method="post" action="profile.php">
        <input type="hidden" name="action" value="change_password">

        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required>

        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required minlength="8">

        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

        <p style="margin-top:16px">
            <button type="submit" class="btn btn-green">Change password</button>
        </p>
    </form>
</div>

<?php page_footer(); ?>
