<?php
// ============================================================
// บัญชีรายได้ผู้สอน (instructor_ledger) — append-only ห้ามแก้ยอดย้อนหลัง
//   sale       +instructor_amount  status=held จนถึง available_at (= วันจ่าย + hold_days)
//   refund     -ส่วนที่หักคืน       ถ้ายอดขายยังพักอยู่ → พักพร้อมกัน (ปล่อยวันเดียวกัน หักล้างเป็นศูนย์)
//                                  ถ้าปล่อยแล้ว → หักจากยอดถอนได้ทันที (ติดลบได้ หักจากยอดขายถัดไป)
//   payout     -ยอดที่โอน (phase 3)
//   adjustment ± ปรับยอดโดยแอดมิน
// ============================================================

class LedgerService
{
    public static function holdDays(){
        return max(0, (int)(function_exists('setting') ? setting('earnings_hold_days', '14') : 14));
    }

    public static function recordSale(array $item, $paidAt){
        $amt = (string)$item['instructor_amount'];
        $availableAt = date('Y-m-d H:i:s', strtotime($paidAt.' +'.self::holdDays().' days'));
        $status = self::holdDays() === 0 ? 'available' : 'held';
        return db_insert("INSERT INTO instructor_ledger (instructor_id, type, amount, status, available_at, order_item_id, note, created_at)
                          VALUES (?, 'sale', ?, ?, ?, ?, ?, ?)",
            [(int)$item['instructor_id'], $amt, $status, $availableAt, (int)$item['id'], 'ขายคอร์ส #'.(int)$item['course_id'], date('Y-m-d H:i:s')]);
    }

    public static function recordRefund($refundId, array $item, $reversal){
        $sale = db_one("SELECT * FROM instructor_ledger WHERE type = 'sale' AND order_item_id = ? FOR UPDATE", [(int)$item['id']]);
        $now = date('Y-m-d H:i:s');
        if($sale && $sale['status'] === 'held'){ $status = 'held'; $availableAt = $sale['available_at']; }
        else { $status = 'available'; $availableAt = $now; }
        return db_insert("INSERT INTO instructor_ledger (instructor_id, type, amount, status, available_at, order_item_id, refund_id, note, created_by, created_at)
                          VALUES (?, 'refund', ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int)$item['instructor_id'], '-'.number_format((float)$reversal, 2, '.', ''), $status, $availableAt,
             (int)$item['id'], (int)$refundId, 'คืนเงินรายการ #'.(int)$item['id'], current_user_id() ?: null, $now]);
    }

    public static function adjust($instructorId, $amount, $note){
        $id = db_insert("INSERT INTO instructor_ledger (instructor_id, type, amount, status, available_at, note, created_by, created_at)
                         VALUES (?, 'adjustment', ?, 'available', ?, ?, ?, ?)",
            [(int)$instructorId, number_format((float)$amount, 2, '.', ''), date('Y-m-d H:i:s'), mb_substr($note, 0, 255), current_user_id() ?: null, date('Y-m-d H:i:s')]);
        audit('ledger_adjustment', 'instructor', (int)$instructorId, null, ['ledger_id' => $id, 'amount' => $amount, 'note' => $note]);
        return $id;
    }

    /** ปล่อยเงินที่พ้นช่วงพัก (เรียกจาก cron) — คืนจำนวนแถว */
    public static function releaseDue($at = null){
        return db_write("UPDATE instructor_ledger SET status = 'available' WHERE status = 'held' AND available_at <= ?",
            [$at ?: date('Y-m-d H:i:s')]);
    }

    /** ['held','available','lifetime_sales','refunds'] */
    public static function balances($instructorId){
        $r = db_one("SELECT
                COALESCE(SUM(CASE WHEN status = 'held' THEN amount END), 0)      AS held,
                COALESCE(SUM(CASE WHEN status = 'available' THEN amount END), 0) AS available,
                COALESCE(SUM(CASE WHEN type = 'sale' THEN amount END), 0)        AS lifetime_sales,
                COALESCE(SUM(CASE WHEN type = 'refund' THEN amount END), 0)      AS refunds
              FROM instructor_ledger WHERE instructor_id = ?", [(int)$instructorId]);
        return $r;
    }
}
