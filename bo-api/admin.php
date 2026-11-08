<?php
/**
 * BO Planning System – admin: access requests and user accounts (admin only).
 *   POST ?a=list                         requests, users, recent activity
 *   POST ?a=approve   {id}               approve a request (the user is told by email)
 *   POST ?a=reject    {id}
 *   POST ?a=disable   {id} / ?a=enable {id}
 *   POST ?a=reset     {id, password}     temporary password; the user must change it at the next sign-in
 *   POST ?a=role      {id, role}         'user' or 'admin'
 *   POST ?a=delete    {id}               the account and all its saved work
 * Saved work stays readable after a password reset (it is encrypted with the server key, not the password).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_guard('POST');
$me = bo_require_admin();
$a = (string)($_GET['a'] ?? '');
$in = bo_input();
$db = bo_db();
$id = (int)($in['id'] ?? 0);

function target(PDO $db, int $id): array {
    $st = $db->prepare('SELECT * FROM bo_users WHERE id = ?'); $st->execute([$id]); $u = $st->fetch();
    if (!$u) bo_fail(404, 'That account no longer exists.');
    return $u;
}
function admins_left(PDO $db, int $exceptId): int {
    $st = $db->prepare("SELECT COUNT(*) FROM bo_users WHERE role = 'admin' AND status = 'approved' AND id <> ?"); $st->execute([$exceptId]);
    return (int)$st->fetchColumn();
}

switch ($a) {
case 'list': {
    $users = $db->query('SELECT id, email, name, company, note, role, status, must_change, created_at, decided_at, last_login FROM bo_users ORDER BY status = \'pending\' DESC, created_at DESC')->fetchAll();
    $saves = [];
    foreach ($db->query('SELECT user_id, COUNT(*) n, MAX(saved_at) last, SUM(bytes) bytes FROM bo_saves GROUP BY user_id')->fetchAll() as $r) $saves[(int)$r['user_id']] = $r;
    foreach ($users as &$u) {
        $s = $saves[(int)$u['id']] ?? null;
        $u['saves'] = $s ? (int)$s['n'] : 0; $u['lastSave'] = $s ? (int)$s['last'] : null; $u['bytes'] = $s ? (int)$s['bytes'] : 0;
        $u['id'] = (int)$u['id']; $u['must_change'] = (int)$u['must_change'];
    }
    $audit = $db->query('SELECT a.at, a.action, a.detail, u.email AS user, x.email AS actor FROM bo_audit a LEFT JOIN bo_users u ON u.id = a.user_id LEFT JOIN bo_users x ON x.id = a.actor_id ORDER BY a.id DESC LIMIT 60')->fetchAll();
    bo_json(['ok' => true, 'users' => $users, 'audit' => $audit, 'me' => (int)$me['id']]);
}
case 'approve': case 'reject': {
    $u = target($db, $id);
    $status = $a === 'approve' ? 'approved' : 'rejected';
    $db->prepare('UPDATE bo_users SET status = ?, decided_at = ?, decided_by = ? WHERE id = ?')->execute([$status, bo_now(), $me['id'], $id]);
    bo_audit($a, $id, (int)$me['id'], $u['email']);
    $mailed = $a === 'approve'
        ? bo_mail($u['email'], 'BO Planning System: your access is approved', "Hello {$u['name']},\n\nYour access to the BO Planning System is approved. Sign in with your email and the password you chose:\n" . bo_site_url() . "/bo-planning/\n")
        : bo_mail($u['email'], 'BO Planning System: access request', "Hello {$u['name']},\n\nYour access request for the BO Planning System was not approved. Reply to this email if you think this is a mistake.\n");
    bo_json(['ok' => true, 'mailed' => $mailed]);
}
case 'disable': case 'enable': {
    $u = target($db, $id);
    if ($a === 'disable' && $u['role'] === 'admin' && admins_left($db, $id) === 0) bo_fail(400, 'Keep at least one admin.');
    $db->prepare('UPDATE bo_users SET status = ? WHERE id = ?')->execute([$a === 'disable' ? 'disabled' : 'approved', $id]);
    if ($a === 'disable') $db->prepare('DELETE FROM bo_sessions WHERE user_id = ?')->execute([$id]);
    bo_audit($a, $id, (int)$me['id'], $u['email']);
    bo_json(['ok' => true]);
}
case 'reset': {
    $u = target($db, $id); $pass = (string)($in['password'] ?? '');
    if ($msg = bo_password_ok($pass)) bo_fail(400, $msg);
    $db->prepare('UPDATE bo_users SET pw_hash = ?, must_change = 1, failed = 0, locked_until = 0 WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    $db->prepare('DELETE FROM bo_sessions WHERE user_id = ?')->execute([$id]);
    bo_audit('password-reset', $id, (int)$me['id'], $u['email']);
    bo_json(['ok' => true]);
}
case 'role': {
    $u = target($db, $id); $role = ($in['role'] ?? '') === 'admin' ? 'admin' : 'user';
    if ($role === 'user' && $u['role'] === 'admin' && admins_left($db, $id) === 0) bo_fail(400, 'Keep at least one admin.');
    $db->prepare('UPDATE bo_users SET role = ? WHERE id = ?')->execute([$role, $id]);
    bo_audit('role-' . $role, $id, (int)$me['id'], $u['email']);
    bo_json(['ok' => true]);
}
case 'delete': {
    $u = target($db, $id);
    if ($id === (int)$me['id']) bo_fail(400, 'You cannot delete your own account.');
    if ($u['role'] === 'admin' && admins_left($db, $id) === 0) bo_fail(400, 'Keep at least one admin.');
    bo_rmdir(bo_private_dir() . "/saves/u$id");
    $db->prepare('DELETE FROM bo_saves WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM bo_sessions WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM bo_users WHERE id = ?')->execute([$id]);
    bo_audit('delete', null, (int)$me['id'], $u['email']);
    bo_json(['ok' => true]);
}
default:
    bo_fail(404, 'Unknown request.');
}
