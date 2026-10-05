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
<div class="page-header"><div><h1><i class="fi fi-rr-home"></i> สวัสดี <?= h(current_user()['instructor_name']) ?></h1><p>ภาพรวมการสอนและรายได้ของคุณ</p></div>
  <?php if(PermissionService::can($uid, 'courses')): ?><a class="btn btn-primary" href="<?= h(iu('courses')) ?>"><i class="fi fi-rr-add"></i> สร้างคอร์ส</a><?php endif; ?></div>
<div class="stat-grid">
  <?php foreach([['รายได้รอปล่อย (พักเงิน)', baht($bal['held']), 'fi-rr-hourglass-end', '#f59e0b'], ['ยอดถอนได้', baht($bal['available']), 'fi-rr-sack-dollar', '#10b981'],
                 ['ยอดขาย (รายการ)', (int)$stats['sales'], 'fi-rr-shopping-cart', '#6366f1'], ['ผู้เรียน', $students, 'fi-rr-graduation-cap', '#06b6d4']] as $t): ?>
  <div class="stat-card"><div><div class="stat-label"><?= h($t[0]) ?></div><div class="stat-value"><?= h($t[1]) ?></div></div><div class="stat-icon" style="background:<?= $t[3] ?>1a;color:<?= $t[3] ?>"><i class="fi <?= $t[2] ?>"></i></div></div>
  <?php endforeach; ?>
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
