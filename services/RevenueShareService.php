<?php
// ============================================================
// ส่วนแบ่งรายได้ — หาอัตรา (กฎ 3 ระดับ) + คำนวณยอดต่อรายการขาย
//
// อัตรา (platform_rate) หาแบบ "เจาะจงที่สุดชนะ":  course > instructor > global
//   กฎที่ใช้ได้ ณ เวลา T: is_active=1 และ starts_at<=T (หรือว่าง) และ ends_at>T (หรือว่าง)
//   ระดับเดียวกันมีหลายกฎ → เอา starts_at ล่าสุด แล้วค่อย id มากสุด
//   ไม่มีกฎเลย → ใช้ setting default_platform_rate (ค่าเริ่ม 30)
//
// การแบ่ง (คิดเป็นสตางค์ทั้งหมด กันเศษ float):
//   net        = paid - gateway_fee
//   platform   = round(net × rate)
//   instructor = net - platform            (ผลรวมตรงกับ net เสมอ)
//   คูปองผู้สอน   → ลดราคาก่อนแบ่ง (ผู้สอนแบกส่วนลดตามสัดส่วน)
//   คูปองแพลตฟอร์ม → ผู้สอนได้เท่ากับขายราคาเต็ม (list - ค่าธรรมเนียมของราคาเต็ม) × (1-rate)
//                   แพลตฟอร์มรับส่วนที่เหลือ (อาจติดลบ = แพลตฟอร์มออกส่วนลดเอง)
// ============================================================

class RevenueShareService
{
    const DEFAULT_RATE = 30.0;
    const SCOPE_RANK = ['course' => 3, 'instructor' => 2, 'global' => 1];

    // ── เงิน ↔ สตางค์ ──
    public static function toSatang($v){ return (int)round(((float)$v) * 100); }
    public static function fromSatang($s){ return number_format($s / 100, 2, '.', ''); }

    /** ปัดครึ่งขึ้น (ห่างจากศูนย์) ของ a×bp/10000 — bp = อัตรา % × 100 */
    private static function mulBp($satang, $bp){
        $x = $satang * $bp;
        $q = intdiv(abs($x) + 5000, 10000);
        return $x < 0 ? -$q : $q;
    }
    private static function rateBp($ratePct){ return (int)round(((float)$ratePct) * 100); }

    // ── หากฎ (pure — ทดสอบได้โดยไม่ต้องมีฐานข้อมูล) ──
    /**
     * @param array  $rules  แถวจาก revenue_share_rules (array assoc)
     * @param string $at     'Y-m-d H:i:s'
     * @return array|null    กฎที่ชนะ
     */
    public static function resolveRule(array $rules, $courseId, $instructorId, $at){
        $best = null;
        foreach($rules as $r){
            if(isset($r['is_active']) && (int)$r['is_active'] !== 1) continue;
            if(!empty($r['starts_at']) && $r['starts_at'] > $at) continue;
            if(!empty($r['ends_at'])   && $r['ends_at'] <= $at) continue;
            if($r['scope'] === 'course'     && (int)$r['course_id']     !== (int)$courseId) continue;
            if($r['scope'] === 'instructor' && (int)$r['instructor_id'] !== (int)$instructorId) continue;
            if(!isset(self::SCOPE_RANK[$r['scope']])) continue;
            if($best === null || self::beats($r, $best)) $best = $r;
        }
        return $best;
    }
    private static function beats($a, $b){
        $ra = self::SCOPE_RANK[$a['scope']]; $rb = self::SCOPE_RANK[$b['scope']];
        if($ra !== $rb) return $ra > $rb;
        $sa = (string)($a['starts_at'] ?? ''); $sb = (string)($b['starts_at'] ?? '');
        if($sa !== $sb) return $sa > $sb;
        return (int)$a['id'] > (int)$b['id'];
    }

    /** กฎที่อาจเกี่ยวกับคอร์สนี้ (ให้ resolveRule เลือกต่อ) */
    public static function candidates($courseId, $instructorId){
        return db_all("SELECT * FROM revenue_share_rules
                       WHERE is_active = 1 AND (scope = 'global'
                          OR (scope = 'instructor' AND instructor_id = ?)
                          OR (scope = 'course' AND course_id = ?))", [(int)$instructorId, (int)$courseId]);
    }

    /** อัตราที่ใช้ ณ เวลา $at → ['rate' => float, 'rule_id' => int|null, 'scope' => string] */
    public static function rateFor($courseId, $instructorId, $at = null){
        $at = $at ?: date('Y-m-d H:i:s');
        $r = self::resolveRule(self::candidates($courseId, $instructorId), $courseId, $instructorId, $at);
        if($r) return ['rate' => (float)$r['platform_rate'], 'rule_id' => (int)$r['id'], 'scope' => $r['scope']];
        $d = function_exists('setting') ? (float)setting('default_platform_rate', self::DEFAULT_RATE) : self::DEFAULT_RATE;
        return ['rate' => $d, 'rule_id' => null, 'scope' => 'default'];
    }

    // ── คำนวณ (pure) ──
    /** ค่าธรรมเนียม gateway โดยประมาณจากอัตรา % */
    public static function estimateFee($amount, $feeRatePct){
        return self::fromSatang(self::mulBp(self::toSatang($amount), self::rateBp($feeRatePct)));
    }

    /** กระจายค่าธรรมเนียมทั้งใบไปตามสัดส่วนยอดจ่ายของแต่ละรายการ (เศษไปลงรายการสุดท้าย) */
    public static function allocateFee($totalFee, array $paidAmounts){
        $fee = self::toSatang($totalFee);
        $sum = 0; foreach($paidAmounts as $p) $sum += self::toSatang($p);
        $out = []; $used = 0; $keys = array_keys($paidAmounts); $last = end($keys);
        foreach($paidAmounts as $k => $p){
            if($k === $last){ $out[$k] = self::fromSatang($fee - $used); break; }
            $part = $sum > 0 ? intdiv(self::toSatang($p) * $fee * 2 + $sum, 2 * $sum) : 0;
            $out[$k] = self::fromSatang($part);
            $used += $part;
        }
        return $out;
    }

    /**
     * แบ่งรายได้ของ 1 รายการขาย
     * @param string $couponOwner none | instructor | platform
     * @return array ['net','platform','instructor'] เป็นสตริงทศนิยม 2 ตำแหน่ง
     */
    public static function split($listPrice, $paidAmount, $gatewayFee, $ratePct, $couponOwner = 'none', $feeRatePct = 0.0){
        $paid = self::toSatang($paidAmount);
        $fee  = self::toSatang($gatewayFee);
        $bp   = self::rateBp($ratePct);
        if($bp < 0 || $bp > 10000) throw new InvalidArgumentException('platform rate ต้องอยู่ระหว่าง 0-100');
        $net  = $paid - $fee;

        $list = self::toSatang($listPrice);
        if($couponOwner === 'platform' && $list > $paid){
            // ผู้สอนได้เท่ากับขายราคาเต็ม
            $hypoNet    = $list - self::mulBp($list, self::rateBp($feeRatePct));
            $instructor = $hypoNet - self::mulBp($hypoNet, $bp);
            $platform   = $net - $instructor;
        }else{
            $platform   = self::mulBp($net, $bp);
            $instructor = $net - $platform;
        }
        return [
            'net'        => self::fromSatang($net),
            'platform'   => self::fromSatang($platform),
            'instructor' => self::fromSatang($instructor),
        ];
    }

    // ── จัดการกฎ (ทุกการเปลี่ยนแปลงลง audit_logs) ──
    /**
     * ตั้งอัตราใหม่ให้ระดับ/เป้าหมายหนึ่ง — ไม่แก้กฎเดิม แต่ "ปิด" กฎที่เปิดค้างอยู่ ณ เวลาเริ่มของกฎใหม่
     * แล้วสร้างกฎใหม่ จึงย้อนดูได้ว่าช่วงไหนใช้อัตราอะไร
     */
    public static function setRate($scope, $targetId, $ratePct, $startsAt = null, $endsAt = null, $note = ''){
        if(!isset(self::SCOPE_RANK[$scope])) throw new InvalidArgumentException('scope ไม่ถูกต้อง');
        $rate = round((float)$ratePct, 2);
        if($rate < 0 || $rate > 100) throw new InvalidArgumentException('อัตราต้องอยู่ระหว่าง 0-100%');
        $startsAt = $startsAt ?: date('Y-m-d H:i:s');
        if($endsAt !== null && $endsAt !== '' && $endsAt <= $startsAt) throw new InvalidArgumentException('วันสิ้นสุดต้องหลังวันเริ่ม');
        $instructorId = $scope === 'instructor' ? (int)$targetId : null;
        $courseId     = $scope === 'course'     ? (int)$targetId : null;

        return db_tx(function() use($scope, $instructorId, $courseId, $rate, $startsAt, $endsAt, $note){
            // ปิดกฎเปิดค้าง (ไม่มีวันสิ้นสุด) ของเป้าหมายเดียวกันที่เริ่มก่อนกฎใหม่
            if($endsAt === null || $endsAt === ''){
                $open = db_all("SELECT * FROM revenue_share_rules WHERE scope = ? AND is_active = 1
                                  AND ".($scope === 'course' ? "course_id = ".(int)$courseId : ($scope === 'instructor' ? "instructor_id = ".(int)$instructorId : "1=1"))."
                                  AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?) FOR UPDATE",
                               [$scope, $startsAt, $startsAt]);
                foreach($open as $o){
                    db_write("UPDATE revenue_share_rules SET ends_at = ? WHERE id = ?", [$startsAt, (int)$o['id']]);
                    audit('rate_rule_closed', 'revenue_share_rule', (int)$o['id'], ['ends_at' => $o['ends_at']], ['ends_at' => $startsAt]);
                }
            }
            $id = db_insert("INSERT INTO revenue_share_rules (scope, instructor_id, course_id, platform_rate, starts_at, ends_at, is_active, note, created_by, created_at)
                             VALUES (?,?,?,?,?,?,1,?,?,?)",
                [$scope, $instructorId, $courseId, number_format($rate, 2, '.', ''), $startsAt, ($endsAt ?: null), mb_substr((string)$note, 0, 255),
                 current_user_id() ?: null, date('Y-m-d H:i:s')]);
            audit('rate_rule_created', 'revenue_share_rule', $id, null, [
                'scope' => $scope, 'instructor_id' => $instructorId, 'course_id' => $courseId,
                'platform_rate' => $rate, 'starts_at' => $startsAt, 'ends_at' => $endsAt ?: null, 'note' => $note]);
            return $id;
        });
    }

    /** ยกเลิกการใช้กฎ (กลับไปใช้ระดับที่กว้างกว่า) — กฎที่เริ่มแล้วจะถูกปิดด้วย ends_at=ตอนนี้, กฎล่วงหน้าถูกยกเลิก */
    public static function endRule($ruleId){
        return db_tx(function() use($ruleId){
            $r = db_one("SELECT * FROM revenue_share_rules WHERE id = ? FOR UPDATE", [(int)$ruleId]);
            if(!$r || (int)$r['is_active'] !== 1) return false;
            $now = date('Y-m-d H:i:s');
            if(!empty($r['starts_at']) && $r['starts_at'] > $now){
                db_write("UPDATE revenue_share_rules SET is_active = 0, deactivated_by = ?, deactivated_at = ? WHERE id = ?",
                    [current_user_id() ?: null, $now, (int)$ruleId]);
                audit('rate_rule_cancelled', 'revenue_share_rule', (int)$ruleId, ['is_active' => 1], ['is_active' => 0]);
            }else{
                if($r['scope'] === 'global') throw new InvalidArgumentException('ปิดกฎระดับ global ไม่ได้ — ให้ตั้งอัตราใหม่แทน');
                db_write("UPDATE revenue_share_rules SET ends_at = ?, deactivated_by = ?, deactivated_at = ? WHERE id = ?",
                    [$now, current_user_id() ?: null, $now, (int)$ruleId]);
                audit('rate_rule_ended', 'revenue_share_rule', (int)$ruleId, ['ends_at' => $r['ends_at']], ['ends_at' => $now]);
            }
            return true;
        });
    }

    /** กฎที่ใช้อยู่ตอนนี้ของเป้าหมาย (ไว้แสดงในหน้าแอดมิน) */
    public static function currentRuleOf($scope, $targetId = null){
        $now = date('Y-m-d H:i:s');
        $col = $scope === 'course' ? 'course_id' : ($scope === 'instructor' ? 'instructor_id' : null);
        $rows = db_all("SELECT * FROM revenue_share_rules WHERE scope = ? AND is_active = 1".($col ? " AND $col = ".(int)$targetId : ''), [$scope]);
        $best = null;
        foreach($rows as $r){
            if(!empty($r['starts_at']) && $r['starts_at'] > $now) continue;
            if(!empty($r['ends_at']) && $r['ends_at'] <= $now) continue;
            if($best === null || self::beats($r, $best)) $best = $r;
        }
        return $best;
    }
}
