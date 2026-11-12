<?php
/**
 * BO Planning System – team rules: one set of rules and changes for every user (11/12).
 *   POST ?a=get                         the team rules, or null when nobody has saved any yet
 *   POST ?a=save   multipart: rules (JSON text), base (savedAt the user started from), note
 * Any approved user can change them; the last 30 versions are kept for recovery. The rules are encrypted with the
 * server key before they are written (AES-256-GCM), like saved work.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_guard('POST');
$me = bo_require_user();
$uid = (int)$me['id'];
$a = (string)($_GET['a'] ?? '');
const BO_TEAM_KEEP = 30;

function team_dir(): string {
    $d = bo_private_dir() . '/team';
    if (!is_dir($d) && !@mkdir($d, 0700, true)) bo_fail(500, 'The server could not store the team rules.');
    return $d;
}
function team_read(): ?array {
    $f = team_dir() . '/rules.bin';
    if (!is_file($f)) return null;
    $doc = json_decode(bo_open((string)file_get_contents($f), 'team|rules'), true);
    return is_array($doc) ? $doc : null;
}

switch ($a) {
case 'get': {
    bo_json(['ok' => true, 'team' => team_read()]);
}
case 'save': {
    $text = (string)($_POST['rules'] ?? '');
    if (strlen($text) > 4 * 1048576) bo_fail(413, 'The rules are too large to save.');
    $rules = json_decode($text, true);
    if (!is_array($rules) || ($rules['schema'] ?? '') !== 'freightkit-bo-rules' || !isset($rules['routes'])) bo_fail(400, 'These are not BO rules.');
    $base = (int)($_POST['base'] ?? 0);
    $cur = team_read();
    // Someone else saved after this user loaded the rules: send theirs back instead of overwriting them.
    if ($cur && (int)$cur['savedAt'] > $base && (int)($cur['byId'] ?? 0) !== $uid) bo_fail(409, 'Another planner changed the team rules first.', ['team' => $cur]);
    $doc = ['rules' => $rules, 'savedAt' => bo_now(), 'by' => (string)$me['name'], 'byId' => $uid, 'note' => mb_substr(trim(str_replace("\0", '', (string)($_POST['note'] ?? ''))), 0, 200, 'UTF-8')];
    $dir = team_dir();
    if ($cur && is_file("$dir/rules.bin")) @copy("$dir/rules.bin", "$dir/rules-" . (int)$cur['savedAt'] . '.bin');
    if (file_put_contents("$dir/rules.bin", bo_seal(json_encode($doc, JSON_UNESCAPED_UNICODE), 'team|rules'), LOCK_EX) === false) bo_fail(500, 'The server could not store the team rules.');
    $old = glob("$dir/rules-*.bin") ?: [];
    sort($old);
    while (count($old) > BO_TEAM_KEEP) @unlink(array_shift($old));
    bo_audit('team-rules', $uid, $uid, $doc['note']);
    bo_json(['ok' => true, 'team' => ['savedAt' => $doc['savedAt'], 'by' => $doc['by'], 'byId' => $uid, 'note' => $doc['note']]]);
}
default:
    bo_fail(404, 'Unknown request.');
}
