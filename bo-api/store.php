<?php
/**
 * BO Planning System – the team's file store (11/24): any signed-in user puts files here and every signed-in user can
 * download them. The planning never reads these files; they are only kept for the team.
 *   POST ?a=list                  every file (newest first): id, name, size, who added it, when
 *   POST ?a=add      multipart: files f0, f1…
 *   POST ?a=get      {id}         one file, as uploaded
 *   POST ?a=delete   {id}
 * Files are encrypted with the server key (AES-256-GCM) in the private folder, outside the website.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const STORE_MAX_FILE = 25 * 1048576;   // one file
const STORE_MAX_TOTAL = 500 * 1048576; // the whole store
const STORE_MAX_FILES = 300;

bo_guard('POST');
$me = bo_require_user();
$uid = (int)$me['id'];
$a = (string)($_GET['a'] ?? '');

function store_dir(): string {
    $d = bo_private_dir() . '/store';
    if (!is_dir($d) && !@mkdir($d, 0700, true)) bo_fail(500, 'The server could not open the file store.');
    return $d;
}
// The list of files, read and written under a lock so two people adding at once do not lose an entry.
function store_locked(callable $fn) {
    $lock = fopen(store_dir() . '/index.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) bo_fail(500, 'The file store is busy. Try again.');
    try {
        $f = store_dir() . '/index.bin';
        $list = is_file($f) ? json_decode(bo_open((string)file_get_contents($f), 'store|index'), true) : [];
        if (!is_array($list)) $list = [];
        [$list2, $out] = $fn($list);
        if ($list2 !== null) file_put_contents($f, bo_seal(json_encode(array_values($list2), JSON_UNESCAPED_UNICODE), 'store|index'));
        return $out;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function store_name(string $n): string {
    $n = preg_replace('/[\x00-\x1f\x7f<>:"\/\\\\|?*]+/u', '_', $n) ?? 'file';
    $n = trim(mb_substr($n, 0, 150, 'UTF-8'), " .");
    return $n === '' ? 'file' : $n;
}
function store_public(array $x): array {
    return ['id' => $x['id'], 'name' => $x['name'], 'size' => (int)$x['size'], 'by' => $x['by'], 'at' => (int)$x['at']];
}

switch ($a) {
case 'list': {
    $list = store_locked(fn($l) => [null, $l]);
    usort($list, fn($x, $y) => $y['at'] <=> $x['at']);
    bo_json(['ok' => true, 'files' => array_map('store_public', $list)]);
}
case 'add': {
    $sent = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($sent > 0 && empty($_FILES)) bo_fail(413, 'The files are larger than the server accepts in one go (limit ' . ini_get('post_max_size') . ').');
    $got = [];
    foreach ($_FILES as $field => $f) {
        if (!preg_match('/^f\d{1,3}$/', (string)$field) || !is_array($f)) continue;
        $name = store_name((string)($f['name'] ?? 'file'));
        if (in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)($f['size'] ?? 0) > STORE_MAX_FILE) bo_fail(413, "$name is larger than 25 MB.");
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) bo_fail(400, "$name did not arrive. Try again.");
        $got[] = [$name, (string)$f['tmp_name']];
    }
    if (!$got) bo_fail(400, 'No file to add.');
    $who = mb_substr((string)($me['name'] ?? $me['email'] ?? 'user'), 0, 80, 'UTF-8');
    $added = store_locked(function ($list) use ($got, $uid, $who) {
        $total = array_sum(array_map(fn($x) => (int)$x['size'], $list));
        $new = [];
        foreach ($got as [$name, $tmp]) {
            $bytes = (string)file_get_contents($tmp);
            if (count($list) + count($new) >= STORE_MAX_FILES) bo_fail(413, 'The file store is full (300 files). Remove old files first.');
            if ($total + strlen($bytes) > STORE_MAX_TOTAL) bo_fail(413, 'The file store is full (500 MB). Remove old files first.');
            $id = bin2hex(random_bytes(12));
            if (file_put_contents(store_dir() . "/$id.bin", bo_seal($bytes, "store|$id")) === false) bo_fail(500, 'The server could not store the file.');
            $total += strlen($bytes);
            $new[] = ['id' => $id, 'name' => $name, 'size' => strlen($bytes), 'by' => $who, 'byId' => $uid, 'at' => bo_now()];
        }
        return [array_merge($list, $new), $new];
    });
    bo_audit('store-add', $uid, $uid, implode(', ', array_map(fn($x) => $x['name'], $added)));
    bo_json(['ok' => true, 'files' => array_map('store_public', $added)]);
}
case 'get': {
    $id = (string)(bo_input()['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{24}$/', $id)) bo_fail(400, 'Which file?');
    $hit = store_locked(fn($l) => [null, array_values(array_filter($l, fn($x) => $x['id'] === $id))[0] ?? null]);
    $f = store_dir() . "/$id.bin";
    if (!$hit || !is_file($f)) bo_fail(404, 'That file is no longer in the store.');
    $bytes = bo_open((string)file_get_contents($f), "store|$id");
    bo_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
case 'delete': {
    $id = (string)(bo_input()['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{24}$/', $id)) bo_fail(400, 'Which file?');
    $gone = store_locked(function ($list) use ($id) {
        $hit = null; $keep = [];
        foreach ($list as $x) { if ($x['id'] === $id) $hit = $x; else $keep[] = $x; }
        if (!$hit) bo_fail(404, 'That file is no longer in the store.');
        @unlink(store_dir() . "/$id.bin");
        return [$keep, $hit];
    });
    bo_audit('store-delete', $uid, $uid, (string)$gone['name']);
    bo_json(['ok' => true]);
}
default:
    bo_fail(400, 'Unknown request.');
}
