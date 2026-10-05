<?php
$TITLE = 'แดชบอร์ดผู้สอน';
$uid = current_user_id();
$bal = LedgerService::balances($uid);
$stats = db_one("SELECT COUNT(*) sales, COALESCE(SUM(oi.paid_amount),0) gross, COALESCE(SUM(oi.instructor_amount),0) mine
                 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.instructor_id = ? AND o.status IN ('paid','partially_refunded') AND oi.refunded_at IS NULL", [$uid]);
$students = (int)db_val("SELECT COUNT(DISTINCT e.user_id) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.instructor_id = ? AND e.status = 'active'", [$uid]);
$recent = db_all("SELECT oi.*, o.order_no, o.paid_at, c.title FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN courses c ON c.id = oi.course_id
                  WHERE oi.instructor_id = ? AND o.paid_at IS NOT NULL ORDER BY o.paid_at DESC LIMIT 10", [$uid]);
?>
<h1>สวัสดี <?= h(current_user()['instructor_name']) ?></h1>
<div class="grid g4 mb">
  <div class="card stat"><div class="label">รายได้รอปล่อย (พักเงิน)</div><div class="value"><?= baht($bal['held']) ?></div></div>
  <div class="card stat"><div class="label">ยอดถอนได้</div><div class="value"><?= baht($bal['available']) ?></div></div>
  <div class="card stat"><div class="label">ยอดขาย (รายการ)</div><div class="value"><?= (int)$stats['sales'] ?></div></div>
  <div class="card stat"><div class="label">ผู้เรียน</div><div class="value"><?= $students ?></div></div>
</div>
<div class="card">
  <div class="row between"><h2>ยอดขายล่าสุด</h2><a href="<?= h(iu('earnings')) ?>" class="small">ดูทั้งหมด →</a></div>
  <?php if(!$recent): ?><p class="muted">ยังไม่มียอดขาย</p><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>วันที่</th><th>คอร์ส</th><th class="num">ลูกค้าจ่าย</th><th class="num">แพลตฟอร์ม</th><th class="num">คุณได้รับ</th></tr>
    <?php foreach($recent as $r): ?>
    <tr><td class="small nowrap"><?= h($r['paid_at']) ?></td><td><?= h($r['title']) ?><?= $r['refunded_at'] ? ' '.status_badge('refunded') : '' ?></td>
        <td class="num"><?= baht($r['paid_amount']) ?></td><td class="num small muted"><?= h($r['platform_rate']) ?>%</td><td class="num"><strong><?= baht($r['instructor_amount']) ?></strong></td></tr>
    <?php endforeach; ?>
  </table></div><?php endif; ?>
</div>
