<?php
$c = db_one("SELECT c.*, COALESCE(ip.display_name, u.name) AS teacher, ip.headline, ip.bio
             FROM courses c JOIN users u ON u.id = c.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id
             WHERE c.slug = ?", [get('slug')]);
// คอร์สที่ยังไม่เผยแพร่ ให้ดูได้เฉพาะเจ้าของ/แอดมิน (preview)
$canPreview = $c && (is_admin() || (int)$c['instructor_id'] === current_user_id());
if(!$c || ($c['status'] !== 'published' && !$canPreview)){ http_response_code(404); echo '<div class="card"><h1>ไม่พบคอร์ส</h1></div>'; return; }
$TITLE = $c['title'];
$me = current_user();
$enrolled = $me && OrderService::isEnrolled($me['id'], $c['id']);

// คอร์สฟรี: ลงทะเบียนทันที
if(is_post() && post('action') === 'enroll_free'){
    require_csrf(); require_login();
    if((float)$c['price'] <= 0 && $c['status'] === 'published'){ OrderService::enrollFree($me['id'], $c['id']); flash('ลงทะเบียนเรียบร้อย'); }
    redirect(u('learn', ['course' => $c['id']]));
}
$sections = db_all("SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id", [$c['id']]);
$lessons = [];
foreach(db_all("SELECT id, section_id, title, type, duration_min, is_preview FROM lessons WHERE course_id = ? ORDER BY sort_order, id", [$c['id']]) as $l) $lessons[$l['section_id']][] = $l;
$totalMin = (int)db_val("SELECT COALESCE(SUM(duration_min),0) FROM lessons WHERE course_id = ?", [$c['id']]);
$nLessons = (int)db_val("SELECT COUNT(*) FROM lessons WHERE course_id = ?", [$c['id']]);
$rating = db_one("SELECT AVG(rating) avg, COUNT(*) n FROM reviews WHERE course_id = ?", [$c['id']]);
?>
<?php if($c['status'] !== 'published'): ?><div class="alert alert-warning">ตัวอย่างก่อนเผยแพร่ — สถานะ: <?= status_badge($c['status']) ?></div><?php endif; ?>
<div class="course-layout">
  <div>
    <h1><?= h($c['title']) ?></h1>
    <?php if($c['subtitle']): ?><p class="muted"><?= h($c['subtitle']) ?></p><?php endif; ?>
    <p class="small muted">สอนโดย <strong><?= h($c['teacher']) ?></strong> · <?= $nLessons ?> บทเรียน · <?= $totalMin ?> นาที
      <?php if((int)$rating['n']): ?> · ★ <?= number_format($rating['avg'], 1) ?> (<?= (int)$rating['n'] ?>)<?php endif; ?></p>
    <div class="card mt"><h2>รายละเอียดคอร์ส</h2><div class="prose"><?= h($c['description']) ?></div></div>
    <div class="card curriculum">
      <h2>เนื้อหาในคอร์ส</h2>
      <?php foreach($sections as $s): ?>
        <div class="sec"><?= h($s['title']) ?></div>
        <?php foreach($lessons[$s['id']] ?? [] as $l): ?>
          <?php if($l['is_preview'] || $enrolled || $canPreview): ?>
            <a href="<?= h(u('learn', ['course' => $c['id'], 'lesson' => $l['id']])) ?>"><span class="tick">▶</span><?= h($l['title']) ?>
              <?php if($l['is_preview'] && !$enrolled): ?><span class="badge badge-primary">ดูฟรี</span><?php endif; ?>
              <span class="small muted" style="margin-left:auto"><?= (int)$l['duration_min'] ?> นาที</span></a>
          <?php else: ?>
            <a style="pointer-events:none;color:var(--muted)"><span class="tick">🔒</span><?= h($l['title']) ?><span class="small" style="margin-left:auto"><?= (int)$l['duration_min'] ?> นาที</span></a>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
    <?php if($c['bio']): ?><div class="card"><h2>เกี่ยวกับผู้สอน</h2><strong><?= h($c['teacher']) ?></strong> <span class="muted"><?= h($c['headline']) ?></span><p class="prose"><?= h($c['bio']) ?></p></div><?php endif; ?>
  </div>
  <div class="card buybox">
    <img src="<?= h(cover_url($c)) ?>" alt="" style="border-radius:10px;aspect-ratio:16/9;object-fit:cover;width:100%">
    <div style="font-size:1.6rem;font-weight:700;margin:.6rem 0"><?= (float)$c['price'] > 0 ? baht($c['price']) : 'ฟรี' ?></div>
    <?php if($enrolled): ?>
      <a class="btn btn-primary btn-block" href="<?= h(u('learn', ['course' => $c['id']])) ?>">เข้าเรียน</a>
    <?php elseif($c['status'] !== 'published'): ?>
      <button class="btn btn-block" disabled>ยังไม่เปิดขาย</button>
    <?php elseif((float)$c['price'] <= 0): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enroll_free"><button class="btn btn-primary btn-block">ลงทะเบียนเรียนฟรี</button></form>
    <?php else: ?>
      <a class="btn btn-primary btn-block" href="<?= h(u('checkout', ['course' => $c['id']])) ?>">ซื้อคอร์สนี้</a>
    <?php endif; ?>
  </div>
</div>
