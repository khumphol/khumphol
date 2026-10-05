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

            $order = OrderService::createOrder($uid, $course, OrderService::quote($course));
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
            $o2 = OrderService::createOrder($uid, $course, $q);
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

            throw new TestRollback();
        });
    } catch(TestRollback $e){ echo "  (rollback แล้ว)\n"; }
}

echo "\n".($fail ? "FAILED" : "OK")." — $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
