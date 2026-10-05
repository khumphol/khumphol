<?php
// ข้อมูลเดโม่เพิ่มเติม (รันหลัง install.sh): คอร์สเผยแพร่เพิ่ม 2 คอร์ส + ยอดขายตัวอย่างผ่าน OrderService
// ใช้: /Applications/MAMP/bin/php/php8.4.1/bin/php sql/demo.php   (รันซ้ำได้ — ข้ามถ้ามีแล้ว)
if(PHP_SAPI !== 'cli') exit;
require dirname(__DIR__).'/core/bootstrap.php';
$_SESSION['uid'] = 1;   // แอดมิน (สำหรับ audit)
if(db_val("SELECT id FROM courses WHERE slug = 'excel-for-work'")){ echo "demo มีอยู่แล้ว\n"; exit; }

$now = now();
$courses = [
    ['excel-for-work', 'Excel สำหรับคนทำงาน', 'สูตร, Pivot และกราฟที่ใช้จริงในออฟฟิศ', 790, 'intermediate'],
    ['ux-writing', 'UX Writing ภาษาไทย', 'เขียนข้อความในแอปให้คนใช้เข้าใจทันที', 1290, 'beginner'],
];
foreach($courses as $c){
    $id = db_insert("INSERT INTO courses (instructor_id, slug, title, subtitle, description, level, price, status, submitted_at, published_at, reviewed_by, created_at, updated_at)
                     VALUES (2, ?, ?, ?, ?, ?, ?, 'published', ?, ?, 1, ?, ?)",
        [$c[0], $c[1], $c[2], $c[2]."\n\nคอร์สตัวอย่างสำหรับเดโม่ระบบ", $c[4], $c[3], $now, $now, $now, $now]);
    $s = db_insert("INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, 'เริ่มต้น', 1)", [$id]);
    db_insert("INSERT INTO lessons (course_id, section_id, title, type, video_url, content, duration_min, is_preview, sort_order) VALUES (?, ?, 'แนะนำคอร์ส', 'video', 'https://www.youtube.com/watch?v=OK_JCtrrv-c', 'ภาพรวม', 5, 1, 1)", [$id, $s]);
    db_insert("INSERT INTO lessons (course_id, section_id, title, type, content, duration_min, sort_order) VALUES (?, ?, 'บทที่ 1', 'text', 'เนื้อหาบทแรก', 12, 2)", [$id, $s]);
}
// อัตราเฉพาะคอร์ส Excel = 20% (โปรเปิดตัว)
$excel = db_one("SELECT * FROM courses WHERE slug = 'excel-for-work'");
RevenueShareService::setRate('course', $excel['id'], 20, null, null, 'โปรเปิดตัว');

// ยอดขายตัวอย่าง: นักเรียนซื้อ PHP พื้นฐาน (1000 บาท, global 30%) เมื่อ 20 วันก่อน (พ้นช่วงพักแล้ว) + Excel (790, 20%) วันนี้
$php = db_one("SELECT * FROM courses WHERE slug = 'php-basics'");
$o1 = OrderService::createOrder(3, OrderService::quote($php));
OrderService::attachGatewayRef($o1['id'], 'mock', 'mock_demo_1');
OrderService::confirmPaid($o1['id'], 'mock_demo_1', null, date('Y-m-d H:i:s', strtotime('-20 days')));
$o2 = OrderService::createOrder(3, OrderService::quote($excel));
OrderService::attachGatewayRef($o2['id'], 'mock', 'mock_demo_2');
OrderService::confirmPaid($o2['id'], 'mock_demo_2');
LedgerService::releaseDue();

// นักเรียนเรียนจบ PHP พื้นฐาน → ใบประกาศ
foreach(db_all("SELECT id FROM lessons WHERE course_id = ?", [(int)$php['id']]) as $l)
    db_write("INSERT INTO lesson_progress (user_id, course_id, lesson_id, completed_at, last_seen_at) VALUES (3, ?, ?, NOW(), NOW())
              ON DUPLICATE KEY UPDATE completed_at = NOW()", [(int)$php['id'], (int)$l['id']]);
$cert = CertificateService::issueIfEligible(3, (int)$php['id']);
echo 'certificate: '.($cert['serial'] ?? '-')."\n";

// รอบจ่ายเดือนที่แล้ว (ยอดขาย PHP พ้นช่วงพักแล้ว)
$ids = PayoutService::createRun(date('Y-m', strtotime('first day of last month')));
echo 'payouts: '.count($ids)."\n";

// ตะกร้าตัวอย่าง
db_write("INSERT IGNORE INTO cart_items (user_id, course_id, created_at) SELECT 3, id, NOW() FROM courses WHERE slug = 'ux-writing'");
foreach(db_all("SELECT c.title, oi.paid_amount, oi.gateway_fee, oi.platform_rate, oi.platform_amount, oi.instructor_amount FROM order_items oi JOIN courses c ON c.id = oi.course_id") as $r)
    echo implode(' | ', $r)."\n";
