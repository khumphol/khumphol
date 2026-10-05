<?php
$TITLE = 'หน้าแรก';
$m = date('Y-m-01 00:00:00');
$k = db_one("SELECT COUNT(DISTINCT o.id) orders, COALESCE(SUM(oi.paid_amount - oi.refunded_amount),0) gross, COALESCE(SUM(oi.gateway_fee),0) fee,
               COALESCE(SUM(oi.platform_amount),0) platform, COALESCE(SUM(oi.instructor_amount - oi.instructor_reversed),0) instructor
             FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.paid_at >= ? AND o.status IN ('paid','partially_refunded','refunded')", [$m]);
$pendingInst = (int)db_val("SELECT COUNT(*) FROM instructor_profiles WHERE status = 'pending'");
$pendingCourses = (int)db_val("SELECT COUNT(*) FROM courses WHERE status = 'pending_review'");
$pendingPayouts = db_one("SELECT COUNT(*) n, COALESCE(SUM(net_amount),0) amt FROM payouts WHERE status = 'pending'");
$owed = db_one("SELECT COALESCE(SUM(CASE WHEN status='held' THEN amount END),0) held, COALESCE(SUM(CASE WHEN status='available' THEN amount END),0) available FROM instructor_ledger");
$counts = db_one("SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM courses WHERE status='published') courses,
                         (SELECT COUNT(*) FROM instructor_profiles WHERE status='approved') instructors, (SELECT COUNT(*) FROM enrollments WHERE status='active') enrollments");
$recent = db_all("SELECT o.*, u.name FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 8");
$trend = db_all("SELECT DATE(o.paid_at) d, SUM(oi.paid_amount) v FROM orders o JOIN order_items oi ON oi.order_id = o.id
                 WHERE o.paid_at >= ? GROUP BY DATE(o.paid_at) ORDER BY d", [date('Y-m-d', strtotime('-13 days'))]);
$byDay = []; foreach($trend as $t) $byDay[$t['d']] = (float)$t['v'];
$max = $byDay ? max($byDay) : 0;
$tiles = [
    ['ยอดขายเดือนนี้ (สุทธิหลังคืนเงิน)', baht($k['gross']), (int)$k['orders'].' ออเดอร์', 'fi-rr-shopping-cart', '#6366f1'],
    ['รายได้แพลตฟอร์ม', baht($k['platform']), 'ค่าธรรมเนียม '.baht($k['fee']), 'fi-rr-chart-pie-alt', '#10b981'],
    ['ส่วนของผู้สอน', baht($k['instructor']), 'เดือนนี้', 'fi-rr-chalkboard-user', '#06b6d4'],
    ['ค้างจ่ายผู้สอน', baht($owed['held'] + $owed['available']), 'ถอนได้ '.baht($owed['available']), 'fi-rr-sack-dollar', '#f59e0b'],
];
?>
<div class="page-header"><div><h1><i class="fi fi-rr-home"></i> สวัสดี <?= h(current_user()['name']) ?></h1><p>ภาพรวมแพลตฟอร์ม · <?= h(date('j/n/Y')) ?></p></div></div>
<?php if($pendingInst || $pendingCourses || (int)$pendingPayouts['n']): ?>
<div class="alert alert-warning"><i class="fi fi-rr-bell"></i> รอดำเนินการ:
  <?php if($pendingInst): ?><a href="<?= h(au('instructors', ['status' => 'pending'])) ?>">ใบสมัครผู้สอน <?= $pendingInst ?></a> · <?php endif; ?>
  <?php if($pendingCourses): ?><a href="<?= h(au('courses', ['status' => 'pending_review'])) ?>">คอร์สรอตรวจ <?= $pendingCourses ?></a> · <?php endif; ?>
  <?php if((int)$pendingPayouts['n']): ?><a href="<?= h(au('payouts')) ?>">รอบจ่ายรอโอน <?= (int)$pendingPayouts['n'] ?> (<?= baht($pendingPayouts['amt']) ?>)</a><?php endif; ?></div>
<?php endif; ?>
<div class="stat-grid">
  <?php foreach($tiles as $t): ?>
  <div class="stat-card"><div><div class="stat-label"><?= h($t[0]) ?></div><div class="stat-value"><?= h($t[1]) ?></div><div class="stat-sub"><?= h($t[2]) ?></div></div>
    <div class="stat-icon" style="background:<?= $t[4] ?>1a;color:<?= $t[4] ?>"><i class="fi <?= $t[3] ?>"></i></div></div>
  <?php endforeach; ?>
</div>
<div class="row row-2">
  <div class="card"><div class="card-header"><div class="card-header-title">ยอดขาย 14 วันล่าสุด</div></div><div class="card-body">
    <div style="display:flex;align-items:flex-end;gap:6px;height:160px">
      <?php for($i = 13; $i >= 0; $i--): $d = date('Y-m-d', strtotime("-$i days")); $v = $byDay[$d] ?? 0; ?>
        <div title="<?= h($d.' · '.baht($v)) ?>" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;height:100%">
          <div style="width:100%;border-radius:6px 6px 2px 2px;background:var(--primary);opacity:<?= $v ? 1 : .15 ?>;height:<?= $max ? max(3, round($v / $max * 130)) : 3 ?>px"></div>
          <span style="font-size:.66rem;color:var(--text-muted)"><?= date('j', strtotime($d)) ?></span></div>
      <?php endfor; ?>
    </div></div></div>
  <div class="card"><div class="card-header"><div class="card-header-title">แพลตฟอร์ม</div></div><div class="card-body">
    <div class="grid g2">
      <div class="stat"><div class="label">ผู้ใช้</div><div class="value"><?= number_format($counts['users']) ?></div></div>
      <div class="stat"><div class="label">ผู้สอน</div><div class="value"><?= number_format($counts['instructors']) ?></div></div>
      <div class="stat"><div class="label">คอร์สที่เผยแพร่</div><div class="value"><?= number_format($counts['courses']) ?></div></div>
      <div class="stat"><div class="label">การลงทะเบียน</div><div class="value"><?= number_format($counts['enrollments']) ?></div></div>
    </div></div></div>
</div>
<div class="card"><div class="card-header"><div class="card-header-title">คำสั่งซื้อล่าสุด</div><a class="btn btn-outline btn-sm" href="<?= h(au('orders')) ?>">ทั้งหมด</a></div>
  <div class="table-wrap"><table><thead><tr><th>เลขที่</th><th>ลูกค้า</th><th class="num">ยอด</th><th>สถานะ</th><th>วันที่</th></tr></thead><tbody>
  <?php foreach($recent as $o): ?><tr><td><a href="<?= h(au('order', ['id' => $o['id']])) ?>"><?= h($o['order_no']) ?></a></td><td><?= h($o['name']) ?></td>
    <td class="num"><?= baht($o['total']) ?></td><td><?= status_badge($o['status']) ?></td><td class="small muted"><?= h($o['created_at']) ?></td></tr><?php endforeach; ?>
  <?php if(!$recent): ?><tr><td colspan="5" class="muted">ยังไม่มีคำสั่งซื้อ</td></tr><?php endif; ?>
  </tbody></table></div></div>
