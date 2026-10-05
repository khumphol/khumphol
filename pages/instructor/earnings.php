<?php
$TITLE = 'รายได้';
$uid = current_user_id();
$bal = LedgerService::balances($uid);
$rows = db_all("SELECT l.*, c.title FROM instructor_ledger l LEFT JOIN order_items oi ON oi.id = l.order_item_id LEFT JOIN courses c ON c.id = oi.course_id
                WHERE l.instructor_id = ? ORDER BY l.id DESC LIMIT 200", [$uid]);
$payouts = db_all("SELECT * FROM payouts WHERE instructor_id = ? AND status <> 'cancelled' ORDER BY period DESC LIMIT 24", [$uid]);
$typeLabel = ['sale' => 'ขาย', 'refund' => 'คืนเงิน', 'payout' => 'โอนเงิน', 'adjustment' => 'ปรับยอด'];
?>
<div class="page-header"><div><h1><i class="fi fi-rr-sack-dollar"></i> รายได้</h1><p>ยอดขายพักเงิน <?= LedgerService::holdDays() ?> วันก่อนถอนได้ · โอนรายเดือนเมื่อถึงขั้นต่ำ · หัก ณ ที่จ่าย <?= h(PayoutService::withholdingRate()) ?>%</p></div></div>
<div class="grid g3 mb">
  <div class="card stat"><div class="label">พักเงิน (<?= LedgerService::holdDays() ?> วันหลังขาย)</div><div class="value"><?= baht($bal['held']) ?></div></div>
  <div class="card stat"><div class="label">ยอดถอนได้</div><div class="value"><?= baht($bal['available']) ?></div>
    <div class="small muted">โอนรายเดือนเมื่อยอดถึง <?= baht(setting('payout_min_amount', '500')) ?></div></div>
  <div class="card stat"><div class="label">รายได้สะสม (หลังคืนเงิน)</div><div class="value"><?= baht($bal['lifetime_sales'] + $bal['refunds']) ?></div></div>
</div>
<?php if($payouts): ?><div class="card table-wrap"><h2>รอบจ่ายเงิน</h2><table>
  <tr><th>รอบ</th><th class="num">ยอดรวม</th><th class="num">หัก ณ ที่จ่าย</th><th class="num">ยอดโอน</th><th>สถานะ</th></tr>
  <?php foreach($payouts as $p): ?><tr><td><?= h($p['period']) ?></td><td class="num"><?= baht($p['gross']) ?></td><td class="num"><?= baht($p['withholding_tax']) ?></td>
    <td class="num"><strong><?= baht($p['net_amount']) ?></strong></td><td><?= $p['status'] === 'paid' ? '<span class="badge badge-success">โอนแล้ว '.h(substr($p['paid_at'], 0, 10)).'</span>' : '<span class="badge badge-warning">รอโอน</span>' ?></td></tr><?php endforeach; ?>
</table></div><?php endif; ?>
<div class="card table-wrap">
  <h2>รายการบัญชี</h2>
  <?php if(!$rows): ?><p class="muted">ยังไม่มีรายการ</p><?php else: ?>
  <table>
    <tr><th>วันที่</th><th>ประเภท</th><th>รายละเอียด</th><th class="num">จำนวน</th><th>สถานะ</th><th>ถอนได้เมื่อ</th></tr>
    <?php foreach($rows as $r): ?>
    <tr><td class="small nowrap"><?= h($r['created_at']) ?></td><td><?= h($typeLabel[$r['type']] ?? $r['type']) ?></td>
        <td><?= h($r['title'] ?: $r['note']) ?></td>
        <td class="num" style="color:<?= (float)$r['amount'] < 0 ? 'var(--red)' : 'inherit' ?>"><?= baht($r['amount']) ?></td>
        <td><?= status_badge($r['status']) ?></td><td class="small muted nowrap"><?= h(substr($r['available_at'], 0, 10)) ?></td></tr>
    <?php endforeach; ?>
  </table><?php endif; ?>
</div>
