<?php
// ============================================================
// เทสต์ส่วนแบ่งรายได้ + ลำดับกฎ + flow ซื้อ/ลงบัญชี/คืนเงิน
// รัน:  /Applications/MAMP/bin/php/php8.4.1/bin/php tests/run.php
//   - unit: ไม่แตะฐานข้อมูล
//   - integration: ทำใน transaction แล้ว rollback ทิ้ง (ข้ามได้ด้วย --unit)
// ============================================================
require __DIR__.'/../core/bootstrap.php';

$pass = 0; $fail = 0;
function eq($label, $expected, $actual){
    global $pass, $fail;
    if($expected === $actual){ $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label\n      expected: ".var_export($expected, true)."\n      actual:   ".var_export($actual, true)."\n"; }
}
function section($t){ echo "\n$t\n"; }

// ── 1) การแบ่งเงิน ──
section('split()');
$fee = RevenueShareService::estimateFee('1000', 3.65);
eq('ค่าธรรมเนียม 3.65% ของ 1000 = 36.50', '36.50', $fee);
$s = RevenueShareService::split('1000', '1000', $fee, 30);
eq('net = 963.50', '963.50', $s['net']);
eq('platform 30% = 289.05', '289.05', $s['platform']);
eq('instructor = 674.45', '674.45', $s['instructor']);

$s = RevenueShareService::split('999', '999', RevenueShareService::estimateFee('999', 3.65), 30);
eq('ปัดเศษ: 999 → fee 36.46, net 962.54, platform 288.76', ['962.54','288.76','673.78'], [$s['net'],$s['platform'],$s['instructor']]);
eq('platform + instructor = net เสมอ', $s['net'], number_format($s['platform'] + $s['instructor'], 2, '.', ''));

// คูปองผู้สอน 20% → ลดราคาก่อนแบ่ง
$s = RevenueShareService::split('1000', '800', RevenueShareService::estimateFee('800', 3.65), 30, 'instructor', 3.65);
eq('คูปองผู้สอน: จ่าย 800 → fee 29.20 net 770.80 platform 231.24 instructor 539.56', ['770.80','231.24','539.56'], [$s['net'],$s['platform'],$s['instructor']]);

// คูปองแพลตฟอร์ม 20% → ผู้สอนได้เท่าราคาเต็ม
$s = RevenueShareService::split('1000', '800', RevenueShareService::estimateFee('800', 3.65), 30, 'platform', 3.65);
eq('คูปองแพลตฟอร์ม: ผู้สอนยังได้ 674.45', '674.45', $s['instructor']);
eq('คูปองแพลตฟอร์ม: แพลตฟอร์ม = 770.80 - 674.45 = 96.35', '96.35', $s['platform']);

$s = RevenueShareService::split('1000', '0', '0', 30, 'platform', 3.65);
eq('คูปองแพลตฟอร์ม 100%: แพลตฟอร์มติดลบ -674.45', ['674.45','-674.45'], [$s['instructor'],$s['platform']]);

$s = RevenueShareService::split('500', '500', '0', 0);
eq('อัตรา 0% → ผู้สอนได้ทั้งหมด', '500.00', $s['instructor']);
$s = RevenueShareService::split('500', '500', '0', 100);
eq('อัตรา 100% → ผู้สอนได้ 0', '0.00', $s['instructor']);
$threw = false; try { RevenueShareService::split('500', '500', '0', 101); } catch(InvalidArgumentException $e){ $threw = true; }
eq('อัตรา > 100 ถูกปฏิเสธ', true, $threw);

section('allocateFee()');
eq('กระจาย 36.50 ตามยอด 600/400', ['a' => '21.90', 'b' => '14.60'], RevenueShareService::allocateFee('36.50', ['a' => '600', 'b' => '400']));
$al = RevenueShareService::allocateFee('10.00', ['x' => '1', 'y' => '1', 'z' => '1']);
eq('เศษไปลงรายการสุดท้าย (3.33/3.33/3.34)', ['x' => '3.33', 'y' => '3.33', 'z' => '3.34'], $al);

// ── 2) ลำดับกฎ ──
section('resolveRule() — course > instructor > global');
$rules = [
    ['id' => 1, 'scope' => 'global',     'instructor_id' => null, 'course_id' => null, 'platform_rate' => '30.00', 'starts_at' => null,                  'ends_at' => null,                  'is_active' => 1],
    ['id' => 2, 'scope' => 'instructor', 'instructor_id' => 7,    'course_id' => null, 'platform_rate' => '20.00', 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => null,                  'is_active' => 1],
    ['id' => 3, 'scope' => 'course',     'instructor_id' => null, 'course_id' => 42,   'platform_rate' => '10.00', 'starts_at' => '2026-06-01 00:00:00', 'ends_at' => '2026-07-01 00:00:00', 'is_active' => 1],
    ['id' => 4, 'scope' => 'course',     'instructor_id' => null, 'course_id' => 99,   'platform_rate' => '5.00',  'starts_at' => null,                  'ends_at' => null,                  'is_active' => 0],
    ['id' => 5, 'scope' => 'global',     'instructor_id' => null, 'course_id' => null, 'platform_rate' => '35.00', 'starts_at' => '2026-09-01 00:00:00', 'ends_at' => null,                  'is_active' => 1],
];
$r = function($course, $inst, $at) use($rules){ $x = RevenueShareService::resolveRule($rules, $course, $inst, $at); return $x ? (int)$x['id'] : null; };
eq('คอร์สอื่น ผู้สอนอื่น → global', 1, $r(1, 1, '2026-03-01 00:00:00'));
eq('ผู้สอน 7 → instructor rule', 2, $r(1, 7, '2026-03-01 00:00:00'));
eq('ผู้สอน 7 ก่อนกฎเริ่ม → global', 1, $r(1, 7, '2025-12-31 23:59:59'));
eq('คอร์ส 42 ในช่วงโปร → course rule ชนะ instructor', 3, $r(42, 7, '2026-06-15 00:00:00'));
eq('คอร์ส 42 หลังโปรจบ (ends_at ไม่รวม) → กลับไป instructor', 2, $r(42, 7, '2026-07-01 00:00:00'));
eq('กฎที่ปิดใช้ (is_active=0) ไม่ถูกเลือก', 1, $r(99, 1, '2026-03-01 00:00:00'));
eq('global หลายอัน → starts_at ล่าสุดชนะ', 5, $r(1, 1, '2026-10-01 00:00:00'));
eq('ไม่มีกฎ → null', null, RevenueShareService::resolveRule([], 1, 1, '2026-01-01 00:00:00'));

// ── สิทธิ์ผู้สอน (pure) ──
section('PermissionService::resolve() — override > default > registry');
eq('ไม่มีการตั้งค่า → ค่าใน registry (vc ปิด)', false, PermissionService::resolve('vc', [], []));
eq('ไม่มีการตั้งค่า → ค่าใน registry (coupons เปิด)', true, PermissionService::resolve('coupons', [], []));
eq('ค่าเริ่มต้นปิด', false, PermissionService::resolve('coupons', ['coupons' => 0], []));
eq('override เปิดชนะค่าเริ่มต้นปิด', true, PermissionService::resolve('coupons', ['coupons' => 0], ['coupons' => 1]));
eq('override ปิดชนะค่าเริ่มต้นเปิด', false, PermissionService::resolve('vc', ['vc' => 1], ['vc' => 0]));
eq('โมดูลปิด → ปิดเสมอ แม้ override เปิด', false, PermissionService::resolve('vc', [], ['vc' => 1], false));
eq('สิทธิ์ที่ไม่รู้จัก → ปิด', false, PermissionService::resolve('nope', ['nope' => 1], []));

section('PayoutService::withholding()');
eq('1000 × 3% = 30 → 970', ['withholding' => '30.00', 'net' => '970.00'], PayoutService::withholding('1000', 3));
eq('อัตรา 0', ['withholding' => '0.00', 'net' => '674.45'], PayoutService::withholding('674.45', 0));

section('IntegrationService — Playground ticket / VC JWT (รูปแบบเดียวกับ aleanor_ai)');
$sec = 'test-secret';
$t = IntegrationService::pgIssueTicket(42, 90, $sec, 1000000);
eq('ticket ถูกต้อง → user 42', 42, IntegrationService::pgVerifyTicket($t, $sec, 1000010));
eq('ticket หมดอายุ (>90 วิ) → 0', 0, IntegrationService::pgVerifyTicket($t, $sec, 1000091));
eq('secret ผิด → 0', 0, IntegrationService::pgVerifyTicket($t, 'other', 1000010));
list($pp, $sg) = explode('.', $t);
$forged = IntegrationService::b64(str_replace('"u":42', '"u":1', IntegrationService::unb64($pp))).'.'.$sg;
eq('แก้ payload (เปลี่ยน user) → 0', 0, IntegrationService::pgVerifyTicket($forged, $sec, 1000010));
eq('k เป็นรหัส 32 ตัวตามที่ Playground เก็บ', 32, strlen(json_decode(IntegrationService::unb64($pp), true)['k']));
$jwt = IntegrationService::jwt(['iss' => 'cloud', 'sub' => 'cloud-1', 'exp' => 1], $sec);
list($jh, $jp, $js) = explode('.', $jwt);
eq('JWT HS256 ลายเซ็นตรวจได้', true, hash_equals(IntegrationService::b64(hash_hmac('sha256', "$jh.$jp", $sec, true)), $js));
eq('JWT header alg', 'HS256', json_decode(IntegrationService::unb64($jh), true)['alg']);

// ── 3) integration (ฐานข้อมูล, rollback ทิ้ง) ──
if(!in_array('--unit', $argv, true)){
    section('integration — checkout → ledger → refund (rollback ทิ้ง)');
    class TestRollback extends Exception {}
    try {
        db_tx(function(){
            $_SESSION['uid'] = 1;   // แอดมิน (สำหรับ audit)
            setting_set('earnings_hold_days', '14');
            setting_set('gateway_fee_rate', '3.65');
            $now = date('Y-m-d H:i:s');
            db_write("UPDATE revenue_share_rules SET is_active = 0 WHERE scope <> 'global'");
            RevenueShareService::setRate('global', null, 30, date('Y-m-d H:i:s', strtotime('-1 day')), null, 'test');

            $uid = db_insert("INSERT INTO users (email, password_hash, name, created_at) VALUES (?, 'x', 'buyer', ?)", ['buyer_'.uniqid().'@t.test', $now]);
            $tid = db_insert("INSERT INTO users (email, password_hash, name, created_at) VALUES (?, 'x', 'teacher', ?)", ['teacher_'.uniqid().'@t.test', $now]);
            $cid = db_insert("INSERT INTO courses (instructor_id, slug, title, price, status, created_at, updated_at) VALUES (?, ?, 'T', 1000, 'published', ?, ?)", [$tid, 'test-'.uniqid(), $now, $now]);
            $course = db_one("SELECT * FROM courses WHERE id = ?", [$cid]);

            $order = OrderService::createOrder($uid, OrderService::quote($course));
            OrderService::attachGatewayRef($order['id'], 'mock', 'mock_test_'.uniqid());
            eq('confirmPaid ครั้งแรก = true', true, OrderService::confirmPaid($order['id'], '', null));
            eq('confirmPaid ซ้ำ (webhook ซ้ำ) = false', false, OrderService::confirmPaid($order['id'], '', null));
            $it = db_one("SELECT * FROM order_items WHERE order_id = ?", [$order['id']]);
            eq('snapshot: fee/net/rate/platform/instructor', ['36.50','963.50','30.00','289.05','674.45'],
               [$it['gateway_fee'], $it['net_amount'], $it['platform_rate'], $it['platform_amount'], $it['instructor_amount']]);
            eq('ลง ledger 1 แถว (ไม่ซ้ำ)', 1, (int)db_val("SELECT COUNT(*) FROM instructor_ledger WHERE order_item_id = ?", [$it['id']]));
            $b = LedgerService::balances($tid);
            eq('ยอดพัก 674.45 / ถอนได้ 0', ['674.45','0.00'], [$b['held'], $b['available']]);
            eq('ลงทะเบียนเรียนแล้ว', true, OrderService::isEnrolled($uid, $cid));

            // เปลี่ยนอัตราภายหลัง → ยอดเก่าไม่เปลี่ยน, ยอดใหม่ใช้กฎคอร์ส
            RevenueShareService::setRate('instructor', $tid, 20, null, null, 'test');
            RevenueShareService::setRate('course', $cid, 10, null, null, 'test');
            eq('กฎคอร์สชนะกฎผู้สอน', 10.0, RevenueShareService::rateFor($cid, $tid)['rate']);
            eq('ยอดขายเก่ายังเป็น 30%', '289.05', db_val("SELECT platform_amount FROM order_items WHERE id = ?", [$it['id']]));
            RevenueShareService::setRate('course', $cid, 15, null, null, 'test เปลี่ยนอีกรอบ');
            eq('ตั้งกฎคอร์สใหม่ → กฎเก่าถูกปิด เหลือเปิด 1 อัน', 1,
               (int)db_val("SELECT COUNT(*) FROM revenue_share_rules WHERE scope='course' AND course_id = ? AND ends_at IS NULL", [$cid]));
            eq('ทุกการเปลี่ยนอัตราลง audit_logs', true, (int)db_val("SELECT COUNT(*) FROM audit_logs WHERE entity = 'revenue_share_rule'") >= 4);

            // คืนเงินในช่วงพัก → หักล้างในสถานะ held
            OrderService::refundItem($it['id'], 'test');
            $b = LedgerService::balances($tid);
            eq('คืนเงินในช่วงพัก: held = 0', '0.00', $b['held']);
            eq('ออเดอร์เป็น refunded + ยกเลิกสิทธิ์', ['refunded', false],
               [db_val("SELECT status FROM orders WHERE id = ?", [$order['id']]), OrderService::isEnrolled($uid, $cid)]);

            // ขายรอบสอง (คูปองแพลตฟอร์ม) → ปล่อยเงินด้วย cron
            $cpId = db_insert("INSERT INTO coupons (code, owner_type, type, value, created_by, created_at) VALUES (?, 'platform', 'percent', 20, 1, ?)", ['T'.strtoupper(uniqid()), $now]);
            $code = db_val("SELECT code FROM coupons WHERE id = ?", [$cpId]);
            $q = OrderService::quote($course, $code);
            eq('quote คูปอง 20% → 800', '800.00', $q['total']);
            $o2 = OrderService::createOrder($uid, $q);
            OrderService::confirmPaid($o2['id'], '', null, date('Y-m-d H:i:s', strtotime('-15 days')));
            $it2 = db_one("SELECT * FROM order_items WHERE order_id = ?", [$o2['id']]);
            eq('คูปองแพลตฟอร์ม + กฎคอร์ส 15% (ณ วันจ่าย -15 วัน ยังเป็น global 30%)', ['30.00','674.45','96.35'],
               [$it2['platform_rate'], $it2['instructor_amount'], $it2['platform_amount']]);
            eq('นับการใช้คูปอง', 1, (int)db_val("SELECT used_count FROM coupons WHERE id = ?", [$cpId]));
            LedgerService::releaseDue();
            eq('cron ปล่อยเงินที่พ้น 14 วัน → available 674.45', '674.45', LedgerService::balances($tid)['available']);

            // คืนเงินหลังปล่อยแล้ว → หักจากยอดถอนได้
            OrderService::refundItem($it2['id'], 'test');
            eq('คืนเงินหลังปล่อย: available = 0', '0.00', LedgerService::balances($tid)['available']);
            $threw = false; try { OrderService::refundItem($it2['id'], 'again'); } catch(RuntimeException $e){ $threw = true; }
            eq('คืนเงินซ้ำไม่ได้', true, $threw);

            // ── คืนเงินบางส่วน ──
            $c3 = db_insert("INSERT INTO courses (instructor_id, slug, title, price, status, created_at, updated_at) VALUES (?, ?, 'T3', 1000, 'published', ?, ?)", [$tid, 'test-'.uniqid(), $now, $now]);
            $course3 = db_one("SELECT * FROM courses WHERE id = ?", [$c3]);
            db_write("UPDATE revenue_share_rules SET is_active = 0 WHERE scope = 'course' AND course_id = ?", [$c3]);
            RevenueShareService::setRate('course', $c3, 30, date('Y-m-d H:i:s', strtotime('-1 day')), null, 'test');
            $o3 = OrderService::createOrder($uid, OrderService::quote($course3));
            OrderService::attachGatewayRef($o3['id'], 'mock', 'mock_t3_'.uniqid());
            OrderService::confirmPaid($o3['id']);
            $it3 = db_one("SELECT * FROM order_items WHERE order_id = ?", [$o3['id']]);
            OrderService::refundItem($it3['id'], 'partial', '300');
            $it3 = db_one("SELECT * FROM order_items WHERE id = ?", [$it3['id']]);
            eq('คืนบางส่วน 300/1000 → หักผู้สอน 202.34 (674.45×0.3), ยังเรียนได้', ['300.00','202.34',true,'partially_refunded'],
               [$it3['refunded_amount'], $it3['instructor_reversed'], OrderService::isEnrolled($uid, $c3), db_val("SELECT status FROM orders WHERE id = ?", [$o3['id']])]);
            $threw = false; try { OrderService::refundItem($it3['id'], 'too much', '800'); } catch(RuntimeException $e){ $threw = true; }
            eq('คืนเกินยอดคงเหลือไม่ได้', true, $threw);
            OrderService::refundItem($it3['id'], 'rest', null, true);   // ผ่าน gateway (mock)
            $it3 = db_one("SELECT * FROM order_items WHERE id = ?", [$it3['id']]);
            eq('คืนส่วนที่เหลือ → หักผู้สอนรวมเท่ายอดที่ได้พอดี + ยกเลิกสิทธิ์', ['1000.00','674.45',false,'refunded'],
               [$it3['refunded_amount'], $it3['instructor_reversed'], OrderService::isEnrolled($uid, $c3), db_val("SELECT status FROM orders WHERE id = ?", [$o3['id']])]);
            eq('บันทึก gateway_refund_ref เมื่อคืนผ่าน gateway', 1, (int)db_val("SELECT COUNT(*) FROM refunds WHERE order_item_id = ? AND via_gateway = 1 AND gateway_refund_ref LIKE 'mockrf_%'", [$it3['id']]));
            eq('ledger ผู้สอนคอร์สนี้รวมเป็น 0', '0.00', db_val("SELECT COALESCE(SUM(amount),0) FROM instructor_ledger WHERE order_item_id = ?", [$it3['id']]));

            // ── ตะกร้าหลายคอร์ส + คูปองผู้สอน (เฉพาะคอร์สของผู้สอนคนนั้น) ──
            $t2 = db_insert("INSERT INTO users (email, password_hash, name, created_at) VALUES (?, 'x', 'teacher2', ?)", ['t2_'.uniqid().'@t.test', $now]);
            $cA = db_one("SELECT * FROM courses WHERE id = ?", [db_insert("INSERT INTO courses (instructor_id, slug, title, price, status, created_at, updated_at) VALUES (?, ?, 'A', 600, 'published', ?, ?)", [$tid, 'a-'.uniqid(), $now, $now])]);
            $cB = db_one("SELECT * FROM courses WHERE id = ?", [db_insert("INSERT INTO courses (instructor_id, slug, title, price, status, created_at, updated_at) VALUES (?, ?, 'B', 400, 'published', ?, ?)", [$t2, 'b-'.uniqid(), $now, $now])]);
            $ic = 'I'.strtoupper(uniqid());
            db_insert("INSERT INTO coupons (code, owner_type, instructor_id, type, value, created_by, created_at) VALUES (?, 'instructor', ?, 'percent', 50, ?, ?)", [$ic, $tid, $tid, $now]);
            $q = OrderService::quoteCart([$cA, $cB], $ic);
            eq('คูปองผู้สอน 50% ลดเฉพาะคอร์สของตัวเอง', ['300.00','0.00','700.00','instructor','none'],
               [$q['items'][0]['discount'], $q['items'][1]['discount'], $q['total'], $q['items'][0]['coupon_owner'], $q['items'][1]['coupon_owner']]);
            $fc = 'F'.strtoupper(uniqid());
            db_insert("INSERT INTO coupons (code, owner_type, type, value, created_by, created_at) VALUES (?, 'platform', 'fixed', 100, 1, ?)", [$fc, $now]);
            $q = OrderService::quoteCart([$cA, $cB], $fc);
            eq('คูปองแพลตฟอร์ม 100 บาท กระจาย 60/40', ['60.00','40.00','900.00'], [$q['items'][0]['discount'], $q['items'][1]['discount'], $q['total']]);
            $o4 = OrderService::createOrder($uid, $q);
            OrderService::confirmPaid($o4['id']);
            $its = db_all("SELECT * FROM order_items WHERE order_id = ? ORDER BY id", [$o4['id']]);
            eq('ออเดอร์ 2 รายการ: ค่าธรรมเนียม 32.85 กระจาย 19.71/13.14', ['19.71','13.14'], [$its[0]['gateway_fee'], $its[1]['gateway_fee']]);
            eq('คูปองแพลตฟอร์ม: ผู้สอนได้เท่าราคาเต็ม (A: กฎผู้สอน 20%, B: global 30%)', ['462.48','269.78'], [$its[0]['instructor_amount'], $its[1]['instructor_amount']]);
            eq('มีเลขใบเสร็จ', 1, preg_match('~^RC\d{4}-\d{6}$~', (string)db_val("SELECT receipt_no FROM orders WHERE id = ?", [$o4['id']])));
            eq('คิวอีเมลผู้ซื้อ + ผู้สอน 2 คน', 3, (int)db_val("SELECT COUNT(*) FROM mail_queue WHERE subject LIKE ? OR subject LIKE 'มียอดขายใหม่: A' OR subject LIKE 'มียอดขายใหม่: B'",
               ['%'.db_val("SELECT receipt_no FROM orders WHERE id = ?", [$o4['id']])]));

            // ── รอบจ่ายเงิน ──
            setting_set('payout_min_amount', '500'); setting_set('withholding_tax_rate', '3');
            LedgerService::adjust($tid, '1000', 'test top-up');
            $period = date('Y-m');
            $prev = PayoutService::preview($period);
            eq('preview: ผู้สอน 1 ยอดถอนได้รวม 1000 (ขาย+คืน+ปรับ)', '1000.00', $prev[$tid]['gross'] ?? null);
            eq('ผู้สอน 2 ยอดยังพักอยู่ → ไม่เข้ารอบ', false, isset($prev[$t2]));
            $ids = PayoutService::createRun($period);
            $p = db_one("SELECT * FROM payouts WHERE instructor_id = ? AND period = ?", [$tid, $period]);
            eq('รอบจ่าย: gross 1000, หัก 3% = 30, โอน 970', ['1000.00','30.00','970.00'], [$p['gross'], $p['withholding_tax'], $p['net_amount']]);
            eq('ยอดถอนได้หลังสร้างรอบ = 0', '0.00', LedgerService::balances($tid)['available']);
            eq('สร้างรอบซ้ำเดือนเดิมไม่ได้ซ้ำ', [], PayoutService::createRun($period));
            PayoutService::cancel($p['id'], 'test');
            eq('ยกเลิกรอบ → ยอดกลับมา 1000', '1000.00', LedgerService::balances($tid)['available']);
            $ids = PayoutService::createRun($period);
            eq('สร้างรอบใหม่หลังยกเลิกได้', 1, count($ids));
            eq('markPaid', true, PayoutService::markPaid($ids[0], 'TRX1'));
            eq('withholding() ปัดเศษ 333.33 × 3% = 10.00', ['10.00','323.33'], array_values(PayoutService::withholding('333.33', 3)));

            // ── VAT ──
            setting_set('vat_enabled', '1'); setting_set('vat_rate', '7');
            eq('VAT รวมใน 1070 = 70.00', '70.00', OrderService::vatOf('1070')['vat']);
            eq('VAT รวมใน 1000 = 65.42', '65.42', OrderService::vatOf('1000')['vat']);

            // ── สิทธิ์ผู้สอน (ฐานข้อมูล) ──
            PermissionService::set(null, 'coupons', 0);
            eq('ค่าเริ่มต้นปิดคูปอง → ผู้สอนทุกคนใช้ไม่ได้', false, PermissionService::can($tid, 'coupons'));
            PermissionService::set($tid, 'coupons', 1);
            eq('override รายคนเปิด → คนนี้ใช้ได้', [true, false], [PermissionService::can($tid, 'coupons'), PermissionService::can($t2, 'coupons')]);
            PermissionService::set($tid, 'coupons', null);
            eq('ลบ override → กลับไปตามค่าเริ่มต้น', false, PermissionService::can($tid, 'coupons'));
            setting_set('mod_indy', '0');
            eq('ปิดโมดูล indy → ไม่มีใครใช้ lesson.indy ได้', false, PermissionService::can($tid, 'lesson.indy'));
            // ── กันเดารหัสผ่าน ──
            $em = 'throttle_'.uniqid().'@t.test';
            for($i = 0; $i < 4; $i++) login_failed($em);
            eq('พลาด 4 ครั้ง → ยังไม่ล็อก', false, login_throttled($em));
            login_failed($em);
            eq('พลาดครั้งที่ 5 → ล็อก 15 นาที', true, login_throttled($em));
            login_clear($em);
            eq('ล็อกอินสำเร็จแล้วล้างตัวนับ', false, login_throttled($em));
            eq('เปลี่ยนสิทธิ์ลง audit_logs', true, (int)db_val("SELECT COUNT(*) FROM audit_logs WHERE action = 'permission_set'") >= 3);

            throw new TestRollback();
        });
    } catch(TestRollback $e){ echo "  (rollback แล้ว)\n"; }
}

echo "\n".($fail ? "FAILED" : "OK")." — $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
