<?php
/**
 * API Cantici con accordi + preset utente - ADI Cuneo
 * File: api-cantici.php (stessa cartella di api-musicisti.php)
 *
 * Dati salvati in data/ (creata da sola e protetta da .htaccess):
 *   data/songs/<numero>.json      -> un cantico con accordi
 *   data/presets/<hash-utente>.json -> preset dell'utente
 *
 * Sessioni: l'utente musicista e' in $_SESSION['user'] (impostato da api-musicisti.php),
 * l'admin in $_SESSION['admin_auth'].
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

define('DATA_DIR', __DIR__ . '/data');
define('SONGS_DIR', DATA_DIR . '/songs');
define('PRESETS_DIR', DATA_DIR . '/presets');

foreach ([DATA_DIR, SONGS_DIR, PRESETS_DIR] as $d) {
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
}
if (!file_exists(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess',
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}

// ---------- helper ----------
function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($msg, $code = 400) { out(['success' => false, 'message' => $msg], $code); }
function isAdmin() { return !empty($_SESSION['admin_auth']); }
function curUser() { return $_SESSION['user'] ?? null; }
function needAccess() { if (!curUser() && !isAdmin()) fail('Non autenticato', 401); }
function needAdmin() { if (!isAdmin()) fail('Serve l\'accesso amministratore', 401); }
function needUser() { if (!curUser()) fail('Accedi come musicista', 401); }

// Per le POST accetta solo JSON (blocca i form cross-site)
function body() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Metodo non consentito', 405);
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) fail('Content-Type non valido', 415);
    $j = json_decode(file_get_contents('php://input'), true);
    if (!is_array($j)) fail('JSON non valido');
    return $j;
}
function readJson($file, $default) {
    if (!is_file($file)) return $default;
    $j = json_decode(file_get_contents($file), true);
    return $j === null ? $default : $j;
}
function writeJson($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function numero($v) {
    $n = (int)$v;
    if ($n < 1 || $n > 99999) fail('Numero cantico non valido');
    return $n;
}
function validKey($k) {
    return is_string($k) && preg_match('/^(Do|Re|Mi|Fa|Sol|La|Si)(#|b)?-?$/', $k);
}
function songFile($n) { return SONGS_DIR . '/' . $n . '.json'; }
function presetFile() { return PRESETS_DIR . '/' . substr(hash('sha256', curUser()['username']), 0, 32) . '.json'; }
function normPresets($list) {
    foreach ($list as &$p) { if (empty($p['overrides'])) $p['overrides'] = new stdClass(); }
    return $list;
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'me':
        $u = curUser();
        out(['success' => true, 'user' => $u ? ['username' => $u['username'], 'name' => $u['name']] : null, 'admin' => isAdmin()]);

    case 'logout':
        unset($_SESSION['user']);
        out(['success' => true]);

    case 'list':
        needAccess();
        $songs = [];
        foreach (glob(SONGS_DIR . '/*.json') ?: [] as $f) {
            $s = readJson($f, null);
            if ($s) $songs[] = ['numero' => $s['numero'], 'titolo' => $s['titolo'], 'key' => $s['key'], 'accordi' => strpos($s['testo'], '[') !== false];
        }
        usort($songs, function ($a, $b) { return $a['numero'] <=> $b['numero']; });
        out(['success' => true, 'songs' => $songs]);

    case 'get':
        needAccess();
        $n = numero($_GET['numero'] ?? 0);
        $s = readJson(songFile($n), null);
        if (!$s) fail('Cantico non trovato', 404);
        out(['success' => true, 'song' => $s]);

    case 'save':
        needAdmin();
        $b = body();
        $n = numero($b['numero'] ?? 0);
        $titolo = trim((string)($b['titolo'] ?? ''));
        $testo = str_replace("\r", '', (string)($b['testo'] ?? ''));
        if ($titolo === '' || mb_strlen($titolo) > 120) fail('Titolo non valido');
        if (!validKey($b['key'] ?? '')) fail('Tonalita non valida');
        if (trim($testo) === '' || mb_strlen($testo) > 20000) fail('Testo vuoto o troppo lungo');
        $ok = writeJson(songFile($n), [
            'numero' => $n, 'titolo' => $titolo, 'key' => $b['key'], 'testo' => $testo, 'updatedAt' => date('c')
        ]);
        if (!$ok) fail('Impossibile scrivere il file (permessi cartella data/)', 500);
        out(['success' => true]);

    case 'delete':
        needAdmin();
        $b = body();
        $f = songFile(numero($b['numero'] ?? 0));
        if (is_file($f)) unlink($f);
        out(['success' => true]);

    case 'presets':
        needUser();
        $n = numero($_GET['numero'] ?? 0);
        $all = readJson(presetFile(), []);
        out(['success' => true, 'presets' => normPresets($all[(string)$n] ?? [])]);

    case 'preset-save':
        needUser();
        $b = body();
        $n = numero($b['numero'] ?? 0);
        $name = trim((string)($b['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 40) fail('Nome preset non valido');
        if (!validKey($b['key'] ?? '')) fail('Tonalita non valida');
        $ov = [];
        foreach ((is_array($b['overrides'] ?? null) ? $b['overrides'] : []) as $i => $c) {
            if (!ctype_digit((string)$i)) continue;
            if (!is_string($c) || !preg_match('/^[A-Za-z0-9#\/\-+() \x{00B0}]{1,24}$/u', $c)) fail('Accordo non valido');
            $ov[(string)$i] = $c;
        }
        if (count($ov) > 600) fail('Troppe modifiche');

        $all = readJson(presetFile(), []);
        $list = $all[(string)$n] ?? [];
        $id = (string)($b['id'] ?? '');
        $found = false;
        foreach ($list as &$p) {
            if ($id !== '' && $p['id'] === $id) {
                $p = ['id' => $id, 'name' => $name, 'key' => $b['key'], 'overrides' => $ov];
                $found = true;
            }
        }
        unset($p);
        if (!$found) {
            if (count($list) >= 30) fail('Limite di 30 preset per cantico raggiunto');
            $id = bin2hex(random_bytes(6));
            $list[] = ['id' => $id, 'name' => $name, 'key' => $b['key'], 'overrides' => $ov];
        }
        $all[(string)$n] = $list;
        if (!writeJson(presetFile(), $all)) fail('Impossibile salvare il preset', 500);
        out(['success' => true, 'id' => $id, 'presets' => normPresets($list)]);

    case 'preset-delete':
        needUser();
        $b = body();
        $n = numero($b['numero'] ?? 0);
        $id = (string)($b['id'] ?? '');
        $all = readJson(presetFile(), []);
        $list = array_values(array_filter($all[(string)$n] ?? [], function ($p) use ($id) { return $p['id'] !== $id; }));
        $all[(string)$n] = $list;
        writeJson(presetFile(), $all);
        out(['success' => true, 'presets' => normPresets($list)]);

    default:
        fail('Azione non valida');
}
