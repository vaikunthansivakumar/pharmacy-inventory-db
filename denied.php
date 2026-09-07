<?php
require_once __DIR__ . '/auth.php';
require_login();          // must be signed in to even see this page
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Not authorised &mdash; Pharmacy</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="centered">
<div class="card auth">
  <h1>Not authorised</h1>
  <p class="muted" style="margin-top:10px">
    You are signed in as <strong><?= h(current_user_name()) ?></strong>
    (<?= h(current_role()) ?>), and that role cannot open this page.
  </p>
  <p style="margin-top:16px">
    <a class="btn btn-green" href="<?= h(home_page_for(current_role())) ?>">Back to my pages</a>
    <a class="btn btn-light" href="logout.php">Sign out</a>
  </p>
</div>
</body>
</html>
