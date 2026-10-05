<?php
$TITLE = 'ผู้สอน';
$st = get('status');
$rows = db_all("SELECT ip.*, u.email, u.name,
                  (SELECT COUNT(*) FROM courses c WHERE c.instructor_id = ip.user_id) AS n_courses
                FROM instructor_profiles ip JOIN users u ON u.id = ip.user_id
                ".($st !== '' ? "WHERE ip.status = ?" : '')." ORDER BY FIELD(ip.status,'pending','approved','rejected','suspended'), ip.updated_at DESC",
               $st !== '' ? [$st] : []);
?>
<h1>ผู้สอน</h1>
<div class="row mb">
  <?php foreach(['' => 'ทั้งหมด', 'pending' => 'รออนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่ผ่าน', 'suspended' => 'ระงับ'] as $k => $v): ?>
    <a class="btn btn-sm <?= $st === $k ? 'btn-primary' : '' ?>" href="<?= h(au('instructors', ['status' => $k])) ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>
<div class="card table-wrap"><?php if(!$rows): ?><p class="muted">ไม่มีรายการ</p><?php else: ?><table>
  <tr><th>ผู้สอน</th><th>อีเมล</th><th>สถานะ</th><th class="num">คอร์ส</th><th class="num">อัตราแพลตฟอร์ม</th><th>อัปเดต</th><th></th></tr>
  <?php foreach($rows as $r): $rate = RevenueShareService::rateFor(0, $r['user_id']); ?>
  <tr><td><strong><?= h($r['display_name']) ?></strong><div class="small muted"><?= h($r['headline']) ?></div></td><td class="small"><?= h($r['email']) ?></td>
      <td><?= status_badge($r['status']) ?></td><td class="num"><?= (int)$r['n_courses'] ?></td>
      <td class="num"><?= h($rate['rate']) ?>%<?= $rate['scope'] === 'instructor' ? ' <span class="badge badge-primary">เฉพาะ</span>' : '' ?></td>
      <td class="small muted"><?= h($r['updated_at']) ?></td><td class="right"><a class="btn btn-sm" href="<?= h(au('instructor', ['id' => $r['user_id']])) ?>">เปิด</a></td></tr>
  <?php endforeach; ?>
</table><?php endif; ?></div>
