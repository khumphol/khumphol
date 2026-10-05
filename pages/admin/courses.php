<?php
$TITLE = 'คอร์ส';
$st = get('status');
$rows = db_all("SELECT c.*, COALESCE(ip.display_name, u.name) AS teacher,
                  (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS n_students
                FROM courses c JOIN users u ON u.id = c.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id
                ".($st !== '' ? "WHERE c.status = ?" : '')." ORDER BY FIELD(c.status,'pending_review','published','rejected','unpublished','draft'), c.updated_at DESC LIMIT 300",
               $st !== '' ? [$st] : []);
?>
<h1>คอร์ส</h1>
<div class="row mb">
  <?php foreach(['' => 'ทั้งหมด', 'pending_review' => 'รอตรวจ', 'published' => 'เผยแพร่', 'rejected' => 'ไม่ผ่าน', 'unpublished' => 'ปิดการขาย', 'draft' => 'ฉบับร่าง'] as $k => $v): ?>
    <a class="btn btn-sm <?= $st === $k ? 'btn-primary' : '' ?>" href="<?= h(au('courses', ['status' => $k])) ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>
<div class="card table-wrap"><?php if(!$rows): ?><p class="muted">ไม่มีรายการ</p><?php else: ?><table>
  <tr><th>คอร์ส</th><th>ผู้สอน</th><th>สถานะ</th><th class="num">ราคา</th><th class="num">ผู้เรียน</th><th class="num">อัตรา</th><th></th></tr>
  <?php foreach($rows as $c): $rate = RevenueShareService::rateFor($c['id'], $c['instructor_id']); ?>
  <tr><td><strong><?= h($c['title']) ?></strong><?php if($c['status'] === 'pending_review'): ?><div class="small muted">ส่งตรวจ <?= h($c['submitted_at']) ?></div><?php endif; ?></td>
      <td><?= h($c['teacher']) ?></td><td><?= status_badge($c['status']) ?></td><td class="num"><?= baht($c['price']) ?></td>
      <td class="num"><?= (int)$c['n_students'] ?></td>
      <td class="num"><?= h($rate['rate']) ?>%<?= $rate['scope'] === 'course' ? ' <span class="badge badge-primary">เฉพาะ</span>' : '' ?></td>
      <td class="right"><a class="btn btn-sm" href="<?= h(au('course', ['id' => $c['id']])) ?>"><?= $c['status'] === 'pending_review' ? 'ตรวจ' : 'เปิด' ?></a></td></tr>
  <?php endforeach; ?>
</table><?php endif; ?></div>
