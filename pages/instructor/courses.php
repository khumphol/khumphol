<?php
$TITLE = 'คอร์สของฉัน';
$uid = current_user_id();
if(is_post() && post('action') === 'create'){
    require_csrf();
    $title = mb_substr(post('title'), 0, 255);
    if($title === ''){ flash('กรุณาตั้งชื่อคอร์ส', 'danger'); redirect(iu('courses')); }
    $id = db_insert("INSERT INTO courses (instructor_id, slug, title, description, status, created_at, updated_at) VALUES (?,?,?, '', 'draft', ?, ?)",
        [$uid, make_slug($title), $title, now(), now()]);
    db_insert("INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, 'บทที่ 1', 1)", [$id]);
    redirect(iu('course-edit', ['id' => $id]));
}
$courses = db_all("SELECT c.*, (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS n_lessons,
                     (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS n_students
                   FROM courses c WHERE c.instructor_id = ? ORDER BY c.updated_at DESC", [$uid]);
?>
<div class="row between mb"><h1>คอร์สของฉัน</h1></div>
<div class="card mb">
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <input type="text" name="title" placeholder="ชื่อคอร์สใหม่" required style="max-width:420px"><button class="btn btn-primary">+ สร้างคอร์ส</button></form>
</div>
<div class="card table-wrap">
  <?php if(!$courses): ?><p class="muted">ยังไม่มีคอร์ส</p><?php else: ?>
  <table>
    <tr><th>คอร์ส</th><th>สถานะ</th><th class="num">ราคา</th><th class="num">บทเรียน</th><th class="num">ผู้เรียน</th><th></th></tr>
    <?php foreach($courses as $c): ?>
    <tr><td><strong><?= h($c['title']) ?></strong><?php if($c['status'] === 'rejected' && $c['review_note']): ?><div class="small" style="color:var(--red)">เหตุผล: <?= h($c['review_note']) ?></div><?php endif; ?></td>
        <td><?= status_badge($c['status']) ?></td><td class="num"><?= baht($c['price']) ?></td>
        <td class="num"><?= (int)$c['n_lessons'] ?></td><td class="num"><?= (int)$c['n_students'] ?></td>
        <td class="right nowrap"><a class="btn btn-sm" href="<?= h(iu('course-edit', ['id' => $c['id']])) ?>">แก้ไข</a>
          <a class="btn btn-sm" href="<?= h(u('course', ['slug' => $c['slug']])) ?>">ดูหน้าคอร์ส</a></td></tr>
    <?php endforeach; ?>
  </table><?php endif; ?>
</div>
