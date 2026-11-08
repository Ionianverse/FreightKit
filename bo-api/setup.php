<?php
/**
 * BO Planning System – one-time setup. Works only while bo-private/config.php does not exist.
 * 1. Opening this page creates the private folder and a setup code in bo-private/setup-code.txt
 *    (readable only in the hosting File Manager, so only the site owner can finish the setup).
 * 2. The owner enters that code, the MySQL database login and the first admin account.
 * 3. The page writes bo-private/config.php, makes the server key bo-private/data.key, creates the tables
 *    and the admin account, then deletes the setup code. After that this page only says "done".
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_headers();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');

$dir = bo_private_dir();
$codeFile = "$dir/setup-code.txt";
$testDb = getenv('BO_TEST_SQLITE') ?: '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = ''; $done = false;

function page(string $body): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
       . '<title>BO Planning System setup</title><style>body{font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:#f4f6f9;color:#0f172a;margin:0;padding:32px 16px}'
       . 'main{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px}h1{font-size:22px;margin:0 0 6px}h2{font-size:15px;margin:22px 0 6px}'
       . 'label{display:block;font-weight:600;font-size:13px;margin:10px 0 3px}input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}'
       . 'button{margin-top:18px;background:#1d4ed8;color:#fff;border:0;border-radius:8px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.note{font-size:13px;color:#475569}'
       . '.err{background:#fff1f2;border:1px solid #fda4af;color:#9f1239;padding:10px 12px;border-radius:8px}.ok{background:#ecfdf5;border:1px solid #6ee7b7;padding:10px 12px;border-radius:8px}code{background:#f1f5f9;padding:1px 5px;border-radius:4px}</style></head><body><main>'
       . $body . '</main></body></html>';
    exit;
}

if (is_file("$dir/config.php")) page('<h1>Setup is complete</h1><p>The BO Planning System is set up. Sign in at <a href="../bo-planning/">BO Planning</a>.</p>');

if (!is_dir($dir) && !@mkdir($dir, 0700, true)) page('<h1>Setup</h1><p class="err">The server could not create the private folder <code>' . $h($dir) . '</code>.</p>');
@file_put_contents("$dir/.htaccess", "Require all denied\n");
if (!is_file($codeFile)) file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(6))) . "\n");
@chmod($codeFile, 0600);
// A copy where the File Manager opens (public_html/bo-api/): the web server never serves it (bo-api/.htaccess).
$codeCopy = __DIR__ . '/setup-code.txt';
@file_put_contents($codeCopy, trim((string)file_get_contents($codeFile)) . PHP_EOL);
@chmod($codeCopy, 0600);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p = fn($k, $m = 200) => trim(mb_substr((string)($_POST[$k] ?? ''), 0, $m, 'UTF-8'));
    $code = strtoupper($p('code', 40));
    $expect = strtoupper(trim((string)file_get_contents($codeFile)));
    $email = strtolower($p('email', 190)); $notify = strtolower($p('notify', 190)); $from = strtolower($p('mail_from', 190));
    $pass = (string)($_POST['password'] ?? '');
    if ($code === '' || !hash_equals($expect, $code)) $msg = 'The setup code is not correct. Copy it from public_html/bo-api/setup-code.txt in the File Manager.';
    elseif ($p('name', 120) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $msg = 'Enter your name and email for the admin account.';
    elseif ($e = bo_password_ok($pass)) $msg = $e;
    elseif ($pass !== (string)($_POST['password2'] ?? '')) $msg = 'The two passwords are not the same.';
    elseif ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) $msg = 'The notification email is not valid.';
    elseif ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) $msg = 'The sender email is not valid.';
    else {
        $cfg = $testDb
            ? ['db_dsn' => 'sqlite:' . $testDb, 'db_user' => null, 'db_pass' => null]
            : ['db_dsn' => 'mysql:host=' . ($p('db_host', 120) ?: 'localhost') . ';dbname=' . $p('db_name', 120) . ';charset=utf8mb4', 'db_user' => $p('db_user', 120), 'db_pass' => (string)($_POST['db_pass'] ?? '')];
        try {
            $pdo = new PDO($cfg['db_dsn'], $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            bo_schema($pdo);
            if ((int)$pdo->query("SELECT COUNT(*) FROM bo_users WHERE role = 'admin'")->fetchColumn() > 0) throw new RuntimeException('This database already has an admin. Use a new, empty database.');
            $pdo->prepare("INSERT INTO bo_users (email, name, company, note, pw_hash, role, status, created_at, decided_at) VALUES (?,?,?,?,?,'admin','approved',?,?)")
                ->execute([$email, $p('name', 120), $p('company', 160), 'Set up the system', password_hash($pass, PASSWORD_DEFAULT), time(), time()]);
            if (!is_file("$dir/data.key")) { file_put_contents("$dir/data.key", base64_encode(random_bytes(32)) . "\n"); @chmod("$dir/data.key", 0600); }
            @mkdir("$dir/saves", 0700, true);
            $cfg += ['notify_email' => $notify ?: $email, 'mail_from' => $from, 'site_url' => '', 'created' => date('c')];
            file_put_contents("$dir/config.php", "<?php\n// BO Planning System settings. Private: keep this file outside the public web folder.\nreturn " . var_export($cfg, true) . ";\n");
            @chmod("$dir/config.php", 0600);
            @unlink($codeFile); @unlink($codeCopy);
            $done = true;
        } catch (Throwable $e) {
            $msg = ($e instanceof PDOException) ? 'Could not open the database: check the database name, user and password from hPanel. (' . $e->getMessage() . ')' : $e->getMessage();
        }
    }
}

if ($done) page('<h1>Setup is complete</h1><p class="ok">Your admin account is ready. Sign in at <a href="../bo-planning/">BO Planning</a> with your email and password.</p>'
    . '<p class="note">New access requests appear under “Users &amp; requests” after you sign in' . ($from ? ', and are emailed to you' : '') . '. The server key that encrypts saved work is in <code>bo-private/data.key</code>: download a copy of the bo-private folder and keep it safe; without it saved work cannot be read.</p>');

$v = fn($k, $d = '') => $h($_POST[$k] ?? $d);
$db = $testDb ? '<p class="note">Test mode: local test database.</p>' :
    '<h2>2. Database (from hPanel → Databases → MySQL)</h2>'
    . '<label for="db_name">Database name</label><input id="db_name" name="db_name" value="' . $v('db_name') . '" required placeholder="u202783376_bo">'
    . '<label for="db_user">Database user</label><input id="db_user" name="db_user" value="' . $v('db_user') . '" required>'
    . '<label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" required autocomplete="off">'
    . '<label for="db_host">Database host</label><input id="db_host" name="db_host" value="' . $v('db_host', 'localhost') . '">';
page('<h1>Set up the BO Planning System</h1><p class="note">Do this once. The passwords go straight to your server; nobody else sees them.</p>'
    . ($msg ? '<p class="err">' . $h($msg) . '</p>' : '')
    . '<form method="post" autocomplete="off">'
    . '<h2>1. Setup code</h2><p class="note">In hPanel open <strong>File Manager</strong> for freightkit.in. In <code>public_html</code> open the folder <code>bo-api</code>, then open <code>setup-code.txt</code> and copy the code. Visitors can never open this file; it is deleted when the setup is done.</p>'
    . '<label for="code">Setup code</label><input id="code" name="code" required>'
    . $db
    . '<h2>3. Your admin account</h2>'
    . '<label for="name">Your name</label><input id="name" name="name" value="' . $v('name') . '" required>'
    . '<label for="email">Your email (sign-in)</label><input id="email" name="email" type="email" value="' . $v('email') . '" required>'
    . '<label for="company">Company</label><input id="company" name="company" value="' . $v('company') . '">'
    . '<label for="password">Password (12+ characters, letters and numbers)</label><input id="password" name="password" type="password" required autocomplete="new-password">'
    . '<label for="password2">Password again</label><input id="password2" name="password2" type="password" required autocomplete="new-password">'
    . '<h2>4. Emails</h2><p class="note">New access requests are emailed to you; approved users get an email too. The sender must be a mailbox of this domain (create it in hPanel → Emails), e.g. <code>noreply@freightkit.in</code>. Leave empty to skip emails.</p>'
    . '<label for="notify">Send access requests to</label><input id="notify" name="notify" type="email" value="' . $v('notify') . '" placeholder="same as your email">'
    . '<label for="mail_from">Sender address</label><input id="mail_from" name="mail_from" type="email" value="' . $v('mail_from') . '" placeholder="noreply@freightkit.in">'
    . '<button type="submit">Finish setup</button></form>');
