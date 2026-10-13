<?php
/**
 * ADI Cuneo - Gestione eventi
 *
 * Utenti:
 *   superadmin -> gestisce gli eventi + reimposta la password del Pastore
 *   pastore    -> aggiunge, modifica ed elimina gli eventi
 *
 * PRIMA DI CARICARE SU FTP: cambia SETUP_KEY qui sotto.
 * Al primo accesso la pagina chiede la chiave e fa scegliere le due password.
 */

// ===================== CONFIGURAZIONE =====================
define('SETUP_KEY', 'CAMBIA-QUESTA-CHIAVE');   // <-- CAMBIALA (solo per la prima configurazione)

define('EVENTI_FILE', __DIR__ . '/eventi.json');
define('PRIVATE_DIR', __DIR__ . '/private');
define('USERS_FILE', PRIVATE_DIR . '/users.php');
define('ATTEMPTS_FILE', PRIVATE_DIR . '/attempts.php');
define('MAX_ATTEMPTS', 5);        // tentativi di login sbagliati...
define('LOCK_SECONDS', 900);      // ...poi blocco per 15 minuti
define('SESSION_IDLE', 7200);     // logout automatico dopo 2 ore di inattività
define('MIN_PASSWORD', 5);
define('PHP_GUARD', "<?php http_response_code(404); exit; ?>\n");

// ===================== SICUREZZA BASE =====================
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('adi_eventi');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $https,
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

// ===================== HELPER =====================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}
function flash($type, $msg) { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function go($qs = '') { header('Location: ' . $_SERVER['SCRIPT_NAME'] . $qs); exit; }
function len($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }

// Prova a rendere scrivibile un file/cartella (funziona se PHP e FTP usano lo stesso utente)
function try_writable($path) {
    if (is_writable($path)) return true;
    $isDir = is_dir($path);
    @chmod($path, $isDir ? 0775 : 0664);
    clearstatcache(true, $path);
    if (is_writable($path)) return true;
    @chmod($path, $isDir ? 0777 : 0666);
    clearstatcache(true, $path);
    return is_writable($path);
}

// File "privati": prefissati da una riga PHP che risponde 404 se aperti dal browser
function private_read($file) {
    if (!is_file($file)) return null;
    $c = @file_get_contents($file);
    if ($c === false) return null;
    $pos = strpos($c, "\n");
    if ($pos === false) return null;
    $d = json_decode(substr($c, $pos + 1), true);
    return is_array($d) ? $d : null;
}
function private_write($file, $data) {
    if (!is_dir(PRIVATE_DIR)) @mkdir(PRIVATE_DIR, 0775, true);
    if (is_dir(PRIVATE_DIR)) try_writable(PRIVATE_DIR);
    $ht = PRIVATE_DIR . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
    $ok = @file_put_contents($file, PHP_GUARD . json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok !== false) @chmod($file, 0600);
    return $ok !== false;
}

// Anti brute-force
function client_key() { return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x'); }
function attempts_get() { $a = private_read(ATTEMPTS_FILE); return is_array($a) ? $a : []; }
function lock_remaining() {
    $a = attempts_get(); $k = client_key();
    return (isset($a[$k]['until']) && $a[$k]['until'] > time()) ? $a[$k]['until'] - time() : 0;
}
function attempt_fail() {
    $a = attempts_get(); $k = client_key(); $now = time();
    foreach ($a as $kk => $v) { if (($v['last'] ?? 0) < $now - 86400) unset($a[$kk]); }
    $r = $a[$k] ?? ['n' => 0, 'until' => 0, 'last' => 0];
    if (!empty($r['until']) && $r['until'] < $now) $r = ['n' => 0, 'until' => 0, 'last' => 0];
    $r['n']++; $r['last'] = $now;
    if ($r['n'] >= MAX_ATTEMPTS) $r['until'] = $now + LOCK_SECONDS;
    $a[$k] = $r;
    private_write(ATTEMPTS_FILE, $a);
}
function attempt_clear() {
    $a = attempts_get(); $k = client_key();
    if (isset($a[$k])) { unset($a[$k]); private_write(ATTEMPTS_FILE, $a); }
}

// Eventi
function events_read() {
    if (!is_file(EVENTI_FILE)) return [];
    $d = json_decode((string)file_get_contents(EVENTI_FILE), true);
    return is_array($d) ? $d : [];
}
function events_write($list) {
    usort($list, function ($a, $b) {
        return strcmp($a['data'] . ' ' . $a['ora'], $b['data'] . ' ' . $b['ora']);
    });
    $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_file(EVENTI_FILE)) try_writable(EVENTI_FILE);
    return @file_put_contents(EVENTI_FILE, $json, LOCK_EX) !== false;
}
function clean_text($s) {
    $s = str_replace(["\r\n", "\r"], "\n", (string)$s);
    $s = preg_replace('/[^\P{C}\n]+/u', '', $s);
    return trim((string)$s);
}
function italian_date($data, $ora = '') {
    $g = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
    $m = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $t = strtotime($data . ' 12:00');
    if (!$t) return $data;
    return $g[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t) . ($ora ? ' · ' . $ora : '');
}

// ===================== DATI UTENTI =====================
$users = private_read(USERS_FILE);
$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'POST') ? ($_POST['action'] ?? '') : '';

// ===================== PRIMA CONFIGURAZIONE =====================
if ($users === null) {
    $err = '';
    $keyOk = SETUP_KEY !== 'CAMBIA-QUESTA-CHIAVE';
    if ($method === 'POST' && $action === 'setup' && $keyOk) {
        $p1 = (string)($_POST['p_super'] ?? '');
        $p2 = (string)($_POST['p_pastore'] ?? '');
        if (!csrf_ok()) $err = 'Sessione scaduta, riprova.';
        elseif (!hash_equals(SETUP_KEY, (string)($_POST['key'] ?? ''))) $err = 'Chiave di configurazione errata.';
        elseif (len($p1) < MIN_PASSWORD || len($p2) < MIN_PASSWORD) $err = 'Le password devono avere almeno ' . MIN_PASSWORD . ' caratteri.';
        elseif ($p1 === $p2) $err = 'Le due password devono essere diverse.';
        else {
            $new = [
                'superadmin' => ['name' => 'Super Admin', 'role' => 'super',   'hash' => password_hash($p1, PASSWORD_DEFAULT)],
                'pastore'    => ['name' => 'Pastore',     'role' => 'pastore', 'hash' => password_hash($p2, PASSWORD_DEFAULT)],
            ];
            if (private_write(USERS_FILE, $new)) {
                flash('ok', 'Configurazione completata. Ora puoi accedere.');
                go();
            }
            $err = 'Impossibile scrivere nella cartella "private". Chiedi al supporto dell\'hosting di renderla scrivibile.';
        }
    }
    page_start('Prima configurazione');
    echo '<div class="wrap narrow"><div class="card"><h2>🔧 Prima configurazione</h2>';
    if (!$keyOk) {
        echo '<div class="flash err">Apri <b>admin-eventi.php</b> e cambia il valore di <b>SETUP_KEY</b> in cima al file, poi ricarica questa pagina.</div>';
    } else {
        if ($err) echo '<div class="flash err">' . h($err) . '</div>';
        if (!is_dir(PRIVATE_DIR)) @mkdir(PRIVATE_DIR, 0775, true);
        $okPriv = is_dir(PRIVATE_DIR) && try_writable(PRIVATE_DIR);
        $okEv = is_file(EVENTI_FILE) ? try_writable(EVENTI_FILE) : (is_writable(__DIR__) || try_writable(__DIR__));
        echo '<p class="muted"><b>Controllo del server</b><br>'
           . ($okPriv ? '✅' : '⚠️') . ' Cartella <b>private</b> ' . ($okPriv ? 'scrivibile' : 'NON scrivibile') . '<br>'
           . ($okEv ? '✅' : '⚠️') . ' File <b>eventi.json</b> ' . ($okEv ? 'scrivibile' : 'NON scrivibile') . '</p>';
        if (!$okPriv || !$okEv) echo '<div class="flash err">Il server non permette la scrittura. Chiedi al supporto dell\'hosting di rendere scrivibili la cartella <b>private</b> e il file <b>eventi.json</b> per l\'utente PHP.</div>';
        echo '<p class="muted">Scegli le password dei due account. Usa almeno ' . MIN_PASSWORD . ' caratteri.</p>
        <form method="post" autocomplete="off">' . csrf_field() . '<input type="hidden" name="action" value="setup">
        <label>Chiave di configurazione</label><input type="password" name="key" required>
        <label>Password Super Admin (username: superadmin)</label><input type="password" name="p_super" minlength="' . MIN_PASSWORD . '" required>
        <label>Password Pastore (username: pastore)</label><input type="password" name="p_pastore" minlength="' . MIN_PASSWORD . '" required>
        <p style="margin-top:18px"><button class="btn gold" type="submit">Crea account</button></p></form>';
    }
    echo '</div></div>';
    page_end();
    exit;
}

// ===================== AUTENTICAZIONE =====================
$me = null;
if (!empty($_SESSION['u']) && isset($users[$_SESSION['u']])) {
    if (time() - ($_SESSION['t'] ?? 0) > SESSION_IDLE) {
        $_SESSION = [];
        flash('err', 'Sessione scaduta per inattività. Accedi di nuovo.');
    } else {
        $me = $_SESSION['u'];
        $_SESSION['t'] = time();
    }
}

if ($me === null) {
    $err = '';
    if ($method === 'POST' && $action === 'login') {
        $wait = lock_remaining();
        if (!csrf_ok()) {
            $err = 'Sessione scaduta, riprova.';
        } elseif ($wait > 0) {
            $err = 'Troppi tentativi. Riprova tra ' . ceil($wait / 60) . ' minuti.';
        } else {
            $u = strtolower(trim((string)($_POST['username'] ?? '')));
            $p = (string)($_POST['password'] ?? '');
            $dummy = '$2y$10$artBx7VMpEt7XGC5W7m1QuB1XQv3H.mJc1CcIxfknKoNRUFubryoe';
            $hash = isset($users[$u]) ? $users[$u]['hash'] : $dummy;
            $valid = password_verify($p, $hash) && isset($users[$u]);
            if ($valid) {
                attempt_clear();
                session_regenerate_id(true);
                $_SESSION['u'] = $u;
                $_SESSION['t'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $users[$u]['hash'] = password_hash($p, PASSWORD_DEFAULT);
                    private_write(USERS_FILE, $users);
                }
                go();
            }
            attempt_fail();
            usleep(400000);
            $err = 'Credenziali non valide.';
        }
    }
    $fl = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    page_start('Accesso');
    echo '<div class="wrap narrow"><div class="card"><div style="text-align:center;font-size:3rem">🗓️</div><h2 style="text-align:center;border:none">Gestione Eventi</h2>';
    if ($fl) echo '<div class="flash ' . h($fl['type']) . '">' . h($fl['msg']) . '</div>';
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="login">
        <label>Username</label><input type="text" name="username" autocomplete="username" autocapitalize="none" required>
        <label>Password</label><input type="password" name="password" autocomplete="current-password" required>
        <p style="margin-top:18px"><button class="btn gold" style="width:100%" type="submit">Accedi</button></p></form>
        </div></div>';
    page_end();
    exit;
}

$user = $users[$me];
$isSuper = ($user['role'] === 'super');

// ===================== AZIONI (solo utenti loggati) =====================
if ($method === 'POST') {
    if (!csrf_ok()) { flash('err', 'Sessione scaduta, riprova.'); go(); }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('ok', 'Disconnesso.');
        go();
    }

    if ($action === 'save_event') {
        $id    = (string)($_POST['id'] ?? '');
        $data  = trim((string)($_POST['data'] ?? ''));
        $ora   = trim((string)($_POST['ora'] ?? ''));
        $tit   = clean_text($_POST['titolo'] ?? '');
        $desc  = clean_text($_POST['descrizione'] ?? '');
        $errs  = [];
        $d = DateTime::createFromFormat('Y-m-d', $data);
        if (!$d || $d->format('Y-m-d') !== $data) $errs[] = 'Data non valida.';
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ora)) $errs[] = 'Ora non valida.';
        if ($tit === '' || len($tit) > 100) $errs[] = 'Il titolo è obbligatorio (max 100 caratteri).';
        if ($desc === '' || len($desc) > 1000) $errs[] = 'La descrizione è obbligatoria (max 1000 caratteri).';

        if ($errs) {
            $_SESSION['form_old'] = ['id' => $id, 'data' => $data, 'ora' => $ora, 'titolo' => $tit, 'descrizione' => $desc];
            flash('err', implode(' ', $errs));
            go($id !== '' ? '?edit=' . urlencode($id) : '');
        }

        $list = events_read();
        $found = false;
        if ($id !== '') {
            foreach ($list as &$e) {
                if (($e['id'] ?? '') === $id) {
                    $e['data'] = $data; $e['ora'] = $ora; $e['titolo'] = $tit; $e['descrizione'] = $desc;
                    $found = true; break;
                }
            }
            unset($e);
        }
        if (!$found) {
            $list[] = ['id' => bin2hex(random_bytes(6)), 'data' => $data, 'ora' => $ora, 'titolo' => $tit, 'descrizione' => $desc];
        }
        if (events_write($list)) flash('ok', $found ? 'Evento aggiornato.' : 'Evento aggiunto.');
        else flash('err', 'Impossibile salvare: il server non permette di scrivere eventi.json. Contatta chi gestisce l\'hosting.');
        go();
    }

    if ($action === 'delete_event') {
        $id = (string)($_POST['id'] ?? '');
        $list = array_values(array_filter(events_read(), function ($e) use ($id) { return ($e['id'] ?? '') !== $id; }));
        if (events_write($list)) flash('ok', 'Evento eliminato.');
        else flash('err', 'Impossibile salvare: controlla i permessi di eventi.json.');
        go();
    }

    if ($action === 'change_password') {
        if (!$isSuper) { flash('err', 'Non hai il permesso di cambiare la password.'); go(); }
        $cur = (string)($_POST['current'] ?? '');
        $n1  = (string)($_POST['new'] ?? '');
        $n2  = (string)($_POST['confirm'] ?? '');
        if (!password_verify($cur, $user['hash'])) flash('err', 'La password attuale non è corretta.');
        elseif (len($n1) < MIN_PASSWORD) flash('err', 'La nuova password deve avere almeno ' . MIN_PASSWORD . ' caratteri.');
        elseif ($n1 !== $n2) flash('err', 'Le due nuove password non coincidono.');
        else {
            $users[$me]['hash'] = password_hash($n1, PASSWORD_DEFAULT);
            if (private_write(USERS_FILE, $users)) flash('ok', 'Password aggiornata.');
            else flash('err', 'Impossibile salvare la password (permessi cartella private).');
        }
        go();
    }

    if ($action === 'reset_pastore' && $isSuper) {
        $n1 = (string)($_POST['new'] ?? '');
        if (len($n1) < MIN_PASSWORD) flash('err', 'La password deve avere almeno ' . MIN_PASSWORD . ' caratteri.');
        else {
            $users['pastore']['hash'] = password_hash($n1, PASSWORD_DEFAULT);
            if (private_write(USERS_FILE, $users)) flash('ok', 'Password del Pastore reimpostata.');
            else flash('err', 'Impossibile salvare (permessi cartella private).');
        }
        go();
    }

    flash('err', 'Azione non valida.');
    go();
}

// ===================== PAGINA ADMIN =====================
$fl = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$old = $_SESSION['form_old'] ?? null; unset($_SESSION['form_old']);

$events = events_read();
usort($events, function ($a, $b) { return strcmp($a['data'] . ' ' . $a['ora'], $b['data'] . ' ' . $b['ora']); });
$today = date('Y-m-d');
$upcoming = []; $past = [];
foreach ($events as $e) { if ($e['data'] >= $today) $upcoming[] = $e; else $past[] = $e; }
$past = array_reverse($past);

$form = ['id' => '', 'data' => '', 'ora' => '', 'titolo' => '', 'descrizione' => ''];
if (isset($_GET['edit'])) {
    foreach ($events as $e) { if ($e['id'] === $_GET['edit']) { $form = $e; break; } }
}
if ($old) $form = $old;
$editing = $form['id'] !== '';

function month_abbr($data) {
    $m = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    return $m[(int)date('n', strtotime($data . ' 12:00'))];
}
function event_item($e) {
    echo '<div class="ev"><div class="evd"><b>' . h(date('j', strtotime($e['data']))) . '</b><span>'
       . h(month_abbr($e['data'])) . '</span></div>
      <div class="evb"><h3>' . h($e['titolo']) . '</h3>
      <div class="meta">' . h(italian_date($e['data'], $e['ora'])) . '</div>
      <p>' . nl2br(h($e['descrizione'])) . '</p>
      <div class="acts"><a class="btn sm ghost" href="?edit=' . h(urlencode($e['id'])) . '#form">✏️ Modifica</a>
      <form method="post" onsubmit="return confirm(\'Eliminare questo evento?\')" style="display:inline">'
       . csrf_field() . '<input type="hidden" name="action" value="delete_event"><input type="hidden" name="id" value="' . h($e['id']) . '">
      <button class="btn sm danger" type="submit">🗑️ Elimina</button></form></div></div></div>';
}

page_start('Gestione eventi');
?>
<div class="top">
  <div><b>ADI Cuneo</b> · Gestione Eventi</div>
  <div class="who"><?= h($user['name']) ?>
    · <a href="eventi.html" target="_blank">Vedi pagina</a>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="linkbtn" type="submit">Esci</button></form>
  </div>
</div>
<div class="wrap">
  <?php if ($fl): ?><div class="flash <?= h($fl['type']) ?>"><?= h($fl['msg']) ?></div><?php endif; ?>

  <div class="card" id="form">
    <h2><?= $editing ? '✏️ Modifica evento' : '➕ Nuovo evento' ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_event">
      <input type="hidden" name="id" value="<?= h($form['id']) ?>">
      <div class="row">
        <div><label for="data">Data</label><input type="date" id="data" name="data" value="<?= h($form['data']) ?>" required></div>
        <div><label for="ora">Ora</label><input type="time" id="ora" name="ora" value="<?= h($form['ora']) ?>" required></div>
      </div>
      <label for="titolo">Titolo</label>
      <input type="text" id="titolo" name="titolo" maxlength="100" value="<?= h($form['titolo']) ?>" placeholder="es. Evangelizzazione" required>
      <label for="descrizione">Descrizione (anche luogo e dettagli)</label>
      <textarea id="descrizione" name="descrizione" maxlength="1000" placeholder="es. Evento di evangelizzazione presso la città di Cuneo, Piazza ex Foro Boario" required><?= h($form['descrizione']) ?></textarea>
      <p style="margin-top:16px">
        <button class="btn gold" type="submit"><?= $editing ? 'Salva modifiche' : 'Aggiungi evento' ?></button>
        <?php if ($editing): ?><a class="btn ghost" href="<?= h($_SERVER['SCRIPT_NAME']) ?>">Annulla</a><?php endif; ?>
      </p>
    </form>
  </div>

  <div class="card">
    <h2>📅 Prossimi eventi (<?= count($upcoming) ?>)</h2>
    <?php if (!$upcoming): ?><p class="muted">Nessun evento in programma.</p><?php endif; ?>
    <?php foreach ($upcoming as $e) event_item($e); ?>
  </div>

  <?php if ($past): ?>
  <div class="card">
    <details>
      <summary>🕘 Eventi passati (<?= count($past) ?>)</summary>
      <div style="margin-top:14px"><?php foreach ($past as $e) event_item($e); ?></div>
    </details>
  </div>
  <?php endif; ?>

  <?php if ($isSuper): ?>
  <div class="card">
    <h2>🔑 Cambia la tua password</h2>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
      <label>Password attuale</label><input type="password" name="current" autocomplete="current-password" required>
      <label>Nuova password (min <?= MIN_PASSWORD ?> caratteri)</label><input type="password" name="new" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required>
      <label>Ripeti nuova password</label><input type="password" name="confirm" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required>
      <p style="margin-top:16px"><button class="btn" type="submit">Aggiorna password</button></p>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($isSuper): ?>
  <div class="card">
    <h2>🛡️ Super Admin: reimposta password Pastore</h2>
    <p class="muted">Usa questa funzione se il Pastore dimentica la password.</p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_pastore">
      <label>Nuova password per il Pastore</label><input type="text" name="new" minlength="<?= MIN_PASSWORD ?>" autocomplete="off" required>
      <p style="margin-top:16px"><button class="btn" type="submit">Reimposta</button></p>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
page_end();

// ===================== TEMPLATE =====================
function page_start($title) {
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#4a4a54">
<title><?= h($title) ?> - ADI Cuneo</title>
<style>
:root{--dark:#4a4a54;--gold:#e0ca55;--bg:#f5f5f5}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#333;line-height:1.5;-webkit-text-size-adjust:100%}
.top{background:var(--dark);color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:10;box-shadow:0 2px 10px rgba(0,0,0,.2)}
.top b{color:var(--gold)}
.who{font-size:.9rem}
.who a{color:var(--gold);text-decoration:none;font-weight:600}
.linkbtn{background:none;border:none;color:#fff;font:inherit;font-weight:600;cursor:pointer;margin-left:10px;text-decoration:underline}
.wrap{max-width:820px;margin:0 auto;padding:18px}
.wrap.narrow{max-width:440px;padding-top:50px}
.card{background:#fff;border-radius:16px;padding:22px;box-shadow:0 4px 18px rgba(0,0,0,.07);margin-bottom:18px}
.card h2{font-size:1.2rem;color:var(--dark);margin-bottom:14px;padding-bottom:10px;border-bottom:3px solid var(--gold)}
.muted{color:#777;font-size:.95rem}
label{display:block;font-weight:600;color:var(--dark);margin:12px 0 6px}
input,textarea{width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font:inherit;font-size:1rem;background:#fff;color:#333}
input:focus,textarea:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(224,202,85,.25)}
textarea{min-height:130px;resize:vertical}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{display:inline-block;border:none;border-radius:10px;padding:12px 22px;font:inherit;font-weight:600;cursor:pointer;background:var(--dark);color:#fff;text-decoration:none;transition:all .25s}
.btn:hover{background:var(--gold);color:var(--dark);transform:translateY(-2px)}
.btn.gold{background:var(--gold);color:var(--dark)}
.btn.gold:hover{background:#d4bd45}
.btn.ghost{background:#eee;color:var(--dark)}
.btn.danger{background:#fee2e2;color:#b91c1c}
.btn.danger:hover{background:#dc2626;color:#fff}
.btn.sm{padding:8px 14px;font-size:.9rem}
.flash{padding:14px 16px;border-radius:10px;margin-bottom:16px;font-weight:600}
.flash.ok{background:#d1fae5;color:#065f46}
.flash.err{background:#fee2e2;color:#991b1b}
.ev{display:flex;gap:14px;padding:16px;border-left:4px solid var(--gold);background:#fafafa;border-radius:12px;margin-bottom:12px}
.evd{flex:0 0 58px;text-align:center;background:var(--dark);color:#fff;border-radius:10px;padding:8px 4px;align-self:flex-start}
.evd b{display:block;font-size:1.5rem;line-height:1;color:var(--gold)}
.evd span{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em}
.evb{min-width:0;flex:1}
.evb h3{color:var(--dark);font-size:1.05rem;margin-bottom:2px;overflow-wrap:anywhere}
.meta{font-size:.88rem;font-weight:600;color:#8a7a1f;margin-bottom:6px}
.evb p{color:#555;font-size:.95rem;overflow-wrap:anywhere}
.acts{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}
summary{cursor:pointer;font-weight:700;color:var(--dark);font-size:1.1rem}
@media(max-width:480px){.row{grid-template-columns:1fr 1fr}.card{padding:18px}}
</style>
</head>
<body>
<?php
}
function page_end() { echo "</body></html>"; }