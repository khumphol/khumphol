<?php
// ============================================================
// รอบจ่ายเงินผู้สอนรายเดือน
//   สร้างรอบ (period YYYY-MM): ผู้สอนแต่ละคน รวมแถว ledger ที่ "ถอนได้" (available, ถึงวันตัดรอบ)
//   ที่ยังไม่เคยอยู่ในรอบใด (payout_items) — ทั้ง sale/refund/adjustment (ยอดติดลบจึงหักอัตโนมัติ)
//   ยอดรวม >= payout_min_amount → สร้าง payout + payout_items + ledger 'payout' (-gross) ทันที (ยอดถอนได้ลดลง)
//   หัก ณ ที่จ่าย = round(gross × withholding_tax_rate) → net = gross - withholding
//   ยกเลิกรอบ (ยังไม่จ่าย): ledger 'payout' (+gross) คืนยอด, ปลด payout_items ให้เข้ารอบใหม่ได้
// ============================================================

class PayoutService
{
    public static function minAmount(){ return (float)setting('payout_min_amount', '500'); }
    public static function withholdingRate(){ return max(0, min(100, (float)setting('withholding_tax_rate', '3'))); }

    /** วันตัดรอบ = สิ้นเดือนของ period */
    public static function cutoff($period){
        if(!preg_match('~^\d{4}-(0[1-9]|1[0-2])$~', $period)) throw new InvalidArgumentException('รูปแบบรอบต้องเป็น YYYY-MM');
        return date('Y-m-t 23:59:59', strtotime($period.'-01'));
    }

    /** หัก ณ ที่จ่าย (pure) → ['withholding','net'] */
    public static function withholding($gross, $ratePct){
        $g = RevenueShareService::toSatang($gross);
        $bp = (int)round((float)$ratePct * 100);
        $w = $g > 0 ? intdiv($g * $bp + 5000, 10000) : 0;
        return ['withholding' => RevenueShareService::fromSatang($w), 'net' => RevenueShareService::fromSatang($g - $w)];
    }

    /** ตัวอย่างก่อนสร้างรอบ: [instructor_id => ['name','gross','rows','bank','eligible']] */
    public static function preview($period){
        $cut = self::cutoff($period);
        $rows = db_all("SELECT l.instructor_id, SUM(l.amount) gross, COUNT(*) n, COALESCE(ip.display_name, u.name) name,
                          CONCAT_WS(' ', ip.bank_name, ip.bank_account_no, ip.bank_account_name) bank
                        FROM instructor_ledger l JOIN users u ON u.id = l.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = l.instructor_id
                        WHERE l.status = 'available' AND l.type <> 'payout' AND l.available_at <= ?
                          AND NOT EXISTS (SELECT 1 FROM payout_items pi WHERE pi.ledger_id = l.id)
                          AND NOT EXISTS (SELECT 1 FROM payouts p WHERE p.instructor_id = l.instructor_id AND p.period = ? AND p.status <> 'cancelled')
                        GROUP BY l.instructor_id ORDER BY gross DESC", [$cut, $period]);
        $out = [];
        foreach($rows as $r){
            $r['eligible'] = (float)$r['gross'] >= self::minAmount() && (float)$r['gross'] > 0;
            $out[(int)$r['instructor_id']] = $r;
        }
        return $out;
    }

    /** สร้างรอบจ่าย — คืนรายการ payout id ที่สร้าง */
    public static function createRun($period){
        $cut = self::cutoff($period);
        $rate = self::withholdingRate();
        return db_tx(function() use($period, $cut, $rate){
            $created = [];
            foreach(self::preview($period) as $iid => $p){
                if(!$p['eligible']) continue;
                $ledger = db_all("SELECT id, amount FROM instructor_ledger l WHERE l.instructor_id = ? AND l.status = 'available' AND l.type <> 'payout'
                                    AND l.available_at <= ? AND NOT EXISTS (SELECT 1 FROM payout_items pi WHERE pi.ledger_id = l.id) FOR UPDATE", [$iid, $cut]);
                $g = 0; foreach($ledger as $l) $g += RevenueShareService::toSatang($l['amount']);
                if($g < RevenueShareService::toSatang(self::minAmount()) || $g <= 0) continue;
                $gross = RevenueShareService::fromSatang($g);
                $w = self::withholding($gross, $rate);
                $pid = db_insert("INSERT INTO payouts (instructor_id, period, gross, withholding_rate, withholding_tax, net_amount, bank_snapshot, status, created_at)
                                  VALUES (?,?,?,?,?,?,?, 'pending', ?)",
                    [$iid, $period, $gross, number_format($rate, 2, '.', ''), $w['withholding'], $w['net'], mb_substr(trim($p['bank']), 0, 400), date('Y-m-d H:i:s')]);
                foreach($ledger as $l) db_insert("INSERT INTO payout_items (payout_id, ledger_id) VALUES (?,?)", [$pid, (int)$l['id']]);
                db_insert("INSERT INTO instructor_ledger (instructor_id, type, amount, status, available_at, payout_id, note, created_by, created_at)
                           VALUES (?, 'payout', ?, 'available', ?, ?, ?, ?, ?)",
                    [$iid, '-'.$gross, date('Y-m-d H:i:s'), $pid, 'รอบจ่าย '.$period, current_user_id() ?: null, date('Y-m-d H:i:s')]);
                $created[] = $pid;
            }
            audit('payout_run_created', 'payout', null, null, ['period' => $period, 'payouts' => $created]);
            return $created;
        });
    }

    public static function markPaid($payoutId, $transferRef = '', $slip = ''){
        $n = db_write("UPDATE payouts SET status = 'paid', paid_at = ?, paid_by = ?, transfer_ref = ?, slip = IF(? = '', slip, ?) WHERE id = ? AND status = 'pending'",
            [date('Y-m-d H:i:s'), current_user_id() ?: null, mb_substr($transferRef, 0, 100), $slip, $slip, (int)$payoutId]);
        if($n){
            audit('payout_paid', 'payout', (int)$payoutId, ['status' => 'pending'], ['status' => 'paid', 'transfer_ref' => $transferRef, 'slip' => $slip]);
            if(class_exists('MailService')) MailService::payoutPaid($payoutId);
        }
        return $n > 0;
    }

    public static function cancel($payoutId, $note = ''){
        return db_tx(function() use($payoutId, $note){
            $p = db_one("SELECT * FROM payouts WHERE id = ? FOR UPDATE", [(int)$payoutId]);
            if(!$p || $p['status'] !== 'pending') return false;
            db_write("DELETE FROM payout_items WHERE payout_id = ?", [(int)$payoutId]);
            db_insert("INSERT INTO instructor_ledger (instructor_id, type, amount, status, available_at, payout_id, note, created_by, created_at)
                       VALUES (?, 'payout', ?, 'available', ?, ?, ?, ?, ?)",
                [(int)$p['instructor_id'], $p['gross'], date('Y-m-d H:i:s'), (int)$payoutId, 'ยกเลิกรอบจ่าย '.$p['period'], current_user_id() ?: null, date('Y-m-d H:i:s')]);
            db_write("UPDATE payouts SET status = 'cancelled', cancelled_at = ?, note = ? WHERE id = ?", [date('Y-m-d H:i:s'), mb_substr($note, 0, 255), (int)$payoutId]);
            audit('payout_cancelled', 'payout', (int)$payoutId, ['status' => 'pending'], ['status' => 'cancelled', 'note' => $note]);
            return true;
        });
    }

    /** CSV สำหรับโอนเงินผ่านธนาคาร (รอบที่ยังไม่จ่าย) */
    public static function csvRows($period){
        return db_all("SELECT p.id, COALESCE(ip.display_name, u.name) name, ip.bank_name, ip.bank_account_no, ip.bank_account_name, ip.tax_id,
                         p.gross, p.withholding_tax, p.net_amount, p.status
                       FROM payouts p JOIN users u ON u.id = p.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = p.instructor_id
                       WHERE p.period = ? AND p.status <> 'cancelled' ORDER BY p.id", [$period]);
    }
}
