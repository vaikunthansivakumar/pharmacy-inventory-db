<?php
/* ============================================================
   login.php  -  the only way in.
   ============================================================ */

require_once __DIR__ . '/auth.php';

// Already signed in? Go straight to your own dashboard.
if (is_logged_in()) {
    header('Location: ' . home_page_for(current_role()));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        $error = 'Please enter your email and password.';
    } else {
        $role = login_user($pdo, $email, $pass);

        if ($role !== null) {
            header('Location: ' . home_page_for($role));
            exit;
        }
        /* Deliberately vague. Saying "no such email" would let
           someone test addresses to discover who has an account. */
        $error = 'Email or password is incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &mdash; Pharmacy Management System</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="centered">

<div class="card auth">
  <div class="brand">
    <span class="mark"></span>
    <div>
      <h1>Pharmacy Management System</h1>
      <p class="muted">Faculty of Engineering, University of Jaffna</p>
    </div>
  </div>

  <?php show_flash(); ?>

  <?php if ($error): ?>
    <p class="alert alert-error"><?= h($error) ?></p>
  <?php endif; ?>

  <form method="post" action="login.php" autocomplete="on">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required
           value="<?= h($_POST['email'] ?? '') ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit" class="btn btn-green">Sign in</button>
  </form>

  <p class="muted small" style="margin-top:16px">
    New customer? <a href="signup.php">Create an account</a>.
  </p>

  <p class="muted small" style="margin-top:6px">
    Staff accounts are created by the administrator.
  </p>
</div>

</body>
</html>
