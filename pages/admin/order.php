<?php
$MENU = 'orders';
$o = db_one("SELECT o.*, u.name, u.email, cp.code AS coupon_code FROM orders o JOIN users u ON u.id = o.user_id LEFT JOIN coupons cp ON cp.id = o.coupon_id WHERE o.id = ?", [(int)get('id')]);
if(!$o){ echo '<div class="card">ไม่พบคำสั่งซื้อ</div>'; return; }
$TITLE = 'คำสั่งซื้อ '.$o['order_no'];
if(is_post() && post('action') === 'refund'){
    require_csrf();
    try {
        if(post('reason') === '') throw new RuntimeException('กรุณาระบุเหตุผลการคืนเงิน');
        $via = post('via_gateway') === '1';
        OrderService::refundItem((int)post('item_id'), post('reason'), post('amount') === '' ? null : post('amount'), $via);
        flash($via ? 'คืนเงินผ่าน '.($o['gateway'] ?: 'gateway').' และบันทึกแล้ว' : 'บันทึกการคืนเงินแล้ว — อย่าลืมคืนเงินจริงที่หน้า '.($o['gateway'] ?: 'gateway'));
    } catch(RuntimeException $e){ flash($e->getMessage(), 'danger'); }
    redirect(au('order', ['id' => $o['id']]));
}
$items = db_all("SELECT oi.*, c.title, COALESCE(ip.display_name, u.name) AS teacher, r.scope AS rule_scope
                 FROM order_items oi JOIN courses c ON c.id = oi.course_id JOIN users u ON u.id = oi.instructor_id
                 LEFT JOIN instructor_profiles ip ON ip.user_id = oi.instructor_id LEFT JOIN revenue_share_rules r ON r.id = oi.rule_id
                 WHERE oi.order_id = ?", [(int)$o['id']]);
$refunds = db_all("SELECT rf.*, c.title, u.name by_name FROM refunds rf JOIN order_items oi ON oi.id = rf.order_item_id JOIN courses c ON c.id = oi.course_id
                   LEFT JOIN users u ON u.id = rf.created_by WHERE rf.order_id = ? ORDER BY rf.id", [(int)$o['id']]);
$events = db_all("SELECT * FROM payment_events WHERE order_id = ? ORDER BY id", [(int)$o['id']]);
$hold = LedgerService::holdDays();
?>
<a class="small" href="<?= h(au('orders')) ?>">← คำสั่งซื้อทั้งหมด</a>
<h1><?= h($o['order_no']) ?> <?= status_badge($o['status']) ?></h1>
<div class="grid g4 mb">
  <div class="card stat"><div class="label">ลูกค้า</div><div><?= h($o['name']) ?></div><div class="small muted"><?= h($o['email']) ?></div></div>
  <div class="card stat"><div class="label">ยอดชำระ</div><div class="value"><?= baht($o['total']) ?></div><div class="small muted">ราคา <?= baht($o['subtotal']) ?><?= $o['coupon_code'] ? ' · คูปอง '.h($o['coupon_code']).' -'.baht($o['discount']) : '' ?></div></div>
  <div class="card stat"><div class="label">ค่าธรรมเนียม gateway</div><div class="value"><?= baht($o['gateway_fee']) ?></div></div>
  <div class="card stat"><div class="label">ช่องทาง / อ้างอิง</div><div><?= h($o['gateway'] ?: '—') ?></div><div class="small muted" style="word-break:break-all"><?= h($o['gateway_ref'] ?: '') ?></div><div class="small muted">ชำระ <?= h($o['paid_at'] ?: '—') ?></div></div>
</div>
<div class="card table-wrap"><h2>รายการ & การแบ่งรายได้ (snapshot ณ วันขาย)</h2><table>
  <tr><th>คอร์ส</th><th class="num">ราคา</th><th class="num">ส่วนลด</th><th class="num">จ่าย</th><th class="num">ค่าธรรมเนียม</th><th class="num">สุทธิ</th><th class="num">อัตรา</th><th class="num">แพลตฟอร์ม</th><th class="num">ผู้สอน</th><th></th></tr>
  <?php foreach($items as $it): ?>
  <tr><td style="min-width:180px"><?= h($it['title']) ?><div class="small muted"><?= h($it['teacher']) ?></div></td>
      <td class="num"><?= baht($it['list_price']) ?></td><td class="num"><?= baht($it['discount']) ?><?= $it['coupon_owner'] !== 'none' ? '<div class="small muted">'.($it['coupon_owner'] === 'platform' ? 'แพลตฟอร์ม' : 'ผู้สอน').'</div>' : '' ?></td>
      <td class="num"><?= baht($it['paid_amount']) ?></td><td class="num"><?= baht($it['gateway_fee']) ?></td><td class="num"><?= baht($it['net_amount']) ?></td>
      <td class="num"><?= $it['platform_rate'] !== null ? h($it['platform_rate']).'%' : '—' ?><div class="small muted"><?= h($it['rule_id'] ? 'กฎ #'.$it['rule_id'].' ('.$it['rule_scope'].')' : ($it['platform_rate'] !== null ? 'ค่าสำรอง' : '')) ?></div></td>
      <td class="num"><?= baht($it['platform_amount']) ?></td><td class="num"><strong><?= baht($it['instructor_amount']) ?></strong></td>
      <td class="right" style="min-width:230px"><?php if($it['refunded_at']): ?><?= status_badge('refunded') ?>
        <?php elseif(in_array($o['status'], ['paid', 'partially_refunded'], true)):
          $left = (float)$it['paid_amount'] - (float)$it['refunded_amount']; ?>
        <?php if((float)$it['refunded_amount'] > 0): ?><div class="small">คืนแล้ว <?= baht($it['refunded_amount']) ?></div><?php endif; ?>
        <form method="post" onsubmit="return confirm('ยืนยันคืนเงิน? (คืนครบ = ยกเลิกสิทธิ์เรียน) ระบบจะหักรายได้ผู้สอนตามสัดส่วน')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="refund"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <input type="number" name="amount" step="0.01" min="0.01" max="<?= h($left) ?>" placeholder="จำนวน (ว่าง = ทั้งหมด <?= money($left) ?>)">
          <input type="text" name="reason" placeholder="เหตุผล" required style="margin-top:.35rem">
          <?php if($o['gateway'] && $o['gateway'] !== 'coupon'): ?><label class="check small" style="margin:.35rem 0"><input type="checkbox" name="via_gateway" value="1" checked> คืนเงินผ่าน <?= h($o['gateway']) ?> อัตโนมัติ</label><?php endif; ?>
          <button class="btn btn-sm btn-danger">คืนเงิน</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>
<p class="small muted">คืนบางส่วนได้หลายครั้ง · หักรายได้ผู้สอนตามสัดส่วน (ภายใน <?= $hold ?> วันหักจากยอดพักเงิน) · ถ้าไม่ติ๊ก "คืนผ่าน gateway" ต้องคืนเงินจริงเองที่หน้าจัดการของ gateway</p></div>
<?php if($refunds): ?><div class="card table-wrap"><h2>ประวัติการคืนเงิน</h2><table>
  <tr><th>เวลา</th><th>คอร์ส</th><th class="num">คืนลูกค้า</th><th class="num">หักผู้สอน</th><th>ช่องทาง</th><th>เหตุผล</th><th>โดย</th></tr>
  <?php foreach($refunds as $rf): ?><tr><td class="small nowrap"><?= h($rf['created_at']) ?></td><td><?= h($rf['title']) ?></td><td class="num"><?= baht($rf['amount']) ?></td>
    <td class="num"><?= baht($rf['instructor_reversal']) ?></td><td class="small"><?= $rf['via_gateway'] ? 'gateway '.h($rf['gateway_refund_ref']) : 'บันทึกอย่างเดียว' ?></td>
    <td class="small"><?= h($rf['reason']) ?></td><td class="small muted"><?= h($rf['by_name']) ?></td></tr><?php endforeach; ?>
</table></div><?php endif; ?>
<?php if($events): ?><div class="card table-wrap"><h2>เหตุการณ์ชำระเงิน</h2><table>
  <tr><th>เวลา</th><th>เหตุการณ์</th><th>อ้างอิง</th><th>ข้อมูล</th></tr>
  <?php foreach($events as $e): ?><tr><td class="small nowrap"><?= h($e['created_at']) ?></td><td><?= h($e['event']) ?></td><td class="small"><?= h($e['ref']) ?></td><td class="small muted"><code><?= h($e['payload']) ?></code></td></tr><?php endforeach; ?>
</table></div><?php endif; ?>
