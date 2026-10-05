<?php
// กลับจาก gateway — ถามสถานะจริงจากผู้ให้บริการเสมอ (ไม่เชื่อพารามิเตอร์ใน URL)
$TITLE = 'ผลการชำระเงิน';
require_login();
$o = db_one("SELECT * FROM orders WHERE order_no = ? AND user_id = ?", [get('order'), current_user_id()]);
if(!$o){ echo '<div class="card">ไม่พบคำสั่งซื้อ</div>'; return; }
// กดยกเลิก (?cancel=1) ไม่ทำให้ออเดอร์ล้มเหลวทันที — หน้าจ่ายของ Stripe ยังจ่ายต่อได้ ปล่อยให้หมดอายุเอง (cron expire)
if(in_array($o['status'], ['pending', 'failed'], true) && $o['gateway_ref']){
    $r = gwRetrieve($o['gateway_ref']);
    if(!empty($r['ok'])){
        if(!empty($r['paid'])) OrderService::confirmPaid($o['id'], $o['gateway_ref'], $r['fee']);
        elseif(($r['status'] ?? '') === 'failed') OrderService::markFailed($o['id'], 'failed');
    }
    $o = db_one("SELECT * FROM orders WHERE id = ?", [$o['id']]);
}
$item = db_one("SELECT oi.course_id, c.title, c.slug FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = ? LIMIT 1", [$o['id']]);
?>
<div class="card result">
  <?php if($o['status'] === 'paid'): ?>
    <div class="result-ico ok"><i class="fi fi-rr-check"></i></div><h1>ชำระเงินสำเร็จ</h1>
    <p class="muted"><?= h($item['title']) ?> · <?= baht($o['total']) ?></p>
    <a class="btn btn-primary" href="<?= h(u('learn', ['course' => $item['course_id']])) ?>">เริ่มเรียนเลย</a>
    <a class="btn" href="<?= h(u('receipt', ['order' => $o['order_no']])) ?>">ใบเสร็จ</a>
  <?php elseif($o['status'] === 'pending'): ?>
    <div class="result-ico wait"><i class="fi fi-rr-hourglass-end"></i></div><h1>กำลังรอยืนยันการชำระเงิน</h1>
    <p class="muted">ระบบจะเปิดสิทธิ์เรียนให้อัตโนมัติเมื่อได้รับการยืนยันจากผู้ให้บริการ</p>
    <a class="btn" href="<?= h(u('pay-return', ['order' => $o['order_no']])) ?>">ตรวจสอบอีกครั้ง</a>
  <?php else: ?>
    <div class="result-ico bad"><i class="fi fi-rr-cross"></i></div><h1>ชำระเงินไม่สำเร็จ</h1>
    <p class="muted">คำสั่งซื้อ <?= h($o['order_no']) ?> <?= status_badge($o['status']) ?></p>
    <a class="btn btn-primary" href="<?= h(u('checkout', ['course' => $item['course_id']])) ?>">ลองอีกครั้ง</a>
  <?php endif; ?>
</div>
