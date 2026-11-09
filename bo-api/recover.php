<?php
/**
 * BO Planning System – reset an admin password, for the site owner when no admin can sign in.
 * 1. Opening this page makes a one-time code, valid 30 minutes, in public_html/bo-api/recover-code.txt
 *    (readable only in the hosting File Manager; the web server never serves it, see bo-api/.htaccess).
 * 2. The owner types that code, the admin account's email and a new password.
 * 3. The password is replaced, the account is unlocked, its sessions end and the code is deleted.
 * Saved work stays readable: it is encrypted with the server key, not with the password.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_headers();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

function page(string $body): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
       . '<title>BO Planning System: reset admin password</title><style>body{font:15px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:#f4f6f9;color:#0f172a;margin:0;padding:32px 16px}'
       . 'main{max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px}h1{font-size:22px;margin:0 0 6px}'
       . 'label{display:block;font-weight:600;font-size:13px;margin:12px 0 3px}input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}'
       . 'button{margin-top:18px;background:#1d4ed8;color:#fff;border:0;border-radius:8px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer}.note{font-size:13px;color:#475569}'
       . '.err{background:#fff1f2;border:1px solid #fda4af;color:#9f1239;padding:10px 12px;border-radius:8px}.ok{background:#ecfdf5;border:1px solid #6ee7b7;padding:10px 12px;border-radius:8px}'
       . 'code{background:#f1f5f9;padding:1px 5px;border-radius:4px}ol{padding-left:20px}</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}

if (!bo_config()) page('<h1>Not set up yet</h1><p>Open the <a href="setup.php">setup page</a> first.</p>');

$dir = bo_private_dir();
$codeFile = "$dir/recover-code.txt";            // the real code and its expiry
$copy = __DIR__ . '/recover-code.txt';           // the code only, where the File Manager opens
$ttl = 1800;
$t = is_file($codeFile) ? trim((string)file_get_contents($codeFile)) : '';
[$code, $exp] = array_pad(explode('|', $t), 2, '0');
$exp = (int)$exp;
if ($code === '' || $exp < bo_now()) {
    $code = strtoupper(bin2hex(random_bytes(6))); $exp = bo_now() + $ttl;
    file_put_contents($codeFile, "$code|$exp\n", LOCK_EX); @chmod($codeFile, 0600);
    file_put_contents($copy, $code . PHP_EOL, LOCK_EX); @chmod($copy, 0600);
}

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $given = strtoupper(trim(substr((string)($_POST['code'] ?? ''), 0, 40)));
    $email = strtolower(trim(substr((string)($_POST['email'] ?? ''), 0, 190)));
    $pass = (string)($_POST['password'] ?? '');
    if (bo_too_many('recover', 10, 3600)) {
        $msg = 'Too many tries from this network. Wait an hour, then open this page again.';
    } elseif ($given === '' || !hash_equals($code, $given)) {
        bo_attempt('recover');
        $msg = 'The code is not correct. Copy it from public_html/bo-api/recover-code.txt in the File Manager (a new code is made every 30 minutes).';
    } else {
        $db = bo_db();
        $admins = $db->query("SELECT email FROM bo_users WHERE role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $st = $db->prepare('SELECT * FROM bo_users WHERE email = ?'); $st->execute([$email]); $u = $st->fetch();
        // Only someone with the File Manager code gets here, so the admin emails can be shown.
        if (!$u || $u['role'] !== 'admin') $msg = 'No admin account has this email. Admin account' . (count($admins) === 1 ? '' : 's') . ': ' . implode(', ', $admins) . '.';
        elseif ($e = bo_password_ok($pass)) $msg = $e;
        elseif ($pass !== (string)($_POST['password2'] ?? '')) $msg = 'The two passwords are not the same.';
        else {
            $db->prepare("UPDATE bo_users SET pw_hash = ?, must_change = 0, failed = 0, locked_until = 0, status = 'approved' WHERE id = ?")
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            $db->prepare('DELETE FROM bo_sessions WHERE user_id = ?')->execute([$u['id']]);
            bo_audit('password-recovered', (int)$u['id'], null, $email);
            @unlink($codeFile); @unlink($copy);
            page('<h1>Password changed</h1><p class="ok">The password of <strong>' . $h($email) . '</strong> is changed and the account is unlocked. Your saved work is kept.</p><p><a href="../bo-planning/">Sign in to BO Planning</a></p>');
        }
    }
}

$left = max(1, (int)ceil(($exp - bo_now()) / 60));
page('<h1>Reset the admin password</h1>'
   . '<p class="note">For the site owner, when the admin cannot sign in.</p>'
   . '<ol class="note"><li>Open hPanel → <strong>File Manager</strong> → <code>public_html/bo-api/recover-code.txt</code> and copy the code (valid ' . $left . ' more minute' . ($left === 1 ? '' : 's') . ').</li>'
   . '<li>Type it below with your admin email and a new password (12+ characters, letters and numbers).</li></ol>'
   . ($msg ? '<p class="err">' . $h($msg) . '</p>' : '')
   . '<form method="post" autocomplete="off"><label for="code">Code from the File Manager</label><input id="code" name="code" required autocomplete="off" spellcheck="false">'
   . '<label for="email">Admin email (the one used to sign in)</label><input id="email" name="email" type="email" required value="' . $h($_POST['email'] ?? '') . '">'
   . '<label for="password">New password</label><input id="password" name="password" type="password" required autocomplete="new-password">'
   . '<label for="password2">New password again</label><input id="password2" name="password2" type="password" required autocomplete="new-password">'
   . '<button type="submit">Change password</button></form>');
