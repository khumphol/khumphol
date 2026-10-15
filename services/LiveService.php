<?php
// ============================================================
// Aleanor Streaming — ผู้สอนถ่ายทอดสดจากเบราว์เซอร์ (พอร์ตจาก aleanor_ai core/live.core.php)
//   cf  : ตั้งค่า Cloudflare Stream ไว้ (live_use_cf + cf_account_id + cf_api_token) → WHIP ขึ้น Cloudflare / ผู้ชมดูผ่าน WHEP
//   p2p : ส่งตรงจากเครื่องผู้สอนถึงผู้เรียนแต่ละคน เซิร์ฟเวอร์แค่แลก SDP — เหมาะห้องเล็ก (live_p2p_max)
// ผู้ชมเป็นฝั่งสร้าง offer (recvonly) ทั้งสองแบบ · ตาราง live_* (sql/008_live.sql)
// ============================================================
class LiveService
{
    const VIEWER_TIMEOUT = 45;    // ผู้ชมเงียบเกินนี้ (วินาที) = ออกไปแล้ว
    const HOST_TIMEOUT   = 90;    // ผู้สอนเงียบเกินนี้ = จบไลฟ์

    public static function cfConfigured(){
        return trim((string)setting('cf_account_id', '')) !== '' && trim((string)setting('cf_api_token', '')) !== '';
    }
    public static function mode(){ return (self::cfConfigured() && setting('live_use_cf', '1') === '1') ? 'cf' : 'p2p'; }
    public static function p2pMax(){ return max(1, min(200, (int)setting('live_p2p_max', '30'))); }
    public static function uplink(){ return max(1000, min(200000, (int)setting('live_p2p_uplink', '12000'))); }
    public static function ice(){
        $ice = [['urls' => ['stun:stun.cloudflare.com:3478', 'stun:stun.l.google.com:19302']]];
        $turn = trim((string)setting('live_turn_url', ''));
        if($turn !== ''){
            $urls = array_values(array_filter(array_map('trim', preg_split('~[\s,]+~', $turn)), function($u){ return (bool)preg_match('~^turns?:~i', $u); }));
            if($urls) $ice[] = ['urls' => $urls, 'username' => (string)setting('live_turn_user', ''), 'credential' => (string)setting('live_turn_pass', '')];
        }
        return $ice;
    }

    // ── Cloudflare Stream live input ──
    private static function cf($method, $path, $body = null){
        $acc = trim((string)setting('cf_account_id', '')); $tok = trim((string)setting('cf_api_token', ''));
        if($acc === '' || $tok === '') return ['ok' => false, 'error' => 'Cloudflare ยังไม่ได้ตั้งค่า'];
        $ch = curl_init('https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($acc).'/stream'.$path);
        $h = ['Authorization: Bearer '.$tok];
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        if($body !== null){ $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
        if($raw === false) return ['ok' => false, 'error' => 'เชื่อมต่อ Cloudflare ไม่ได้: '.$err];
        $j = json_decode($raw, true);
        if(!is_array($j) || empty($j['success'])) return ['ok' => false, 'error' => $j['errors'][0]['message'] ?? 'Cloudflare API error'];
        return ['ok' => true, 'result' => $j['result'] ?? []];
    }
    public static function cfCreate($name){
        $r = self::cf('POST', '/live_inputs', ['meta' => ['name' => mb_substr((string)$name, 0, 100)], 'recording' => ['mode' => 'off']]);
        if(!$r['ok']) return $r;
        $x = $r['result'];
        $whip = $x['webRTC']['url'] ?? ''; $whep = $x['webRTCPlayback']['url'] ?? '';
        if(($x['uid'] ?? '') === '' || $whip === '' || $whep === '') return ['ok' => false, 'error' => 'Cloudflare ไม่ส่งลิงก์ WebRTC กลับมา'];
        return ['ok' => true, 'uid' => $x['uid'], 'whip' => $whip, 'whep' => $whep];
    }
    public static function cfDelete($uid){ if($uid !== '') @self::cf('DELETE', '/live_inputs/'.rawurlencode($uid)); }
    public static function cfTest(){ return self::cf('GET', '/live_inputs?per_page=1'); }

    // ── session ──
    public static function key(){ return bin2hex(random_bytes(16)); }
    public static function get($key){ return db_one("SELECT * FROM live_sessions WHERE session_key = ?", [(string)$key]); }
    public static function active($courseId){
        $s = db_one("SELECT * FROM live_sessions WHERE course_id = ? AND status = 1 ORDER BY id DESC LIMIT 1", [(int)$courseId]);
        if(!$s) return null;
        if(strtotime($s['heartbeat_at']) < time() - self::HOST_TIMEOUT){ self::end($s); return null; }
        return $s;
    }
    /** เริ่มไลฟ์ — ปิดไลฟ์เก่าของคอร์สเดียวกันที่ค้างอยู่ก่อน */
    public static function start(array $course, $userId, $title = ''){
        $old = db_one("SELECT * FROM live_sessions WHERE course_id = ? AND status = 1 ORDER BY id DESC LIMIT 1", [(int)$course['id']]);
        if($old) self::end($old);
        $mode = self::mode();
        $title = trim((string)$title) !== '' ? mb_substr(trim((string)$title), 0, 200) : $course['title'];
        $cf = ['uid' => '', 'whip' => '', 'whep' => ''];
        if($mode === 'cf'){
            $r = self::cfCreate($course['title'].' · '.date('Y-m-d H:i'));
            if(!$r['ok']) return ['ok' => false, 'error' => 'cf', 'message' => $r['error']];
            $cf = $r;
        }
        $k = self::key(); $now = now();
        db_insert("INSERT INTO live_sessions (session_key, course_id, user_id, mode, title, status, started_at, heartbeat_at, cf_uid, cf_whip, cf_whep, peak)
                   VALUES (?,?,?,?,?,1,?,?,?,?,?,0)", [$k, (int)$course['id'], (int)$userId, $mode, $title, $now, $now, $cf['uid'], $cf['whip'], $cf['whep']]);
        return ['ok' => true, 'session' => self::get($k)];
    }
    /** จบไลฟ์: ปิดผู้ชมทุกคน ลบ live input ของ Cloudflare (ลิงก์ส่งภาพใช้ซ้ำไม่ได้อีก) */
    public static function end(array $s){
        if((int)$s['status'] !== 1) return;
        $now = now();
        $end = strtotime($s['heartbeat_at']) < time() - self::HOST_TIMEOUT ? $s['heartbeat_at'] : $now;
        db_write("UPDATE live_sessions SET status = 0, ended_at = ?, cf_whip = '' WHERE session_key = ?", [$end, $s['session_key']]);
        db_write("UPDATE live_peers SET state = 'closed', updated_at = ? WHERE session_key = ? AND state <> 'closed'", [$now, $s['session_key']]);
        if($s['mode'] === 'cf') self::cfDelete($s['cf_uid']);
    }

    // ── ผู้ชม ──
    public static function touchViewer(array $s, $userId){
        $v = db_one("SELECT * FROM live_viewers WHERE session_key = ? AND user_id = ?", [$s['session_key'], (int)$userId]);
        $now = now();
        if($v){
            $gap = time() - strtotime($v['last_at']);
            $add = ($gap > 0 && $gap <= self::VIEWER_TIMEOUT) ? $gap : 0;
            db_write("UPDATE live_viewers SET last_at = ?, seconds = seconds + ? WHERE id = ?", [$now, $add, (int)$v['id']]);
        }else{
            db_write("INSERT IGNORE INTO live_viewers (session_key, user_id, first_at, last_at, seconds) VALUES (?,?,?,?,0)", [$s['session_key'], (int)$userId, $now, $now]);
        }
    }
    public static function watching(array $s){
        return db_all("SELECT v.user_id, v.first_at, v.seconds, u.name FROM live_viewers v JOIN users u ON u.id = v.user_id
                       WHERE v.session_key = ? AND v.last_at >= ? ORDER BY v.first_at", [$s['session_key'], date('Y-m-d H:i:s', time() - self::VIEWER_TIMEOUT)]);
    }
    public static function attendance($key){
        return db_all("SELECT v.*, u.name FROM live_viewers v JOIN users u ON u.id = v.user_id WHERE v.session_key = ? ORDER BY v.first_at", [(string)$key]);
    }
    public static function history($courseId, $limit = 10){
        return db_all("SELECT s.*, (SELECT COUNT(*) FROM live_viewers v WHERE v.session_key = s.session_key) AS viewers
                       FROM live_sessions s WHERE s.course_id = ? AND s.status = 0 ORDER BY s.id DESC LIMIT ".(int)$limit, [(int)$courseId]);
    }
    public static function sdpOk($sdp){
        $sdp = (string)$sdp;
        return strlen($sdp) > 20 && strlen($sdp) < 60000 && strpos($sdp, 'v=0') === 0 && strpos($sdp, 'm=') !== false;
    }
    /** สิทธิ์: เจ้าของคอร์ส / แอดมิน = ผู้สอน · ผู้ลงทะเบียน = ผู้ชม */
    public static function canHost(array $course, $userId){ return (int)$course['instructor_id'] === (int)$userId || is_admin(); }
    public static function canWatch(array $course, $userId){ return self::canHost($course, $userId) || OrderService::isEnrolled($userId, (int)$course['id']); }
    public static function enabledFor(array $course){
        return PermissionService::moduleOn('live') && PermissionService::can((int)$course['instructor_id'], 'live');
    }

    // ── ข้อความ (ภาษาไทย — ชุดเดียวกับ live.* ของ aleanor_ai) ──
    public static function L($k = null, array $rep = []){
        static $L = [
            'sw_studio_sub' => 'สอนสดจากคอมพิวเตอร์เครื่องนี้ ไม่ต้องใช้โปรแกรมหรือเว็บอื่น',
            'idle' => 'ยังไม่ออกอากาศ',
            'connecting' => 'กำลังเชื่อมต่อ…',
            'reconnecting' => 'กำลังเชื่อมต่อใหม่…',
            'start' => 'เริ่มสอนสด',
            'stop' => 'จบการสอนสด',
            'started' => 'ออกอากาศแล้ว — ผู้เรียนเห็นแท็บสอนสดในหน้ารายวิชา และได้รับการแจ้งเตือน',
            'confirm_stop' => 'จบการสอนสดตอนนี้? ผู้เรียนทุกคนจะหลุดจากไลฟ์',
            'ended_elsewhere' => 'ไลฟ์นี้ถูกปิดจากที่อื่น (ผู้สอนอีกคน หรือขาดการเชื่อมต่อนานเกินไป)',
            'start_fail' => 'เริ่มไลฟ์ไม่สำเร็จ',
            'cf_fail' => 'เชื่อมต่อ Cloudflare Stream ไม่สำเร็จ',
            'cam_fail' => 'เปิดกล้องไม่ได้ — ตรวจว่าอนุญาตให้เว็บนี้ใช้กล้อง และไม่มีโปรแกรมอื่นใช้กล้องอยู่',
            'mic_fail' => 'เปิดไมโครโฟนไม่ได้ — ตรวจว่าอนุญาตให้เว็บนี้ใช้ไมโครโฟน',
            'no_support' => 'เบราว์เซอร์นี้ถ่ายทอดสดไม่ได้ แนะนำ Chrome หรือ Edge รุ่นล่าสุด',
            'screen_stopped' => 'หยุดแชร์หน้าจอแล้ว — กลับมาใช้กล้อง',
            'waiting_cam' => 'กำลังเตรียมกล้อง…',
            'cam_off_note' => 'ปิดกล้องชั่วคราว',
            'no_viewers' => 'ยังไม่มีผู้ชม',
            'rec_fail' => 'อัดวิดีโอในเครื่องไม่ได้ (เบราว์เซอร์ไม่รองรับ) — ไลฟ์ยังออกอากาศตามปกติ',
            'mic_mute' => 'ปิดไมค์',
            'mic_unmute' => 'เปิดไมค์',
            'cam_off' => 'ปิดกล้อง',
            'cam_on' => 'เปิดกล้อง',
            'camera' => 'กล้อง',
            'mic' => 'ไมโครโฟน',
            'mode_cf' => 'ผ่าน Cloudflare · ผู้ชมไม่จำกัด',
            'mode_p2p' => 'ส่งตรงจากเครื่องผู้สอน · สูงสุด :n คน',
            'resume_mine' => 'คุณมีไลฟ์ที่ยังออกอากาศค้างอยู่',
            'other_live' => ':name กำลังสอนสดในรายวิชานี้อยู่',
            'resume_go' => 'ออกอากาศต่อ',
            'resume_end' => 'จบไลฟ์นั้น',
            'viewers' => 'ผู้ชม',
            'mic_level' => 'ระดับเสียงไมค์',
            'setup_title' => 'พร้อมสอนสดแล้วหรือยัง?',
            'setup_hint' => 'กดปุ่มด้านล่างแล้วอนุญาตให้ใช้กล้องและไมโครโฟน จะเห็นภาพตัวอย่างก่อน ยังไม่มีใครเห็นจนกว่าจะกด "เริ่มสอนสด"',
            'setup_go' => 'เปิดกล้องและไมโครโฟน',
            'src_camera' => 'กล้อง',
            'src_screen' => 'แชร์หน้าจอ',
            'src_both' => 'หน้าจอ + กล้อง',
            'corner' => 'ย้ายกล้องไปมุมอื่น',
            'bubble_size' => 'ขยาย/ย่อภาพกล้อง',
            'stop_share' => 'หยุดแชร์หน้าจอ',
            'settings' => 'ตั้งค่าอุปกรณ์',
            'quality' => 'ความคมชัด',
            'q_sd' => 'ปกติ 480p (เน็ตช้า)',
            'q_hd' => 'HD 720p (แนะนำ)',
            'q_fhd' => 'Full HD 1080p (เน็ตเร็ว)',
            'record' => 'อัดวิดีโอเก็บไว้ในเครื่องนี้',
            'record_hint' => 'จบไลฟ์แล้วดาวน์โหลดไฟล์ไปอัปโหลดเป็นบทเรียนย้อนหลังได้',
            'title_ph' => 'หัวข้อการสอนวันนี้ (ไม่บังคับ)',
            'summary' => 'สรุปการสอนสด',
            'duration' => 'ระยะเวลา',
            'viewers_total' => 'ผู้เข้าชม',
            'peak' => 'ดูพร้อมกันสูงสุด',
            'peak_short' => 'สูงสุด :n',
            'download_rec' => 'ดาวน์โหลดวิดีโอที่อัดไว้',
            'rec_note' => 'ไฟล์อัดเก็บอยู่ในเบราว์เซอร์นี้เท่านั้น ดาวน์โหลดก่อนปิดหน้า',
            'history' => 'ไลฟ์ย้อนหลัง',
            'history_empty' => 'ยังไม่เคยสอนสดด้วย Aleanor Streaming ในรายวิชานี้',
            'h_date' => 'วันที่',
            'h_title' => 'หัวข้อ',
            'attendance' => 'รายชื่อผู้ชม',
            'no_attendance' => 'ไม่มีผู้เข้าชม',
            'minutes' => 'นาที',
            'tap_sound' => 'แตะเพื่อเปิดเสียง',
            'sound' => 'เสียง',
            'pip' => 'ภาพซ้อน',
            'fullscreen' => 'เต็มจอ',
            'full' => 'ห้องเต็มแล้ว',
            'full_sub' => 'ไลฟ์นี้รับผู้ชมพร้อมกันได้ :n คน ระบบจะลองเข้าให้ใหม่อัตโนมัติ',
            'ended' => 'จบการสอนสดแล้ว',
            'ended_sub' => 'ขอบคุณที่เข้าร่วม — ดูบทเรียนต่อได้ที่แท็บบทเรียน',
            'error' => 'โหลดไลฟ์ไม่ได้',
            'net_err' => 'ตรวจการเชื่อมต่ออินเทอร์เน็ต แล้วลองรีเฟรชหน้า',
            'waiting' => 'ยังไม่เริ่มสอนสด',
            'waiting_sub' => 'เปิดหน้านี้ค้างไว้ได้ ผู้สอนเริ่มเมื่อไรภาพจะขึ้นเอง',
            'by' => 'สอนโดย',
            'toast' => 'ผู้สอนเริ่มสอนสดแล้ว',
            'toast_go' => 'ดูเลย',
            'chat'           => 'แชท',
            'chat_ph'        => 'พิมพ์ข้อความ…',
            'chat_send'      => 'ส่ง',
            'chat_empty'     => 'ยังไม่มีข้อความ',
            'chat_host'      => 'ผู้สอน',
            'chat_slow'      => 'ส่งถี่เกินไป รอสักครู่',
            'chat_closed'    => 'แชทปิดเมื่อจบไลฟ์',
            'open_studio'    => 'เปิดสตูดิโอสอนสด',
            'admin_use_cf' => 'ส่งภาพผ่าน Cloudflare Stream เมื่อเชื่อมต่อไว้',
            'admin_use_cf_hint' => 'ผู้ชมไม่จำกัด ดีเลย์ต่ำกว่า 1 วินาที คิดค่าบริการตามนาทีที่มีคนดู (Cloudflare) · API Token ต้องมีสิทธิ์ Stream:Edit · ปิดไว้ = ส่งตรงจากเครื่องผู้สอนเสมอ',
            'admin_p2p_max' => 'ผู้ชมสูงสุดต่อไลฟ์ (แบบส่งตรง)',
            'admin_p2p_max_hint' => 'แบบส่งตรงใช้เน็ตขาขึ้นของผู้สอนตามจำนวนผู้ชม แนะนำไม่เกิน 30–40 คน',
            'admin_uplink' => 'เน็ตขาขึ้นที่ให้ใช้ (kbps)',
            'admin_uplink_hint' => 'แบ่งให้ผู้ชมแต่ละคน ผู้ชมเยอะภาพแต่ละคนจะเบาลงเอง (12000 = 12 Mbps)',
            'admin_turn' => 'TURN server (ไม่บังคับ)',
            'admin_turn_hint' => 'ช่วยผู้ชมที่อยู่หลังเครือข่ายที่ปิดการเชื่อมต่อตรง เช่น บางเครือข่ายองค์กร ใส่ได้หลายตัวคั่นด้วยเว้นวรรค เช่น turn:turn.example.com:3478',
            'admin_turn_user' => 'ชื่อผู้ใช้ TURN',
            'admin_turn_pass' => 'รหัสผ่าน TURN',
            'admin_now' => 'ตอนนี้ไลฟ์ใช้:',
            'admin_saved' => 'บันทึกการตั้งค่า Aleanor Streaming แล้ว'
        ];
        if($k === null) return $L;
        $s = $L[$k] ?? $k;
        return $rep ? strtr($s, $rep) : $s;
    }
    /** ข้อความสำหรับ JS (key แบบ camelCase เหมือน aleanor_ai) */
    public static function jsLabels(array $keys){
        $o = [];
        foreach($keys as $k) $o[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $k))))] = self::L($k, [':n' => self::p2pMax()]);
        return $o;
    }
}
