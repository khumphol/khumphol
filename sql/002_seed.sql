-- ============================================================
-- ค่าเริ่มต้น + ข้อมูลตัวอย่างสำหรับทดสอบบนเครื่อง (MAMP)
-- บัญชีทดสอบ (รหัสผ่านทุกบัญชี: test1234)
--   admin@aleanor.test       แอดมิน
--   teacher@aleanor.test     ผู้สอน (อนุมัติแล้ว) มีคอร์สเผยแพร่ 1 คอร์ส
--   student@aleanor.test     ผู้เรียน
-- ============================================================
SET NAMES utf8mb4;

INSERT INTO settings (k, v, updated_at) VALUES
 ('site_name', 'Aleanor Cloud', NOW()),
 ('default_platform_rate', '30', NOW()),       -- สำรองเมื่อไม่มีกฎ global
 ('gateway_fee_rate', '3.65', NOW()),          -- % ค่าธรรมเนียม gateway (ใช้ประมาณเมื่อ gateway ไม่ส่งค่าจริง)
 ('earnings_hold_days', '14', NOW()),
 ('payout_min_amount', '500', NOW()),
 ('gw_provider', 'mock', NOW()),
 ('gw_public_key', '', NOW()),
 ('gw_secret_key', '', NOW()),
 ('gw_webhook_secret', '', NOW())
ON DUPLICATE KEY UPDATE k = k;

-- กฎ global 30%
INSERT INTO revenue_share_rules (scope, platform_rate, starts_at, is_active, note, created_at)
SELECT 'global', 30.00, NULL, 1, 'ค่าเริ่มต้นของระบบ', NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM revenue_share_rules WHERE scope = 'global');

-- bcrypt ของ "test1234"
INSERT IGNORE INTO users (id, email, password_hash, name, is_admin, status, created_at) VALUES
 (1, 'admin@aleanor.test',   '$2y$12$cg3/XH32FCcN3ixFLc1QmufLuGwuxeA0YZzm6HQIiBKf1ZX85jrEC', 'ผู้ดูแลระบบ', 1, 1, NOW()),
 (2, 'teacher@aleanor.test', '$2y$12$cg3/XH32FCcN3ixFLc1QmufLuGwuxeA0YZzm6HQIiBKf1ZX85jrEC', 'ครูสมชาย ใจดี', 0, 1, NOW()),
 (3, 'student@aleanor.test', '$2y$12$cg3/XH32FCcN3ixFLc1QmufLuGwuxeA0YZzm6HQIiBKf1ZX85jrEC', 'นักเรียน ทดสอบ', 0, 1, NOW());

INSERT IGNORE INTO instructor_profiles (id, user_id, display_name, headline, bio, expertise, status, reviewed_by, reviewed_at, created_at, updated_at) VALUES
 (1, 2, 'ครูสมชาย', 'สอนเขียนโปรแกรม 10 ปี', 'ผู้สอนตัวอย่างสำหรับทดสอบระบบ', 'PHP, MySQL', 'approved', 1, NOW(), NOW(), NOW());

INSERT IGNORE INTO courses (id, instructor_id, slug, title, subtitle, description, level, price, status, submitted_at, published_at, reviewed_by, created_at, updated_at) VALUES
 (1, 2, 'php-basics', 'PHP พื้นฐานสำหรับมือใหม่', 'เริ่มเขียนเว็บด้วย PHP + MySQL', 'คอร์สตัวอย่าง: เรียนรู้ PHP ตั้งแต่ศูนย์', 'beginner', 1000.00, 'published', NOW(), NOW(), 1, NOW(), NOW());

INSERT IGNORE INTO course_sections (id, course_id, title, sort_order) VALUES
 (1, 1, 'เริ่มต้น', 1), (2, 1, 'ฐานข้อมูล', 2);

INSERT IGNORE INTO lessons (id, course_id, section_id, title, type, video_url, content, duration_min, is_preview, sort_order) VALUES
 (1, 1, 1, 'แนะนำคอร์ส', 'video', 'https://www.youtube.com/watch?v=OK_JCtrrv-c', 'ภาพรวมของคอร์ส', 5, 1, 1),
 (2, 1, 1, 'ติดตั้ง MAMP', 'text', '', 'ดาวน์โหลด MAMP แล้วเปิด Apache + MySQL', 10, 0, 2),
 (3, 1, 2, 'เชื่อมต่อ MySQL ด้วย mysqli', 'text', '', 'ใช้ prepared statement เสมอ', 15, 0, 1);
