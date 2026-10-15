<?php
// ============================================================
// Aleanor Streaming — คนกลางของการถ่ายทอดสด (JSON)
//   ผู้สอน (เจ้าของคอร์ส/แอดมิน): host_state, host_start, host_poll, host_answer, host_reject, host_notify, host_stop, attendance
//   ผู้ชม (ผู้ลงทะเบียน/เจ้าของ/แอดมิน): status, join, wait, ping, leave
//   แชท (ทั้งสองฝ่าย): chat_send, chat_poll
//   ทุกคำสั่งเป็น POST + CSRF (header X-CSRF-Token หรือฟิลด์ csrf_token) — ไม่ consume ใช้ซ้ำได้ระหว่างไลฟ์
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require dirname(__DIR__).'/core/bootstrap.php';

function lout(array $a){ echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$uid = current_user_id();
if(!$uid) lout(['ok' => false, 'error' => 'unauth']);
if(!is_post()) lout(['ok' => false, 'error' => 'bad_method']);
$tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
if($tok === '' || !hash_equals(csrf_token(), $tok)) lout(['ok' => false, 'error' => 'csrf']);
if(!PermissionService::moduleOn('live')) lout(['ok' => false, 'error' => 'module_off']);

$action = (string)($_POST['action'] ?? '');
$clean  = function($k){ return preg_replace('~[^A-Za-z0-9_-]~', '', (string)($_POST[$k] ?? '')); };

function liveCourse($id){
    $c = $id > 0 ? db_one("SELECT * FROM courses WHERE id = ?", [(int)$id]) : null;
    if(!$c) lout(['ok' => false, 'error' => 'bad_course']);
    if(!LiveService::enabledFor($c)) lout(['ok' => false, 'error' => 'feature_off']);
    return $c;
}
function liveClientInfo(array $s, $withWhip){
    $o = ['session' => $s['session_key'], 'mode' => $s['mode'], 'title' => $s['title'], 'started' => $s['started_at'],
          'elapsed' => max(0, time() - strtotime($s['started_at'])), 'ice' => LiveService::ice()];
    if($s['mode'] === 'cf') $o['whep'] = $s['cf_whep'];
    if($withWhip && $s['mode'] === 'cf') $o['whip'] = $s['cf_whip'];
    if($s['mode'] === 'p2p'){ $o['max'] = LiveService::p2pMax(); $o['uplink'] = LiveService::uplink(); }
    return $o;
}
function livePerson(array $o){ return ['key' => (string)$o['user_id'], 'name' => (string)($o['name'] ?? ''), 'photo' => '']; }
function liveUserName($id){ return (string)db_val("SELECT name FROM users WHERE id = ?", [(int)$id]); }

// ═════════════════ แชท ═════════════════
if($action === 'chat_send' || $action === 'chat_poll'){
    $s = LiveService::get($clean('session'));
    if(!$s) lout(['ok' => false, 'error' => 'no_session']);
    $course = liveCourse((int)$s['course_id']);
    if(!LiveService::canWatch($course, $uid)) lout(['ok' => false, 'error' => 'forbidden']);
    if($action === 'chat_send'){
        if((int)$s['status'] !== 1) lout(['ok' => false, 'error' => 'ended']);
        $body = trim(preg_replace('~\s+~u', ' ', (string)($_POST['body'] ?? '')));
        if($body === '') lout(['ok' => false, 'error' => 'empty']);
        $last = db_val("SELECT created_at FROM live_messages WHERE session_key = ? AND user_id = ? ORDER BY id DESC LIMIT 1", [$s['session_key'], $uid]);
        if($last && strtotime($last) > time() - 1) lout(['ok' => false, 'error' => 'slow']);
        db_insert("INSERT INTO live_messages (session_key, user_id, body, created_at) VALUES (?,?,?,?)", [$s['session_key'], $uid, mb_substr($body, 0, 500), now()]);
    }
    $after = max(0, (int)($_POST['after'] ?? 0));
    $rows = db_all("SELECT m.id, m.user_id, m.body, m.created_at, u.name FROM live_messages m JOIN users u ON u.id = m.user_id
                    WHERE m.session_key = ? AND m.id > ? ORDER BY m.id DESC LIMIT 100", [$s['session_key'], $after]);
    $hostId = (int)$s['user_id'];
    $list = array_map(function($m) use ($uid, $hostId){
        return ['id' => (int)$m['id'], 'name' => $m['name'], 'body' => $m['body'], 'time' => date('H:i', strtotime($m['created_at'])),
                'me' => (int)$m['user_id'] === $uid, 'host' => (int)$m['user_id'] === $hostId];
    }, array_reverse($rows));
    lout(['ok' => true, 'messages' => $list, 'live' => (int)$s['status'] === 1]);
}

// ═════════════════ ผู้สอน ═════════════════
if(strpos($action, 'host_') === 0 || $action === 'attendance'){
    if($action === 'host_start' || $action === 'host_state'){
        $course = liveCourse((int)($_POST['course_id'] ?? ($_POST['course_key'] ?? 0)));
        if(!LiveService::canHost($course, $uid)) lout(['ok' => false, 'error' => 'forbidden']);
        if($action === 'host_state'){
            $s = LiveService::active((int)$course['id']);
            if(!$s) lout(['ok' => true, 'live' => false, 'mode' => LiveService::mode()]);
            $mine = (int)$s['user_id'] === $uid;
            $out = ['ok' => true, 'live' => true, 'mine' => $mine] + liveClientInfo($s, $mine);
            if(!$mine) $out['host'] = liveUserName($s['user_id']);
            lout($out);
        }
        $r = LiveService::start($course, $uid, (string)($_POST['title'] ?? ''));
        if(!$r['ok']) lout(['ok' => false, 'error' => $r['error'], 'message' => $r['message'] ?? '']);
        audit('live.start', 'course', (int)$course['id']);
        lout(['ok' => true, 'live' => true, 'mine' => true] + liveClientInfo($r['session'], true));
    }

    $s = LiveService::get($clean('session'));
    if(!$s) lout(['ok' => false, 'error' => 'no_session']);
    $course = db_one("SELECT * FROM courses WHERE id = ?", [(int)$s['course_id']]);
    if(!$course || !LiveService::canHost($course, $uid)) lout(['ok' => false, 'error' => 'forbidden']);

    if($action === 'attendance'){
        $list = array_map(function($v){
            return livePerson($v) + ['first' => $v['first_at'], 'last' => $v['last_at'], 'seconds' => (int)$v['seconds']];
        }, LiveService::attendance($s['session_key']));
        lout(['ok' => true, 'list' => $list, 'title' => $s['title'], 'started' => $s['started_at'], 'ended' => $s['ended_at'], 'peak' => (int)$s['peak']]);
    }
    $mine = (int)$s['user_id'] === $uid;

    if($action === 'host_stop'){
        if((int)$s['status'] === 1) LiveService::end($s);
        audit('live.stop', 'course', (int)$course['id']);
        $s = LiveService::get($s['session_key']);
        $att = LiveService::attendance($s['session_key']);
        lout(['ok' => true, 'duration' => max(0, strtotime($s['ended_at']) - strtotime($s['started_at'])), 'viewers' => count($att), 'peak' => (int)$s['peak']]);
    }
    if((int)$s['status'] !== 1) lout(['ok' => false, 'error' => 'ended']);
    if(!$mine) lout(['ok' => false, 'error' => 'not_host']);

    if($action === 'host_notify'){
        // อีเมลแจ้งผู้เรียนครั้งเดียวต่อไลฟ์ (เข้าคิว mail_queue — cron ส่ง)
        if(db_write("UPDATE live_sessions SET notified = 1 WHERE session_key = ? AND notified = 0", [$s['session_key']]) < 1) lout(['ok' => true, 'sent' => 0]);
        $n = 0;
        $link = site_url().'index.php?p=learn&course='.(int)$course['id'];
        foreach(db_all("SELECT u.email, u.name FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? AND e.status = 'active' AND u.status = 1", [(int)$course['id']]) as $r){
            $n += MailService::queue($r['email'], 'กำลังสอนสด: '.$course['title'],
                '<p>สวัสดีคุณ '.h($r['name']).'</p><p>ผู้สอนกำลังสอนสดในคอร์ส <b>'.h($course['title']).'</b> อยู่ตอนนี้</p>'
                .'<p><a href="'.h($link).'" style="display:inline-block;background:#6366f1;color:#fff;padding:10px 18px;border-radius:9px;text-decoration:none">เข้าชมการสอนสด</a></p>', 'live_started') ? 1 : 0;
        }
        lout(['ok' => true, 'sent' => $n]);
    }

    if($action === 'host_poll'){
        $now = now();
        db_write("UPDATE live_sessions SET heartbeat_at = ? WHERE session_key = ?", [$now, $s['session_key']]);
        $cut = date('Y-m-d H:i:s', time() - LiveService::VIEWER_TIMEOUT);
        db_write("UPDATE live_peers SET state = 'closed', updated_at = ? WHERE session_key = ? AND state IN ('offer','answered') AND updated_at < ?", [$now, $s['session_key'], $cut]);
        $offers = [];
        if($s['mode'] === 'p2p'){
            foreach(db_all("SELECT p.peer_key, p.offer, u.name FROM live_peers p LEFT JOIN users u ON u.id = p.user_id
                            WHERE p.session_key = ? AND p.state = 'offer' ORDER BY p.id LIMIT 10", [$s['session_key']]) as $o)
                $offers[] = ['peer' => $o['peer_key'], 'sdp' => $o['offer'], 'name' => (string)$o['name']];
        }
        $closed = [];
        $have = array_slice(array_values(array_filter(array_map(function($x){ return preg_replace('~[^A-Za-z0-9_-]~', '', $x); }, explode(',', (string)($_POST['have'] ?? ''))))), 0, 300);
        if($have){
            $ph = implode(',', array_fill(0, count($have), '?'));
            $known = [];
            foreach(db_all("SELECT peer_key, state FROM live_peers WHERE session_key = ? AND peer_key IN ($ph)", array_merge([$s['session_key']], $have)) as $o){
                $known[$o['peer_key']] = 1;
                if($o['state'] !== 'answered') $closed[] = $o['peer_key'];
            }
            foreach($have as $h) if(!isset($known[$h])) $closed[] = $h;
        }
        $watching = LiveService::watching($s);
        $n = count($watching);
        if($n > (int)$s['peak']) db_write("UPDATE live_sessions SET peak = ? WHERE session_key = ?", [$n, $s['session_key']]);
        lout(['ok' => true, 'offers' => $offers, 'closed' => array_values(array_unique($closed)),
              'watching' => array_map('livePerson', $watching), 'count' => $n, 'elapsed' => max(0, time() - strtotime($s['started_at']))]);
    }
    if($action === 'host_answer' || $action === 'host_reject'){
        $p = db_one("SELECT * FROM live_peers WHERE peer_key = ? AND session_key = ?", [$clean('peer'), $s['session_key']]);
        if(!$p) lout(['ok' => false, 'error' => 'no_peer']);
        if($action === 'host_reject'){
            db_write("UPDATE live_peers SET state = 'full', updated_at = ? WHERE id = ?", [now(), (int)$p['id']]);
            lout(['ok' => true]);
        }
        $sdp = (string)($_POST['sdp'] ?? '');
        if(!LiveService::sdpOk($sdp)) lout(['ok' => false, 'error' => 'bad_sdp']);
        db_write("UPDATE live_peers SET answer = ?, state = 'answered', updated_at = ? WHERE id = ? AND state = 'offer'", [$sdp, now(), (int)$p['id']]);
        lout(['ok' => true]);
    }
    lout(['ok' => false, 'error' => 'bad_action']);
}

// ═════════════════ ผู้ชม ═════════════════
if($action === 'status'){
    $course = liveCourse((int)($_POST['course_id'] ?? ($_POST['course_key'] ?? 0)));
    if(!LiveService::canWatch($course, $uid)) lout(['ok' => false, 'error' => 'forbidden']);
    $s = LiveService::active((int)$course['id']);
    if(!$s) lout(['ok' => true, 'live' => false]);
    lout(['ok' => true, 'live' => true, 'host' => liveUserName($s['user_id']), 'self' => (int)$s['user_id'] === $uid] + liveClientInfo($s, false));
}

$s = LiveService::get($clean('session'));
if(!$s) lout(['ok' => false, 'error' => 'no_session']);
$course = liveCourse((int)$s['course_id']);
if(!LiveService::canWatch($course, $uid)) lout(['ok' => false, 'error' => 'forbidden']);
if((int)$s['status'] !== 1 || strtotime($s['heartbeat_at']) < time() - LiveService::HOST_TIMEOUT){
    if((int)$s['status'] === 1) LiveService::end($s);
    lout(['ok' => true, 'live' => false, 'error' => 'ended']);
}
$isHost = (int)$s['user_id'] === $uid;

if($action === 'join'){
    if($s['mode'] !== 'p2p') lout(['ok' => false, 'error' => 'bad_mode']);
    $sdp = (string)($_POST['offer'] ?? '');
    if(!LiveService::sdpOk($sdp)) lout(['ok' => false, 'error' => 'bad_sdp']);
    $now = now();
    db_write("UPDATE live_peers SET state = 'closed', updated_at = ? WHERE session_key = ? AND user_id = ? AND state IN ('offer','answered')", [$now, $s['session_key'], $uid]);
    $busy = (int)db_val("SELECT COUNT(*) FROM live_peers WHERE session_key = ? AND state IN ('offer','answered') AND updated_at >= ?",
                        [$s['session_key'], date('Y-m-d H:i:s', time() - LiveService::VIEWER_TIMEOUT)]);
    if($busy >= LiveService::p2pMax()) lout(['ok' => false, 'error' => 'full', 'max' => LiveService::p2pMax()]);
    $pk = LiveService::key();
    db_insert("INSERT INTO live_peers (peer_key, session_key, user_id, offer, state, created_at, updated_at) VALUES (?,?,?,?, 'offer', ?, ?)",
              [$pk, $s['session_key'], $uid, $sdp, $now, $now]);
    if(!$isHost) LiveService::touchViewer($s, $uid);
    lout(['ok' => true, 'peer' => $pk]);
}

if($action === 'wait' || $action === 'ping' || $action === 'leave'){
    $p = null;
    $pk = $clean('peer');
    if($pk !== ''){
        $p = db_one("SELECT * FROM live_peers WHERE peer_key = ? AND session_key = ? AND user_id = ?", [$pk, $s['session_key'], $uid]);
        if(!$p) lout(['ok' => false, 'error' => 'no_peer']);
    }
    if($action === 'leave'){
        if($p) db_write("UPDATE live_peers SET state = 'closed', updated_at = ? WHERE id = ?", [now(), (int)$p['id']]);
        lout(['ok' => true]);
    }
    if($action === 'wait'){
        if(!$p) lout(['ok' => false, 'error' => 'no_peer']);
        if($p['state'] === 'answered') lout(['ok' => true, 'live' => true, 'state' => 'answered', 'answer' => $p['answer']]);
        lout(['ok' => true, 'live' => true, 'state' => $p['state']]);
    }
    if(!$isHost) LiveService::touchViewer($s, $uid);
    $state = '';
    if($p){
        if(in_array($p['state'], ['answered', 'offer'], true)) db_write("UPDATE live_peers SET updated_at = ? WHERE id = ?", [now(), (int)$p['id']]);
        $state = $p['state'];
    }
    lout(['ok' => true, 'live' => true, 'state' => $state]);
}

lout(['ok' => false, 'error' => 'bad_action']);
