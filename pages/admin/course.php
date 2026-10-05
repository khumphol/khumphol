<?php
$MENU = 'courses';
$c = db_one("SELECT c.*, COALESCE(ip.display_name, u.name) AS teacher FROM courses c JOIN users u ON u.id = c.instructor_id
             LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id WHERE c.id = ?", [(int)get('id')]);
if(!$c){ echo '<div class="card">ไม่พบคอร์ส</div>'; return; }
$TITLE = $c['title'];
$back = au('course', ['id' => $c['id']]);
require __DIR__.'/_rate_post.php';
rate_handle_post('course', (int)$c['id'], $back);
if(is_post()){
    require_csrf();
    $a = post('action'); $note = mb_substr(post('note'), 0, 500);
    $to = null;
    if($a === 'approve' && in_array($c['status'], ['pending_review', 'unpublished'], true)) $to = 'published';
    if($a === 'reject' && $c['status'] === 'pending_review'){ if($note === ''){ flash('กรุณาระบุเหตุผล', 'danger'); redirect($back); } $to = 'rejected'; }
    if($a === 'unpublish' && $c['status'] === 'published') $to = 'unpublished';
    if($to){
        db_write("UPDATE courses SET status = ?, review_note = ?, reviewed_by = ?, published_at = IF(? = 'published', COALESCE(published_at, ?), published_at), updated_at = ? WHERE id = ?",
            [$to, $note, current_user_id(), $to, now(), now(), (int)$c['id']]);
        audit('course_'.$a, 'course', (int)$c['id'], ['status' => $c['status']], ['status' => $to, 'note' => $note]);
        MailService::courseStatus((int)$c['id'], $to, $note);
        flash('อัปเดตสถานะคอร์สแล้ว');
    }
    redirect($back);
}
$sections = db_all("SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id", [(int)$c['id']]);
$lessons = [];
foreach(db_all("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order, id", [(int)$c['id']]) as $l) $lessons[$l['section_id']][] = $l;
$sales = db_one("SELECT COUNT(*) n, COALESCE(SUM(paid_amount),0) gross, COALESCE(SUM(platform_amount),0) platform FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.course_id = ? AND o.paid_at IS NOT NULL AND oi.refunded_at IS NULL", [(int)$c['id']]);
$rateScope = 'course'; $rateTarget = (int)$c['id']; $rateEffective = RevenueShareService::rateFor($c['id'], $c['instructor_id']);
?>
<a class="small" href="<?= h(au('courses')) ?>">← คอร์สทั้งหมด</a>
<h1><?= h($c['title']) ?> <?= status_badge($c['status']) ?></h1>
<p class="muted">โดย <a href="<?= h(au('instructor', ['id' => $c['instructor_id']])) ?>"><?= h($c['teacher']) ?></a> · <?= baht($c['price']) ?>
  · ขายได้ <?= (int)$sales['n'] ?> (<?= baht($sales['gross']) ?>, แพลตฟอร์ม <?= baht($sales['platform']) ?>)
  · <a href="<?= h(u('course', ['slug' => $c['slug']])) ?>" target="_blank">ดูหน้าคอร์ส ↗</a></p>
<div class="grid g2" style="align-items:start">
  <div class="card">
    <h2>รายละเอียด</h2>
    <img src="<?= h(cover_url($c)) ?>" alt="" style="border-radius:9px;max-width:320px">
    <p><strong><?= h($c['subtitle']) ?></strong></p><div class="prose small"><?= h($c['description']) ?></div>
    <h2 class="mt">เนื้อหา</h2>
    <?php foreach($sections as $s): ?><div class="small"><strong><?= h($s['title']) ?></strong>
      <ul style="margin:.2rem 0 .6rem"><?php foreach($lessons[$s['id']] ?? [] as $l): ?>
        <li><a href="<?= h(u('learn', ['course' => $c['id'], 'lesson' => $l['id']])) ?>" target="_blank"><?= h($l['title']) ?></a> <span class="muted">(<?= $l['type'] === 'video' ? 'วิดีโอ' : 'บทความ' ?>, <?= (int)$l['duration_min'] ?> นาที<?= $l['is_preview'] ? ', ดูฟรี' : '' ?>)</span></li>
      <?php endforeach; ?></ul></div><?php endforeach; ?>
  </div>
  <div class="card">
    <h2>การตรวจ</h2>
    <?php if($c['review_note']): ?><p class="small">หมายเหตุล่าสุด: <?= h($c['review_note']) ?></p><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
      <input type="text" name="note" placeholder="หมายเหตุถึงผู้สอน (จำเป็นเมื่อไม่ผ่าน)">
      <div class="row mt">
        <?php if($c['status'] === 'pending_review'): ?>
          <button class="btn btn-primary" name="action" value="approve">อนุมัติ & เผยแพร่</button>
          <button class="btn btn-danger" name="action" value="reject">ไม่ผ่าน</button>
        <?php elseif($c['status'] === 'published'): ?>
          <button class="btn btn-danger" name="action" value="unpublish" onclick="return confirm('ปิดการขายคอร์สนี้? ผู้ที่ซื้อแล้วยังเรียนได้')">ปิดการขาย</button>
        <?php elseif($c['status'] === 'unpublished'): ?>
          <button class="btn btn-primary" name="action" value="approve">เผยแพร่อีกครั้ง</button>
        <?php else: ?><span class="muted small">ผู้สอนยังไม่ได้ส่งตรวจ</span><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<div class="mt"><?php require __DIR__.'/_rate.php'; ?></div>
