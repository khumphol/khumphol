<?php
$c = db_one("SELECT * FROM courses WHERE id = ?", [(int)get('course')]);
if(!$c){ echo '<div class="card">ไม่พบคอร์ส</div>'; return; }
$TITLE = $c['title'];
$uid = current_user_id();
$isOwner = $uid && ((int)$c['instructor_id'] === $uid || is_admin());
$enrolled = $uid && OrderService::isEnrolled($uid, $c['id']);

$sections = db_all("SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id", [$c['id']]);
$all = db_all("SELECT l.* FROM lessons l JOIN course_sections s ON s.id = l.section_id WHERE l.course_id = ? ORDER BY s.sort_order, s.id, l.sort_order, l.id", [$c['id']]);
if(!$all){ echo '<div class="card">คอร์สนี้ยังไม่มีบทเรียน</div>'; return; }
$lesson = null;
foreach($all as $l) if((int)$l['id'] === (int)get('lesson')) $lesson = $l;
if(!$lesson) $lesson = $all[0];
$canView = $enrolled || $isOwner || ((int)$lesson['is_preview'] === 1 && $c['status'] === 'published');
if(!$canView){
    if(!$uid) require_login();
    flash('ซื้อคอร์สก่อนเพื่อดูบทเรียนนี้', 'warning');
    redirect(u('course', ['slug' => $c['slug']]));
}

$done = [];
if($enrolled){
    // บันทึกว่าเปิดดูแล้ว / กดเรียนจบ
    db_write("INSERT INTO lesson_progress (user_id, course_id, lesson_id, last_seen_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)",
        [$uid, (int)$c['id'], (int)$lesson['id'], now()]);
    if(is_post() && post('action') === 'complete'){
        require_csrf();
        db_write("UPDATE lesson_progress SET completed_at = COALESCE(completed_at, ?) WHERE user_id = ? AND lesson_id = ?", [now(), $uid, (int)$lesson['id']]);
        // ไปบทถัดไป
        $ids = array_column($all, 'id'); $i = array_search($lesson['id'], $ids);
        redirect(u('learn', ['course' => $c['id'], 'lesson' => $ids[$i + 1] ?? $lesson['id']]));
    }
    if(is_post() && post('action') === 'review'){
        require_csrf();
        $rt = max(1, min(5, (int)post('rating')));
        db_write("INSERT INTO reviews (course_id, user_id, rating, body, created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), body = VALUES(body)",
            [(int)$c['id'], $uid, $rt, mb_substr(post('body'), 0, 2000), now()]);
        flash('ขอบคุณสำหรับรีวิว');
        redirect(u('learn', ['course' => $c['id'], 'lesson' => $lesson['id']]));
    }
    foreach(db_all("SELECT lesson_id FROM lesson_progress WHERE user_id = ? AND course_id = ? AND completed_at IS NOT NULL", [$uid, (int)$c['id']]) as $p) $done[(int)$p['lesson_id']] = 1;
}
$pct = count($all) ? round(count($done) * 100 / count($all)) : 0;
$embed = $lesson['video_url'] !== '' ? video_embed($lesson['video_url']) : null;
$myReview = $enrolled ? db_one("SELECT * FROM reviews WHERE course_id = ? AND user_id = ?", [(int)$c['id'], $uid]) : null;
?>
<div class="learn">
  <div>
    <div class="small muted"><a href="<?= h(u('course', ['slug' => $c['slug']])) ?>"><?= h($c['title']) ?></a></div>
    <h1><?= h($lesson['title']) ?></h1>
    <?php if($lesson['type'] === 'video' && $lesson['video_url'] !== ''): ?>
      <div class="player">
        <?php if($embed): ?><iframe src="<?= h($embed) ?>" allow="accelerometer; autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
        <?php else: ?><video src="<?= h($lesson['video_url']) ?>" controls controlsList="nodownload" preload="metadata"></video><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if(trim((string)$lesson['content']) !== ''): ?><div class="card mt prose"><?= h($lesson['content']) ?></div><?php endif; ?>
    <?php if($enrolled): ?>
      <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="action" value="complete">
        <button class="btn btn-primary"><?= isset($done[(int)$lesson['id']]) ? '✓ เรียนแล้ว — ไปบทถัดไป' : 'ทำเครื่องหมายว่าเรียนจบ' ?></button></form>
      <div class="card mt">
        <h2>รีวิวคอร์สนี้</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="review">
          <select name="rating" style="max-width:160px"><?php for($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= ($myReview['rating'] ?? 5) == $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?></option><?php endfor; ?></select>
          <textarea name="body" class="mt" placeholder="เล่าประสบการณ์การเรียน…"><?= h($myReview['body'] ?? '') ?></textarea>
          <button class="btn mt">บันทึกรีวิว</button></form>
      </div>
    <?php elseif(!$isOwner): ?>
      <div class="alert alert-info mt">นี่คือบทเรียนตัวอย่าง — <a href="<?= h(u('checkout', ['course' => $c['id']])) ?>">ซื้อคอร์ส</a> เพื่อเรียนครบทุกบท</div>
    <?php endif; ?>
  </div>
  <aside class="card curriculum">
    <?php if($enrolled): ?><div class="small muted">ความคืบหน้า <?= $pct ?>%</div><div class="progress"><span style="width:<?= $pct ?>%"></span></div><?php endif; ?>
    <?php foreach($sections as $s): ?>
      <div class="sec"><?= h($s['title']) ?></div>
      <?php foreach($all as $l): if((int)$l['section_id'] !== (int)$s['id']) continue;
        $open = $enrolled || $isOwner || (int)$l['is_preview'] === 1; ?>
        <a class="<?= (int)$l['id'] === (int)$lesson['id'] ? 'on' : '' ?>" <?= $open ? 'href="'.h(u('learn', ['course' => $c['id'], 'lesson' => $l['id']])).'"' : 'style="color:var(--muted)"' ?>>
          <span class="tick"><?= isset($done[(int)$l['id']]) ? '✓' : ($open ? '' : '🔒') ?></span><?= h($l['title']) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </aside>
</div>
