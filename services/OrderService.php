<?php
// ============================================================
// คำสั่งซื้อ: ราคา+คูปอง → สร้างออเดอร์ → ยืนยันการจ่าย → คืนเงิน
//
// confirmPaid() ทำทุกอย่างใน transaction เดียว:
//   ล็อกออเดอร์ (FOR UPDATE) → ถ้าจ่ายแล้วจบ (idempotent: webhook/return ซ้ำไม่ลงบัญชีซ้ำ)
//   → ค่าธรรมเนียม (จริงจาก gateway หรือประมาณจากอัตรา) → กระจายลงรายการ
//   → หาอัตราส่วนแบ่ง ณ เวลาจ่าย + snapshot ลง order_items → ledger sale → เปิดสิทธิ์เรียน → นับคูปอง
// ============================================================

class OrderService
{
    /** ตรวจคูปองกับคอร์ส → ['ok'=>bool,'error'=>string,'coupon'=>array,'discount'=>string] */
    public static function checkCoupon($code, array $course){
        $code = strtoupper(trim((string)$code));
        if($code === '') return ['ok' => false, 'error' => ''];
        $cp = db_one("SELECT * FROM coupons WHERE code = ?", [$code]);
        $now = now();
        if(!$cp || !(int)$cp['is_active'])                       return ['ok' => false, 'error' => 'ไม่พบคูปองนี้'];
        if($cp['starts_at'] && $cp['starts_at'] > $now)          return ['ok' => false, 'error' => 'คูปองยังไม่เริ่มใช้'];
        if($cp['ends_at'] && $cp['ends_at'] <= $now)             return ['ok' => false, 'error' => 'คูปองหมดอายุแล้ว'];
        if((int)$cp['max_uses'] > 0 && (int)$cp['used_count'] >= (int)$cp['max_uses']) return ['ok' => false, 'error' => 'คูปองถูกใช้ครบแล้ว'];
        if($cp['course_id'] && (int)$cp['course_id'] !== (int)$course['id'])            return ['ok' => false, 'error' => 'คูปองนี้ใช้กับคอร์สนี้ไม่ได้'];
        if($cp['owner_type'] === 'instructor' && (int)$cp['instructor_id'] !== (int)$course['instructor_id'])
                                                                 return ['ok' => false, 'error' => 'คูปองนี้ใช้กับคอร์สนี้ไม่ได้'];
        $list = RevenueShareService::toSatang($course['price']);
        $disc = $cp['type'] === 'fixed' ? RevenueShareService::toSatang($cp['value'])
                                        : intdiv($list * (int)round($cp['value'] * 100) + 5000, 10000);
        $disc = min($disc, $list);
        if($disc <= 0) return ['ok' => false, 'error' => 'คูปองนี้ไม่มีส่วนลดสำหรับคอร์สนี้'];
        return ['ok' => true, 'error' => '', 'coupon' => $cp, 'discount' => RevenueShareService::fromSatang($disc)];
    }

    /** สรุปราคาสำหรับหน้า checkout */
    public static function quote(array $course, $couponCode = ''){
        $q = ['list' => number_format((float)$course['price'], 2, '.', ''), 'discount' => '0.00', 'coupon' => null, 'coupon_error' => ''];
        if($couponCode !== ''){
            $c = self::checkCoupon($couponCode, $course);
            if($c['ok']){ $q['discount'] = $c['discount']; $q['coupon'] = $c['coupon']; }
            else $q['coupon_error'] = $c['error'];
        }
        $q['total'] = RevenueShareService::fromSatang(RevenueShareService::toSatang($q['list']) - RevenueShareService::toSatang($q['discount']));
        return $q;
    }

    public static function isEnrolled($userId, $courseId){
        return (bool)db_val("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ? AND status = 'active'", [(int)$userId, (int)$courseId]);
    }

    /** สร้างออเดอร์ (pending) 1 คอร์ส — โครงตารางรองรับหลายรายการ */
    public static function createOrder($userId, array $course, array $quote){
        return db_tx(function() use($userId, $course, $quote){
            $no = 'AC'.date('ymd').strtoupper(bin2hex(random_bytes(4)));
            $now = now();
            $orderId = db_insert("INSERT INTO orders (order_no, user_id, status, subtotal, discount, total, coupon_id, created_at, updated_at)
                                  VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?)",
                [$no, (int)$userId, $quote['list'], $quote['discount'], $quote['total'],
                 $quote['coupon'] ? (int)$quote['coupon']['id'] : null, $now, $now]);
            db_insert("INSERT INTO order_items (order_id, course_id, instructor_id, list_price, discount, coupon_owner, paid_amount, created_at)
                       VALUES (?,?,?,?,?,?,?,?)",
                [$orderId, (int)$course['id'], (int)$course['instructor_id'], $quote['list'], $quote['discount'],
                 $quote['coupon'] ? $quote['coupon']['owner_type'] : 'none', $quote['total'], $now]);
            return db_one("SELECT * FROM orders WHERE id = ?", [$orderId]);
        });
    }

    public static function attachGatewayRef($orderId, $gateway, $ref){
        db_write("UPDATE orders SET gateway = ?, gateway_ref = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
            [$gateway, $ref, now(), (int)$orderId]);
    }

    /**
     * ยืนยันการจ่าย — เรียกซ้ำได้ คืน true ถ้าสถานะเปลี่ยนในครั้งนี้
     * @param float|null $gatewayFee ค่าธรรมเนียมจริงจาก gateway (null = ประมาณจาก gateway_fee_rate)
     * @param string|null $paidAt    ใช้ในเทสต์/ย้อนเวลา — ปกติ = ตอนนี้
     */
    public static function confirmPaid($orderId, $gatewayRef = '', $gatewayFee = null, $paidAt = null){
        return db_tx(function() use($orderId, $gatewayRef, $gatewayFee, $paidAt){
            $o = db_one("SELECT * FROM orders WHERE id = ? FOR UPDATE", [(int)$orderId]);
            if(!$o) throw new RuntimeException('ไม่พบคำสั่งซื้อ');
            if($o['status'] !== 'pending') return false;
            if($gatewayRef !== '' && $o['gateway_ref'] && $o['gateway_ref'] !== $gatewayRef)
                throw new RuntimeException('gateway_ref ไม่ตรงกับคำสั่งซื้อ');

            $paidAt = $paidAt ?: now();
            $feeRate = (float)setting('gateway_fee_rate', '3.65');
            $fee = (float)$o['total'] <= 0 ? '0.00'
                 : ($gatewayFee !== null ? number_format((float)$gatewayFee, 2, '.', '') : RevenueShareService::estimateFee($o['total'], $feeRate));

            $items = db_all("SELECT * FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE", [(int)$o['id']]);
            $alloc = RevenueShareService::allocateFee($fee, array_column($items, 'paid_amount', 'id'));

            foreach($items as $it){
                $r = RevenueShareService::rateFor((int)$it['course_id'], (int)$it['instructor_id'], $paidAt);
                $s = RevenueShareService::split($it['list_price'], $it['paid_amount'], $alloc[$it['id']], $r['rate'], $it['coupon_owner'], $feeRate);
                db_write("UPDATE order_items SET gateway_fee = ?, net_amount = ?, platform_rate = ?, rule_id = ?, platform_amount = ?, instructor_amount = ? WHERE id = ?",
                    [$alloc[$it['id']], $s['net'], number_format($r['rate'], 2, '.', ''), $r['rule_id'], $s['platform'], $s['instructor'], (int)$it['id']]);
                $it = db_one("SELECT * FROM order_items WHERE id = ?", [(int)$it['id']]);
                if((float)$it['instructor_amount'] != 0.0) LedgerService::recordSale($it, $paidAt);
                db_write("INSERT INTO enrollments (user_id, course_id, order_item_id, source, status, created_at) VALUES (?, ?, ?, 'purchase', 'active', ?)
                          ON DUPLICATE KEY UPDATE status = 'active', order_item_id = VALUES(order_item_id), source = 'purchase'",
                    [(int)$o['user_id'], (int)$it['course_id'], (int)$it['id'], $paidAt]);
            }
            db_write("UPDATE orders SET status = 'paid', paid_at = ?, gateway_fee = ?, gateway_ref = COALESCE(gateway_ref, ?), updated_at = ? WHERE id = ?",
                [$paidAt, $fee, $gatewayRef !== '' ? $gatewayRef : null, now(), (int)$o['id']]);
            if($o['coupon_id']) db_write("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?", [(int)$o['coupon_id']]);
            gwLog((int)$o['id'], 'marked_paid', $gatewayRef, ['fee' => $fee]);
            return true;
        });
    }

    public static function markFailed($orderId, $reason = ''){
        $n = db_write("UPDATE orders SET status = 'failed', updated_at = ? WHERE id = ? AND status = 'pending'", [now(), (int)$orderId]);
        if($n) gwLog((int)$orderId, 'failed', '', ['reason' => $reason]);
        return $n > 0;
    }

    /** คอร์สฟรี (ราคา 0) — ลงทะเบียนเลยไม่ต้องมีออเดอร์ */
    public static function enrollFree($userId, $courseId){
        db_write("INSERT INTO enrollments (user_id, course_id, source, status, created_at) VALUES (?, ?, 'free', 'active', ?)
                  ON DUPLICATE KEY UPDATE status = 'active'", [(int)$userId, (int)$courseId, now()]);
    }

    /**
     * คืนเงินทั้งรายการ (phase 2: คืนเต็มจำนวนต่อรายการ — ยอดเงินจริงให้แอดมินคืนที่หน้า gateway)
     * ผู้สอนถูกหักเท่าที่ได้รับจากรายการนั้น ค่าธรรมเนียม gateway แพลตฟอร์มรับไว้
     */
    public static function refundItem($itemId, $reason){
        return db_tx(function() use($itemId, $reason){
            $it = db_one("SELECT * FROM order_items WHERE id = ? FOR UPDATE", [(int)$itemId]);
            if(!$it) throw new RuntimeException('ไม่พบรายการ');
            $o = db_one("SELECT * FROM orders WHERE id = ? FOR UPDATE", [(int)$it['order_id']]);
            if(!in_array($o['status'], ['paid', 'partially_refunded'], true)) throw new RuntimeException('คำสั่งซื้อนี้คืนเงินไม่ได้');
            if($it['refunded_at']) throw new RuntimeException('รายการนี้คืนเงินไปแล้ว');
            $now = now();
            $refundId = db_insert("INSERT INTO refunds (order_id, order_item_id, amount, instructor_reversal, reason, created_by, created_at) VALUES (?,?,?,?,?,?,?)",
                [(int)$o['id'], (int)$it['id'], $it['paid_amount'], $it['instructor_amount'], mb_substr($reason, 0, 500), current_user_id(), $now]);
            if((float)$it['instructor_amount'] != 0.0) LedgerService::recordRefund($refundId, $it, $it['instructor_amount']);
            db_write("UPDATE order_items SET refunded_at = ? WHERE id = ?", [$now, (int)$it['id']]);
            db_write("UPDATE enrollments SET status = 'revoked' WHERE user_id = ? AND course_id = ? AND order_item_id = ?",
                [(int)$o['user_id'], (int)$it['course_id'], (int)$it['id']]);
            $left = (int)db_val("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND refunded_at IS NULL", [(int)$o['id']]);
            db_write("UPDATE orders SET status = ?, updated_at = ? WHERE id = ?", [$left ? 'partially_refunded' : 'refunded', $now, (int)$o['id']]);
            audit('refund', 'order_item', (int)$it['id'], null, ['refund_id' => $refundId, 'amount' => $it['paid_amount'],
                  'instructor_reversal' => $it['instructor_amount'], 'reason' => $reason]);
            return $refundId;
        });
    }
}
