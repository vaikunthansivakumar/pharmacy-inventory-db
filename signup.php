<?php
/* ============================================================
   signup.php  -  Public customer registration.

   This page can ONLY ever create a CUSTOMER account. The role
   is hard-coded below - there is no role field on the form and
   no role value is ever read from $_POST. A public form that let
   a visitor pick their own role would be privilege escalation.
   Pharmacist and admin accounts are created by staff.php instead
   (admin only) - see staff.php for why self-signup for staff
   would break R1.
   ============================================================ */

require_once __DIR__ . '/auth.php';

// Already signed in? No need to sign up again.
if (is_logged_in()) {
    header('Location: ' . home_page_for(current_role()));
    exit;
}

$errors  = [];
$name    = '';
$email   = '';
$phone   = '';
$address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    // Server-side validation - the HTML "required" attributes below
    // are just a hint, a request can always be sent by hand.
    if ($name === '') {
        $errors[] = 'Enter your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (!$errors) {
        try {
            /* One SYSTEM_USER row and one CUSTOMER row, or neither -
               R1 says every user has exactly one subclass row, so a
               user must never exist without one. */
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO SYSTEM_USER (name, email, password) VALUES (?, ?, ?)"
            )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

            $userId = (int)$pdo->lastInsertId();

            // CUSTOMER is hard-coded here. Nothing from $_POST decides
            // which subclass table this new user_id goes into.
            $pdo->prepare(
                "INSERT INTO CUSTOMER (user_id, phone, address) VALUES (?, ?, ?)"
            )->execute([$userId, $phone !== '' ? $phone : null, $address !== '' ? $address : null]);

            $pdo->commit();

            set_flash('success', 'Account created. Please sign in.');
            header('Location: login.php');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            // MySQL error code 1062 = duplicate key - the UNIQUE email.
            if ((int)$e->errorInfo[1] === 1062) {
                $errors[] = 'An account with that email already exists.';
            } else {
                $errors[] = 'Could not create the account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account &mdash; Pharmacy Management System</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="centered">

<div class="card auth">
  <div class="brand">
    <span class="mark"></span>
    <div>
      <h1>Create your account</h1>
      <p class="muted">Faculty of Engineering, University of Jaffna</p>
    </div>
  </div>

  <?php foreach ($errors as $err): ?>
    <p class="alert alert-error"><?= h($err) ?></p>
  <?php endforeach; ?>

  <form method="post" action="signup.php" autocomplete="on">
    <label for="name">Full name</label>
    <input type="text" id="name" name="name" required value="<?= h($name) ?>">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required value="<?= h($email) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required minlength="8">

    <label for="confirm">Confirm password</label>
    <input type="password" id="confirm" name="confirm" required minlength="8">

    <label for="phone">Phone</label>
    <input type="text" id="phone" name="phone" value="<?= h($phone) ?>">

    <label for="address">Address</label>
    <input type="text" id="address" name="address" value="<?= h($address) ?>">

    <button type="submit" class="btn btn-green">Create account</button>
  </form>

  <p class="muted small" style="margin-top:16px">
    Already have an account? <a href="login.php">Sign in</a>.
  </p>
</div>

</body>
</html>
