<?php
// BayanAlert JSON API. Single endpoint: api/index.php?a=<action>
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/config.php';
function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $code = 400) { out(['error' => $m], $code); }
try {
    $db = new PDO("mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4", $cfg['user'], $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { fail('Cannot connect to the database. Check api/config.php.', 500); }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? 'state';
$PU = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6'];
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }             // escape on output
function cl($s, $n = 2000) { return mb_substr(html_entity_decode(trim((string)$s), ENT_QUOTES, 'UTF-8'), 0, $n); } // clean on input
function me() {
    global $db;
    if (empty($_SESSION['uid'])) return null;
    $s = $db->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$_SESSION['uid']]);
    return $s->fetch() ?: null;
}
function rep($r, $cm) {
    $t = strtotime($r['created_at']);
    return ['id' => $r['ref'], 't' => h($r['title']), 'c' => h($r['category']), 'l' => h($r['purok']), 's' => $r['status'],
        'd' => date('M d', $t), 'ts' => date('M j, Y · g:i A', $t), 'ds' => h($r['description']), 'ph' => (int)$r['photos'],
        'nw' => (int)$r['is_new'], 'o' => h($r['owner_email'] ?? 'guest'), 'cm' => $cm];
}
function state($u) {
    global $db;
    $pf = ['sms' => 1, 'email' => 1, 'push' => 0, 'rs' => 1, 'ann' => 0];
    if (!$u) return ['me' => 0, 'role' => 'res', 'reports' => [], 'pf' => $pf];
    if ($u['prefs']) $pf = json_decode($u['prefs'], true) ?: $pf;
    $q = 'SELECT r.*, u.email owner_email FROM reports r LEFT JOIN users u ON u.id=r.user_id'
        . ($u['role'] === 'admin' ? '' : ' WHERE r.user_id=' . (int)$u['id']) . ' ORDER BY r.id DESC';
    $rs = $db->query($q)->fetchAll(); $cm = [];
    if ($rs) {
        $ids = implode(',', array_map('intval', array_column($rs, 'id')));
        foreach ($db->query("SELECT * FROM comments WHERE report_id IN ($ids) ORDER BY id")->fetchAll() as $x)
            $cm[$x['report_id']][] = ['by' => h($x['author']), 'm' => h($x['message']), 'tm' => date('M j · g:i A', strtotime($x['created_at']))];
    }
    return ['me' => 1, 'role' => $u['role'] === 'admin' ? 'adm' : 'res',
        'acct' => ['name' => h($u['name']), 'email' => h($u['email']), 'mob' => h($u['mobile']), 'pu' => h($u['purok']), 'pc' => h($u['postal'])],
        'pf' => $pf, 'reports' => array_map(fn($r) => rep($r, $cm[$r['id']] ?? []), $rs)];
}

$u = me();
if ($a === 'state') out(state($u));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
$admin = $u && $u['role'] === 'admin';

switch ($a) {
case 'login':
    $s = $db->prepare('SELECT * FROM users WHERE email=?'); $s->execute([strtolower(trim($in['email'] ?? ''))]); $r = $s->fetch();
    if (!$r || !password_verify((string)($in['password'] ?? ''), $r['password_hash'])) fail('Incorrect email or password.', 401);
    session_regenerate_id(true); $_SESSION['uid'] = (int)$r['id']; out(['ok' => 1]);

case 'register':
    $n = cl($in['name'] ?? '', 120); $e = strtolower(cl($in['email'] ?? '', 160)); $p = (string)($in['password'] ?? ''); $pu = $in['purok'] ?? '';
    if ($n === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Enter your name and a valid email.');
    if (strlen($p) < 6) fail('Password must be at least 6 characters.');
    if (!in_array($pu, $PU, true)) fail('Select your purok.');
    $s = $db->prepare('SELECT 1 FROM users WHERE email=?'); $s->execute([$e]);
    if ($s->fetch()) fail('That email is already registered.', 409);
    $db->prepare('INSERT INTO users(name,email,password_hash,mobile,purok) VALUES(?,?,?,?,?)')
       ->execute([$n, $e, password_hash($p, PASSWORD_DEFAULT), cl($in['mobile'] ?? '', 30), $pu]);
    session_regenerate_id(true); $_SESSION['uid'] = (int)$db->lastInsertId(); out(['ok' => 1]);

case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => 1]);

case 'report': // residents file here; guests may only send emergency (DIS) reports
    $pre = substr((string)($in['id'] ?? ''), 0, 3) === 'DIS' ? 'DIS' : 'CMP';
    if ($admin) fail('Admins cannot file reports.', 403);
    if (!$u && $pre !== 'DIS') fail('Please sign in to file a complaint.', 401);
    $t = cl($in['t'] ?? '', 200); $c = cl($in['c'] ?? '', 60); $d = cl($in['ds'] ?? '', 3000); $l = $in['l'] ?? '';
    $st = ($pre === 'DIS' && ($in['s'] ?? '') === 'urgent') ? 'urgent' : 'pending';
    if ($t === '' || $c === '' || $d === '' || !in_array($l, $PU, true)) fail('Please complete all required fields.');
    $db->prepare('INSERT INTO reports(user_id,title,category,purok,description,status,photos) VALUES(?,?,?,?,?,?,?)')
       ->execute([$u['id'] ?? null, $t, $c, $l, $d, $st, min(10, max(0, (int)($in['ph'] ?? 0)))]);
    $id = (int)$db->lastInsertId();
    $db->prepare('UPDATE reports SET ref=? WHERE id=?')->execute([sprintf('%s-%s-%04d', $pre, date('Y'), $id), $id]);
    $r = $db->query('SELECT r.*, u.email owner_email FROM reports r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=' . $id)->fetch();
    out(['report' => rep($r, [])]);

case 'status':
    if (!$admin) fail('Admins only.', 403);
    $s = $in['s'] ?? '';
    if (!in_array($s, ['pending', 'progress', 'urgent', 'resolved'], true)) fail('Invalid status.');
    $db->prepare('UPDATE reports SET status=?, is_new=0 WHERE ref=?')->execute([$s, $in['id'] ?? '']); out(['ok' => 1]);

case 'seen':
    if (!$admin) fail('Admins only.', 403);
    $db->prepare('UPDATE reports SET is_new=0 WHERE ref=?')->execute([$in['id'] ?? '']); out(['ok' => 1]);

case 'comment':
    if (!$u) fail('Please sign in.', 401);
    $s = $db->prepare('SELECT id,user_id FROM reports WHERE ref=?'); $s->execute([$in['id'] ?? '']); $r = $s->fetch();
    if (!$r || (!$admin && (int)$r['user_id'] !== (int)$u['id'])) fail('Report not found.', 404);
    $m = cl($in['m'] ?? '', 1000); if ($m === '') fail('Write a message first.');
    $db->prepare('INSERT INTO comments(report_id,author,message) VALUES(?,?,?)')->execute([$r['id'], $u['name'], $m]); out(['ok' => 1]);

case 'profile':
    if (!$u) fail('Please sign in.', 401);
    $n = cl($in['name'] ?? '', 120); $e = strtolower(cl($in['email'] ?? '', 160)); $pu = $in['purok'] ?? '';
    if ($n === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || !in_array($pu, $PU, true)) fail('Check your name, email and purok.');
    $s = $db->prepare('SELECT 1 FROM users WHERE email=? AND id<>?'); $s->execute([$e, $u['id']]);
    if ($s->fetch()) fail('That email is already in use.', 409);
    $db->prepare('UPDATE users SET name=?,email=?,mobile=?,purok=?,postal=? WHERE id=?')
       ->execute([$n, $e, cl($in['mobile'] ?? '', 30), $pu, cl($in['postal'] ?? '', 10), $u['id']]); out(['ok' => 1]);

case 'password':
    if (!$u) fail('Please sign in.', 401);
    if (!password_verify((string)($in['old'] ?? ''), $u['password_hash'])) fail('Current password is incorrect.');
    if (strlen((string)($in['new'] ?? '')) < 6) fail('New password must be at least 6 characters.');
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($in['new'], PASSWORD_DEFAULT), $u['id']]); out(['ok' => 1]);

case 'prefs':
    if (!$u) fail('Please sign in.', 401);
    $p = []; foreach (['sms', 'email', 'push', 'rs', 'ann'] as $k) $p[$k] = empty($in[$k]) ? 0 : 1;
    $db->prepare('UPDATE users SET prefs=? WHERE id=?')->execute([json_encode($p), $u['id']]); out(['ok' => 1]);

default: fail('Unknown action.', 404);
}
