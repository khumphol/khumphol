<?php
$TITLE = 'ส่วนแบ่งรายได้';
require __DIR__.'/_rate_post.php';
rate_handle_post('global', null, au('revenue'));
$overrides = db_all("SELECT r.*, c.title AS course_title, COALESCE(ip.display_name, u.name) AS instructor_name
                     FROM revenue_share_rules r LEFT JOIN courses c ON c.id = r.course_id
                     LEFT JOIN users u ON u.id = r.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = r.instructor_id
                     WHERE r.scope <> 'global' AND r.is_active = 1 AND (r.ends_at IS NULL OR r.ends_at > ?)
                     ORDER BY r.scope DESC, r.id DESC", [now()]);
$rateScope = 'global'; $rateTarget = null; $rateEffective = RevenueShareService::rateFor(0, 0);
?>
<h1>ส่วนแบ่งรายได้</h1>
<div class="card mb small">
  <strong>วิธีคิดต่อการขาย 1 รายการ</strong>
  <ul style="margin:.4rem 0 0">
    <li>ยอดสุทธิ = ยอดที่ลูกค้าจ่าย − ค่าธรรมเนียม gateway (ค่าจริงจาก Omise หรือประมาณ <?= h(setting('gateway_fee_rate', '3.65')) ?>%)</li>
    <li>แพลตฟอร์ม = ยอดสุทธิ × อัตรา · ผู้สอน = ยอดสุทธิ − แพลตฟอร์ม</li>
    <li>อัตราเลือกจากกฎที่เจาะจงที่สุด: <strong>คอร์ส &gt; ผู้สอน &gt; ทั้งระบบ</strong> ณ เวลาที่ชำระเงิน แล้วบันทึกตายตัวไว้กับรายการขาย</li>
    <li>คูปองผู้สอน ลดราคาก่อนแบ่ง · คูปองแพลตฟอร์ม ผู้สอนได้เท่ากับขายราคาเต็ม</li>
  </ul>
</div>
<?php require __DIR__.'/_rate.php'; ?>
<div class="card">
  <h2>อัตราเฉพาะที่มีผลอยู่/ตั้งล่วงหน้า</h2>
  <?php if(!$overrides): ?><p class="muted small">ไม่มี — ทุกคอร์สใช้อัตราทั้งระบบ</p><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>ระดับ</th><th>เป้าหมาย</th><th class="num">อัตรา</th><th>เริ่ม</th><th>สิ้นสุด</th><th>หมายเหตุ</th></tr>
    <?php foreach($overrides as $r): ?>
    <tr><td><span class="badge badge-primary"><?= $r['scope'] === 'course' ? 'คอร์ส' : 'ผู้สอน' ?></span></td>
        <td><?= $r['scope'] === 'course' ? '<a href="'.h(au('course', ['id' => $r['course_id']])).'">'.h($r['course_title']).'</a>' : '<a href="'.h(au('instructor', ['id' => $r['instructor_id']])).'">'.h($r['instructor_name']).'</a>' ?></td>
        <td class="num"><strong><?= h($r['platform_rate']) ?>%</strong></td><td class="small"><?= h($r['starts_at'] ?: '—') ?></td><td class="small"><?= h($r['ends_at'] ?: '—') ?></td><td class="small"><?= h($r['note']) ?></td></tr>
    <?php endforeach; ?>
  </table></div><?php endif; ?>
  <p class="small muted">ตั้งอัตราเฉพาะได้ที่หน้า <a href="<?= h(au('instructors')) ?>">ผู้สอน</a> หรือ <a href="<?= h(au('courses')) ?>">คอร์ส</a></p>
</div>
