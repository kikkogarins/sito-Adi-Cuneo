<?php
/**
 * ADI Cuneo - Gestione galleria foto
 * Usa gli stessi account (superadmin / pastore) di admin-eventi.php:
 * se hai gia' fatto il login li', qui sei gia' dentro.
 */

define('GALLERIA_FILE', __DIR__ . '/galleria.json');
define('UPLOAD_DIR', __DIR__ . '/img/galleria');
define('UPLOAD_URL', 'img/galleria');
define('PRIVATE_DIR', __DIR__ . '/private');
define('USERS_FILE', PRIVATE_DIR . '/users.php');
define('SESSION_IDLE', 7200);
define('MAX_SIDE', 1600);
define('DEFAULT_CATS', ['Battesimi', 'Giovani', 'IlluminiAMO']);

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
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
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
function json_out($ok, $msg) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit;
}
function fail($msg, $ajax) {
    if ($ajax) json_out(false, $msg);
    flash('err', $msg);
    go();
}
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
function private_read($file) {
    if (!is_file($file)) return null;
    $c = @file_get_contents($file);
    if ($c === false) return null;
    $pos = strpos($c, "\n");
    if ($pos === false) return null;
    $d = json_decode(substr($c, $pos + 1), true);
    return is_array($d) ? $d : null;
}
function clean_line($s) {
    $s = preg_replace('/\p{C}+/u', ' ', (string)$s);
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    return trim((string)$s);
}
function valid_date($d) {
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d;
}
function it_date($d) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m)) return '';
    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

// Galleria
function gal_read() {
    if (!is_file(GALLERIA_FILE)) return [];
    $d = json_decode((string)file_get_contents(GALLERIA_FILE), true);
    return is_array($d) ? $d : [];
}
function gal_write($list) {
    usort($list, function ($a, $b) {
        $c = strcmp((string)($b['data'] ?? ''), (string)($a['data'] ?? ''));
        return $c !== 0 ? $c : (($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
    });
    $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_file(GALLERIA_FILE)) try_writable(GALLERIA_FILE);
    elseif (!is_writable(__DIR__)) try_writable(__DIR__);
    return @file_put_contents(GALLERIA_FILE, $json, LOCK_EX) !== false;
}
function is_own_upload($file) {
    return (bool)preg_match('#^img/galleria/[a-f0-9]{16}\.(jpg|png|webp)$#', (string)$file);
}
function files_list($key) {
    if (empty($_FILES[$key])) return [];
    $f = $_FILES[$key];
    $out = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            $out[] = ['name' => $n, 'tmp' => $f['tmp_name'][$i], 'err' => $f['error'][$i]];
        }
    } else {
        $out[] = ['name' => $f['name'], 'tmp' => $f['tmp_name'], 'err' => $f['error']];
    }
    return $out;
}

// Elabora una foto: ridimensiona (max 1600px) e salva come JPEG. Ritorna il percorso relativo.
function process_image($tmp, &$err) {
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
    if (defined('IMAGETYPE_WEBP')) $allowed[] = IMAGETYPE_WEBP;
    if (!$info || !in_array($info[2], $allowed, true)) {
        $err = 'Formato non supportato (usa JPG, PNG o WebP)';
        return null;
    }
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
    if (is_dir(UPLOAD_DIR)) try_writable(UPLOAD_DIR);
    if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
        $err = 'La cartella img/galleria non e scrivibile (permessi)';
        return null;
    }
    $ht = UPLOAD_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|php[0-9]|phar)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $base = bin2hex(random_bytes(8));

    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $src = null;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($tmp);
        elseif ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($tmp);
        elseif (defined('IMAGETYPE_WEBP') && $info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($tmp);
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') && function_exists('imagerotate')) {
                $ex = @exif_read_data($tmp);
                $o = $ex['Orientation'] ?? 1;
                $deg = [3 => 180, 6 => -90, 8 => 90];
                if (isset($deg[$o])) {
                    $r = @imagerotate($src, $deg[$o], 0);
                    if ($r) { imagedestroy($src); $src = $r; }
                }
            }
            $w = imagesx($src); $hh = imagesy($src);
            $s = min(1, MAX_SIDE / max($w, $hh));
            $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($hh * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hh);
            $name = $base . '.jpg';
            $ok = @imagejpeg($dst, UPLOAD_DIR . '/' . $name, 84);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) { @chmod(UPLOAD_DIR . '/' . $name, 0644); return UPLOAD_URL . '/' . $name; }
        }
    }
    // Se il server non ha GD: salva l'originale
    if (filesize($tmp) > 8 * 1024 * 1024) { $err = 'Immagine troppo grande'; return null; }
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_JPEG ? 'jpg' : 'webp');
    $name = $base . '.' . $ext;
    if (!@move_uploaded_file($tmp, UPLOAD_DIR . '/' . $name)) { $err = 'Impossibile salvare la foto'; return null; }
    @chmod(UPLOAD_DIR . '/' . $name, 0644);
    return UPLOAD_URL . '/' . $name;
}

// ===================== AUTENTICAZIONE =====================
$users = private_read(USERS_FILE);
$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'POST') ? ($_POST['action'] ?? '') : '';
$ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');

$me = null;
if ($users && !empty($_SESSION['u']) && isset($users[$_SESSION['u']])) {
    if (time() - ($_SESSION['t'] ?? 0) > SESSION_IDLE) { $_SESSION = []; }
    else { $me = $_SESSION['u']; $_SESSION['t'] = time(); }
}
if ($me === null) {
    if ($ajax) json_out(false, 'Sessione scaduta, accedi di nuovo');
    header('Location: admin-eventi.php');
    exit;
}
$user = $users[$me];

// Upload troppo grande per il server (PHP svuota $_POST)
if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail('Le foto sono troppo pesanti per il server (limite ' . ini_get('post_max_size') . '). Caricane meno per volta.', $ajax);
}

// ===================== AZIONI =====================
if ($method === 'POST') {
    if (!csrf_ok()) fail('Sessione scaduta, ricarica la pagina e riprova', $ajax);

    if ($action === 'upload') {
        $sel = (string)($_POST['categoria_sel'] ?? '');
        $cat = clean_line($sel === '__new__' ? ($_POST['categoria_new'] ?? '') : $sel);
        $tit = clean_line($_POST['titolo'] ?? '');
        $data = trim((string)($_POST['data'] ?? ''));
        if ($data === '') $data = date('Y-m-d');
        if ($cat === '' || len($cat) > 40) fail('Categoria obbligatoria (max 40 caratteri)', $ajax);
        if (len($tit) > 100) fail('Titolo troppo lungo (max 100 caratteri)', $ajax);
        if (!valid_date($data)) fail('Data non valida', $ajax);

        $files = files_list('foto');
        if (!$files) fail('Nessuna foto selezionata', $ajax);

        $list = gal_read();
        $added = []; $errors = [];
        $t0 = (int)round(microtime(true) * 1000);
        foreach ($files as $i => $f) {
            if ($f['err'] === UPLOAD_ERR_NO_FILE) continue;
            if ($f['err'] === UPLOAD_ERR_INI_SIZE || $f['err'] === UPLOAD_ERR_FORM_SIZE) { $errors[] = 'Foto troppo pesante per il server (limite ' . ini_get('upload_max_filesize') . ')'; continue; }
            if ($f['err'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) { $errors[] = 'Caricamento non riuscito'; continue; }
            $err = '';
            $path = process_image($f['tmp'], $err);
            if ($path === null) { $errors[] = $err; continue; }
            $added[] = $path;
            $list[] = ['id' => bin2hex(random_bytes(6)), 'file' => $path, 'categoria' => $cat, 'titolo' => $tit, 'data' => $data, 'ts' => $t0 + $i];
        }
        if ($added && !gal_write($list)) {
            foreach ($added as $p) @unlink(__DIR__ . '/' . $p);
            fail('Impossibile salvare: controlla i permessi di galleria.json', $ajax);
        }
        if ($ajax) {
            if ($added) json_out(true, 'ok');
            json_out(false, $errors ? $errors[0] : 'Nessuna foto caricata');
        }
        if ($added) flash('ok', count($added) . (count($added) === 1 ? ' foto aggiunta.' : ' foto aggiunte.') . ($errors ? ' Alcune non sono state caricate: ' . $errors[0] : ''));
        else flash('err', $errors ? $errors[0] : 'Nessuna foto caricata');
        go();
    }

    if ($action === 'edit_item') {
        $id = (string)($_POST['id'] ?? '');
        $sel = (string)($_POST['categoria_sel'] ?? '');
        $cat = clean_line($sel === '__new__' ? ($_POST['categoria_new'] ?? '') : $sel);
        $tit = clean_line($_POST['titolo'] ?? '');
        $data = trim((string)($_POST['data'] ?? ''));
        if ($cat === '' || len($cat) > 40 || len($tit) > 100 || ($data !== '' && !valid_date($data))) {
            flash('err', 'Dati non validi (categoria, titolo o data).');
            go('?edit=' . urlencode($id));
        }
        $list = gal_read();
        foreach ($list as &$e) {
            if (($e['id'] ?? '') === $id) { $e['categoria'] = $cat; $e['titolo'] = $tit; $e['data'] = $data; break; }
        }
        unset($e);
        if (gal_write($list)) flash('ok', 'Foto aggiornata.');
        else flash('err', 'Impossibile salvare: controlla i permessi di galleria.json.');
        go();
    }

    if ($action === 'delete_item') {
        $id = (string)($_POST['id'] ?? '');
        $list = gal_read(); $keep = []; $gone = null;
        foreach ($list as $e) {
            if (($e['id'] ?? '') === $id) $gone = $e; else $keep[] = $e;
        }
        if ($gone && gal_write($keep)) {
            if (is_own_upload($gone['file'] ?? '')) @unlink(__DIR__ . '/' . $gone['file']);
            flash('ok', 'Foto eliminata.');
        } else flash('err', 'Impossibile eliminare: controlla i permessi di galleria.json.');
        go();
    }

    flash('err', 'Azione non valida.');
    go();
}

// ===================== PAGINA =====================
$fl = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
if (!$fl && isset($_GET['ok'])) {
    $ok = (int)$_GET['ok']; $ko = (int)($_GET['ko'] ?? 0);
    if ($ok > 0) $fl = ['type' => 'ok', 'msg' => $ok . ($ok === 1 ? ' foto aggiunta.' : ' foto aggiunte.') . ($ko ? ' Non caricate: ' . $ko . '. ' . substr((string)($_GET['m'] ?? ''), 0, 150) : '')];
    else $fl = ['type' => 'err', 'msg' => 'Nessuna foto caricata. ' . substr((string)($_GET['m'] ?? ''), 0, 150)];
}

$items = gal_read();
usort($items, function ($a, $b) {
    $c = strcmp((string)($b['data'] ?? ''), (string)($a['data'] ?? ''));
    return $c !== 0 ? $c : (($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
});
$cats = DEFAULT_CATS;
foreach ($items as $e) { if (!empty($e['categoria']) && !in_array($e['categoria'], $cats, true)) $cats[] = $e['categoria']; }

$editItem = null;
if (isset($_GET['edit'])) { foreach ($items as $e) { if (($e['id'] ?? '') === $_GET['edit']) { $editItem = $e; break; } } }

if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
$okDir = is_dir(UPLOAD_DIR) && try_writable(UPLOAD_DIR);
$okJson = is_file(GALLERIA_FILE) ? try_writable(GALLERIA_FILE) : (is_writable(__DIR__) || try_writable(__DIR__));

function cat_options($cats, $selected = '') {
    $o = '';
    foreach ($cats as $c) $o .= '<option value="' . h($c) . '"' . ($c === $selected ? ' selected' : '') . '>' . h($c) . '</option>';
    return $o . '<option value="__new__">+ Nuova categoria...</option>';
}
function src_of($f) { return h(str_replace(' ', '%20', (string)$f)); }

page_start('Gestione galleria');
?>
<div class="top">
  <div><b>ADI Cuneo</b> &middot; Gestione Galleria</div>
  <div class="who"><?= h($user['name']) ?>
    &middot; <a href="admin-eventi.php">Eventi</a>
    &middot; <a href="gallery.html" target="_blank">Vedi galleria</a>
  </div>
</div>
<div class="wrap">
  <?php if ($fl): ?><div class="flash <?= h($fl['type']) ?>"><?= h($fl['msg']) ?></div><?php endif; ?>
  <?php if (!$okDir || !$okJson): ?>
    <div class="flash err">Il server non permette la scrittura: dai permessi 775 (o 777) alla cartella <b>img/galleria</b> e 664 (o 666) a <b>galleria.json</b>.</div>
  <?php endif; ?>

  <?php if ($editItem): ?>
  <div class="card">
    <h2>&#9999;&#65039; Modifica foto</h2>
    <img class="thumb-big" src="<?= src_of($editItem['file']) ?>" alt="">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_item">
      <input type="hidden" name="id" value="<?= h($editItem['id']) ?>">
      <label>Categoria</label>
      <select name="categoria_sel" id="eCatSel"><?= cat_options($cats, $editItem['categoria'] ?? '') ?></select>
      <input type="text" name="categoria_new" id="eCatNew" maxlength="40" placeholder="Nome nuova categoria" style="display:none;margin-top:8px">
      <label>Titolo</label>
      <input type="text" name="titolo" maxlength="100" value="<?= h($editItem['titolo'] ?? '') ?>">
      <label>Data</label>
      <input type="date" name="data" value="<?= h($editItem['data'] ?? '') ?>">
      <p style="margin-top:16px">
        <button class="btn gold" type="submit">Salva modifiche</button>
        <a class="btn ghost" href="admin-galleria.php">Annulla</a>
      </p>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>&#10133; Aggiungi foto</h2>
    <form method="post" enctype="multipart/form-data" id="upForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <label for="foto">Foto (puoi sceglierne anche molte insieme)</label>
      <input type="file" id="foto" name="foto[]" accept="image/jpeg,image/png,image/webp" multiple required>
      <label for="catSel">Categoria</label>
      <select name="categoria_sel" id="catSel"><?= cat_options($cats) ?></select>
      <input type="text" name="categoria_new" id="catNew" maxlength="40" placeholder="Nome nuova categoria" style="display:none;margin-top:8px">
      <label for="titolo">Titolo (uguale per tutte le foto scelte)</label>
      <input type="text" id="titolo" name="titolo" maxlength="100" placeholder="es. Evangelizzazione Piazza Chanoux">
      <label for="data">Data (serve solo per l'ordine: le pi&ugrave; recenti in alto)</label>
      <input type="date" id="data" name="data" value="<?= h(date('Y-m-d')) ?>" required>
      <p class="muted" style="margin-top:10px">Le foto vengono ridimensionate automaticamente: puoi caricare direttamente quelle del telefono.</p>
      <div id="status" class="status"></div>
      <p style="margin-top:16px"><button class="btn gold" id="upBtn" type="submit">Carica foto</button></p>
    </form>
  </div>

  <div class="card">
    <h2>&#128444;&#65039; Foto in galleria (<?= count($items) ?>)</h2>
    <?php if (!$items): ?><p class="muted">Nessuna foto presente.</p><?php endif; ?>
    <div class="gl">
    <?php foreach ($items as $e): ?>
      <div class="gi">
        <img src="<?= src_of($e['file'] ?? '') ?>" alt="" loading="lazy">
        <div class="gb">
          <b><?= h(($e['titolo'] ?? '') !== '' ? $e['titolo'] : '(senza titolo)') ?></b>
          <span><?= h($e['categoria'] ?? '') ?><?= !empty($e['data']) ? ' &middot; ' . h(it_date($e['data'])) : '' ?></span>
          <div class="acts">
            <a class="btn sm ghost" href="?edit=<?= h(urlencode($e['id'] ?? '')) ?>">Modifica</a>
            <form method="post" onsubmit="return confirm('Eliminare questa foto?')" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?= h($e['id'] ?? '') ?>">
              <button class="btn sm danger" type="submit">Elimina</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
(function () {
  function bindCat(selId, newId) {
    var s = document.getElementById(selId), n = document.getElementById(newId);
    if (!s || !n) return;
    s.addEventListener('change', function () {
      n.style.display = s.value === '__new__' ? 'block' : 'none';
      if (s.value === '__new__') n.focus();
    });
  }
  bindCat('catSel', 'catNew');
  bindCat('eCatSel', 'eCatNew');

  var form = document.getElementById('upForm');
  if (!form) return;
  var input = document.getElementById('foto');
  var st = document.getElementById('status');
  var btn = document.getElementById('upBtn');

  // Ridimensiona nel browser (max 1600px) per caricare piu in fretta
  function shrink(file) {
    return new Promise(function (resolve) {
      var MAX = 1600;
      function done(src, w, h) {
        var k = Math.min(1, MAX / Math.max(w, h));
        var cw = Math.round(w * k), ch = Math.round(h * k);
        var c = document.createElement('canvas');
        c.width = cw; c.height = ch;
        var x = c.getContext('2d');
        x.fillStyle = '#fff'; x.fillRect(0, 0, cw, ch);
        x.drawImage(src, 0, 0, cw, ch);
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.82);
      }
      function fallback() {
        var img = new Image();
        var u = URL.createObjectURL(file);
        img.onload = function () { done(img, img.naturalWidth, img.naturalHeight); URL.revokeObjectURL(u); };
        img.onerror = function () { resolve(file); };
        img.src = u;
      }
      if (window.createImageBitmap) {
        createImageBitmap(file, { imageOrientation: 'from-image' })
          .then(function (bm) { done(bm, bm.width, bm.height); })
          .catch(fallback);
      } else { fallback(); }
    });
  }

  form.addEventListener('submit', function (e) {
    var files = Array.prototype.slice.call(input.files || []);
    if (!files.length || !window.fetch || !window.FormData) return;
    e.preventDefault();
    btn.disabled = true;
    var ok = 0, ko = 0, i = 0, last = '';
    function next() {
      if (i >= files.length) {
        location.href = location.pathname + '?ok=' + ok + '&ko=' + ko + '&m=' + encodeURIComponent(last);
        return;
      }
      st.textContent = 'Caricamento foto ' + (i + 1) + ' di ' + files.length + '...';
      shrink(files[i]).then(function (blob) {
        var fd = new FormData();
        fd.append('csrf', form.elements['csrf'].value);
        fd.append('action', 'upload');
        fd.append('categoria_sel', form.elements['categoria_sel'].value);
        fd.append('categoria_new', form.elements['categoria_new'].value);
        fd.append('titolo', form.elements['titolo'].value);
        fd.append('data', form.elements['data'].value);
        fd.append('foto[]', blob, 'foto.jpg');
        return fetch(location.pathname, {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch' }
        }).then(function (r) { return r.json(); });
      }).then(function (j) {
        if (j.ok) { ok++; } else { ko++; last = j.msg || ''; }
        i++; next();
      }).catch(function () {
        ko++; last = 'Errore di connessione'; i++; next();
      });
    }
    next();
  });
})();
</script>
<?php
page_end();

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
.wrap{max-width:900px;margin:0 auto;padding:18px}
.card{background:#fff;border-radius:16px;padding:22px;box-shadow:0 4px 18px rgba(0,0,0,.07);margin-bottom:18px}
.card h2{font-size:1.2rem;color:var(--dark);margin-bottom:14px;padding-bottom:10px;border-bottom:3px solid var(--gold)}
.muted{color:#777;font-size:.9rem}
label{display:block;font-weight:600;color:var(--dark);margin:12px 0 6px}
input,select,textarea{width:100%;padding:12px 14px;border:2px solid #e0e0e0;border-radius:10px;font:inherit;font-size:1rem;background:#fff;color:#333}
input:focus,select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(224,202,85,.25)}
.btn{display:inline-block;border:none;border-radius:10px;padding:12px 22px;font:inherit;font-weight:600;cursor:pointer;background:var(--dark);color:#fff;text-decoration:none;transition:all .25s}
.btn:hover{background:var(--gold);color:var(--dark)}
.btn:disabled{opacity:.5;cursor:wait}
.btn.gold{background:var(--gold);color:var(--dark)}
.btn.ghost{background:#eee;color:var(--dark)}
.btn.danger{background:#fee2e2;color:#b91c1c}
.btn.danger:hover{background:#dc2626;color:#fff}
.btn.sm{padding:8px 14px;font-size:.9rem}
.flash{padding:14px 16px;border-radius:10px;margin-bottom:16px;font-weight:600}
.flash.ok{background:#d1fae5;color:#065f46}
.flash.err{background:#fee2e2;color:#991b1b}
.status{margin-top:10px;font-weight:600;color:var(--dark)}
.gl{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.gi{background:#fafafa;border-radius:12px;overflow:hidden;border:1px solid #eee}
.gi img{width:100%;height:150px;object-fit:cover;display:block}
.gb{padding:12px;display:flex;flex-direction:column;gap:4px}
.gb b{color:var(--dark);font-size:.95rem;overflow-wrap:anywhere}
.gb span{font-size:.82rem;color:#8a7a1f;font-weight:600}
.acts{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap}
.thumb-big{max-width:100%;max-height:260px;border-radius:12px;display:block;margin-bottom:6px}
</style>
</head>
<body>
<?php
}
function page_end() { echo "</body></html>"; }