<?php
$TITLE = 'แดชบอร์ดแอดมิน';
$m = date('Y-m-01 00:00:00');
$k = db_one("SELECT COUNT(DISTINCT o.id) orders, COALESCE(SUM(oi.paid_amount),0) gross, COALESCE(SUM(oi.gateway_fee),0) fee,
               COALESCE(SUM(oi.platform_amount),0) platform, COALESCE(SUM(oi.instructor_amount),0) instructor
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.paid_at >= ? AND oi.refunded_at IS NULL AND o.status IN ('paid','partially_refunded')", [$m]);
$pendingInst = (int)db_val("SELECT COUNT(*) FROM instructor_profiles WHERE status = 'pending'");
$pendingCourses = (int)db_val("SELECT COUNT(*) FROM courses WHERE status = 'pending_review'");
$owed = db_one("SELECT COALESCE(SUM(CASE WHEN status='held' THEN amount END),0) held, COALESCE(SUM(CASE WHEN status='available' THEN amount END),0) available FROM instructor_ledger");
$recent = db_all("SELECT o.*, u.name FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 8");
?>
<h1>แดชบอร์ด</h1>
<?php if($pendingInst || $pendingCourses): ?>
<div class="alert alert-warning">รอดำเนินการ:
  <?php if($pendingInst): ?><a href="<?= h(au('instructors', ['status' => 'pending'])) ?>">ใบสมัครผู้สอน <?= $pendingInst ?></a><?php endif; ?>
  <?php if($pendingCourses): ?> · <a href="<?= h(au('courses', ['status' => 'pending_review'])) ?>">คอร์สรอตรวจ <?= $pendingCourses ?></a><?php endif; ?></div>
<?php endif; ?>
<p class="muted small">เดือนนี้ (ตั้งแต่ <?= h(substr($m, 0, 10)) ?>, ไม่รวมรายการที่คืนเงิน)</p>
<div class="grid g4 mb">
  <div class="card stat"><div class="label">ยอดขาย</div><div class="value"><?= baht($k['gross']) ?></div><div class="small muted"><?= (int)$k['orders'] ?> ออเดอร์</div></div>
  <div class="card stat"><div class="label">ค่าธรรมเนียม gateway</div><div class="value"><?= baht($k['fee']) ?></div></div>
  <div class="card stat"><div class="label">รายได้แพลตฟอร์ม</div><div class="value"><?= baht($k['platform']) ?></div></div>
  <div class="card stat"><div class="label">ส่วนของผู้สอน</div><div class="value"><?= baht($k['instructor']) ?></div></div>
</div>
<div class="grid g2 mb">
  <div class="card stat"><div class="label">ค้างจ่ายผู้สอน — พักเงิน</div><div class="value"><?= baht($owed['held']) ?></div></div>
  <div class="card stat"><div class="label">ค้างจ่ายผู้สอน — ถอนได้</div><div class="value"><?= baht($owed['available']) ?></div></div>
</div>
<div class="card table-wrap"><h2>คำสั่งซื้อล่าสุด</h2>
  <table><tr><th>เลขที่</th><th>ลูกค้า</th><th class="num">ยอด</th><th>สถานะ</th><th>วันที่</th></tr>
  <?php foreach($recent as $o): ?><tr><td><a href="<?= h(au('order', ['id' => $o['id']])) ?>"><?= h($o['order_no']) ?></a></td><td><?= h($o['name']) ?></td>
    <td class="num"><?= baht($o['total']) ?></td><td><?= status_badge($o['status']) ?></td><td class="small muted"><?= h($o['created_at']) ?></td></tr><?php endforeach; ?>
  </table></div>
