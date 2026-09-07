<?php
require_once __DIR__ . '/auth.php';

/* Empty the session array, destroy the server-side session, and
   expire the cookie. session_destroy() alone would leave the cookie
   in the browser pointing at a dead session. */
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
              $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

header('Location: login.php');
exit;
