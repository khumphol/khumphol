<?php
// ============================================================
// เชื่อมแอปภายนอก (รูปแบบ token เดียวกับ aleanor_ai เพื่อให้แอปเดิมรับได้โดยไม่ต้องแก้)
//
// Aleanor Playground — SSO แบบ ticket (aleanor_ai/core/playground.core.php):
//   ticket = b64url(json{k,u,iat,exp,n,aud:"playground"}) . "." . b64url(HMAC-SHA256(payload, secret))
//   Playground แลก ticket → โปรไฟล์ที่ api/playground.php?action=sso_verify (X-Api-Key = secret)
//   k = รหัสผู้ใช้ 32 ตัว (Playground เก็บเป็น CHAR(32)) = md5('aleanor_cloud:'.id)
//
// Aleanor VC — JWT HS256 (aleanor_ai/core/vc.core.php vcLaunchUrl):
//   {iss:tenant, sub, name, role, email, class_token, jti, iat, exp:+300} → <vc>/api/vc-gateway/enter.php?t=
// ============================================================

class IntegrationService
{
    public static function b64($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    public static function unb64($d){ return base64_decode(strtr($d, '-_', '+/').str_repeat('=', (4 - strlen($d) % 4) % 4)); }

    // ── Playground ──
    public static function pgEnabled(){ return PermissionService::moduleOn('playground') && self::pgBase() !== '' && self::pgSecret() !== ''; }
    public static function pgBase(){ return rtrim(trim(setting('playground_url', '')), '/'); }
    public static function pgSecret(){ return (string)setting('playground_secret', ''); }
    public static function pgUserKey($userId){ return md5('aleanor_cloud:'.(int)$userId); }

    public static function pgIssueTicket($userId, $ttl = 90, $secret = null, $now = null){
        $secret = $secret ?? self::pgSecret();
        if($secret === '') return '';
        $now = $now ?? time();
        $p = self::b64(json_encode(['k' => self::pgUserKey($userId), 'u' => (int)$userId, 'iat' => $now, 'exp' => $now + $ttl,
                                   'n' => bin2hex(random_bytes(8)), 'aud' => 'playground'], JSON_UNESCAPED_UNICODE));
        return $p.'.'.self::b64(hash_hmac('sha256', $p, $secret, true));
    }
    /** ตรวจ ticket → user id (0 = ไม่ผ่าน) — กันใช้ซ้ำทำที่ฝั่ง Playground (pg_sso_used) */
    public static function pgVerifyTicket($ticket, $secret = null, $now = null){
        $secret = $secret ?? self::pgSecret();
        $now = $now ?? time();
        if($secret === '' || !is_string($ticket) || substr_count($ticket, '.') !== 1) return 0;
        list($p, $sig) = explode('.', $ticket, 2);
        if(!hash_equals(self::b64(hash_hmac('sha256', $p, $secret, true)), $sig)) return 0;
        $d = json_decode(self::unb64($p), true);
        if(!is_array($d) || ($d['aud'] ?? '') !== 'playground') return 0;
        if(!isset($d['exp']) || $d['exp'] < $now || !isset($d['iat']) || $d['iat'] > $now + 60) return 0;
        if(($d['k'] ?? '') !== self::pgUserKey($d['u'] ?? 0)) return 0;
        return (int)$d['u'];
    }
    public static function pgProfile($userId){
        $u = db_one("SELECT u.*, ip.status ist FROM users u LEFT JOIN instructor_profiles ip ON ip.user_id = u.id WHERE u.id = ? AND u.status = 1", [(int)$userId]);
        if(!$u) return null;
        return ['user_key' => self::pgUserKey($u['id']), 'username' => $u['email'], 'display_name' => $u['name'], 'email' => $u['email'],
                'role' => (int)$u['is_admin'] === 1 ? 'admin' : ($u['ist'] === 'approved' ? 'teacher' : 'student'),
                'student_code' => '', 'school' => setting('site_name', 'Aleanor Cloud')];
    }
    /** ลิงก์เปิดงาน Playground ของบทเรียน (ผ่าน SSO ของ Playground) */
    public static function pgLessonUrl($assignmentKey){
        return self::pgBase().'/sso/start.php?next='.rawurlencode('play.php?a='.$assignmentKey);
    }

    // ── VC ──
    public static function vcEnabled(){ return PermissionService::moduleOn('vc') && self::vcBase() !== '' && self::vcSecret() !== ''; }
    public static function vcBase(){ return rtrim(trim(setting('vc_base_url', '')), '/'); }
    public static function vcSecret(){ return (string)setting('vc_jwt_secret', ''); }
    public static function jwt(array $payload, $secret){
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $h.'.'.$p.'.'.self::b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
    }
    /** ห้องผูกกับ id คอร์สเสมอ (ผู้สอนตั้งชื่อห้องเองไม่ได้ — กันแอบเข้าห้องคอร์สอื่นในฐานะ teacher) */
    public static function vcRoom(array $course){ return 'course-'.(int)$course['id']; }
    /** สิทธิ์เข้าห้อง: ผู้ลงทะเบียน (student) / ผู้สอนเจ้าของคอร์สหรือแอดมิน (teacher) — null = เข้าไม่ได้ */
    public static function vcRoleFor(array $course, $userId){
        if(!$userId) return null;
        $u = db_one("SELECT is_admin FROM users WHERE id = ?", [(int)$userId]);
        if((int)$course['instructor_id'] === (int)$userId || ($u && (int)$u['is_admin'] === 1)) return 'teacher';
        return OrderService::isEnrolled($userId, $course['id']) ? 'student' : null;
    }
    public static function vcLaunchUrl(array $course, array $user, $role){
        $now = time();
        $jwt = self::jwt(['iss' => setting('vc_tenant_id', 'cloud'), 'sub' => 'cloud-'.(int)$user['id'], 'name' => $user['name'], 'role' => $role,
                          'email' => $user['email'], 'class_token' => self::vcRoom($course), 'jti' => bin2hex(random_bytes(12)),
                          'iat' => $now, 'exp' => $now + 300], self::vcSecret());
        return self::vcBase().'/api/vc-gateway/enter.php?t='.rawurlencode($jwt);
    }
}
