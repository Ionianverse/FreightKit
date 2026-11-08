<?php
/**
 * BO Planning System – a user's saved work (each user sees only their own).
 *   POST ?a=save     multipart: state (JSON text) + files f0, f1… (the day's input files)
 *   POST ?a=list     the user's saves (newest first)
 *   POST ?a=state    {id}       the saved state (rules, edits, remarks…)
 *   POST ?a=file     {id, n}    one saved file, as uploaded
 *   POST ?a=latest              the newest save's state, or null
 *   POST ?a=carry    {before}   the remarks of the newest save with a plan date before this date (yesterday)
 *   POST ?a=delete   {id}
 * Every file and state is encrypted with the server key before it is written (AES-256-GCM).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_guard('POST');
$me = bo_require_user();
$uid = (int)$me['id'];
$a = (string)($_GET['a'] ?? '');
$db = bo_db();

function own_save(PDO $db, int $uid, int $id): array {
    $st = $db->prepare('SELECT * FROM bo_saves WHERE id = ? AND user_id = ?'); $st->execute([$id, $uid]); $s = $st->fetch();
    if (!$s) bo_fail(404, 'That save no longer exists.');
    return $s;
}
function save_meta(array $s): array {
    return ['id' => (int)$s['id'], 'planDate' => $s['plan_date'], 'savedAt' => (int)$s['saved_at'], 'bytes' => (int)$s['bytes'], 'files' => json_decode($s['files'], true) ?: [], 'note' => $s['note']];
}
function read_state(int $uid, array $s): string {
    $f = bo_save_dir($uid, (int)$s['id']) . '/state.bin';
    if (!is_file($f)) bo_fail(404, 'The saved work is missing on the server.');
    return bo_open((string)file_get_contents($f), "u$uid|s{$s['id']}|state");
}
function prune(PDO $db, int $uid): void {
    $rows = $db->prepare('SELECT id, plan_date FROM bo_saves WHERE user_id = ? ORDER BY plan_date DESC, saved_at DESC, id DESC'); $rows->execute([$uid]);
    $perDay = []; $drop = [];
    foreach ($rows->fetchAll() as $r) {
        $perDay[$r['plan_date']] = ($perDay[$r['plan_date']] ?? 0) + 1;
        if ($perDay[$r['plan_date']] > BO_KEEP_PER_DAY || count($perDay) > BO_KEEP_DAYS) $drop[] = (int)$r['id'];
    }
    foreach ($drop as $id) {
        bo_rmdir(bo_save_dir($uid, $id));
        $db->prepare('DELETE FROM bo_saves WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    }
}

switch ($a) {
case 'save': {
    $stateText = (string)($_POST['state'] ?? '');
    $state = json_decode($stateText, true);
    if (!is_array($state)) bo_fail(400, 'Nothing to save.');
    $planDate = (string)($state['planDate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate)) bo_fail(400, 'The plan date is missing.');
    $files = []; $total = strlen($stateText);
    foreach ($_FILES as $field => $f) {
        if (!preg_match('/^f(\d{1,3})$/', $field, $m) || !is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) continue;
        $total += (int)$f['size'];
        $files[(int)$m[1]] = $f;
    }
    if ($total > BO_MAX_SAVE_BYTES) bo_fail(413, 'These files are too large to save together (over 60 MB).');
    $list = is_array($state['files'] ?? null) ? $state['files'] : [];
    if (count($list) !== count($files)) bo_fail(400, 'Some files did not arrive. Try again.');
    $db->prepare('INSERT INTO bo_saves (user_id, plan_date, saved_at, files, bytes, note) VALUES (?,?,?,?,?,?)')
        ->execute([$uid, $planDate, bo_now(), '[]', $total, mb_substr((string)($state['note'] ?? ''), 0, 200, 'UTF-8')]);
    $sid = (int)$db->lastInsertId();
    $dir = bo_save_dir($uid, $sid);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) bo_fail(500, 'The server could not store the saved work.');
    $meta = [];
    try {
        foreach ($list as $n => $info) {
            if (!isset($files[$n])) throw new RuntimeException('missing file');
            $bytes = (string)file_get_contents($files[$n]['tmp_name']);
            file_put_contents("$dir/f$n.bin", bo_seal($bytes, "u$uid|s$sid|f$n"));
            $meta[] = ['n' => $n, 'box' => mb_substr((string)($info['box'] ?? ''), 0, 20, 'UTF-8'), 'name' => mb_substr((string)($info['name'] ?? "file $n"), 0, 200, 'UTF-8'), 'size' => strlen($bytes)];
        }
        file_put_contents("$dir/state.bin", bo_seal($stateText, "u$uid|s$sid|state"));
    } catch (Throwable $e) {
        bo_rmdir($dir); $db->prepare('DELETE FROM bo_saves WHERE id = ?')->execute([$sid]);
        bo_fail(500, 'The server could not store the saved work.');
    }
    $db->prepare('UPDATE bo_saves SET files = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $sid]);
    prune($db, $uid);
    bo_audit('save', $uid, $uid, "$planDate, " . count($meta) . ' files');
    $st = $db->prepare('SELECT * FROM bo_saves WHERE id = ?'); $st->execute([$sid]);
    bo_json(['ok' => true, 'save' => save_meta($st->fetch())]);
}
case 'list': {
    $st = $db->prepare('SELECT * FROM bo_saves WHERE user_id = ? ORDER BY saved_at DESC, id DESC'); $st->execute([$uid]);
    bo_json(['ok' => true, 'saves' => array_map('save_meta', $st->fetchAll())]);
}
case 'state': {
    $in = bo_input(); $s = own_save($db, $uid, (int)($in['id'] ?? 0));
    bo_json(['ok' => true, 'save' => save_meta($s), 'state' => json_decode(read_state($uid, $s), true)]);
}
case 'latest': {
    $st = $db->prepare('SELECT * FROM bo_saves WHERE user_id = ? ORDER BY saved_at DESC, id DESC LIMIT 1'); $st->execute([$uid]); $s = $st->fetch();
    bo_json(['ok' => true, 'save' => $s ? save_meta($s) : null, 'state' => $s ? json_decode(read_state($uid, $s), true) : null]);
}
case 'carry': {
    $in = bo_input(); $before = (string)($in['before'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) bo_fail(400, 'Bad date.');
    $st = $db->prepare('SELECT * FROM bo_saves WHERE user_id = ? AND plan_date < ? ORDER BY plan_date DESC, saved_at DESC LIMIT 1'); $st->execute([$uid, $before]); $s = $st->fetch();
    if (!$s) bo_json(['ok' => true, 'save' => null, 'day' => null]);
    $state = json_decode(read_state($uid, $s), true);
    bo_json(['ok' => true, 'save' => save_meta($s), 'day' => $state['day'] ?? null]);
}
case 'file': {
    $in = bo_input(); $s = own_save($db, $uid, (int)($in['id'] ?? 0)); $n = (int)($in['n'] ?? -1);
    $f = bo_save_dir($uid, (int)$s['id']) . "/f$n.bin";
    if ($n < 0 || !is_file($f)) bo_fail(404, 'That saved file is missing.');
    $bytes = bo_open((string)file_get_contents($f), "u$uid|s{$s['id']}|f$n");
    bo_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
case 'delete': {
    $in = bo_input(); $s = own_save($db, $uid, (int)($in['id'] ?? 0));
    bo_rmdir(bo_save_dir($uid, (int)$s['id']));
    $db->prepare('DELETE FROM bo_saves WHERE id = ? AND user_id = ?')->execute([$s['id'], $uid]);
    bo_audit('save-deleted', $uid, $uid, $s['plan_date']);
    bo_json(['ok' => true]);
}
default:
    bo_fail(404, 'Unknown request.');
}
