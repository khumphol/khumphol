<?php
// ============================================================
// Aleanor Indy — JSON endpoint ของ player
//   POST action=progress  project_id, scene_id   (header X-CSRF-Token)
//     ต้องล็อกอิน + ลงทะเบียนคอร์สที่มีบทเรียน indy ชี้มาที่โปรเจกต์นี้ (หรือเป็นเจ้าของ/แอดมิน)
//     → {ok, scene_id, ending, reached_end}
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require dirname(__DIR__).'/core/bootstrap.php';
require_once dirname(__DIR__).'/services/IndyService.php';

function indy_out($code, array $a){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if(!is_post()) indy_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
if(!PermissionService::moduleOn('indy')) indy_out(503, ['ok' => false, 'error' => 'module_off']);
$uid = current_user_id();
if(!$uid) indy_out(401, ['ok' => false, 'error' => 'login_required']);
$hdr = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if($hdr === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $hdr))
    indy_out(403, ['ok' => false, 'error' => 'bad_csrf']);

try {
    switch(post('action')){
        case 'progress':
            $pid = (int)post('project_id'); $sid = (int)post('scene_id');
            if($pid <= 0 || $sid <= 0) indy_out(400, ['ok' => false, 'error' => 'bad_request']);
            if(!IndyService::project($pid)) indy_out(404, ['ok' => false, 'error' => 'not_found']);
            if(!IndyService::canPlay($pid, $uid)) indy_out(403, ['ok' => false, 'error' => 'forbidden']);
            $r = IndyService::saveProgress($uid, $pid, $sid);
            indy_out(200, ['ok' => true] + $r);
        default:
            indy_out(400, ['ok' => false, 'error' => 'unknown_action']);
    }
} catch(InvalidArgumentException $e){
    indy_out(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch(Throwable $e){
    error_log('[aleanor_cloud indy] '.$e);
    indy_out(500, ['ok' => false, 'error' => 'server_error']);
}
