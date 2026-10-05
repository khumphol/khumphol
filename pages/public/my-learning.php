<?php
$TITLE = 'การเรียนของฉัน';
require_login();
$uid = current_user_id();
$courses = db_all("SELECT c.*, (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS n,
                     (SELECT COUNT(*) FROM lesson_progress p WHERE p.user_id = e.user_id AND p.course_id = c.id AND p.completed_at IS NOT NULL) AS done
                   FROM enrollments e JOIN courses c ON c.id = e.course_id
                   WHERE e.user_id = ? AND e.status = 'active' ORDER BY e.created_at DESC", [$uid]);
$orders = db_all("SELECT o.*, (SELECT GROUP_CONCAT(c.title SEPARATOR ', ') FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = o.id) AS titles
                  FROM orders o WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 50", [$uid]);
?>
<h1>การเรียนของฉัน</h1>
<?php if(!$courses): ?><div class="card muted">ยังไม่มีคอร์ส — <a href="<?= h(u('courses')) ?>">เลือกดูคอร์ส</a></div><?php endif; ?>
<div class="grid g3">
<?php foreach($courses as $c): $pct = $c['n'] ? round($c['done'] * 100 / $c['n']) : 0; ?>
  <a class="course-card" href="<?= h(u('learn', ['course' => $c['id']])) ?>">
    <img src="<?= h(cover_url($c)) ?>" alt="">
    <div class="body"><div class="title"><?= h($c['title']) ?></div>
      <div class="progress mt"><span style="width:<?= $pct ?>%"></span></div><div class="small muted"><?= $pct ?>% (<?= (int)$c['done'] ?>/<?= (int)$c['n'] ?>)</div></div>
  </a>
<?php endforeach; ?>
</div>
<?php $certs = db_all("SELECT * FROM certificates WHERE user_id = ? AND revoked_at IS NULL ORDER BY issued_at DESC", [$uid]); if($certs): ?>
<div class="card mt2"><h2>ใบประกาศของฉัน</h2><?php foreach($certs as $ct): ?><div class="row between" style="padding:.35rem 0"><span>🎓 <?= h($ct['course_title']) ?></span>
  <a class="small" href="<?= h(u('certificate', ['serial' => $ct['serial']])) ?>"><?= h($ct['serial']) ?></a></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if($orders): ?>
<div class="card mt2"><h2>ประวัติคำสั่งซื้อ</h2><div class="table-wrap"><table>
  <tr><th>เลขที่</th><th>คอร์ส</th><th class="num">ยอด</th><th>สถานะ</th><th>วันที่</th></tr>
  <?php foreach($orders as $o): ?>
  <tr><td class="nowrap"><?= h($o['order_no']) ?></td><td><?= h($o['titles']) ?></td><td class="num"><?= baht($o['total']) ?></td>
      <td><?= status_badge($o['status']) ?> <?php if($o['status'] === 'pending'): ?><a class="small" href="<?= h(u('pay-return', ['order' => $o['order_no']])) ?>">ตรวจสอบ</a><?php elseif($o['paid_at']): ?><a class="small" href="<?= h(u('receipt', ['order' => $o['order_no']])) ?>">ใบเสร็จ</a><?php endif; ?></td>
      <td class="small muted nowrap"><?= h($o['created_at']) ?></td></tr>
  <?php endforeach; ?>
</table></div></div>
<?php endif; ?>
