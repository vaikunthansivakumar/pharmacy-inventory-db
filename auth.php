<?php
/* ============================================================
   auth.php  -  Sessions, login, role checks, shared layout.

   This is the ONLY authentication layer in the system. Every page
   except db.php starts with:

       require_once __DIR__ . '/auth.php';
       require_role('customer');        // or 'pharmacist' or 'admin'

   The role is held in $_SESSION, which lives on the server. It is
   never read from the URL, a form field or an editable cookie, so a
   customer cannot promote themselves by changing a request.
   ============================================================ */

require_once __DIR__ . '/db.php';

/* Harden the session cookie BEFORE the session starts. */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,   // JavaScript cannot read the cookie (blocks XSS session theft)
        'samesite' => 'Lax',  // not sent on cross-site POSTs (partial CSRF defence)
        // 'secure' => true,  // switch on when served over HTTPS
    ]);
    session_start();
}

/* ---------- escape text before printing --------------------
   Everything from the database or a form goes through this, so a
   value like <script>alert(1)</script> is shown as text instead of
   being executed (cross-site scripting).
   ------------------------------------------------------------ */
function h($text): string
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/* Older pages used e(); keep it as an alias so nothing breaks. */
function e($text): string
{
    return h($text);
}

/* ---------- a link that changes one filter, keeps the rest --
   There is no JavaScript anywhere in this project, so a filter
   dropdown cannot submit itself on change. Instead each option
   becomes its own link, carrying the whole current querystring
   with just $key changed.

   Starting from $_GET - not from the handful of variables a page
   happens to have parsed out of it - is what keeps every other
   filter alive, including a second search bar's filters on the
   same page. Hand-building the query string from just the fields
   one form knows about is exactly what breaks the other table.
   ------------------------------------------------------------ */
function filter_link(string $key, string $value, array $drop = []): string
{
    $params = $_GET;

    if ($value === '') {
        unset($params[$key]);          // "All" gets a clean URL, not ?astatus=
    } else {
        $params[$key] = $value;
    }

    foreach ($drop as $d) {
        unset($params[$d]);
    }

    // A filter link must never re-open a confirmation card that
    // happened to be showing - whatever action parameter put it
    // there gets dropped along with the filter change.
    foreach (['edit', 'delete', 'deactivate', 'discontinue', 'cancel',
              'correct', 'view', 'order', 'editorder', 'reactivate',
              'reject'] as $action) {
        unset($params[$action]);
    }

    $query = http_build_query($params);
    return $query === '' ? h(basename($_SERVER['SCRIPT_NAME'])) : '?' . h($query);
}

/* ---------- a link that starts (or ends) one action, keeps
   the rest of the view -----------------------------------------
   filter_link() and this one are a pair: filter_link() changes
   what you are looking at and clears any action; action_link()
   starts an action without changing what you are looking at.
   Between them, no link on a list screen should ever silently
   reset the view.

   Built the same way as filter_link() - from $_GET, so the active
   search and filter parameters survive, which is the whole point.
   Every action parameter is stripped first (the same list
   filter_link() strips), so an Edit link can never land on a page
   that also still carries a leftover ?delete=, fighting over which
   confirmation card shows. $value === '' sets nothing at all,
   which is exactly what "Hide" and "close this card" need: the
   action parameter that opened the card is simply gone.
   ------------------------------------------------------------ */
function action_link(string $key, string $value): string
{
    $params = $_GET;

    foreach (['edit', 'delete', 'deactivate', 'discontinue', 'cancel',
              'correct', 'view', 'order', 'editorder', 'reactivate',
              'reject'] as $action) {
        unset($params[$action]);
    }

    if ($value !== '') {
        $params[$key] = $value;
    }

    $query = http_build_query($params);
    return $query === '' ? h(basename($_SERVER['SCRIPT_NAME'])) : '?' . h($query);
}

/* ---------- who is signed in? ------------------------------- */
function is_logged_in(): bool     { return isset($_SESSION['user_id']); }
function current_user_id(): ?int  { return $_SESSION['user_id'] ?? null; }
function current_user_name(): string { return $_SESSION['name'] ?? ''; }
function current_role(): ?string  { return $_SESSION['role'] ?? null; }

/* ---------- attempt a login --------------------------------
   Returns the role on success, or null on failure.
   ------------------------------------------------------------ */
function login_user(PDO $pdo, string $email, string $password): ?string
{
    /* One query answers both questions: who is this, and what role
       do they hold? The role comes from WHICH SUBCLASS TABLE the
       user_id appears in - the specialization doing its job. There
       is no "role" column anywhere to tamper with. */
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.name, u.password, u.is_active,
                CASE
                    WHEN a.user_id IS NOT NULL THEN 'admin'
                    WHEN p.user_id IS NOT NULL THEN 'pharmacist'
                    WHEN c.user_id IS NOT NULL THEN 'customer'
                END AS role
           FROM      SYSTEM_USER u
           LEFT JOIN ADMIN      a ON a.user_id = u.user_id
           LEFT JOIN PHARMACIST p ON p.user_id = u.user_id
           LEFT JOIN CUSTOMER   c ON c.user_id = u.user_id
          WHERE u.email = ?"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    /* password_verify re-hashes what was typed using the salt stored
       inside the stored hash, then compares. The plain password is
       never stored and never compared directly. */
    if (!$user || !password_verify($password, $user['password'])) {
        return null;                   // wrong email OR wrong password
    }

    if (!$user['role']) {
        return null;                   // no subclass row - breaks R1
    }

    /* A deactivated account must not be able to log in at all. Reusing
       the same null-return path as a wrong password is deliberate: the
       caller (login.php) shows one deliberately vague message for every
       failure reason, so a stranger cannot tell a deactivated account
       apart from a wrong password, and cannot use that to confirm the
       email exists. */
    if (!$user['is_active']) {
        return null;
    }

    /* Kill the pre-login session id and issue a fresh one. Without
       this, someone who plants a known session id before login still
       owns the session afterwards (session fixation). */
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['role']    = $user['role'];

    return $user['role'];
}

/* ---------- gates ------------------------------------------ */

/* Not signed in at all? Back to the login page.

   Then: still allowed in? A session is a claim about who you were at
   the moment you logged in - user_id, name and role were written into
   it once and never looked at again. It is not evidence that the
   account is still permitted. login_user() checks is_active, but only
   there, so an account an admin deactivates mid-session would keep
   full access under its old role until that session happened to end,
   which can be days.

   So authority is re-confirmed against the database on every request
   rather than trusted from the session. That is one extra query per
   page, which at this scale costs nothing, and it is the whole
   difference between "we check the role" and "we check that the
   account is still allowed in".

   Every page inherits this: require_role() calls require_login()
   first, and every page except login/logout/signup goes through one
   of the two. */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;                   // exit is essential - header() alone does
    }                           // not stop the rest of the page running

    global $pdo;                // the connection db.php opened at include time

    $stmt = $pdo->prepare("SELECT is_active FROM SYSTEM_USER WHERE user_id = ?");
    $stmt->execute([current_user_id()]);
    $isActive = $stmt->fetchColumn();

    /* fetchColumn() returns false when there is no row at all. A user
       hard-deleted out from under a live session is treated exactly
       like a deactivated one - in both cases the database no longer
       says this account may be here. */
    if ($isActive === false || !(int)$isActive) {

        /* Tear the session down the same way logout.php does: empty the
           array, expire the cookie, destroy the server-side record.
           session_destroy() alone would leave the browser holding a
           cookie that points at a dead session. */
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                      $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();

        /* A brand-new, empty session, carrying nothing but the
           explanation across the redirect. regenerate_id() issues a
           fresh id and, with it, a fresh cookie - which is what
           overrides the expiry cookie set a few lines above, so the
           message actually survives to login.php. None of the old
           session's identity comes with it. */
        session_start();
        session_regenerate_id(true);

        set_flash('error', 'Your account is no longer active. '
                         . 'Please contact the administrator.');

        header('Location: login.php');
        exit;
    }
}

/* Wrong role? To the refusal page.
   Accepts one role, or several if a page is shared. */
function require_role(string ...$allowed): void
{
    require_login();
    if (!in_array(current_role(), $allowed, true)) {
        header('Location: denied.php');
        exit;
    }
}

/* ---------- where does each role land after login? ---------- */
function home_page_for(?string $role): string
{
    return match ($role) {
        'admin'      => 'admin.php',
        'pharmacist' => 'pharmacist.php',
        'customer'   => 'customer.php',
        default      => 'login.php',
    };
}

/* Older pages used home_for_role(); alias so nothing breaks. */
function home_for_role(?string $role): string
{
    return home_page_for($role);
}

/* ---------- which pages can each role open? ------------------
   Used to build the topbar nav, so a role only ever sees links
   to pages require_role() would actually let it through.
   ------------------------------------------------------------ */
function nav_links_for(?string $role): array
{
    return match ($role) {
        'admin'      => ['admin.php' => 'Dashboard', 'medicines.php' => 'Medicines', 'suppliers.php' => 'Suppliers', 'restock.php' => 'Restock', 'staff.php' => 'Users'],
        'pharmacist' => ['pharmacist.php' => 'Orders', 'payment.php' => 'Payments'],
        'customer'   => ['customer.php' => 'Order medicines'],
        default      => [],
    };
}

/* ---------- shared page top and bottom ----------------------
   The layout is a fixed sidebar (.shell > .sidebar + .content),
   not a top bar. Every page that calls page_header()/page_footer()
   gets the sidebar for free just by being wrapped in it here.
   ------------------------------------------------------------ */
function page_header(string $title): void
{
    $role = current_role();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> &mdash; Pharmacy</title>
        <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    </head>
    <body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brandline">Pharmacy Management System</div>
            <?php if ($role): ?>
                <nav>
                    <?php foreach (nav_links_for($role) as $href => $label): ?>
                        <a href="<?= h($href) ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                    <a href="profile.php">My profile</a>
                </nav>
                <div class="sidebar-foot">
                    <span class="who">
                        <?= h(current_user_name()) ?>
                        <em>(<?= h($role) ?>)</em>
                    </span>
                    <a href="logout.php" class="btn btn-light btn-small">Log out</a>
                </div>
            <?php endif; ?>
        </aside>
        <div class="content">
        <main>
            <h1><?= h($title) ?></h1>
    <?php
}

function page_footer(): void
{
    ?>
        </main>
        <footer class="foot">
            Pharmacy Management System &middot; Database project &middot; Group 06
        </footer>
        </div>
    </div>
    </body>
    </html>
    <?php
}

/* ---------- flash messages (survive one redirect) ----------- */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function show_flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="alert alert-' . h($f['type']) . '">'
       . h($f['message']) . '</div>';
}
