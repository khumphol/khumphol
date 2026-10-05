<?php
$TITLE = 'คำสั่งซื้อ & คืนเงิน';
$st = get('status'); $q = get('q');
$where = []; $params = [];
if($st !== ''){ $where[] = "o.status = ?"; $params[] = $st; }
if($q !== ''){ $where[] = "(o.order_no LIKE ? OR u.email LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
$rows = db_all("SELECT o.*, u.name, u.email FROM orders o JOIN users u ON u.id = o.user_id ".($where ? 'WHERE '.implode(' AND ', $where) : '')." ORDER BY o.id DESC LIMIT 300", $params);
?>
<h1>คำสั่งซื้อ & คืนเงิน</h1>
<form class="row mb" method="get"><input type="hidden" name="p" value="orders">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="เลขที่ / อีเมล" style="max-width:240px">
  <select name="status" style="max-width:180px"><option value="">ทุกสถานะ</option>
    <?php foreach(['pending','paid','failed','refunded','partially_refunded'] as $s): ?><option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= strip_tags(status_badge($s)) ?></option><?php endforeach; ?></select>
  <button class="btn">กรอง</button></form>
<div class="card table-wrap"><?php if(!$rows): ?><p class="muted">ไม่มีรายการ</p><?php else: ?><table>
  <tr><th>เลขที่</th><th>ลูกค้า</th><th class="num">ยอด</th><th class="num">ค่าธรรมเนียม</th><th>ช่องทาง</th><th>สถานะ</th><th>วันที่</th></tr>
  <?php foreach($rows as $o): ?>
  <tr><td><a href="<?= h(au('order', ['id' => $o['id']])) ?>"><?= h($o['order_no']) ?></a></td><td><?= h($o['name']) ?><div class="small muted"><?= h($o['email']) ?></div></td>
      <td class="num"><?= baht($o['total']) ?></td><td class="num small"><?= baht($o['gateway_fee']) ?></td><td class="small"><?= h($o['gateway'] ?: '—') ?></td>
      <td><?= status_badge($o['status']) ?></td><td class="small muted nowrap"><?= h($o['paid_at'] ?: $o['created_at']) ?></td></tr>
  <?php endforeach; ?>
</table><?php endif; ?></div>
