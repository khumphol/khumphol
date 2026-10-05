<?php
$MENU = 'courses';
$uid = current_user_id();
$c = db_one("SELECT * FROM courses WHERE id = ? AND instructor_id = ?", [(int)get('course'), $uid]);
if(!$c || $c['status'] === 'pending_review'){ echo '<div class="card">ไม่พบคอร์ส หรือคอร์สอยู่ระหว่างรอตรวจ</div>'; return; }
$l = get('id') !== '' ? db_one("SELECT * FROM lessons WHERE id = ? AND course_id = ?", [(int)get('id'), (int)$c['id']]) : null;
$sections = db_all("SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id", [(int)$c['id']]);
$TITLE = $l ? 'แก้ไขบทเรียน' : 'เพิ่มบทเรียน';

if(is_post()){
    require_csrf();
    $secId = (int)post('section_id');
    if(!in_array($secId, array_map('intval', array_column($sections, 'id')), true)){ flash('เลือกบทไม่ถูกต้อง', 'danger'); redirect($_SERVER['REQUEST_URI']); }
    $type = post('type') === 'text' ? 'text' : 'video';
    $video = mb_substr(post('video_url'), 0, 500);
    if($video !== '' && !preg_match('~^https?://~i', $video)){ flash('ลิงก์วิดีโอต้องขึ้นต้นด้วย http(s)://', 'danger'); redirect($_SERVER['REQUEST_URI']); }
    $f = [$secId, mb_substr(post('title'), 0, 255) ?: 'บทเรียน', $type, $video, post('content'), max(0, (int)post('duration_min')), post('is_preview') ? 1 : 0];
    if($l){
        db_write("UPDATE lessons SET section_id=?, title=?, type=?, video_url=?, content=?, duration_min=?, is_preview=?, sort_order=? WHERE id=?",
            array_merge($f, [(int)post('sort_order'), (int)$l['id']]));
    } else {
        $n = (int)db_val("SELECT COALESCE(MAX(sort_order),0) FROM lessons WHERE section_id = ?", [$secId]);
        db_insert("INSERT INTO lessons (section_id, title, type, video_url, content, duration_min, is_preview, sort_order, course_id) VALUES (?,?,?,?,?,?,?,?,?)",
            array_merge($f, [$n + 1, (int)$c['id']]));
    }
    db_write("UPDATE courses SET updated_at = ? WHERE id = ?", [now(), (int)$c['id']]);
    flash('บันทึกบทเรียนแล้ว');
    redirect(iu('course-edit', ['id' => $c['id']]));
}
$secSel = $l ? (int)$l['section_id'] : (int)get('section');
?>
<a class="small" href="<?= h(iu('course-edit', ['id' => $c['id']])) ?>">← <?= h($c['title']) ?></a>
<h1><?= h($TITLE) ?></h1>
<div class="card">
<form method="post"><?= csrf_field() ?>
  <div class="grid g2">
    <div><label>บท</label><select name="section_id"><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $secSel === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
    <div><label>ประเภท</label><select name="type"><option value="video">วิดีโอ</option><option value="text" <?= ($l['type'] ?? '') === 'text' ? 'selected' : '' ?>>บทความ</option></select></div>
  </div>
  <label>ชื่อบทเรียน</label><input type="text" name="title" value="<?= h($l['title'] ?? '') ?>" required>
  <label>ลิงก์วิดีโอ (YouTube / Vimeo / ไฟล์ .mp4)</label><input type="url" name="video_url" value="<?= h($l['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=…">
  <label>เนื้อหา / คำอธิบาย</label><textarea name="content" rows="8"><?= h($l['content'] ?? '') ?></textarea>
  <div class="grid g3">
    <div><label>ความยาว (นาที)</label><input type="number" name="duration_min" min="0" value="<?= (int)($l['duration_min'] ?? 0) ?>"></div>
    <?php if($l): ?><div><label>ลำดับ</label><input type="number" name="sort_order" value="<?= (int)$l['sort_order'] ?>"></div><?php endif; ?>
    <div><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_preview" value="1" <?= !empty($l['is_preview']) ? 'checked' : '' ?>> ให้ดูฟรีก่อนซื้อ</label></div>
  </div>
  <button class="btn btn-primary mt">บันทึก</button>
</form>
</div>
