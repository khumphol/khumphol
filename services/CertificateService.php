<?php
// ============================================================
// ใบประกาศ — ออกให้อัตโนมัติเมื่อความคืบหน้าถึง cert_min_progress (%) และผู้สอนมีสิทธิ์ certificates
// เลขที่ไม่ซ้ำ (serial) ใช้ตรวจสอบที่หน้า verify-certificate ได้โดยไม่ต้องล็อกอิน
// ============================================================

class CertificateService
{
    public static function progress($userId, $courseId){
        $n = (int)db_val("SELECT COUNT(*) FROM lessons WHERE course_id = ?", [(int)$courseId]);
        if($n === 0) return 0;
        $d = (int)db_val("SELECT COUNT(*) FROM lesson_progress p JOIN lessons l ON l.id = p.lesson_id WHERE p.user_id = ? AND p.course_id = ? AND p.completed_at IS NOT NULL",
            [(int)$userId, (int)$courseId]);
        return (int)floor($d * 100 / $n);
    }

    public static function forUser($userId, $courseId){
        return db_one("SELECT * FROM certificates WHERE user_id = ? AND course_id = ? AND revoked_at IS NULL", [(int)$userId, (int)$courseId]);
    }

    /** ออกใบประกาศถ้าเข้าเงื่อนไข — คืนแถวใบประกาศหรือ null */
    public static function issueIfEligible($userId, $courseId){
        if(!PermissionService::moduleOn('certificates') || setting('cert_enabled', '1') !== '1') return null;
        if($c = self::forUser($userId, $courseId)) return $c;
        $course = db_one("SELECT c.*, COALESCE(ip.display_name, u.name) teacher FROM courses c JOIN users u ON u.id = c.instructor_id
                          LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id WHERE c.id = ?", [(int)$courseId]);
        if(!$course || !PermissionService::can($course['instructor_id'], 'certificates')) return null;
        if(!OrderService::isEnrolled($userId, $courseId)) return null;
        if(self::progress($userId, $courseId) < (int)setting('cert_min_progress', '100')) return null;
        $u = db_one("SELECT name FROM users WHERE id = ?", [(int)$userId]);
        do { $serial = 'AC-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)); } while(db_val("SELECT id FROM certificates WHERE serial = ?", [$serial]));
        db_write("INSERT INTO certificates (serial, user_id, course_id, name_on_cert, course_title, instructor_name, issued_at) VALUES (?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE revoked_at = NULL",
            [$serial, (int)$userId, (int)$courseId, $u['name'], $course['title'], $course['teacher'], date('Y-m-d H:i:s')]);
        return self::forUser($userId, $courseId);
    }

    public static function bySerial($serial){
        return db_one("SELECT * FROM certificates WHERE serial = ?", [strtoupper(trim((string)$serial))]);
    }
}
