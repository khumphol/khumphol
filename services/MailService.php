<?php
// ============================================================
// อีเมลแจ้งเตือน — เข้าคิว (mail_queue) เสมอ แม้ปิดอีเมลอยู่ (เปิดภายหลังแล้ว cron จะส่งที่ค้าง)
// เทมเพลต: order_paid, sale (ผู้สอน), refunded, instructor_status, course_status, payout_paid
// ============================================================

class MailService
{
    public static function wrap($title, $bodyHtml){
        $brand = h(setting('mail_from_name', '') ?: setting('site_name', 'Aleanor Cloud'));
        return '<div style="font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;padding:24px"><div style="max-width:540px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">'
            .'<div style="background:#6366f1;color:#fff;padding:18px 24px;font-size:18px;font-weight:700">'.$brand.'</div>'
            .'<div style="padding:24px;color:#1e293b;line-height:1.7;font-size:15px"><h2 style="margin:0 0 12px;font-size:18px">'.h($title).'</h2>'.$bodyHtml.'</div>'
            .'<div style="padding:14px 24px;background:#f8fafc;color:#94a3b8;font-size:12px;text-align:center">&copy; '.date('Y').' '.$brand.'</div></div></div>';
    }

    public static function queue($to, $subject, $bodyHtml, $template = ''){
        if(!filter_var($to, FILTER_VALIDATE_EMAIL)) return 0;
        return db_insert("INSERT INTO mail_queue (to_email, subject, body_html, template, created_at) VALUES (?,?,?,?,?)",
            [$to, mb_substr($subject, 0, 255), self::wrap($subject, $bodyHtml), $template, date('Y-m-d H:i:s')]);
    }

    /** ส่งคิวที่ค้าง (cron) — คืน ['sent','failed'] */
    public static function flush($limit = 50){
        $out = ['sent' => 0, 'failed' => 0];
        if(!mailEnabled()) return $out;
        $cfg = mailConfig();
        foreach(db_all("SELECT * FROM mail_queue WHERE status = 'queued' ORDER BY id LIMIT ".(int)$limit) as $m){
            $r = mailSmtp($cfg, $m['to_email'], $m['subject'], $m['body_html']);
            if(!empty($r['ok'])){ db_write("UPDATE mail_queue SET status = 'sent', sent_at = ?, attempts = attempts + 1 WHERE id = ?", [date('Y-m-d H:i:s'), (int)$m['id']]); $out['sent']++; }
            else {
                db_write("UPDATE mail_queue SET attempts = attempts + 1, last_error = ?, status = IF(attempts + 1 >= 5, 'failed', 'queued') WHERE id = ?",
                    [mb_substr((string)$r['error'], 0, 255), (int)$m['id']]);
                $out['failed']++;
            }
        }
        return $out;
    }

    private static function link($path){ return '<p><a href="'.h(site_url().$path).'" style="display:inline-block;background:#6366f1;color:#fff;padding:10px 18px;border-radius:9px;text-decoration:none">เปิดดู</a></p>'; }

    public static function orderPaid($orderId){
        $o = db_one("SELECT o.*, u.email, u.name FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?", [(int)$orderId]);
        if(!$o) return;
        $items = db_all("SELECT oi.*, c.title, u.email AS t_email, COALESCE(ip.display_name, u.name) AS t_name FROM order_items oi JOIN courses c ON c.id = oi.course_id
                         JOIN users u ON u.id = oi.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = oi.instructor_id WHERE oi.order_id = ?", [(int)$orderId]);
        $rows = '';
        foreach($items as $it) $rows .= '<li>'.h($it['title']).' — ฿'.money($it['paid_amount']).'</li>';
        self::queue($o['email'], 'ชำระเงินสำเร็จ — ใบเสร็จ '.$o['receipt_no'],
            '<p>สวัสดีคุณ '.h($o['name']).'</p><p>ขอบคุณสำหรับการสั่งซื้อ เปิดเรียนได้ทันที</p><ul>'.$rows.'</ul><p>ยอดชำระ <b>฿'.money($o['total']).'</b></p>'
            .self::link('index.php?p=receipt&order='.rawurlencode($o['order_no'])), 'order_paid');
        foreach($items as $it){
            self::queue($it['t_email'], 'มียอดขายใหม่: '.$it['title'],
                '<p>คอร์ส <b>'.h($it['title']).'</b> ขายได้ ฿'.money($it['paid_amount']).'</p><p>ส่วนของคุณ <b>฿'.money($it['instructor_amount']).'</b> (พักเงิน '.LedgerService::holdDays().' วันก่อนถอนได้)</p>'
                .self::link('instructor/index.php?p=earnings'), 'sale');
        }
    }

    public static function refunded($refundId){
        $r = db_one("SELECT rf.*, u.email, u.name, c.title FROM refunds rf JOIN orders o ON o.id = rf.order_id JOIN users u ON u.id = o.user_id
                     JOIN order_items oi ON oi.id = rf.order_item_id JOIN courses c ON c.id = oi.course_id WHERE rf.id = ?", [(int)$refundId]);
        if($r) self::queue($r['email'], 'คืนเงินแล้ว: '.$r['title'], '<p>เราได้คืนเงิน ฿'.money($r['amount']).' สำหรับคอร์ส '.h($r['title']).'</p>', 'refunded');
    }

    public static function instructorStatus($userId, $status, $note = ''){
        $u = db_one("SELECT email, name FROM users WHERE id = ?", [(int)$userId]);
        if(!$u) return;
        $map = ['approved' => 'ใบสมัครผู้สอนได้รับการอนุมัติแล้ว 🎉', 'rejected' => 'ผลการพิจารณาใบสมัครผู้สอน', 'suspended' => 'บัญชีผู้สอนถูกระงับชั่วคราว'];
        if(!isset($map[$status])) return;
        self::queue($u['email'], $map[$status], '<p>สวัสดีคุณ '.h($u['name']).'</p>'.($note !== '' ? '<p>หมายเหตุ: '.h($note).'</p>' : '')
            .self::link($status === 'approved' ? 'instructor/' : 'index.php?p=become-instructor'), 'instructor_status');
    }

    public static function courseStatus($courseId, $status, $note = ''){
        $c = db_one("SELECT c.title, u.email FROM courses c JOIN users u ON u.id = c.instructor_id WHERE c.id = ?", [(int)$courseId]);
        if(!$c) return;
        $map = ['published' => 'คอร์สของคุณเผยแพร่แล้ว', 'rejected' => 'คอร์สของคุณยังไม่ผ่านการตรวจ', 'unpublished' => 'คอร์สของคุณถูกปิดการขาย'];
        if(!isset($map[$status])) return;
        self::queue($c['email'], $map[$status].': '.$c['title'], ($note !== '' ? '<p>หมายเหตุ: '.h($note).'</p>' : '')
            .self::link('instructor/index.php?p=course-edit&id='.(int)$courseId), 'course_status');
    }

    public static function payoutPaid($payoutId){
        $p = db_one("SELECT p.*, u.email, u.name FROM payouts p JOIN users u ON u.id = p.instructor_id WHERE p.id = ?", [(int)$payoutId]);
        if($p) self::queue($p['email'], 'โอนรายได้รอบ '.$p['period'].' แล้ว',
            '<p>ยอดรวม ฿'.money($p['gross']).' หัก ณ ที่จ่าย ฿'.money($p['withholding_tax']).'</p><p>โอนสุทธิ <b>฿'.money($p['net_amount']).'</b>'
            .($p['transfer_ref'] !== '' ? ' (อ้างอิง '.h($p['transfer_ref']).')' : '').'</p>'.self::link('instructor/index.php?p=earnings'), 'payout_paid');
    }
}
