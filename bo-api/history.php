<?php
/**
 * BO Planning System – the history of one order line, from the signed-in user's own saved work (11/13).
 *   POST ?a=line  {order, line}  one entry per plan date (the newest save of each date, last 60 dates):
 *                                item, remark, action, special instruction, WH, BO qty, ETA, hot.
 * Only this user's saves are read; nothing is written.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

bo_guard('POST');
$me = bo_require_user();
$uid = (int)$me['id'];
$db = bo_db();
$in = bo_input();

// Order and line numbers compared without leading zeros or a trailing ".0".
function hist_key($v): string {
    $s = trim((string)$v);
    $s = (string)preg_replace('/\.0+$/', '', $s);
    $t = ltrim($s, '0');
    return $t === '' ? $s : $t;
}
$order = hist_key($in['order'] ?? '');
$line = hist_key($in['line'] ?? '');
if ($order === '') bo_fail(400, 'Which order line?');

$st = $db->prepare('SELECT * FROM bo_saves WHERE user_id = ? ORDER BY plan_date DESC, saved_at DESC, id DESC');
$st->execute([$uid]);
$seen = [];
$out = [];
foreach ($st->fetchAll() as $s) {
    if (isset($seen[$s['plan_date']])) continue;
    $seen[$s['plan_date']] = true;
    if (count($seen) > 60) break;
    $f = bo_save_dir($uid, (int)$s['id']) . '/state.bin';
    if (!is_file($f)) continue;
    $blob = (string)file_get_contents($f);
    if (substr($blob, 0, 3) !== 'BO1') continue;
    $state = json_decode(bo_open($blob, "u$uid|s{$s['id']}|state"), true);
    $lines = (is_array($state) && is_array($state['day'] ?? null) && is_array($state['day']['lines'] ?? null)) ? $state['day']['lines'] : [];
    foreach ($lines as $a) {
        if (!is_array($a) || hist_key($a[0] ?? '') !== $order) continue;
        if ($line !== '' && hist_key($a[1] ?? '') !== $line) continue;
        $out[] = [
            'planDate' => $s['plan_date'], 'item' => (string)($a[2] ?? ''), 'remark' => (string)($a[3] ?? ''), 'action' => (string)($a[4] ?? ''),
            'special' => (string)($a[5] ?? ''), 'wh' => (string)($a[15] ?? ''), 'bo' => $a[16] ?? '', 'eta' => $a[17] ?? null, 'hot' => !empty($a[13])
        ];
    }
}
bo_json(['ok' => true, 'history' => $out]);
