<?php
$TITLE = $PAGE === 'courses' ? 'คอร์สทั้งหมด' : '';
$q = get('q');
$params = []; $where = "c.status = 'published'";
if($q !== ''){ $where .= " AND (c.title LIKE ? OR c.subtitle LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
$courses = db_all("SELECT c.*, COALESCE(ip.display_name, u.name) AS teacher,
                     (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS students
                   FROM courses c JOIN users u ON u.id = c.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id
                   WHERE $where ORDER BY c.published_at DESC LIMIT 60", $params);
?>
<?php if($PAGE === 'home'): ?>
<section class="hero">
  <h1>เรียนจากผู้สอนตัวจริง<br>ในที่เดียว</h1>
  <p class="muted">คอร์สออนไลน์จากผู้สอนหลากหลายสาขา — เรียนได้ทุกที่ ทุกเวลา</p>
  <?php if(!is_instructor()): ?><a class="btn" href="<?= h(u('become-instructor')) ?>">อยากสอน? สมัครเป็นผู้สอน →</a><?php endif; ?>
</section>
<?php endif; ?>
<form class="row mb" method="get">
  <input type="hidden" name="p" value="courses">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหาคอร์ส…" style="max-width:360px">
  <button class="btn">ค้นหา</button>
</form>
<?php if(!$courses): ?>
  <div class="card muted">ยังไม่มีคอร์สที่เผยแพร่<?= $q !== '' ? 'ตามคำค้นนี้' : '' ?></div>
<?php else: ?>
<div class="grid g3">
  <?php foreach($courses as $c): ?>
  <a class="course-card" href="<?= h(u('course', ['slug' => $c['slug']])) ?>">
    <img src="<?= h(cover_url($c)) ?>" alt="">
    <div class="body">
      <div class="title"><?= h($c['title']) ?></div>
      <div class="small muted"><?= h($c['teacher']) ?> · ผู้เรียน <?= (int)$c['students'] ?></div>
      <div class="price"><?= (float)$c['price'] > 0 ? baht($c['price']) : 'ฟรี' ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
