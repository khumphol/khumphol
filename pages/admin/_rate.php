<?php
// กล่องตั้งอัตราส่วนแบ่ง ใช้ในหน้าผู้สอน/คอร์ส/revenue — ต้องมี $rateScope ('global'|'instructor'|'course'), $rateTarget (id|null), $rateEffective
// POST จัดการใน _rate_post.php → rate_handle_post()
$now = now();
$col = $rateScope === 'course' ? 'course_id' : ($rateScope === 'instructor' ? 'instructor_id' : null);
$history = db_all("SELECT r.*, u.name AS by_name FROM revenue_share_rules r LEFT JOIN users u ON u.id = r.created_by
                   WHERE r.scope = ?".($col ? " AND r.$col = ".(int)$rateTarget : '')." ORDER BY r.id DESC LIMIT 30", [$rateScope]);
$scopeLabel = ['global' => 'ทั้งระบบ', 'instructor' => 'ผู้สอนคนนี้', 'course' => 'คอร์สนี้'][$rateScope];
?>
<div class="card">
  <h2>ส่วนแบ่งแพลตฟอร์ม — ระดับ<?= h($scopeLabel) ?></h2>
  <?php if(isset($rateEffective)): ?>
    <p>อัตราที่ใช้อยู่ตอนนี้: <strong style="font-size:1.2rem"><?= number_format($rateEffective['rate'], 2) ?>%</strong>
      <span class="badge badge-gray">มาจากระดับ <?= h(['course' => 'คอร์ส', 'instructor' => 'ผู้สอน', 'global' => 'global', 'default' => 'ค่าสำรองใน settings'][$rateEffective['scope']]) ?></span></p>
  <?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_rate">
    <div class="grid g4">
      <div><label>อัตราแพลตฟอร์ม (%)</label><input type="number" name="platform_rate" min="0" max="100" step="0.01" required></div>
      <div><label>เริ่ม (ว่าง = ทันที)</label><input type="datetime-local" name="starts_at"></div>
      <div><label>สิ้นสุด (ว่าง = ไม่กำหนด)</label><input type="datetime-local" name="ends_at"></div>
      <div><label>หมายเหตุ</label><input type="text" name="note" placeholder="เช่น โปรเปิดตัว"></div>
    </div>
    <button class="btn btn-primary mt">ตั้งอัตราใหม่</button>
    <span class="small muted">กฎเดิมที่เปิดค้างจะถูกปิด ณ เวลาเริ่มของกฎใหม่ (เก็บประวัติไว้) · มีผลกับยอดขายหลังจากนี้เท่านั้น</span>
  </form>
  <h3 class="mt2">ประวัติ</h3>
  <?php if(!$history): ?><p class="muted small">ยังไม่มีกฎในระดับนี้ — ใช้อัตราจากระดับที่กว้างกว่า</p><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><?= $rateScope === 'global' ? '' : '' ?><th class="num">อัตรา</th><th>เริ่ม</th><th>สิ้นสุด</th><th>สถานะ</th><th>หมายเหตุ</th><th>โดย</th><th></th></tr>
    <?php foreach($history as $r):
      $live = (int)$r['is_active'] && (!$r['starts_at'] || $r['starts_at'] <= $now) && (!$r['ends_at'] || $r['ends_at'] > $now);
      $future = (int)$r['is_active'] && $r['starts_at'] && $r['starts_at'] > $now; ?>
    <tr><td class="muted"><?= (int)$r['id'] ?></td><td class="num"><strong><?= h($r['platform_rate']) ?>%</strong></td>
        <td class="small"><?= h($r['starts_at'] ?: '—') ?></td><td class="small"><?= h($r['ends_at'] ?: '—') ?></td>
        <td><?= !(int)$r['is_active'] ? '<span class="badge badge-gray">ยกเลิก</span>' : ($live ? '<span class="badge badge-green">ใช้อยู่</span>' : ($future ? '<span class="badge badge-amber">ตั้งล่วงหน้า</span>' : '<span class="badge badge-gray">สิ้นสุดแล้ว</span>')) ?></td>
        <td class="small"><?= h($r['note']) ?></td><td class="small muted"><?= h($r['by_name'] ?? 'ระบบ') ?><div><?= h($r['created_at']) ?></div></td>
        <td class="right"><?php if(($live && $rateScope !== 'global') || $future): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-danger" name="action" value="end_rule" onclick="return confirm('<?= $future ? 'ยกเลิกกฎที่ตั้งล่วงหน้านี้?' : 'ปิดกฎนี้ตอนนี้?' ?>')"><?= $future ? 'ยกเลิก' : 'ปิดกฎ' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div><?php endif; ?>
</div>
