<?php
// ============================================================
// ตัวช่วยทั่วไป: escape, เงิน, URL, flash, settings, audit, auth
// ============================================================

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function now(){ return date('Y-m-d H:i:s'); }
function money($v){ return number_format((float)$v, 2); }
function baht($v){ return '฿'.money($v); }
function post($k, $d = ''){ return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $d; }
function get($k, $d = ''){ return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }
function is_post(){ return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

// ── URL (แบบ ?p= เหมือน aleanor_ai เมื่อปิด pretty URL) ──
/** พาธฐานของแอปเทียบจากรากเว็บ เช่น "/2026/aleanor/aleanor_cloud/" */
function app_base(){
    static $base = null;
    if($base !== null) return $base;
    $appDir  = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));
    $docRoot = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if($docRoot !== '' && strpos($appDir, $docRoot) === 0) $base = substr($appDir, strlen($docRoot));
    else $base = preg_replace('~/(admin|instructor|api)$~', '', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'));
    $base = '/'.trim($base, '/');
    if($base !== '/') $base .= '/';
    return $base;
}
function url_q(array $q){
    $q = array_filter($q, function($v){ return $v !== null && $v !== ''; });
    return $q ? '?'.http_build_query($q) : '';
}
function u($p = '', array $q = []){       // หน้าบ้าน
    return app_base().'index.php'.url_q(array_merge($p !== '' ? ['p' => $p] : [], $q));
}
function iu($p = '', array $q = []){      // /instructor
    return app_base().'instructor/index.php'.url_q(array_merge($p !== '' ? ['p' => $p] : [], $q));
}
function au($p = '', array $q = []){      // /admin
    return app_base().'admin/index.php'.url_q(array_merge($p !== '' ? ['p' => $p] : [], $q));
}
function asset($path){ return app_base().ltrim($path, '/'); }
function site_url(){                      // URL เต็ม (ใช้กับ gateway return / webhook)
    $base = setting('site_base_url', '');
    if($base !== '') return rtrim($base, '/').'/';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').app_base();
}
function redirect($to){ header('Location: '.$to); exit; }

// ── flash message ──
function flash($msg, $type = 'success'){ $_SESSION['flash'][] = ['m' => $msg, 't' => $type]; }
function flashes(){
    $out = '';
    foreach($_SESSION['flash'] ?? [] as $f) $out .= '<div class="alert alert-'.h($f['t']).'">'.h($f['m']).'</div>';
    unset($_SESSION['flash']);
    return $out;
}
/** ตรวจ CSRF ของ POST — ไม่ผ่านให้เด้งกลับ */
function require_csrf(){
    if(!csrf_verify()){ flash('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง', 'danger'); redirect($_SERVER['REQUEST_URI'] ?? u()); }
}

// ── settings (แนว schoolSetting() ของ aleanor_ai) ──
function setting($k, $d = ''){
    static $cache = null;
    if($k === null){ $cache = null; return null; }     // ล้าง cache
    if($cache === null){
        $cache = [];
        foreach(db_all("SELECT k, v FROM settings") as $r) $cache[$r['k']] = $r['v'];
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $d;
}
function setting_set($k, $v){
    db_write("INSERT INTO settings (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
        [$k, (string)$v, now()]);
    setting(null);
}

// ── audit log ──
function audit($action, $entity, $entityId = null, $before = null, $after = null){
    db_insert("INSERT INTO audit_logs (user_id, action, entity, entity_id, before_json, after_json, ip, created_at) VALUES (?,?,?,?,?,?,?,?)", [
        current_user_id() ?: null, $action, $entity, $entityId === null ? null : (int)$entityId,
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        $after  === null ? null : json_encode($after,  JSON_UNESCAPED_UNICODE),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45), now(),
    ]);
}

// ── auth ──
function current_user($refresh = false){
    static $u = null, $for = -1;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if($refresh || $for !== $uid){
        $for = $uid; $u = null;
        if($uid){
            $u = db_one("SELECT u.*, ip.status AS instructor_status, ip.display_name AS instructor_name
                         FROM users u LEFT JOIN instructor_profiles ip ON ip.user_id = u.id
                         WHERE u.id = ? AND u.status = 1", [$uid]);
            if(!$u) unset($_SESSION['uid']);
        }
    }
    return $u;
}
function current_user_id(){ $u = current_user(); return $u ? (int)$u['id'] : 0; }
function is_admin(){ $u = current_user(); return $u && (int)$u['is_admin'] === 1; }
function is_instructor(){ $u = current_user(); return $u && $u['instructor_status'] === 'approved'; }
function login_user($id){
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$id;
    db_write("UPDATE users SET last_login_at = ? WHERE id = ?", [now(), (int)$id]);
}
function require_login(){
    if(!current_user()){
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('กรุณาเข้าสู่ระบบก่อน', 'warning');
        redirect(u('login'));
    }
}

// ── อัปโหลดรูป ──
function upload_image($field, $dir){
    if(empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if(!in_array($ext, ['jpg','jpeg','png','webp'], true) || $_FILES[$field]['size'] > 5 * 1024 * 1024) return '';
    if(@getimagesize($_FILES[$field]['tmp_name']) === false) return '';
    $fn = bin2hex(random_bytes(12)).'.'.$ext;
    $abs = dirname(__DIR__).'/uploads/'.$dir.'/'.$fn;
    return @move_uploaded_file($_FILES[$field]['tmp_name'], $abs) ? 'uploads/'.$dir.'/'.$fn : '';
}
function cover_url($c){
    return !empty($c['cover']) ? asset($c['cover']) : asset('assets/cover-placeholder.svg');
}

// ── ป้ายสถานะ ──
function status_badge($s){
    $map = [
        'draft' => ['ฉบับร่าง','gray'], 'pending_review' => ['รอตรวจ','amber'], 'published' => ['เผยแพร่','green'],
        'rejected' => ['ไม่ผ่าน','red'], 'unpublished' => ['ปิดการขาย','gray'],
        'pending' => ['รอดำเนินการ','amber'], 'approved' => ['อนุมัติ','green'], 'suspended' => ['ระงับ','red'],
        'paid' => ['ชำระแล้ว','green'], 'failed' => ['ไม่สำเร็จ','red'], 'cancelled' => ['ยกเลิก','gray'],
        'refunded' => ['คืนเงินแล้ว','red'], 'partially_refunded' => ['คืนบางส่วน','amber'],
        'held' => ['พักเงิน','amber'], 'available' => ['ถอนได้','green'],
    ];
    $m = $map[$s] ?? [$s, 'gray'];
    return '<span class="badge badge-'.$m[1].'">'.h($m[0]).'</span>';
}

/** slug จากชื่อคอร์ส (ภาษาไทยคงไว้) + กันซ้ำ */
function make_slug($title, $ignoreId = 0){
    $s = mb_strtolower(trim($title));
    $s = preg_replace('~[^\p{L}\p{M}\p{N}]+~u', '-', $s);
    $s = trim($s, '-');
    if($s === '') $s = 'course';
    $s = mb_substr($s, 0, 120);
    $base = $s; $i = 2;
    while(db_val("SELECT id FROM courses WHERE slug = ? AND id <> ?", [$s, (int)$ignoreId])) $s = $base.'-'.$i++;
    return $s;
}

/** แปลงลิงก์วิดีโอเป็น embed (YouTube / Vimeo) — ลิงก์ไฟล์ตรงคืน null แล้วใช้ <video> */
function video_embed($url){
    if(preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|live/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $url, $m))
        return 'https://www.youtube.com/embed/'.$m[1].'?rel=0';
    if(preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m))
        return 'https://player.vimeo.com/video/'.$m[1];
    return null;
}

/** ชนิดบทเรียนที่ผู้สอนคนนี้สร้างได้ (ตามสิทธิ์ + โมดูล) */
function lesson_types_for($uid){
    $t = [];
    if(PermissionService::can($uid, 'lesson.video')){ $t['video'] = 'วิดีโอ'; $t['text'] = 'บทความ'; }
    if(PermissionService::can($uid, 'lesson.indy'))       $t['indy'] = 'Aleanor Indy (วิดีโอเลือกเส้นทาง)';
    if(PermissionService::can($uid, 'docs'))              $t['doc'] = 'เอกสาร Aleanor Docs';
    if(PermissionService::can($uid, 'lesson.playground')) $t['playground'] = 'Aleanor Playground';
    return $t;
}
function lesson_type_label($type){
    $m = ['video' => 'วิดีโอ', 'text' => 'บทความ', 'indy' => 'Indy', 'doc' => 'Docs', 'playground' => 'Playground'];
    return $m[$type] ?? $type;
}

// ── กันเดารหัสผ่าน: 5 ครั้ง/อีเมล หรือ 30 ครั้ง/IP ภายใน 15 นาที ──
function login_throttled($email){
    $since = date('Y-m-d H:i:s', time() - 900);
    $eh = hash('sha256', mb_strtolower(trim($email)));
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    return (int)db_val("SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND created_at > ?", [$eh, $since]) >= 5
        || (int)db_val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?", [$ip, $since]) >= 30;
}
function login_failed($email){
    db_insert("INSERT INTO login_attempts (email_hash, ip, created_at) VALUES (?,?,?)",
        [hash('sha256', mb_strtolower(trim($email))), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), now()]);
    if(mt_rand(1, 50) === 1) db_write("DELETE FROM login_attempts WHERE created_at < ?", [date('Y-m-d H:i:s', time() - 86400)]);
}
function login_clear($email){ db_write("DELETE FROM login_attempts WHERE email_hash = ?", [hash('sha256', mb_strtolower(trim($email)))]); }

/** ค่าในไฟล์ CSV — กันสูตร (=,+,-,@,tab,CR) ที่ Excel จะรันเมื่อเปิดไฟล์ (CSV/formula injection) */
function csv_safe($v){
    $v = (string)$v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) ? "'".$v : $v;
}
function csv_row($fh, array $row){ fputcsv($fh, array_map('csv_safe', $row)); }

/** ไฟล์ส่วนตัว (สลิปโอนเงิน) เก็บใน storage/private ซึ่งเข้าตรงไม่ได้ — เปิดผ่าน admin เท่านั้น */
function upload_private_image($field, $dir){
    if(empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if(!in_array($ext, ['jpg','jpeg','png','webp'], true) || $_FILES[$field]['size'] > 5 * 1024 * 1024) return '';
    if(@getimagesize($_FILES[$field]['tmp_name']) === false) return '';
    $base = dirname(__DIR__).'/storage/private/'.$dir;
    if(!is_dir($base)) @mkdir($base, 0775, true);
    $fn = bin2hex(random_bytes(12)).'.'.$ext;
    return @move_uploaded_file($_FILES[$field]['tmp_name'], $base.'/'.$fn) ? 'private:'.$dir.'/'.$fn : '';
}
