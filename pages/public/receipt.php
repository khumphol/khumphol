<?php
// ใบเสร็จ (/ใบกำกับภาษีอย่างย่อ เมื่อเปิด VAT) — เจ้าของออเดอร์หรือแอดมิน
require_login();
$o = db_one("SELECT o.*, u.name, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_no = ?", [get('order')]);
if(!$o || ((int)$o['user_id'] !== current_user_id() && !is_admin()) || !$o['paid_at']){ echo '<div class="card">ไม่พบใบเสร็จ</div>'; return; }
$TITLE = 'ใบเสร็จ '.$o['receipt_no'];
$items = db_all("SELECT oi.*, c.title FROM order_items oi JOIN courses c ON c.id = oi.course_id WHERE oi.order_id = ?", [(int)$o['id']]);
$refunded = (float)db_val("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ?", [(int)$o['id']]);
$vat = (float)$o['vat_amount'] > 0;
?>
<style>@media print{ .site-header,.site-footer,.topbar,.footer,.no-print{display:none !important} body{background:#fff} .card{box-shadow:none;border:0} }</style>
<div class="card" style="max-width:760px;margin:0 auto">
  <div class="row between"><div><h1 style="margin:0"><?= $vat ? 'ใบเสร็จรับเงิน / ใบกำกับภาษีอย่างย่อ' : 'ใบเสร็จรับเงิน' ?></h1>
    <div class="muted small">เลขที่ <?= h($o['receipt_no']) ?> · วันที่ <?= h(date('d/m/Y H:i', strtotime($o['paid_at']))) ?> · คำสั่งซื้อ <?= h($o['order_no']) ?></div></div>
    <button class="btn no-print" onclick="window.print()">พิมพ์ / PDF</button></div>
  <div class="grid g2 mt">
    <div class="small"><strong>ผู้ขาย</strong><br><?= h(setting('seller_name', '') ?: setting('site_name', 'Aleanor Cloud')) ?><br>
      <?php if(setting('seller_tax_id', '') !== ''): ?>เลขผู้เสียภาษี <?= h(setting('seller_tax_id', '')) ?><br><?php endif; ?><?= nl2br(h(setting('seller_address', ''))) ?></div>
    <div class="small"><strong>ผู้ซื้อ</strong><br><?= h($o['bill_name'] ?: $o['name']) ?><br><?= h($o['email']) ?>
      <?php if($o['bill_tax_id']): ?><br>เลขผู้เสียภาษี <?= h($o['bill_tax_id']) ?><?php endif; ?><?php if($o['bill_address']): ?><br><?= nl2br(h($o['bill_address'])) ?><?php endif; ?></div>
  </div>
  <table class="mt" style="width:100%"><tr><th>รายการ</th><th class="num">ราคา</th><th class="num">ส่วนลด</th><th class="num">จำนวนเงิน</th></tr>
    <?php foreach($items as $it): ?><tr><td><?= h($it['title']) ?></td><td class="num"><?= money($it['list_price']) ?></td><td class="num"><?= money($it['discount']) ?></td><td class="num"><?= money($it['paid_amount']) ?></td></tr><?php endforeach; ?>
    <?php if($vat): ?><tr><td colspan="3" class="right">มูลค่าก่อนภาษี</td><td class="num"><?= money($o['total'] - $o['vat_amount']) ?></td></tr>
      <tr><td colspan="3" class="right">ภาษีมูลค่าเพิ่ม <?= h((float)$o['vat_rate']) ?>%</td><td class="num"><?= money($o['vat_amount']) ?></td></tr><?php endif; ?>
    <tr style="font-weight:700"><td colspan="3" class="right">รวมทั้งสิ้น</td><td class="num"><?= baht($o['total']) ?></td></tr>
    <?php if($refunded > 0): ?><tr><td colspan="3" class="right" style="color:var(--red)">คืนเงินแล้ว</td><td class="num" style="color:var(--red)">-<?= money($refunded) ?></td></tr><?php endif; ?>
  </table>
  <p class="small muted mt">ชำระผ่าน <?= h($o['gateway'] ?: '-') ?><?= $o['gateway_ref'] ? ' · อ้างอิง '.h($o['gateway_ref']) : '' ?></p>
</div>
