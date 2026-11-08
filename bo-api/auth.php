<?php
/**
 * BO Planning System – sign-in, access requests, password change and the tool's code key.
 *   POST ?a=login      {email, password}
 *   POST ?a=logout
 *   POST ?a=me         the signed-in user (or 401)
 *   POST ?a=request    {name, email, company, note, password}: an access request the admin approves
 *   POST ?a=password   {current, next}
 *   POST ?a=appkey     key that opens the tool's code (approved users only)
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_guard('POST');
$a = (string)($_GET['a'] ?? '');
$in = bo_input();
$db = bo_db();

switch ($a) {
case 'login': {
    if (bo_too_many('login', 20, 900)) bo_fail(429, 'Too many attempts from this network. Wait 15 minutes and try again.');
    $email = strtolower(bo_str($in, 'email', 190)); $pass = (string)($in['password'] ?? '');
    $st = $db->prepare('SELECT * FROM bo_users WHERE email = ?'); $st->execute([$email]); $u = $st->fetch();
    $now = bo_now();
    if ($u && (int)$u['locked_until'] > $now) bo_fail(429, 'This account is locked for a few minutes after several wrong passwords. Try again later.');
    // The same check time whether or not the account exists.
    $okPass = $u ? password_verify($pass, $u['pw_hash']) : (password_hash($pass, PASSWORD_DEFAULT) && false);
    if (!$u || !$okPass) {
        bo_attempt('login');
        if ($u) {
            $failed = (int)$u['failed'] + 1;
            $db->prepare('UPDATE bo_users SET failed = ?, locked_until = ? WHERE id = ?')->execute([$failed >= 5 ? 0 : $failed, $failed >= 5 ? $now + 900 : 0, $u['id']]);
            bo_audit('login-failed', (int)$u['id']);
        }
        bo_fail(401, 'Email or password is incorrect.');
    }
    if ($u['status'] === 'pending') bo_fail(403, 'Your access request is waiting for the admin’s approval.');
    if ($u['status'] === 'rejected') bo_fail(403, 'Your access request was not approved.');
    if ($u['status'] !== 'approved') bo_fail(403, 'This account is disabled. Ask the admin.');
    if (password_needs_rehash($u['pw_hash'], PASSWORD_DEFAULT)) $db->prepare('UPDATE bo_users SET pw_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    $db->prepare('UPDATE bo_users SET failed = 0, locked_until = 0, last_login = ? WHERE id = ?')->execute([$now, $u['id']]);
    bo_start_session((int)$u['id']);
    bo_audit('login', (int)$u['id'], (int)$u['id']);
    bo_json(['ok' => true, 'user' => bo_public_user($u), 'idleSeconds' => BO_IDLE_SECONDS]);
}
case 'logout': {
    $u = bo_user(false);
    bo_end_session();
    if ($u) bo_audit('logout', (int)$u['id'], (int)$u['id']);
    bo_json(['ok' => true]);
}
case 'me': {
    $u = bo_require_user();
    bo_json(['ok' => true, 'user' => bo_public_user($u), 'idleSeconds' => BO_IDLE_SECONDS]);
}
case 'request': {
    if (bo_too_many('request', 5, 3600)) bo_fail(429, 'Too many requests from this network. Try again in an hour.');
    $name = bo_str($in, 'name', 120); $email = strtolower(bo_str($in, 'email', 190));
    $company = bo_str($in, 'company', 160); $note = bo_str($in, 'note', 600); $pass = (string)($in['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) bo_fail(400, 'Enter your name and a valid work email.');
    if ($msg = bo_password_ok($pass)) bo_fail(400, $msg);
    bo_attempt('request');
    $st = $db->prepare('SELECT id, status FROM bo_users WHERE email = ?'); $st->execute([$email]); $old = $st->fetch();
    // The same answer whatever exists, so nobody can test which emails have accounts.
    $answer = ['ok' => true, 'message' => 'Thank you. Your request was sent to the admin. You can sign in once it is approved.'];
    if ($old) {
        if ($old['status'] === 'rejected') {
            $db->prepare("UPDATE bo_users SET name = ?, company = ?, note = ?, pw_hash = ?, status = 'pending', created_at = ?, decided_at = NULL, decided_by = NULL WHERE id = ?")
                ->execute([$name, $company, $note, password_hash($pass, PASSWORD_DEFAULT), bo_now(), $old['id']]);
            $id = (int)$old['id'];
        } else bo_json($answer);
    } else {
        $db->prepare("INSERT INTO bo_users (email, name, company, note, pw_hash, role, status, created_at) VALUES (?,?,?,?,?,'user','pending',?)")
            ->execute([$email, $name, $company, $note, password_hash($pass, PASSWORD_DEFAULT), bo_now()]);
        $id = (int)$db->lastInsertId();
    }
    bo_audit('request', $id, null, $email);
    $c = bo_config();
    $notify = $c['notify_email'] ?? '';
    if ($notify) bo_mail($notify, 'BO Planning System: new access request',
        "A new access request is waiting for your approval.\n\nName: $name\nEmail: $email\nCompany: $company\nWhy: $note\n\nApprove or reject it: sign in at " . bo_site_url() . "/bo-planning/ and open “Users & requests”.\n", $email);
    bo_json($answer);
}
case 'password': {
    $u = bo_require_user();
    $cur = (string)($in['current'] ?? ''); $next = (string)($in['next'] ?? '');
    if (!password_verify($cur, $u['pw_hash'])) bo_fail(400, 'The current password is not correct.');
    if ($msg = bo_password_ok($next)) bo_fail(400, $msg);
    if (hash_equals($cur, $next)) bo_fail(400, 'Choose a password different from the current one.');
    $db->prepare('UPDATE bo_users SET pw_hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($next, PASSWORD_DEFAULT), $u['id']]);
    // Other browsers signed in with the old password are signed out.
    $t = $_COOKIE[bo_cookie_name()] ?? '';
    $db->prepare('DELETE FROM bo_sessions WHERE user_id = ? AND token_hash <> ?')->execute([$u['id'], hash('sha256', $t)]);
    bo_audit('password-changed', (int)$u['id'], (int)$u['id']);
    bo_json(['ok' => true]);
}
case 'appkey': {
    $u = bo_require_user();
    if ((int)$u['must_change']) bo_fail(403, 'Change your password first.', ['mustChange' => true]);
    $f = __DIR__ . '/.appkey.php';
    if (!is_file($f)) bo_fail(503, 'The tool is not installed on this server yet.');
    bo_json(['ok' => true, 'key' => (string)(require $f)]);
}
default:
    bo_fail(404, 'Unknown request.');
}
