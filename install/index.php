<?php
// ============================================================
// ตัวติดตั้งครั้งแรก (web install wizard) — install/index.php?step=1..5
//   1 ตรวจเซิร์ฟเวอร์  2 ฐานข้อมูล + สคีมา  3 แอดมินคนแรก  4 เว็บไซต์ + ชำระเงิน  5 เขียน config + ล็อก
// ไม่โหลด core/bootstrap.php (ยังไม่มี config) — ใช้แค่ csrf.core.php + installer.lib.php
// ติดตั้งเสร็จ (มี storage/install.lock หรือ core/config.local.php) → ปฏิเสธทุกคำขอ
// ============================================================
define('ALEANOR_INSTALLER', 1);
require __DIR__.'/installer.lib.php';
require INST_ROOT.'/core/csrf.core.php';

date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$self  = $_SERVER['SCRIPT_NAME'] ?? '/install/index.php';            // เช่น /2026/aleanor/aleanor_cloud/install/index.php
$base  = rtrim(dirname(dirname($self)), '/').'/';                      // พาธของแอป เช่น /2026/aleanor/aleanor_cloud/
session_name('ACINSTALL');
session_set_cookie_params(['lifetime' => 0, 'path' => $base.'install/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.use_strict_mode', '1');
session_start();

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function inst_go($step){ global $self; header('Location: '.$self.'?step='.(int)$step); exit; }

$S = &$_SESSION['inst'];
if(!is_array($S)) $S = ['reached' => 1];

// ── ล็อก: ติดตั้งแล้วห้ามรันซ้ำ (ยกเว้นขั้นสุดท้ายที่รอผู้ใช้วาง config เองแล้วกดยืนยัน) ──
$awaitingConfig = !empty($S['awaiting_config']) && !is_file(INST_LOCK);
if(inst_installed() && !$awaitingConfig){
    http_response_code(403);
    $S = null;
    $step = 0;
} else {
    $step = max(1, min(5, (int)($_GET['step'] ?? 1)));
    if($step > $S['reached']) inst_go($S['reached']);
}

$errors = []; $notice = '';
$old = function($k, $d = '') { return $_POST[$k] ?? $d; };

// ── โทเคนติดตั้ง: ต้องผ่านก่อนใช้งานทุกขั้น ──
$tokenFile = '';
if($step && empty($S['token_ok'])){
    $tokenFile = inst_token_ensure() !== '' ? 'storage/install.token' : '';
    if(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['setup_token'])){
        if(!csrf_verify()) $errors[] = 'เซสชันหมดอายุหรือฟอร์มไม่ถูกต้อง (CSRF) — กรุณาลองใหม่';
        elseif(!inst_token_check($_POST['setup_token'])){ usleep(500000); $errors[] = 'โทเคนไม่ถูกต้อง'; }
        else { session_regenerate_id(true); $S['token_ok'] = true; inst_go($S['reached']); }
    }
    $step = -1;                                                     // แสดงหน้ากรอกโทเคนแทน
}

if($step > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'){
    if(!csrf_verify()){
        $errors[] = 'เซสชันหมดอายุหรือฟอร์มไม่ถูกต้อง (CSRF) — กรุณาลองใหม่';
    }
    elseif($step === 1){
        if(inst_requirements_ok(inst_requirements())){ $S['reached'] = max($S['reached'], 2); inst_go(2); }
        $errors[] = 'ยังมีรายการที่ต้องแก้ไขก่อน';
    }
    elseif($step === 2){
        $db = ['host' => trim($old('db_host')), 'port' => (int)$old('db_port'), 'name' => trim($old('db_name')),
               'user' => trim($old('db_user')), 'pass' => (string)$old('db_pass')];
        $demo = !empty($_POST['demo']);
        if($db['host'] === '' || $db['user'] === '' || $db['port'] < 1 || $db['port'] > 65535) $errors[] = 'กรอก host, port และผู้ใช้ให้ครบ';
        if(!inst_valid_db_name($db['name'])) $errors[] = 'ชื่อฐานข้อมูลใช้ได้เฉพาะ A-Z a-z 0-9 _';
        if(!$errors){
            [$c, $err] = inst_db_connect($db);
            if(!$c) $errors[] = 'เชื่อมต่อไม่สำเร็จ: '.$err;
            elseif(isset($_POST['test'])){
                $exists = @mysqli_select_db($c, $db['name']);
                $tables = $exists ? inst_db_table_count($c) : 0;
                $notice = 'เชื่อมต่อสำเร็จ (MySQL '.mysqli_get_server_info($c).') — '.(!$exists ? 'ยังไม่มีฐาน `'.$db['name'].'` ตัวติดตั้งจะสร้างให้'
                        : ($tables ? 'ฐาน `'.$db['name'].'` มีอยู่แล้ว '.$tables.' ตาราง (ต้องใช้ฐานว่าง)' : 'ฐาน `'.$db['name'].'` ว่าง พร้อมติดตั้ง'));
            } else {
                $err = inst_db_prepare($c, $db['name']);
                if($err) $errors[] = $err;
                elseif(($n = inst_db_table_count($c)) > 0) $errors[] = 'ฐาน `'.$db['name'].'` มีตารางอยู่แล้ว '.$n.' ตาราง — ตัวติดตั้งใช้ได้กับฐานว่างเท่านั้น (กันเขียนทับข้อมูลเดิม)';
                else {
                    $r = inst_run_schema($c, $demo);
                    if(!$r['ok']) $errors[] = 'นำเข้าสคีมาไม่สำเร็จ — '.$r['error'];
                    else {
                        $S['db'] = $db; $S['demo'] = $demo; $S['schema'] = $r;
                        $S['reached'] = max($S['reached'], 3);
                        inst_go(3);
                    }
                }
            }
        }
    }
    elseif($step === 3){
        $name = trim($old('name')); $email = strtolower(trim($old('email')));
        $errors = inst_validate_admin($name, $email, (string)$old('password'), (string)$old('password2'));
        if(!$errors){
            [$c, $err] = inst_db_connect($S['db']);
            if(!$c || inst_db_prepare($c, $S['db']['name'])) $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: '.$err;
            elseif(!($id = inst_create_admin($c, $name, $email, (string)$old('password')))) $errors[] = 'สร้างบัญชีไม่สำเร็จ: '.mysqli_error($c);
            else { $S['admin'] = ['id' => $id, 'name' => $name, 'email' => $email]; $S['reached'] = max($S['reached'], 4); inst_go(4); }
        }
    }
    elseif($step === 4){
        $siteName = trim($old('site_name'));
        $baseUrl  = inst_normalize_base_url($old('base_url'));
        $env      = $old('app_env') === 'dev' ? 'dev' : 'prod';
        $provider = (string)$old('gw_provider'); $mode = (string)$old('gw_mode', 'test');
        $pub = trim($old('gw_public_key')); $sec = trim($old('gw_secret_key')); $whk = trim($old('gw_webhook_secret'));
        if($siteName === '') $errors[] = 'กรอกชื่อเว็บไซต์';
        if($baseUrl === false || $baseUrl === '') $errors[] = 'Base URL ต้องขึ้นต้นด้วย http:// หรือ https://';
        if($provider === 'mock' && $env !== 'dev') $errors[] = 'gateway จำลอง (mock) ใช้ได้เฉพาะ APP_ENV = dev';
        $errors = array_merge($errors, inst_validate_gateway($provider, $mode, $pub, $sec));
        if(!$errors){
            [$c, $err] = inst_db_connect($S['db']);
            if(!$c || inst_db_prepare($c, $S['db']['name'])) $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: '.$err;
            else {
                foreach(['site_name' => $siteName, 'site_base_url' => $baseUrl, 'gw_provider' => $provider, 'gw_mode' => $mode,
                         'gw_public_key' => $pub, 'gw_secret_key' => $sec, 'gw_webhook_secret' => $whk] as $k => $v) inst_setting($c, $k, $v);
                $S['site'] = ['name' => $siteName, 'url' => $baseUrl, 'env' => $env, 'provider' => $provider, 'mode' => $mode, 'keys' => $pub !== '' && $sec !== ''];
                $S['reached'] = 5;
                inst_go(5);
            }
        }
    }
    elseif($step === 5){
        $info = ['db' => $S['db']['name'], 'admin' => $S['admin']['email'], 'demo' => !empty($S['demo']), 'schema_version' => array_key_last($S['schema']['files'])];
        if($awaitingConfig){
            // ผู้ใช้วาง config เองแล้ว → เขียนแค่ล็อก
            if(!is_file(INST_CONFIG)) $errors[] = 'ยังไม่พบไฟล์ core/config.local.php';
            elseif(!@file_put_contents(INST_LOCK, json_encode(['installed_at' => date('c')] + $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) $errors[] = 'เขียน storage/install.lock ไม่ได้';
            else { @unlink(INST_TOKEN); $S['done'] = true; unset($S['awaiting_config']); }
        } else {
            $r = inst_finish(inst_render_config($S['db'], $S['site']['env']), $info);
            if($r === 'config'){ $S['awaiting_config'] = true; $awaitingConfig = true; }
            elseif($r === 'lock') $errors[] = 'เขียน config แล้ว แต่เขียน storage/install.lock ไม่ได้ — ตรวจสิทธิ์โฟลเดอร์ storage/';
            else $S['done'] = true;
        }
        if(!empty($S['done'])){
            $done = ['admin' => $S['admin']['email'], 'url' => $S['site']['url']];
            session_regenerate_id(true);
            $_SESSION = ['finished' => $done];
            header('Location: '.$self.'?step=5&done=1'); exit;
        }
    }
}

// หน้าสรุปหลังติดตั้ง (ล็อกแล้ว แต่ session นี้เพิ่งติดตั้งเสร็จ)
$finished = $_SESSION['finished'] ?? null;
if($step === 0 && $finished && isset($_GET['done'])) $step = 6;

$requirements = $step === 1 ? inst_requirements() : [];
$labels = [1 => 'ตรวจเซิร์ฟเวอร์', 2 => 'ฐานข้อมูล', 3 => 'แอดมิน', 4 => 'เว็บไซต์ & ชำระเงิน', 5 => 'ติดตั้ง'];
$guessUrl = ($https ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').$base;
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ติดตั้ง Aleanor Cloud</title>
<link rel="icon" href="<?= e($base) ?>assets/brand/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=K2D:wght@500;600;700&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--primary:#0891b2;--primary-dark:#0e7490;--bg:#f0f9ff;--card:#fff;--surface2:#e0f2fe;--border:#cfe9fb;--text:#0c2433;--muted:#4a6b80;--ok:#059669;--bad:#dc2626;--warn:#b45309;
  --hero:linear-gradient(140deg,#0c4a6e 0%,#0891b2 60%,#06b6d4 100%);--radius:18px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Sarabun','Noto Sans Thai',sans-serif;background:var(--bg);color:var(--text);line-height:1.65;-webkit-font-smoothing:antialiased;min-height:100vh}
.top{background:var(--hero);color:#fff;padding:28px 16px 84px;text-align:center}
.top img{height:44px;vertical-align:middle}
.top h1{font-family:'K2D',sans-serif;font-size:26px;margin-top:8px}
.top p{color:#bae6fd}
.wrap{max-width:760px;margin:-60px auto 40px;padding:0 16px}
.steps{display:flex;gap:6px;margin-bottom:14px;list-style:none}
.steps li{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:rgba(255,255,255,.85);border-radius:10px;padding:8px 6px;text-align:center;font-size:13px;color:var(--muted);border:1px solid var(--border)}
.steps li b{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:var(--surface2);color:var(--primary-dark);margin-right:4px;font-size:12px}
.steps li.on{background:#fff;color:var(--text);font-weight:600;border-color:var(--primary)}
.steps li.on b,.steps li.done b{background:var(--primary);color:#fff}
.card{background:var(--card);border-radius:var(--radius);box-shadow:0 18px 40px -16px rgba(15,18,40,.16);padding:28px;border:1px solid var(--border)}
h2{font-family:'K2D',sans-serif;font-size:22px;margin-bottom:4px}
.sub{color:var(--muted);margin-bottom:18px}
table{width:100%;border-collapse:collapse}
td{padding:8px 4px;border-bottom:1px solid var(--surface2);font-size:15px}
td:last-child{text-align:right;color:var(--muted)}
.ok{color:var(--ok);font-weight:600}.bad{color:var(--bad);font-weight:600}.opt{color:var(--warn);font-weight:600}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 16px}
.full{grid-column:1/-1}
label{display:block;font-weight:600;font-size:14px;margin-bottom:4px}
label small{font-weight:400;color:var(--muted)}
input[type=text],input[type=email],input[type=password],input[type=number],input[type=url],select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font:inherit;background:#fff;color:var(--text)}
input:focus,select:focus{outline:2px solid rgba(8,145,178,.35);border-color:var(--primary)}
.check{display:flex;gap:10px;align-items:flex-start;background:var(--surface2);border-radius:10px;padding:12px;font-weight:400}
.check input{margin-top:5px}
.actions{display:flex;justify-content:space-between;gap:10px;margin-top:22px;flex-wrap:wrap}
.btn{display:inline-block;border:0;border-radius:9px;padding:11px 20px;font:600 15px 'Sarabun',sans-serif;cursor:pointer;text-decoration:none;background:var(--primary);color:#fff}
.btn:hover{background:var(--primary-dark)}
.btn.ghost{background:#fff;color:var(--primary-dark);border:1px solid var(--border)}
.alert{border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:15px}
.alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.alert.info{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.alert.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.alert ul{margin-left:18px}
fieldset{border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-top:6px}
legend{font-weight:600;padding:0 6px}
.radios{display:flex;gap:16px;flex-wrap:wrap}
.radios label{font-weight:400;display:flex;gap:6px;align-items:center}
dl{display:grid;grid-template-columns:170px 1fr;gap:6px 12px;margin:8px 0 4px}
dt{color:var(--muted)}
textarea{width:100%;height:220px;font:13px ui-monospace,Menlo,monospace;border:1px solid var(--border);border-radius:9px;padding:10px}
code{background:var(--surface2);padding:1px 6px;border-radius:5px;font-size:.92em}
@media (max-width:820px){.steps li{font-size:12px}}
@media (max-width:640px){.grid{grid-template-columns:1fr}.steps li span{display:none}dl{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="top">
  <img src="<?= e($base) ?>assets/brand/logo.png" alt="">
  <h1>ติดตั้ง Aleanor Cloud</h1>
  <p>ตั้งค่าครั้งแรก: เซิร์ฟเวอร์ ฐานข้อมูล แอดมิน และการชำระเงิน</p>
</header>
<main class="wrap">
<?php if($step >= 1 && $step <= 5): ?>
  <ol class="steps">
  <?php foreach($labels as $i => $l): ?>
    <li class="<?= $i === $step ? 'on' : ($i < $S['reached'] ? 'done' : '') ?>"><b><?= $i ?></b><span><?= e($l) ?></span></li>
  <?php endforeach; ?>
  </ol>
<?php endif; ?>
<section class="card">
<?php if($errors): ?><div class="alert err"><ul><?php foreach($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if($notice): ?><div class="alert info"><?= e($notice) ?></div><?php endif; ?>

<?php if($step === -1): ?>
  <h2>ยืนยันว่าคุณเป็นผู้ติดตั้ง</h2>
  <p class="sub">กันคนอื่นที่เปิด install/ เจอก่อนคุณ — โทเคนอยู่ในไฟล์บนเซิร์ฟเวอร์เท่านั้น</p>
  <?php if($tokenFile): ?>
    <p>เปิดไฟล์ <code><?= e($tokenFile) ?></code> ในโฟลเดอร์ของแอป (ผ่าน SSH / FTP / File Manager ของโฮสต์ หรือ Finder บนเครื่องพัฒนา) แล้วคัดลอกโทเคนมาวาง</p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="grid" style="margin-top:14px"><div class="full"><label>โทเคนติดตั้ง</label><input type="text" name="setup_token" autocomplete="off" spellcheck="false" required autofocus></div></div>
      <div class="actions"><span></span><button class="btn">ยืนยัน →</button></div>
    </form>
  <?php else: ?>
    <div class="alert err">เขียนไฟล์ <code>storage/install.token</code> ไม่ได้ — ให้สิทธิ์เว็บเซิร์ฟเวอร์เขียนโฟลเดอร์ <code>storage/</code> แล้วโหลดหน้านี้ใหม่</div>
  <?php endif; ?>

<?php elseif($step === 0): ?>
  <h2>ติดตั้งแล้ว</h2>
  <p class="sub">ระบบนี้ติดตั้งเรียบร้อยแล้ว ตัวติดตั้งจึงถูกล็อกไว้</p>
  <p>ถ้าต้องการติดตั้งใหม่จริง ๆ ให้ผู้ดูแลเซิร์ฟเวอร์ลบ <code>storage/install.lock</code> และ <code>core/config.local.php</code> ก่อน (ใช้ฐานข้อมูลว่างใหม่)</p>
  <div class="actions"><a class="btn" href="<?= e($base) ?>">ไปหน้าเว็บไซต์</a></div>

<?php elseif($step === 6): ?>
  <h2>ติดตั้งเสร็จแล้ว 🎉</h2>
  <p class="sub">เขียน <code>core/config.local.php</code> และล็อกตัวติดตั้ง (<code>storage/install.lock</code>) เรียบร้อย</p>
  <dl><dt>เข้าสู่ระบบแอดมินด้วย</dt><dd><?= e($finished['admin']) ?></dd><dt>เว็บไซต์</dt><dd><?= e($finished['url']) ?></dd></dl>
  <div class="alert warn">ขั้นต่อไป: ตั้ง cron (ดู README), ตั้ง SMTP ใน แอดมิน → อีเมล และลงทะเบียน webhook ใน แอดมิน → ตั้งค่า</div>
  <div class="actions"><a class="btn ghost" href="<?= e($base) ?>">หน้าเว็บไซต์</a><a class="btn" href="<?= e($base) ?>index.php?p=login">เข้าสู่ระบบ</a></div>

<?php elseif($step === 1): ?>
  <h2>1. ตรวจความพร้อมของเซิร์ฟเวอร์</h2>
  <p class="sub">เวอร์ชัน PHP ส่วนขยายที่แอปใช้ และโฟลเดอร์ที่ต้องเขียนได้</p>
  <table>
  <?php foreach($requirements as $r): ?>
    <tr><td><?= $r['ok'] ? '<span class="ok">✓</span>' : ($r['required'] ? '<span class="bad">✗</span>' : '<span class="opt">!</span>') ?> <?= e($r['label']) ?></td><td><?= e($r['detail']) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <form method="post" class="actions">
    <?= csrf_field() ?>
    <a class="btn ghost" href="?step=1">ตรวจอีกครั้ง</a>
    <button class="btn" <?= inst_requirements_ok($requirements) ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>ถัดไป →</button>
  </form>

<?php elseif($step === 2): $d = $S['db'] ?? []; ?>
  <h2>2. ฐานข้อมูล</h2>
  <p class="sub">ใช้ฐานข้อมูลว่าง (ถ้ายังไม่มีและผู้ใช้มีสิทธิ์ ตัวติดตั้งจะสร้างให้) แล้วนำเข้าสคีมา + migration ใน <code>sql/</code></p>
  <?php if(!empty($S['schema'])): ?><div class="alert info">นำเข้าสคีมาในฐาน `<?= e($S['db']['name']) ?>` แล้ว — ไปขั้นถัดไปได้เลย</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid">
      <div><label>Host</label><input type="text" name="db_host" value="<?= e($old('db_host', $d['host'] ?? 'localhost')) ?>" required></div>
      <div><label>Port <small>(MAMP 8889, ทั่วไป 3306)</small></label><input type="number" name="db_port" value="<?= e($old('db_port', $d['port'] ?? 3306)) ?>" required></div>
      <div class="full"><label>ชื่อฐานข้อมูล</label><input type="text" name="db_name" value="<?= e($old('db_name', $d['name'] ?? 'aleanor_cloud')) ?>" pattern="[A-Za-z0-9_]+" required></div>
      <div><label>ผู้ใช้</label><input type="text" name="db_user" value="<?= e($old('db_user', $d['user'] ?? '')) ?>" autocomplete="off" required></div>
      <div><label>รหัสผ่าน</label><input type="password" name="db_pass" value="" autocomplete="new-password"></div>
      <label class="check full"><input type="checkbox" name="demo" value="1" <?= $old('demo', !empty($S['demo'])) ? 'checked' : '' ?>>
        <span><b>ใส่ข้อมูลตัวอย่าง</b><br><small>บัญชีทดสอบ admin/teacher/student@aleanor.test (รหัส test1234) + คอร์สตัวอย่าง 1 คอร์ส — สำหรับเครื่องพัฒนาเท่านั้น อย่าติ๊กบน production</small></span></label>
    </div>
    <div class="actions">
      <a class="btn ghost" href="?step=1">← ย้อนกลับ</a>
      <span><button class="btn ghost" name="test" value="1">ทดสอบการเชื่อมต่อ</button>
      <?php if(!empty($S['schema'])): ?><a class="btn" href="?step=3">ถัดไป →</a><?php else: ?><button class="btn" name="install" value="1">ติดตั้งฐานข้อมูล →</button><?php endif; ?></span>
    </div>
  </form>

<?php elseif($step === 3): $a = $S['admin'] ?? []; ?>
  <h2>3. บัญชีแอดมินคนแรก</h2>
  <p class="sub">ฐาน `<?= e($S['db']['name']) ?>` พร้อมแล้ว (<?= count($S['schema']['files']) ?> ไฟล์<?= $S['demo'] ? ', มีข้อมูลตัวอย่าง' : '' ?>) — สร้างผู้ดูแลระบบ</p>
  <?php if($a): ?><div class="alert info">สร้างแอดมิน <?= e($a['email']) ?> แล้ว — ส่งฟอร์มอีกครั้งเพื่อแก้ไข หรือไปขั้นถัดไป</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="full"><label>ชื่อที่แสดง</label><input type="text" name="name" value="<?= e($old('name', $a['name'] ?? 'ผู้ดูแลระบบ')) ?>" required></div>
      <div class="full"><label>อีเมล (ใช้เข้าสู่ระบบ)</label><input type="email" name="email" value="<?= e($old('email', $a['email'] ?? '')) ?>" required></div>
      <div><label>รหัสผ่าน <small>(อย่างน้อย 8 ตัว)</small></label><input type="password" name="password" minlength="8" autocomplete="new-password" required></div>
      <div><label>ยืนยันรหัสผ่าน</label><input type="password" name="password2" minlength="8" autocomplete="new-password" required></div>
    </div>
    <div class="actions"><a class="btn ghost" href="?step=2">← ย้อนกลับ</a>
      <span><?php if($a): ?><a class="btn ghost" href="?step=4">ข้าม →</a> <?php endif; ?><button class="btn">สร้างแอดมิน →</button></span></div>
  </form>

<?php elseif($step === 4): $s = $S['site'] ?? []; $prov = $old('gw_provider', $s['provider'] ?? ''); $mode = $old('gw_mode', $s['mode'] ?? 'test'); $env = $old('app_env', $s['env'] ?? 'prod'); ?>
  <h2>4. เว็บไซต์และการชำระเงิน</h2>
  <p class="sub">แก้ไขภายหลังได้ที่ แอดมิน → ตั้งค่า</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid">
      <div class="full"><label>ชื่อเว็บไซต์</label><input type="text" name="site_name" value="<?= e($old('site_name', $s['name'] ?? 'Aleanor Cloud')) ?>" required></div>
      <div class="full"><label>Base URL <small>(ใช้กับลิงก์ในอีเมล, return URL และ webhook ของ gateway)</small></label><input type="url" name="base_url" value="<?= e($old('base_url', $s['url'] ?? $guessUrl)) ?>" required></div>
      <div class="full"><label>สภาพแวดล้อม (APP_ENV)</label>
        <div class="radios"><label><input type="radio" name="app_env" value="prod" <?= $env !== 'dev' ? 'checked' : '' ?>> prod — ใช้งานจริง (ซ่อน error)</label>
        <label><input type="radio" name="app_env" value="dev" <?= $env === 'dev' ? 'checked' : '' ?>> dev — เครื่องพัฒนา (แสดง error, ใช้ gateway จำลองได้)</label></div></div>
    </div>
    <fieldset>
      <legend>ช่องทางชำระเงิน</legend>
      <div class="grid">
        <div><label>ผู้ให้บริการ</label><select name="gw_provider">
          <?php foreach(['' => 'ยังไม่ตั้งค่า', 'omise' => 'Omise (บัตร / PromptPay)', 'stripe' => 'Stripe Checkout', 'mock' => 'จำลอง (dev เท่านั้น)'] as $v => $l): ?>
            <option value="<?= e($v) ?>" <?= $prov === $v ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?></select></div>
        <div><label>โหมด</label><div class="radios" style="padding-top:8px">
          <label><input type="radio" name="gw_mode" value="test" <?= $mode !== 'live' ? 'checked' : '' ?>> test</label>
          <label><input type="radio" name="gw_mode" value="live" <?= $mode === 'live' ? 'checked' : '' ?>> live</label></div></div>
        <div><label>Public key <small>(ไม่บังคับ)</small></label><input type="text" name="gw_public_key" value="<?= e($old('gw_public_key')) ?>" autocomplete="off" placeholder="pkey_test_… / pk_test_…"></div>
        <div><label>Secret key <small>(ไม่บังคับ)</small></label><input type="password" name="gw_secret_key" value="" autocomplete="new-password" placeholder="skey_test_… / sk_test_…"></div>
        <div class="full"><label>Webhook secret <small>(ไม่บังคับ — Stripe whsec_…)</small></label><input type="password" name="gw_webhook_secret" value="" autocomplete="new-password"></div>
      </div>
    </fieldset>
    <div class="actions"><a class="btn ghost" href="?step=3">← ย้อนกลับ</a><button class="btn">บันทึก →</button></div>
  </form>

<?php elseif($step === 5): $s = $S['site']; ?>
  <h2>5. ตรวจสอบและติดตั้ง</h2>
  <?php if($awaitingConfig): ?>
    <div class="alert warn">โฟลเดอร์ <code>core/</code> เขียนไม่ได้ — คัดลอกข้อความด้านล่างไปบันทึกเป็น <code>core/config.local.php</code> บนเซิร์ฟเวอร์ แล้วกด “บันทึกแล้ว”</div>
    <textarea readonly><?= e(inst_render_config($S['db'], $s['env'])) ?></textarea>
  <?php else: ?>
    <p class="sub">กด “ติดตั้ง” เพื่อเขียน <code>core/config.local.php</code> และล็อกตัวติดตั้ง</p>
  <?php endif; ?>
  <dl>
    <dt>ฐานข้อมูล</dt><dd><?= e($S['db']['user'].'@'.$S['db']['host'].':'.$S['db']['port'].' / '.$S['db']['name']) ?> (สคีมา <?= e(array_key_last($S['schema']['files'])) ?><?= $S['demo'] ? ', มีข้อมูลตัวอย่าง' : '' ?>)</dd>
    <dt>แอดมิน</dt><dd><?= e($S['admin']['email']) ?></dd>
    <dt>เว็บไซต์</dt><dd><?= e($s['name']) ?> — <?= e($s['url']) ?></dd>
    <dt>APP_ENV</dt><dd><?= e($s['env']) ?></dd>
    <dt>ชำระเงิน</dt><dd><?= $s['provider'] === '' ? 'ยังไม่ตั้งค่า' : e(ucfirst($s['provider']).' · '.$s['mode'].' · '.($s['provider'] === 'mock' ? 'ไม่ต้องใช้คีย์' : ($s['keys'] ? 'ใส่คีย์แล้ว' : 'ยังไม่ใส่คีย์'))) ?></dd>
  </dl>
  <form method="post" class="actions">
    <?= csrf_field() ?>
    <a class="btn ghost" href="?step=4">← ย้อนกลับ</a>
    <button class="btn"><?= $awaitingConfig ? 'บันทึกแล้ว — ล็อกตัวติดตั้ง' : 'ติดตั้ง ✓' ?></button>
  </form>
<?php endif; ?>
</section>
</main>
</body>
</html>
