<?php
// ============================================================
// สิทธิ์การใช้งานของผู้สอน (แอดมินกำหนด)
//   registry ในโค้ด: key → [label, group, default, module]
//   ลำดับ: override รายคน (instructor_permissions.instructor_id = X)
//        > ค่าเริ่มต้นของผู้สอนทุกคน (instructor_id NULL)
//        > ค่า default ใน registry
//   และฟีเจอร์ที่ผูกโมดูล ต้องเปิดโมดูลนั้นด้วย (mod_<module> ใน settings)
// ============================================================

class PermissionService
{
    public static function registry(){
        return [
            'courses'          => ['label' => 'สร้าง/แก้ไขคอร์ส',               'group' => 'คอร์ส',   'default' => 1, 'module' => null],
            'courses.pricing'  => ['label' => 'ตั้งราคาคอร์สเอง',                'group' => 'คอร์ส',   'default' => 1, 'module' => null],
            'courses.submit'   => ['label' => 'ส่งคอร์สให้ตรวจเพื่อเผยแพร่',       'group' => 'คอร์ส',   'default' => 1, 'module' => null],
            'students'         => ['label' => 'ดูรายชื่อ/ความคืบหน้าผู้เรียน',      'group' => 'คอร์ส',   'default' => 1, 'module' => null],
            'coupons'          => ['label' => 'สร้างคูปองส่วนลด',                 'group' => 'การขาย',  'default' => 1, 'module' => null],
            'earnings'         => ['label' => 'ดูรายได้และรอบจ่าย',               'group' => 'การขาย',  'default' => 1, 'module' => null],
            'lesson.video'     => ['label' => 'บทเรียนวิดีโอ/บทความ',             'group' => 'บทเรียน', 'default' => 1, 'module' => null],
            'lesson.indy'      => ['label' => 'Aleanor Indy (วิดีโอเลือกเส้นทาง)', 'group' => 'บทเรียน', 'default' => 1, 'module' => 'indy'],
            'docs'             => ['label' => 'Aleanor Docs (Write/Grid/Present)', 'group' => 'บทเรียน', 'default' => 1, 'module' => 'docs'],
            'lesson.playground'=> ['label' => 'Aleanor Playground',              'group' => 'บทเรียน', 'default' => 1, 'module' => 'playground'],
            'vc'               => ['label' => 'ห้องเรียนเสมือน (Aleanor VC)',      'group' => 'บทเรียน', 'default' => 0, 'module' => 'vc'],
            'certificates'     => ['label' => 'ออกใบประกาศเมื่อเรียนจบ',           'group' => 'บทเรียน', 'default' => 1, 'module' => 'certificates'],
        ];
    }

    /** โมดูลที่แอดมินเปิด/ปิดได้ทั้งระบบ */
    public static function modules(){
        return [
            'indy'         => ['label' => 'Aleanor Indy',       'icon' => 'fi-rr-shuffle',      'desc' => 'วิดีโอแบบเลือกเส้นทาง (branching video)'],
            'docs'         => ['label' => 'Aleanor Docs',       'icon' => 'fi-rr-document',     'desc' => 'Write / Grid / Present + โฟลเดอร์ ถังขยะ โควตา'],
            'playground'   => ['label' => 'Aleanor Playground', 'icon' => 'fi-rr-cube',         'desc' => 'แอป 3D ผ่าน SSO (ต้องตั้ง URL + secret)'],
            'vc'           => ['label' => 'Aleanor VC',         'icon' => 'fi-rr-users-alt',    'desc' => 'ห้องเรียนเสมือนผ่านลิงก์ JWT (ต้องตั้ง URL + secret)'],
            'certificates' => ['label' => 'ใบประกาศ',           'icon' => 'fi-rr-diploma',      'desc' => 'ออกใบประกาศเมื่อเรียนครบ + หน้าตรวจสอบ'],
        ];
    }
    public static function moduleOn($key){ return setting('mod_'.$key, '1') === '1'; }

    /**
     * ตัดสินสิทธิ์ (pure) — แยกออกมาให้เทสต์ได้โดยไม่ต้องมีฐานข้อมูล
     * @param array      $defaults  [feature => 0|1] ค่าเริ่มต้นทุกคน (จากฐานข้อมูล)
     * @param array      $overrides [feature => 0|1] ของผู้สอนคนนี้
     */
    public static function resolve($feature, array $defaults, array $overrides, $moduleOn = true){
        $reg = self::registry();
        if(!isset($reg[$feature])) return false;
        if($reg[$feature]['module'] !== null && !$moduleOn) return false;
        if(array_key_exists($feature, $overrides)) return (bool)$overrides[$feature];
        if(array_key_exists($feature, $defaults))  return (bool)$defaults[$feature];
        return (bool)$reg[$feature]['default'];
    }

    public static function can($instructorId, $feature, $fresh = false){
        static $rows = null;
        if($rows === null || $fresh){
            $rows = [];
            foreach(db_all("SELECT scope_id, feature, allowed FROM instructor_permissions") as $r) $rows[(int)$r['scope_id']][$r['feature']] = (int)$r['allowed'];
        }
        $reg = self::registry();
        $mod = isset($reg[$feature]) && $reg[$feature]['module'] ? self::moduleOn($reg[$feature]['module']) : true;
        return self::resolve($feature, $rows[0] ?? [], $rows[(int)$instructorId] ?? [], $mod);
    }

    /**
     * บันทึกสิทธิ์ — $instructorId null = ค่าเริ่มต้น; $allowed null = ลบ override (กลับไปใช้ค่าเริ่มต้น)
     */
    public static function set($instructorId, $feature, $allowed){
        if(!isset(self::registry()[$feature])) throw new InvalidArgumentException('ไม่รู้จักสิทธิ์ '.$feature);
        $scope = $instructorId === null ? 0 : (int)$instructorId;
        $before = db_one("SELECT allowed FROM instructor_permissions WHERE scope_id = ? AND feature = ?", [$scope, $feature]);
        $old = $before ? (int)$before['allowed'] : null;
        $new = $allowed === null ? null : (int)(bool)$allowed;
        if($old === $new) return false;
        if($new === null) db_write("DELETE FROM instructor_permissions WHERE scope_id = ? AND feature = ?", [$scope, $feature]);
        else db_write("INSERT INTO instructor_permissions (instructor_id, feature, allowed, updated_by, updated_at) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
                       [$instructorId === null ? null : $scope, $feature, $new, current_user_id() ?: null, date('Y-m-d H:i:s')]);
        audit('permission_set', 'instructor_permission', $instructorId === null ? null : $scope,
              ['feature' => $feature, 'allowed' => $old], ['feature' => $feature, 'allowed' => $new, 'scope' => $instructorId === null ? 'default' : 'instructor']);
        self::can(0, 'courses', true);   // รีเฟรช cache
        return true;
    }
}
