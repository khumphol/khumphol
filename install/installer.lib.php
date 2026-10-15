<?php
// ============================================================
// ตัวติดตั้งครั้งแรก — ฟังก์ชันล้วน (ไม่พึ่ง bootstrap/DB ของแอป) ใช้ทั้ง install/index.php และ tests/install_test.php
// ============================================================
if(!defined('ALEANOR_INSTALLER')) { http_response_code(404); exit; }

define('INST_ROOT', dirname(__DIR__));
define('INST_MIN_PHP', '8.0.0');
if(!defined('INST_CONFIG'))  define('INST_CONFIG', INST_ROOT.'/core/config.local.php');
if(!defined('INST_EXAMPLE')) define('INST_EXAMPLE', INST_ROOT.'/core/config.local.example.php');
if(!defined('INST_LOCK'))    define('INST_LOCK', INST_ROOT.'/storage/install.lock');

/** ติดตั้งแล้ว = มี install.lock หรือมี config.local.php อยู่แล้ว (เครื่องที่ตั้งค่ามือก่อนมีตัวติดตั้ง) */
function inst_installed(){ return is_file(INST_LOCK) || is_file(INST_CONFIG); }

// ── 1) ตรวจความพร้อมของเซิร์ฟเวอร์ ──
/** คืน [ ['label','ok','detail','required'], ... ] */
function inst_requirements(){
    $rows = [];
    $rows[] = ['label' => 'PHP '.INST_MIN_PHP.' ขึ้นไป', 'ok' => version_compare(PHP_VERSION, INST_MIN_PHP, '>='), 'detail' => 'ตอนนี้ '.PHP_VERSION, 'required' => true];
    // ส่วนขยายที่โค้ดเรียกใช้จริง: mysqli (DB), mbstring (ข้อความไทย), openssl/curl (gateway/อีเมล/SSO),
    // zip + dom (Docs → pptx/docx), fileinfo (ตรวจชนิดไฟล์อัปโหลด), json/session/hash (ทุกหน้า)
    $exts = ['mysqli' => true, 'json' => true, 'mbstring' => true, 'openssl' => true, 'curl' => true,
             'zip' => true, 'dom' => true, 'fileinfo' => true, 'session' => true, 'hash' => true, 'iconv' => false];
    foreach($exts as $e => $req)
        $rows[] = ['label' => 'ส่วนขยาย '.$e, 'ok' => extension_loaded($e), 'detail' => extension_loaded($e) ? 'มี' : ($req ? 'ไม่มี — ต้องติดตั้ง' : 'ไม่มี (ไม่บังคับ)'), 'required' => $req];
    foreach(inst_writable_dirs() as $rel => $req){
        $p = INST_ROOT.'/'.$rel;
        if(!is_dir($p)) @mkdir($p, 0775, true);
        $ok = is_dir($p) && is_writable($p);
        $rows[] = ['label' => 'เขียนได้: '.$rel.'/', 'ok' => $ok, 'detail' => $ok ? 'เขียนได้' : ($req ? 'เขียนไม่ได้ — chmod ให้เว็บเซิร์ฟเวอร์เขียนได้' : 'เขียนไม่ได้ — จะแสดง config ให้คัดลอกเอง'), 'required' => $req];
    }
    return $rows;
}
function inst_writable_dirs(){
    return ['uploads' => true, 'uploads/covers' => true, 'uploads/slips' => true, 'uploads/docs' => true, 'uploads/indy' => true,
            'storage' => true, 'storage/logs' => true, 'core' => false];
}
function inst_requirements_ok(array $rows){
    foreach($rows as $r) if($r['required'] && !$r['ok']) return false;
    return true;
}

// ── 2) ฐานข้อมูล ──
/** เชื่อมต่อ (ยังไม่เลือกฐาน) → [mysqli|null, error] */
function inst_db_connect(array $db){
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @mysqli_connect($db['host'], $db['user'], $db['pass'], '', (int)$db['port']);
    if(!$c) return [null, mysqli_connect_error() ?: 'เชื่อมต่อไม่สำเร็จ'];
    mysqli_set_charset($c, 'utf8mb4');
    return [$c, ''];
}
function inst_valid_db_name($n){ return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $n); }

/** สร้างฐาน (ถ้ายังไม่มีและสิทธิ์พอ) แล้วเลือกใช้ → error หรือ '' */
function inst_db_prepare(mysqli $c, $name){
    if(!inst_valid_db_name($name)) return 'ชื่อฐานข้อมูลใช้ได้เฉพาะ A-Z a-z 0-9 _';
    if(!@mysqli_select_db($c, $name)){
        if(!@mysqli_query($c, "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"))
            return 'ไม่มีฐาน `'.$name.'` และสร้างเองไม่ได้ ('.mysqli_error($c).') — สร้างฐานว่างใน hosting panel แล้วลองใหม่';
        if(!@mysqli_select_db($c, $name)) return 'เลือกฐาน `'.$name.'` ไม่ได้: '.mysqli_error($c);
    }
    mysqli_query($c, "SET collation_connection='utf8mb4_general_ci', time_zone='+07:00'");
    return '';
}
/** จำนวนตารางในฐานที่เลือกอยู่ — ตัวติดตั้งรับเฉพาะฐานว่าง กันเขียนทับฐานที่ใช้งานอยู่ */
function inst_db_table_count(mysqli $c){
    $r = mysqli_query($c, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    return $r ? (int)mysqli_fetch_row($r)[0] : -1;
}

/** ไฟล์สคีมา + migration ตามลำดับ (sql/0*.sql) */
function inst_sql_files(){
    $f = glob(INST_ROOT.'/sql/0*.sql') ?: [];
    sort($f, SORT_STRING);
    return $f;
}
// ข้อมูลตัวอย่างใน 002_seed.sql (บัญชีทดสอบ + คอร์สตัวอย่าง) — ข้ามเมื่อไม่ได้ติ๊ก "ใส่ข้อมูลตัวอย่าง"
function inst_demo_tables(){ return ['users', 'instructor_profiles', 'courses', 'course_sections', 'lessons']; }

/** แยกคำสั่ง SQL (รองรับ -- comment และสตริงที่มี ; อยู่ข้างใน) */
function inst_split_sql($sql){
    $out = []; $buf = ''; $len = strlen($sql); $q = null;
    for($i = 0; $i < $len; $i++){
        $ch = $sql[$i];
        if($q === null){
            if($ch === '-' && ($sql[$i + 1] ?? '') === '-'){ $nl = strpos($sql, "\n", $i); $i = $nl === false ? $len : $nl; $buf .= "\n"; continue; }
            if($ch === '#'){ $nl = strpos($sql, "\n", $i); $i = $nl === false ? $len : $nl; $buf .= "\n"; continue; }
            if($ch === '/' && ($sql[$i + 1] ?? '') === '*'){ $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $len : $e + 1; continue; }
            if($ch === "'" || $ch === '"' || $ch === '`') $q = $ch;
            elseif($ch === ';'){ if(trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
        } else {
            if($ch === '\\'){ $buf .= $ch.($sql[$i + 1] ?? ''); $i++; continue; }
            if($ch === $q){
                if(($sql[$i + 1] ?? '') === $q){ $buf .= $ch.$ch; $i++; continue; }   // '' ภายในสตริง
                $q = null;
            }
        }
        $buf .= $ch;
    }
    if(trim($buf) !== '') $out[] = trim($buf);
    return $out;
}
function inst_is_demo_stmt($stmt){
    if(!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $stmt, $m)) return false;
    return in_array(strtolower($m[1]), inst_demo_tables(), true);
}

/** รันสคีมา + migration ทั้งหมด → ['ok','error','files'=>[ชื่อไฟล์=>จำนวนคำสั่ง],'skipped'] */
function inst_run_schema(mysqli $c, $withDemo){
    $res = ['ok' => true, 'error' => '', 'files' => [], 'skipped' => 0];
    foreach(inst_sql_files() as $file){
        $n = 0;
        foreach(inst_split_sql((string)file_get_contents($file)) as $stmt){
            if(!$withDemo && inst_is_demo_stmt($stmt)){ $res['skipped']++; continue; }
            if(!mysqli_query($c, $stmt)){
                return ['ok' => false, 'error' => basename($file).': '.mysqli_error($c), 'files' => $res['files'], 'skipped' => $res['skipped']];
            }
            $n++;
        }
        $res['files'][basename($file)] = $n;
    }
    $last = $res['files'] ? substr((string)array_key_last($res['files']), 0, 3) : '';
    inst_setting($c, 'schema_version', $last);
    return $res;
}

function inst_setting(mysqli $c, $k, $v){
    $st = mysqli_prepare($c, "INSERT INTO settings (k, v, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)");
    $v = (string)$v;
    mysqli_stmt_bind_param($st, 'ss', $k, $v);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    return $ok;
}

// ── 3) แอดมินคนแรก ──
/** ตรวจค่าแอดมิน → [errors] */
function inst_validate_admin($name, $email, $pass, $pass2){
    $e = [];
    if(trim($name) === '') $e[] = 'กรอกชื่อ';
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'อีเมลไม่ถูกต้อง';
    if(strlen($pass) < 8) $e[] = 'รหัสผ่านอย่างน้อย 8 ตัวอักษร';
    if($pass !== $pass2) $e[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';
    return $e;
}
/** สร้างแอดมิน (ถ้าอีเมลซ้ำกับบัญชีตัวอย่าง → อัปเดตเป็นแอดมิน + ตั้งรหัสใหม่) → id */
function inst_create_admin(mysqli $c, $name, $email, $pass){
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = mysqli_prepare($c, "INSERT INTO users (email, password_hash, name, is_admin, status, created_at) VALUES (?, ?, ?, 1, 1, NOW())
                              ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name), is_admin = 1, status = 1");
    mysqli_stmt_bind_param($st, 'sss', $email, $hash, $name);
    if(!mysqli_stmt_execute($st)) return 0;
    mysqli_stmt_close($st);
    $st = mysqli_prepare($c, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($st, 's', $email);
    mysqli_stmt_execute($st);
    $id = (int)(mysqli_fetch_row(mysqli_stmt_get_result($st))[0] ?? 0);
    mysqli_stmt_close($st);
    if($id){
        $st = mysqli_prepare($c, "INSERT INTO audit_logs (user_id, action, entity, entity_id, before_json, after_json, ip, created_at) VALUES (?, 'install.admin', 'user', ?, NULL, NULL, ?, NOW())");
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        mysqli_stmt_bind_param($st, 'iis', $id, $id, $ip);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
    return $id;
}

// ── 4) เว็บไซต์ + การชำระเงิน ──
/** ตรวจคีย์ให้ตรงผู้ให้บริการและโหมด (test/live) — คีย์ว่างได้ (ตั้งทีหลังใน แอดมิน → ตั้งค่า) → [errors] */
function inst_validate_gateway($provider, $mode, $pub, $sec){
    $e = [];
    if(!in_array($provider, ['', 'omise', 'stripe', 'mock'], true)) $e[] = 'ผู้ให้บริการชำระเงินไม่ถูกต้อง';
    if(!in_array($mode, ['test', 'live'], true)) $e[] = 'โหมดต้องเป็น test หรือ live';
    if($e || $provider === '' || $provider === 'mock') return $e;
    $pfx = [
        'omise'  => ['test' => ['pkey_test_', 'skey_test_'], 'live' => ['pkey_', 'skey_']],
        'stripe' => ['test' => ['pk_test_', 'sk_test_'],     'live' => ['pk_live_', 'sk_live_']],
    ][$provider][$mode];
    foreach([[$pub, $pfx[0], 'Public key'], [$sec, $pfx[1], 'Secret key']] as [$k, $p, $label]){
        if($k === '') continue;
        $bad = strpos($k, $p) !== 0 || ($provider === 'omise' && $mode === 'live' && strpos($k, $p.'test_') === 0);
        if($bad) $e[] = $label.' ไม่ตรงกับ '.ucfirst($provider).' โหมด '.$mode.' (ต้องขึ้นต้นด้วย '.$p.($provider === 'omise' && $mode === 'live' ? ' ที่ไม่ใช่ test' : '').')';
    }
    return $e;
}
function inst_normalize_base_url($u){
    $u = trim($u);
    if($u === '') return '';
    if(!preg_match('~^https?://[^\s/]+~i', $u)) return false;
    return rtrim($u, '/').'/';
}

// ── 5) config.local.php (สร้างจาก config.local.example.php) ──
function inst_render_config(array $db, $appEnv){
    $tpl = (string)@file_get_contents(INST_EXAMPLE);
    $vals = ['DB_HOST' => (string)$db['host'], 'DB_PORT' => (int)$db['port'], 'DB_USERNAME' => (string)$db['user'],
             'DB_PASSWORD' => (string)$db['pass'], 'DB_NAME' => (string)$db['name'], 'APP_ENV' => $appEnv === 'dev' ? 'dev' : 'prod'];
    foreach($vals as $k => $v){
        $line = "if (!defined('$k')) define('$k', ".var_export($v, true).");";
        $tpl = preg_replace('/^if \(!defined\(\''.$k.'\'\)\)\s*define\(\''.$k.'\',.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $tpl, 1, $cnt);
        if(!$cnt) $tpl .= $line."\n";
    }
    // ตัดคอมเมนต์ "วิธีคัดลอก" หัวไฟล์ตัวอย่าง แล้วใส่หัวของตัวติดตั้งแทน
    $tpl = preg_replace('/^<\?php\s*\n(?:\/\/[^\n]*\n)*/', '', $tpl, 1);
    return "<?php\n// สร้างโดยตัวติดตั้ง (install/) เมื่อ ".date('Y-m-d H:i:s')." — มีรหัสผ่านฐานข้อมูล ห้าม commit (อยู่ใน .gitignore)\n".$tpl;
}
/** เขียน config + install.lock → error หรือ '' */
function inst_finish($configPhp, array $info){
    if(!@file_put_contents(INST_CONFIG, $configPhp, LOCK_EX)) return 'config';
    @chmod(INST_CONFIG, 0640);
    $lock = json_encode(['installed_at' => date('c')] + $info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if(!@file_put_contents(INST_LOCK, $lock, LOCK_EX)) return 'lock';
    return '';
}
