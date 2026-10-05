<?php
// ============================================================
// Aleanor Docs — JSON endpoint (รวม doc_save / doc_fs / doc_upload / doc_import / doc_export ของ aleanor_ai)
//   session เริ่มใน core/bootstrap.php · ต้องล็อกอิน + เป็นผู้สอนที่อนุมัติแล้ว (หรือแอดมิน) + สิทธิ์ 'docs' (PermissionService)
//   POST ทุกคำสั่งต้องแนบ CSRF: header X-CSRF-Token (หรือฟิลด์ csrf_token สำหรับฟอร์มเดิม)
//
//   POST action=save           key|id, type, title, content | content_gz(ไฟล์ gzip), course, folder
//        action=delete         key|id                       → ย้ายลงถังขยะ
//        action=rename         id, title
//        action=versions       id                           → รายการฉบับย้อนหลัง
//        action=version_restore id, version
//        action=folder_add     name, parent
//        action=folder_rename  key|id, name
//        action=folder_delete  key|id
//        action=move           kind(doc|file|folder), key|id, folder
//        action=file_upload    file, folder
//        action=file_delete    key|id
//        action=open_file      key|id                       → แปลงไฟล์ Office เป็นเอกสาร (ครั้งแรกครั้งเดียว)
//        action=image_upload   file                         → รูปในเอกสาร Write/Present
//        action=import         file(.docx/.xlsx/.csv/.pptx), folder
//        action=trash_restore / trash_purge   kind, key|id
//        action=trash_empty
//        action=quota
//   GET  action=export&id=..[&format=csv]                   → ดาวน์โหลด .docx / .xlsx / .csv / .pptx
//        action=file&id=..                                  → ดาวน์โหลดไฟล์ที่อัปโหลดไว้ (ชื่อเดิม)
// ============================================================
header('Cache-Control: no-store');
require dirname(__DIR__).'/core/bootstrap.php';          // เริ่ม session (ACSESS) ให้เอง
require_once dirname(__DIR__).'/services/DocsService.php';

function docs_out($code, array $a){
    if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
function docs_bail($code, $msg){
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code($code);
    echo $msg;
    exit;
}
/** id จากฟิลด์ id หรือ key (ตัวแก้ไขที่พอร์ตมาส่ง key) */
function docs_id(){
    $v = $_POST['id'] ?? ($_POST['key'] ?? ($_GET['id'] ?? ($_GET['key'] ?? 0)));
    return is_scalar($v) ? max(0, (int)$v) : 0;
}
function docs_quota_out($uid, array $extra = []){
    $q = DocsService::quotaInfo($uid);
    docs_out(200, ['ok' => true, 'used' => DocsService::formatBytes($q['used']),
        'limit' => $q['unlimited'] ? '∞' : DocsService::formatBytes($q['limit']),
        'pct' => $q['pct'], 'full' => $q['full'], 'trash' => DocsService::formatBytes($q['trash'])] + $extra);
}
function docs_quota_err($uid){
    $q = DocsService::quotaInfo($uid);
    docs_out(200, ['ok' => false, 'error' => 'quota', 'used' => DocsService::formatBytes($q['used']), 'limit' => DocsService::formatBytes($q['limit']),
                   'message' => DocsService::formatBytes($q['used']).' / '.DocsService::formatBytes($q['limit'])]);
}

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$isGet  = !is_post();

// ── สิทธิ์ ──
$uid = current_user_id();
if(!$uid){ if($isGet) docs_bail(401, 'ต้องเข้าสู่ระบบก่อน'); docs_out(401, ['ok' => false, 'error' => 'login_required']); }
if(!PermissionService::moduleOn('docs')){ if($isGet) docs_bail(503, 'โมดูล Aleanor Docs ถูกปิดอยู่'); docs_out(503, ['ok' => false, 'error' => 'module_off']); }
if(!(is_instructor() || is_admin()) || !PermissionService::can($uid, 'docs')){
    if($isGet) docs_bail(403, 'ไม่มีสิทธิ์ใช้งาน Aleanor Docs'); docs_out(403, ['ok' => false, 'error' => 'forbidden']);
}

try {
    // ── ดาวน์โหลด (GET) ──
    if($isGet){
        if($action === 'export'){
            $doc = DocsService::get((int)get('id'));
            if(!$doc) docs_bail(404, 'ไม่พบเอกสาร');
            if(!DocsService::canEdit($doc, $uid)) docs_bail(403, 'ไม่มีสิทธิ์เปิดเอกสารนี้');
            $f = get('format') === 'csv' ? DocsService::exportCsv($doc) : DocsService::export($doc);
            if(!$f) docs_bail(500, 'สร้างไฟล์ไม่สำเร็จ');
            $name = $f['name'];
        }elseif($action === 'file'){
            $row = DocsService::fileGet((int)get('id'), $uid);
            if(!$row) docs_bail(404, 'ไม่พบไฟล์');
            $path = DocsService::userDir($uid, false).basename($row['stored_name']);
            if(!is_file($path)) docs_bail(404, 'ไม่พบไฟล์');
            $f = ['bin' => null, 'mime' => 'application/octet-stream'];
            $name = $row['name'];
        }else{
            docs_bail(400, 'unknown action');
        }
        header('Content-Type: '.$f['mime']);
        header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"; filename*=UTF-8\'\''.rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        if($f['bin'] === null){ header('Content-Length: '.filesize($path)); readfile($path); exit; }
        header('Content-Length: '.strlen($f['bin']));
        echo $f['bin'];
        exit;
    }

    // ── POST: ตรวจ CSRF ──
    $tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    if($tok === '' || !hash_equals(csrf_token(), $tok)) docs_out(403, ['ok' => false, 'error' => 'csrf']);

    switch($action){

    // ── เอกสาร ──
    case 'save':
        $id = docs_id();
        $doc = null;
        if($id){
            $doc = DocsService::get($id);
            if(!$doc) docs_out(404, ['ok' => false, 'error' => 'not_found']);
            if(!DocsService::canEdit($doc, $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        }else{
            if(!DocsService::typeValid(post('type', 'write'))) docs_out(400, ['ok' => false, 'error' => 'bad_type']);
        }
        // เนื้อหา: ปกติมากับฟอร์ม — สมุดงานใหญ่ส่งมาแบบ gzip เป็นไฟล์ (content_gz) ให้ผ่าน post_max_size ได้
        $content = (string)($_POST['content'] ?? '');
        if(!empty($_FILES['content_gz']['tmp_name']) && is_uploaded_file($_FILES['content_gz']['tmp_name'])){
            $gz = file_get_contents($_FILES['content_gz']['tmp_name']);
            $content = ($gz === false || $gz === '') ? false : @gzdecode($gz, DocsService::maxContentBytes());
            unset($gz);
            if($content === false) docs_out(400, ['ok' => false, 'error' => 'bad_payload']);
        }
        if(strlen($content) > DocsService::maxContentBytes()) docs_out(200, ['ok' => false, 'error' => 'too_large']);
        DocsService::raiseMemory(strlen($content));
        // พื้นที่เต็ม → บันทึกเอกสารใหม่/เนื้อหาที่โตขึ้นไม่ได้
        $delta = max(0, strlen($content) - ($doc ? strlen((string)$doc['content']) : 0));
        if($delta > 0 && !DocsService::quotaAllows($uid, $delta)) docs_quota_err($uid);

        $fields = ['type' => post('type', 'write'), 'title' => post('title'), 'content' => $content];
        if(array_key_exists('course', $_POST)) $fields['course_id'] = (int)post('course');
        if(!$id) $fields['folder_id'] = (int)post('folder');
        $saved = DocsService::save($id, $fields, $uid);
        unset($content, $fields);
        if(!$id) audit('docs.create', 'docs_document', $saved, null, ['type' => post('type'), 'title' => post('title')]);
        $d = DocsService::get($saved);
        docs_out(200, ['ok' => true, 'key' => $saved, 'id' => $saved, 'title' => $d['title'] ?? '', 'updated' => $d['updated_at'] ?? '']);

    case 'delete':
        if(!DocsService::delete(docs_id(), $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        audit('docs.trash', 'docs_document', docs_id());
        docs_out(200, ['ok' => true]);

    case 'rename':
        if(!DocsService::rename(docs_id(), post('title'), $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        docs_out(200, ['ok' => true]);

    case 'versions':
        $doc = DocsService::get(docs_id());
        if(!$doc || !DocsService::canEdit($doc, $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        docs_out(200, ['ok' => true, 'versions' => array_map(function($v){
            return ['id' => (int)$v['id'], 'title' => $v['title'], 'created_at' => $v['created_at'], 'size' => DocsService::formatBytes($v['size'])];
        }, DocsService::versions($doc['id']))]);

    case 'version_restore':
        if(!DocsService::restoreVersion(docs_id(), (int)post('version'), $uid)) docs_out(404, ['ok' => false, 'error' => 'not_found']);
        audit('docs.version_restore', 'docs_document', docs_id(), null, ['version' => (int)post('version')]);
        docs_out(200, ['ok' => true]);

    // ── โฟลเดอร์ ──
    case 'folder_add':
        $fid = DocsService::folderCreate(post('name'), $uid, (int)post('parent'));
        if(!$fid) docs_out(400, ['ok' => false, 'error' => 'bad_name']);
        docs_out(200, ['ok' => true, 'key' => $fid, 'id' => $fid]);

    case 'folder_rename':
        if(!DocsService::folderRename(docs_id(), post('name'), $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        docs_out(200, ['ok' => true]);

    case 'folder_delete':
        if(!DocsService::folderDelete(docs_id(), $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        docs_out(200, ['ok' => true]);

    case 'move':
        $kind = in_array(post('kind'), DocsService::KINDS, true) ? post('kind') : 'doc';
        if(!DocsService::moveTo($kind, docs_id(), (int)post('folder'), $uid)) docs_out(403, ['ok' => false, 'error' => 'forbidden']);
        docs_out(200, ['ok' => true]);

    // ── ไฟล์อัปโหลด ──
    case 'file_upload':
        if(empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) docs_out(400, ['ok' => false, 'error' => 'no_file']);
        $r = DocsService::fileStore($uid, $_FILES['file']['tmp_name'], (string)($_FILES['file']['name'] ?? 'file'), (int)post('folder'));
        if(!$r['ok']){ if($r['error'] === 'quota') docs_quota_err($uid); docs_out(200, $r); }
        audit('docs.upload', 'docs_file', $r['id'], null, ['name' => $r['name'], 'size' => $r['size']]);
        docs_quota_out($uid, ['key' => $r['id'], 'id' => $r['id'], 'name' => $r['name'], 'size' => DocsService::formatBytes($r['size'])]);

    case 'file_delete':
        if(!DocsService::fileDelete(docs_id(), $uid)) docs_out(404, ['ok' => false, 'error' => 'not_found']);
        docs_quota_out($uid);

    case 'open_file':
        $r = DocsService::fileOpen(docs_id(), $uid);
        if(!$r['ok'] && $r['error'] === 'quota') docs_quota_err($uid);
        if($r['ok']) $r['key'] = $r['id'];
        docs_out(200, $r);

    case 'image_upload':
        if(empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) docs_out(400, ['ok' => false, 'error' => 'no_file']);
        docs_out(200, DocsService::imageStore($uid, $_FILES['file']['tmp_name']));

    case 'import':
        if(empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) docs_out(400, ['ok' => false, 'error' => 'no_file']);
        if((int)$_FILES['file']['size'] > 25 * 1024 * 1024) docs_out(200, ['ok' => false, 'error' => 'too_large']);
        $orig = (string)($_FILES['file']['name'] ?? '');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if(!isset(DocsService::convertibleExt()[$ext])) docs_out(200, ['ok' => false, 'error' => 'bad_type', 'ext' => $ext]);
        if(!DocsService::quotaAllows($uid, (int)$_FILES['file']['size'])) docs_quota_err($uid);
        $r = DocsService::importFile($_FILES['file']['tmp_name'], $ext, $orig, $uid);
        if(!$r['ok']) docs_out(200, ['ok' => false, 'error' => $r['error'], 'message' => $r['message'] ?? '']);
        $id = DocsService::save(0, ['type' => $r['type'], 'title' => $r['title'], 'content' => $r['content'],
                                    'folder_id' => (int)post('folder')], $uid);
        audit('docs.import', 'docs_document', $id, null, ['ext' => $ext, 'title' => $r['title']]);
        docs_out(200, ['ok' => true, 'key' => $id, 'id' => $id, 'title' => $r['title'], 'type' => $r['type'], 'chars' => $r['count']]);

    // ── ถังขยะ ──
    case 'trash_restore':
        $kind = in_array(post('kind'), DocsService::KINDS, true) ? post('kind') : 'doc';
        if(!DocsService::trashRestore($kind, docs_id(), $uid)) docs_out(404, ['ok' => false, 'error' => 'not_found']);
        docs_out(200, ['ok' => true]);

    case 'trash_purge':
        $kind = in_array(post('kind'), DocsService::KINDS, true) ? post('kind') : 'doc';
        if(!DocsService::trashPurge($kind, docs_id(), $uid)) docs_out(404, ['ok' => false, 'error' => 'not_found']);
        audit('docs.purge', 'docs_'.$kind, docs_id());
        docs_quota_out($uid);

    case 'trash_empty':
        $n = DocsService::trashEmpty($uid);
        audit('docs.trash_empty', 'docs', null, null, ['n' => $n]);
        docs_quota_out($uid, ['n' => $n]);

    case 'quota':
        docs_quota_out($uid);
    }
    docs_out(400, ['ok' => false, 'error' => 'bad_action']);
} catch(Throwable $e){
    error_log('[aleanor_cloud docs] '.$e);
    docs_out(500, ['ok' => false, 'error' => 'server_error', 'message' => APP_ENV === 'dev' ? $e->getMessage() : '']);
}
