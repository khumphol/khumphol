<?php
// ============================================================
// คำสั่งซื้อ: ตะกร้า/ราคา+คูปอง → สร้างออเดอร์ → ยืนยันการจ่าย → คืนเงิน (ทั้ง/บางส่วน, ผ่าน gateway ได้)
//
// confirmPaid() ทำทุกอย่างใน transaction เดียว:
//   ล็อกออเดอร์ (FOR UPDATE) → ถ้าจ่ายแล้วจบ (idempotent: webhook/return ซ้ำไม่ลงบัญชีซ้ำ)
//   → ค่าธรรมเนียม (จริงจาก gateway หรือประมาณจากอัตรา) → กระจายลงรายการ
//   → หาอัตราส่วนแบ่ง ณ เวลาจ่าย + snapshot ลง order_items → ledger sale → เปิดสิทธิ์เรียน → นับคูปอง
//   → เลขใบเสร็จ + VAT (ถ้าเปิด) → คิวอีเมลแจ้งผู้ซื้อ/ผู้สอน
// ============================================================

class OrderService
{
    // ── คูปอง ──
    /**
     * ตรวจคูปองกับรายการในตะกร้า
     * @param array $courses แถว courses
     * @return array ['ok','error','coupon','discounts' => [course_id => 'x.xx']]
     */
    public static function checkCoupon($code, array $courses){
        $code = strtoupper(trim((string)$code));
        if($code === '') return ['ok' => false, 'error' => ''];
        $cp = db_one("SELECT * FROM coupons WHERE code = ?", [$code]);
        $now = date('Y-m-d H:i:s');
        if(!$cp || !(int)$cp['is_active'])              return ['ok' => false, 'error' => 'ไม่พบคูปองนี้'];
        if($cp['starts_at'] && $cp['starts_at'] > $now) return ['ok' => false, 'error' => 'คูปองยังไม่เริ่มใช้'];
        if($cp['ends_at'] && $cp['ends_at'] <= $now)    return ['ok' => false, 'error' => 'คูปองหมดอายุแล้ว'];
        if((int)$cp['max_uses'] > 0 && (int)$cp['used_count'] >= (int)$cp['max_uses']) return ['ok' => false, 'error' => 'คูปองถูกใช้ครบแล้ว'];

        // รายการที่คูปองใช้ได้: ผูกคอร์ส → คอร์สนั้น, คูปองผู้สอน → คอร์สของผู้สอนนั้น, คูปองแพลตฟอร์ม → ทุกคอร์ส
        $eligible = [];
        foreach($courses as $c){
            if($cp['course_id'] && (int)$cp['course_id'] !== (int)$c['id']) continue;
            if($cp['owner_type'] === 'instructor' && (int)$cp['instructor_id'] !== (int)$c['instructor_id']) continue;
            if((float)$c['price'] <= 0) continue;
            $eligible[(int)$c['id']] = RevenueShareService::toSatang($c['price']);
        }
        if(!$eligible) return ['ok' => false, 'error' => 'คูปองนี้ใช้กับคอร์สในตะกร้าไม่ได้'];

        $disc = [];
        if($cp['type'] === 'percent'){
            $bp = (int)round((float)$cp['value'] * 100);
            foreach($eligible as $id => $list) $disc[$id] = min($list, intdiv($list * $bp + 5000, 10000));
        } else {
            // ส่วนลดจำนวนเงิน → กระจายตามสัดส่วนราคา (ไม่เกินราคารวม)
            $sum = array_sum($eligible);
            $total = min(RevenueShareService::toSatang($cp['value']), $sum);
            $used = 0; $ids = array_keys($eligible); $last = end($ids);
            foreach($eligible as $id => $list){
                $part = $id === $last ? $total - $used : intdiv($list * $total * 2 + $sum, 2 * $sum);
                $disc[$id] = min($list, $part); $used += $disc[$id];
            }
        }
        if(array_sum($disc) <= 0) return ['ok' => false, 'error' => 'คูปองนี้ไม่มีส่วนลดสำหรับรายการนี้'];
        return ['ok' => true, 'error' => '', 'coupon' => $cp,
                'discounts' => array_map(['RevenueShareService', 'fromSatang'], $disc)];
    }

    /** สรุปราคาหลายคอร์ส → ['items' => [...], 'list','discount','total','coupon','coupon_error'] */
    public static function quoteCart(array $courses, $couponCode = ''){
        $q = ['items' => [], 'coupon' => null, 'coupon_error' => ''];
        $disc = [];
        if(trim((string)$couponCode) !== ''){
            $c = self::checkCoupon($couponCode, $courses);
            if($c['ok']){ $q['coupon'] = $c['coupon']; $disc = $c['discounts']; }
            else $q['coupon_error'] = $c['error'];
        }
        $L = 0; $D = 0;
        foreach($courses as $c){
            $list = RevenueShareService::toSatang($c['price']);
            $d = isset($disc[(int)$c['id']]) ? RevenueShareService::toSatang($disc[(int)$c['id']]) : 0;
            $q['items'][] = ['course' => $c, 'list' => RevenueShareService::fromSatang($list), 'discount' => RevenueShareService::fromSatang($d),
                             'paid' => RevenueShareService::fromSatang($list - $d), 'coupon_owner' => $d > 0 ? $q['coupon']['owner_type'] : 'none'];
            $L += $list; $D += $d;
        }
        $q['list'] = RevenueShareService::fromSatang($L);
        $q['discount'] = RevenueShareService::fromSatang($D);
        $q['total'] = RevenueShareService::fromSatang($L - $D);
        return $q;
    }
    /** ราคาคอร์สเดียว (หน้า checkout แบบซื้อทันที) */
    public static function quote(array $course, $couponCode = ''){ return self::quoteCart([$course], $couponCode); }

    public static function isEnrolled($userId, $courseId){
        return (bool)db_val("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ? AND status = 'active'", [(int)$userId, (int)$courseId]);
    }

    /** สร้างออเดอร์ (pending) จาก quote — $bill = ['name','tax_id','address'] สำหรับใบกำกับภาษี */
    public static function createOrder($userId, array $quote, array $bill = []){
        if(!$quote['items']) throw new RuntimeException('ตะกร้าว่าง');
        return db_tx(function() use($userId, $quote, $bill){
            if($quote['coupon']){
                // จองสิทธิ์ใช้คูปองทันที (ล็อกแถว + ตรวจซ้ำ) — กันสร้างหลายออเดอร์ค้างไว้แล้วจ่ายเกินจำนวนที่กำหนด
                $cp = db_one("SELECT * FROM coupons WHERE id = ? FOR UPDATE", [(int)$quote['coupon']['id']]);
                $now = date('Y-m-d H:i:s');
                if(!$cp || !(int)$cp['is_active'] || ($cp['starts_at'] && $cp['starts_at'] > $now) || ($cp['ends_at'] && $cp['ends_at'] <= $now)
                   || ((int)$cp['max_uses'] > 0 && (int)$cp['used_count'] >= (int)$cp['max_uses']))
                    throw new RuntimeException('คูปองนี้ใช้ไม่ได้แล้ว');
                db_write("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?", [(int)$cp['id']]);
            }
            $no = 'AC'.date('ymd').strtoupper(bin2hex(random_bytes(4)));
            $now = date('Y-m-d H:i:s');
            $orderId = db_insert("INSERT INTO orders (order_no, user_id, status, subtotal, discount, total, coupon_id, bill_name, bill_tax_id, bill_address, created_at, updated_at)
                                  VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$no, (int)$userId, $quote['list'], $quote['discount'], $quote['total'], $quote['coupon'] ? (int)$quote['coupon']['id'] : null,
                 mb_substr((string)($bill['name'] ?? ''), 0, 200), preg_replace('~[^0-9]~', '', (string)($bill['tax_id'] ?? '')),
                 mb_substr((string)($bill['address'] ?? ''), 0, 500), $now, $now]);
            foreach($quote['items'] as $it){
                db_insert("INSERT INTO order_items (order_id, course_id, instructor_id, list_price, discount, coupon_owner, paid_amount, created_at)
                           VALUES (?,?,?,?,?,?,?,?)",
                    [$orderId, (int)$it['course']['id'], (int)$it['course']['instructor_id'], $it['list'], $it['discount'], $it['coupon_owner'], $it['paid'], $now]);
            }
            return db_one("SELECT * FROM orders WHERE id = ?", [$orderId]);
        });
    }

    public static function attachGatewayRef($orderId, $gateway, $ref){
        db_write("UPDATE orders SET gateway = ?, gateway_ref = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
            [$gateway, $ref, date('Y-m-d H:i:s'), (int)$orderId]);
    }

    /** VAT แบบราคารวม VAT แล้ว (inclusive) — ['rate','vat'] */
    public static function vatOf($total){
        if(setting('vat_enabled', '0') !== '1') return ['rate' => '0.00', 'vat' => '0.00'];
        $rate = (float)setting('vat_rate', '7');
        $t = RevenueShareService::toSatang($total);
        $base = intdiv($t * 10000 * 2 + (10000 + (int)round($rate * 100)), 2 * (10000 + (int)round($rate * 100)));
        return ['rate' => number_format($rate, 2, '.', ''), 'vat' => RevenueShareService::fromSatang($t - $base)];
    }

    /**
     * ยืนยันการจ่าย — เรียกซ้ำได้ คืน true ถ้าสถานะเปลี่ยนในครั้งนี้
     * @param float|null  $gatewayFee ค่าธรรมเนียมจริงจาก gateway (null = ประมาณจาก gateway_fee_rate)
     * @param string|null $paidAt     ใช้ในเทสต์/ข้อมูลเดโม่ — ปกติ = ตอนนี้
     */
    public static function confirmPaid($orderId, $gatewayRef = '', $gatewayFee = null, $paidAt = null){
        return db_tx(function() use($orderId, $gatewayRef, $gatewayFee, $paidAt){
            $o = db_one("SELECT * FROM orders WHERE id = ? FOR UPDATE", [(int)$orderId]);
            if(!$o) throw new RuntimeException('ไม่พบคำสั่งซื้อ');
            // failed/cancelled ที่ gateway ยืนยันว่าจ่ายแล้ว (เช่น กดยกเลิกแล้วกลับไปจ่ายในแท็บเดิม) → ต้องเปิดสิทธิ์ให้ ไม่งั้นลูกค้าเสียเงินฟรี
            if(!in_array($o['status'], ['pending', 'failed', 'cancelled'], true)) return false;
            if($o['status'] !== 'pending' && ($gatewayRef === '' || $o['gateway_ref'] !== $gatewayRef)) return false;
            if($gatewayRef !== '' && $o['gateway_ref'] && $o['gateway_ref'] !== $gatewayRef)
                throw new RuntimeException('gateway_ref ไม่ตรงกับคำสั่งซื้อ');

            $paidAt = $paidAt ?: date('Y-m-d H:i:s');
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
                db_write("DELETE FROM cart_items WHERE user_id = ? AND course_id = ?", [(int)$o['user_id'], (int)$it['course_id']]);
            }
            $vat = self::vatOf($o['total']);
            $receipt = 'RC'.date('ym', strtotime($paidAt)).'-'.str_pad((string)$o['id'], 6, '0', STR_PAD_LEFT);
            db_write("UPDATE orders SET status = 'paid', paid_at = ?, gateway_fee = ?, gateway_ref = COALESCE(gateway_ref, ?), receipt_no = ?,
                        vat_rate = ?, vat_amount = ?, updated_at = ? WHERE id = ?",
                [$paidAt, $fee, $gatewayRef !== '' ? $gatewayRef : null, $receipt, $vat['rate'], $vat['vat'], date('Y-m-d H:i:s'), (int)$o['id']]);
            // คูปองถูกจองไว้ตอนสร้างออเดอร์แล้ว — ถ้าออเดอร์เคยถูกปล่อย (failed) แต่ gateway ยืนยันว่าจ่ายจริง ให้นับกลับ
            if($o['coupon_id'] && $o['status'] === 'failed') db_write("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?", [(int)$o['coupon_id']]);
            gwLog((int)$o['id'], 'marked_paid', $gatewayRef, ['fee' => $fee]);
            if(class_exists('MailService')) MailService::orderPaid((int)$o['id']);
            return true;
        });
    }

    public static function markFailed($orderId, $reason = ''){
        return db_tx(function() use($orderId, $reason){
            $o = db_one("SELECT * FROM orders WHERE id = ? FOR UPDATE", [(int)$orderId]);
            if(!$o || $o['status'] !== 'pending') return false;
            db_write("UPDATE orders SET status = 'failed', updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int)$orderId]);
            if($o['coupon_id']) db_write("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?", [(int)$o['coupon_id']]);   // คืนสิทธิ์คูปอง
            gwLog((int)$orderId, 'failed', '', ['reason' => $reason]);
            return true;
        });
    }

    /** ออเดอร์ค้างจ่ายเกิน $hours ชม. → failed (คืนสิทธิ์คูปอง) — เรียกจาก cron */
    public static function expireStale($hours = 24){
        $n = 0;
        foreach(db_all("SELECT id FROM orders WHERE status = 'pending' AND created_at < ?", [date('Y-m-d H:i:s', time() - $hours * 3600)]) as $o)
            $n += self::markFailed((int)$o['id'], 'expired') ? 1 : 0;
        return $n;
    }

    /** คอร์สฟรี (ราคา 0) — ลงทะเบียนเลยไม่ต้องมีออเดอร์ */
    public static function enrollFree($userId, $courseId){
        db_write("INSERT INTO enrollments (user_id, course_id, source, status, created_at) VALUES (?, ?, 'free', 'active', ?)
                  ON DUPLICATE KEY UPDATE status = 'active'", [(int)$userId, (int)$courseId, date('Y-m-d H:i:s')]);
    }

    /** ส่วนที่ผู้สอนต้องถูกหักคืนเมื่อคืนเงิน $amount (สัดส่วนกับยอดจ่าย — ครั้งสุดท้ายรับเศษที่เหลือ) */
    public static function reversalFor(array $item, $amount){
        $paid = RevenueShareService::toSatang($item['paid_amount']);
        $done = RevenueShareService::toSatang($item['refunded_amount'] ?? 0);
        $amt  = RevenueShareService::toSatang($amount);
        $inst = RevenueShareService::toSatang($item['instructor_amount']);
        $instDone = RevenueShareService::toSatang($item['instructor_reversed'] ?? 0);
        if($done + $amt >= $paid) return RevenueShareService::fromSatang($inst - $instDone);   // คืนครบ → หักที่เหลือทั้งหมด
        $x = $inst * $amt;
        $r = intdiv(abs($x) * 2 + $paid, 2 * $paid) * ($x < 0 ? -1 : 1);
        return RevenueShareService::fromSatang($r);
    }

    /**
     * คืนเงินรายการเดียว (ทั้งหมดหรือบางส่วน)
     * @param string|null $amount     null = คืนส่วนที่เหลือทั้งหมด
     * @param bool        $viaGateway true = สั่งคืนเงินที่ Omise/Stripe ด้วย (ไม่งั้นแค่บันทึก — คืนเงินเองนอกระบบ)
     */
    public static function refundItem($itemId, $reason, $amount = null, $viaGateway = false){
        // ล็อกต่อรายการตลอดการคืนเงิน (กันกดซ้ำแล้ว gateway คืนเงินสองครั้ง)
        $lock = 'aleanor_cloud_refund_'.(int)$itemId;
        if((int)db_val("SELECT GET_LOCK(?, 5)", [$lock]) !== 1) throw new RuntimeException('มีการคืนเงินรายการนี้อยู่ กรุณารอสักครู่');
        try { return self::refundItemLocked($itemId, $reason, $amount, $viaGateway); }
        finally { db_val("SELECT RELEASE_LOCK(?)", [$lock]); }
    }
    private static function refundItemLocked($itemId, $reason, $amount, $viaGateway){
        $it0 = db_one("SELECT oi.*, o.gateway_ref, o.status AS order_status FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id = ?", [(int)$itemId]);
        if(!$it0) throw new RuntimeException('ไม่พบรายการ');
        if(!in_array($it0['order_status'], ['paid', 'partially_refunded'], true)) throw new RuntimeException('คำสั่งซื้อนี้คืนเงินไม่ได้');
        if($it0['refunded_at']) throw new RuntimeException('รายการนี้คืนเงินครบแล้ว');
        $remaining = RevenueShareService::toSatang($it0['paid_amount']) - RevenueShareService::toSatang($it0['refunded_amount']);
        $amt = $amount === null || $amount === '' ? $remaining : RevenueShareService::toSatang($amount);
        if($amt < 0 || $amt > $remaining || ($amt === 0 && $remaining > 0)) throw new RuntimeException('จำนวนเงินคืนไม่ถูกต้อง (คืนได้ไม่เกิน '.RevenueShareService::fromSatang($remaining).')');
        $amountStr = RevenueShareService::fromSatang($amt);

        // สั่ง gateway ก่อน — ถ้า gateway ไม่ผ่านจะไม่บันทึกอะไรเลย
        $gwRef = null;
        if($viaGateway && $amt > 0){
            $g = gwRefund((string)$it0['gateway_ref'], $amountStr);
            if(empty($g['ok'])) throw new RuntimeException('gateway: '.($g['error'] ?? 'คืนเงินไม่สำเร็จ'));
            $gwRef = $g['ref'];
        }
        try {
            return db_tx(function() use($itemId, $reason, $amt, $amountStr, $viaGateway, $gwRef){
                $it = db_one("SELECT * FROM order_items WHERE id = ? FOR UPDATE", [(int)$itemId]);
                $o = db_one("SELECT * FROM orders WHERE id = ? FOR UPDATE", [(int)$it['order_id']]);
                $remaining = RevenueShareService::toSatang($it['paid_amount']) - RevenueShareService::toSatang($it['refunded_amount']);
                if($it['refunded_at'] || $amt > $remaining) throw new RuntimeException('รายการถูกคืนเงินไปแล้วระหว่างนี้');
                $reversal = self::reversalFor($it, $amountStr);
                $now = date('Y-m-d H:i:s');
                $refundId = db_insert("INSERT INTO refunds (order_id, order_item_id, amount, instructor_reversal, reason, gateway_refund_ref, via_gateway, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)",
                    [(int)$o['id'], (int)$it['id'], $amountStr, $reversal, mb_substr($reason, 0, 500), $gwRef, $viaGateway ? 1 : 0, current_user_id() ?: null, $now]);
                if((float)$reversal != 0.0) LedgerService::recordRefund($refundId, $it, $reversal);
                $full = $amt === $remaining;
                db_write("UPDATE order_items SET refunded_amount = refunded_amount + ?, instructor_reversed = instructor_reversed + ?, refunded_at = ? WHERE id = ?",
                    [$amountStr, $reversal, $full ? $now : null, (int)$it['id']]);
                if($full) db_write("UPDATE enrollments SET status = 'revoked' WHERE user_id = ? AND course_id = ? AND order_item_id = ?",
                    [(int)$o['user_id'], (int)$it['course_id'], (int)$it['id']]);
                $left = (int)db_val("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND refunded_at IS NULL", [(int)$o['id']]);
                db_write("UPDATE orders SET status = ?, updated_at = ? WHERE id = ?", [$left ? 'partially_refunded' : 'refunded', $now, (int)$o['id']]);
                audit('refund', 'order_item', (int)$it['id'], null, ['refund_id' => $refundId, 'amount' => $amountStr, 'full' => $full,
                      'instructor_reversal' => $reversal, 'via_gateway' => $viaGateway, 'gateway_ref' => $gwRef, 'reason' => $reason]);
                if(class_exists('MailService')) MailService::refunded($refundId);
                return $refundId;
            });
        } catch(Throwable $e){
            // gateway คืนเงินไปแล้วแต่บันทึกไม่ได้ → ต้องให้คนตาม — เขียน log ชัด ๆ
            if($gwRef) error_log('[aleanor_cloud] CRITICAL refund recorded at gateway '.$gwRef.' but DB failed: '.$e->getMessage());
            throw $e;
        }
    }
}
