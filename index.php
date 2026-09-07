<?php
/* Front door. Sends each visitor wherever they belong. */
require_once __DIR__ . '/auth.php';

header('Location: ' . (is_logged_in()
        ? home_page_for(current_role())
        : 'login.php'));
exit;
