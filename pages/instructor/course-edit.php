<?php
$MENU = 'courses';
$uid = current_user_id();
$c = db_one("SELECT * FROM courses WHERE id = ? AND instructor_id = ?", [(int)get('id'), $uid]);
if(!$c){ echo '<div class="card">ไม่พบคอร์ส</div>'; return; }
$TITLE = 'แก้ไข: '.$c['title'];
$locked = $c['status'] === 'pending_review';
$back = iu('course-edit', ['id' => $c['id']]);

if(is_post()){
    require_csrf();
    $a = post('action');
    if($a === 'withdraw' && $locked){
        db_write("UPDATE courses SET status = 'draft', updated_at = ? WHERE id = ?", [now(), (int)$c['id']]);
        flash('ถอนคำขอตรวจแล้ว แก้ไขต่อได้'); redirect($back);
    }
    if($locked){ flash('คอร์สอยู่ระหว่างรอตรวจ — ถอนคำขอก่อนจึงจะแก้ไขได้', 'warning'); redirect($back); }
    switch($a){
        case 'details':
            $price = PermissionService::can($uid, 'courses.pricing') ? round(max(0, (float)post('price')), 2) : (float)$c['price'];
            if($price > 0 && $price < gwMinAmount()){ flash('ราคาขั้นต่ำ '.gwMinAmount().' บาท (หรือ 0 = ฟรี)', 'danger'); redirect($back); }
            $cover = upload_image('cover', 'covers');
            $title = mb_substr(post('title'), 0, 255) ?: $c['title'];
            $level = in_array(post('level'), ['all','beginner','intermediate','advanced'], true) ? post('level') : 'all';
            db_write("UPDATE courses SET title = ?, subtitle = ?, description = ?, level = ?, price = ?, cover = IF(? = '', cover, ?), updated_at = ? WHERE id = ?",
                [$title, mb_substr(post('subtitle'), 0, 255), post('description'), $level, number_format($price, 2, '.', ''), $cover, $cover, now(), (int)$c['id']]);
            flash('บันทึกแล้ว'); break;
        case 'add_section':
            $n = (int)db_val("SELECT COALESCE(MAX(sort_order),0) FROM course_sections WHERE course_id = ?", [(int)$c['id']]);
            db_insert("INSERT INTO course_sections (course_id, title, sort_order) VALUES (?,?,?)", [(int)$c['id'], mb_substr(post('title') ?: 'บทใหม่', 0, 255), $n + 1]);
            break;
        case 'save_section':
            db_write("UPDATE course_sections SET title = ?, sort_order = ? WHERE id = ? AND course_id = ?",
                [mb_substr(post('title'), 0, 255), (int)post('sort_order'), (int)post('section_id'), (int)$c['id']]);
            break;
        case 'delete_section':
            db_write("DELETE FROM course_sections WHERE id = ? AND course_id = ?", [(int)post('section_id'), (int)$c['id']]);
            flash('ลบบทแล้ว'); break;
        case 'delete_lesson':
            db_write("DELETE FROM lessons WHERE id = ? AND course_id = ?", [(int)post('lesson_id'), (int)$c['id']]);
            flash('ลบบทเรียนแล้ว'); break;
        case 'vc':
            if(!PermissionService::can($uid, 'vc')){ flash('ไม่มีสิทธิ์ใช้ห้องเรียนเสมือน', 'danger'); break; }
            db_write("UPDATE courses SET vc_enabled = ? WHERE id = ?", [post('vc_enabled') ? 1 : 0, (int)$c['id']]);
            flash('บันทึกห้องเรียนเสมือนแล้ว'); break;
        case 'submit':
            if(!PermissionService::can($uid, 'courses.submit')){ flash('บัญชีของคุณยังไม่ได้รับสิทธิ์ส่งคอร์สตรวจ — ติดต่อผู้ดูแลระบบ', 'danger'); break; }
            $n = (int)db_val("SELECT COUNT(*) FROM lessons WHERE course_id = ?", [(int)$c['id']]);
            if($n === 0 || trim((string)$c['description']) === ''){ flash('ต้องมีคำอธิบายคอร์สและบทเรียนอย่างน้อย 1 บทก่อนส่งตรวจ', 'danger'); break; }
            db_write("UPDATE courses SET status = 'pending_review', submitted_at = ?, updated_at = ? WHERE id = ?", [now(), now(), (int)$c['id']]);
            flash('ส่งให้ทีมงานตรวจแล้ว'); break;
    }
    db_write("UPDATE courses SET updated_at = ? WHERE id = ?", [now(), (int)$c['id']]);
    redirect($back);
}
$sections = db_all("SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id", [(int)$c['id']]);
$lessons = [];
foreach(db_all("SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order, id", [(int)$c['id']]) as $l) $lessons[$l['section_id']][] = $l;
$rate = RevenueShareService::rateFor($c['id'], $uid);
$dis = $locked ? 'disabled' : '';
?>
<div class="row between mb">
  <div><a class="small" href="<?= h(iu('courses')) ?>">← คอร์สของฉัน</a><h1><?= h($c['title']) ?> <?= status_badge($c['status']) ?></h1></div>
  <div class="row">
    <a class="btn" href="<?= h(u('course', ['slug' => $c['slug']])) ?>" target="_blank">ดูตัวอย่าง</a>
    <?php if($locked): ?>
      <form method="post" class="inline"><?= csrf_field() ?><button class="btn" name="action" value="withdraw">ถอนคำขอตรวจ</button></form>
    <?php elseif($c['status'] !== 'published' && PermissionService::can($uid, 'courses.submit')): ?>
      <form method="post" class="inline"><?= csrf_field() ?><button class="btn btn-primary" name="action" value="submit">ส่งตรวจเพื่อเผยแพร่</button></form>
    <?php endif; ?>
  </div>
</div>
<?php if($locked): ?><div class="alert alert-info">คอร์สอยู่ระหว่างรอทีมงานตรวจ — แก้ไขไม่ได้ชั่วคราว</div><?php endif; ?>
<?php if($c['status'] === 'rejected'): ?><div class="alert alert-danger">ไม่ผ่านการตรวจ: <?= h($c['review_note']) ?> — แก้ไขแล้วส่งตรวจใหม่ได้</div><?php endif; ?>
<?php if($c['status'] === 'published'): ?><div class="alert alert-info">คอร์สเผยแพร่แล้ว — การแก้ไขเนื้อหามีผลทันที</div><?php endif; ?>

<div class="card">
  <h2>ข้อมูลคอร์ส</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="details">
    <fieldset style="border:0;padding:0;margin:0" <?= $dis ?>>
    <label>ชื่อคอร์ส</label><input type="text" name="title" value="<?= h($c['title']) ?>" required>
    <label>คำโปรย</label><input type="text" name="subtitle" value="<?= h($c['subtitle']) ?>">
    <label>รายละเอียด</label><textarea name="description" rows="6"><?= h($c['description']) ?></textarea>
    <div class="grid g3">
      <div><label>ระดับ</label><select name="level"><?php foreach(['all' => 'ทุกระดับ','beginner' => 'เริ่มต้น','intermediate' => 'กลาง','advanced' => 'สูง'] as $k => $v): ?><option value="<?= $k ?>" <?= $c['level'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>ราคา (บาท, 0 = ฟรี)</label><input type="number" name="price" min="0" step="0.01" value="<?= h($c['price']) ?>" <?= PermissionService::can($uid, 'courses.pricing') ? '' : 'disabled title="ราคากำหนดโดยผู้ดูแลระบบ"' ?>></div>
      <div><label>ภาพปก (16:9)</label><input type="file" name="cover" accept="image/*"></div>
    </div>
    <p class="small muted">ส่วนแบ่งแพลตฟอร์มปัจจุบันของคอร์สนี้: <strong><?= h($rate['rate']) ?>%</strong> ของยอดหลังหักค่าธรรมเนียมชำระเงิน</p>
    <button class="btn btn-primary">บันทึก</button>
    </fieldset>
  </form>
</div>

<div class="card">
  <div class="row between"><h2>เนื้อหา</h2>
    <?php if(!$locked): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="add_section"><input type="text" name="title" placeholder="ชื่อบทใหม่" style="max-width:220px"><button class="btn btn-sm">+ เพิ่มบท</button></form><?php endif; ?>
  </div>
  <?php foreach($sections as $s): ?>
    <div style="border:1px solid var(--border);border-radius:10px;padding:.75rem;margin-top:.75rem">
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="section_id" value="<?= (int)$s['id'] ?>">
        <input type="number" name="sort_order" value="<?= (int)$s['sort_order'] ?>" style="width:70px" <?= $dis ?> title="ลำดับ">
        <input type="text" name="title" value="<?= h($s['title']) ?>" style="flex:1;min-width:160px" <?= $dis ?>>
        <?php if(!$locked): ?><button class="btn btn-sm" name="action" value="save_section">บันทึก</button>
        <button class="btn btn-sm btn-danger" name="action" value="delete_section" onclick="return confirm('ลบบทนี้และบทเรียนข้างในทั้งหมด?')">ลบ</button><?php endif; ?>
      </form>
      <table class="mt">
        <?php foreach($lessons[$s['id']] ?? [] as $l): ?>
        <tr><td style="width:40px" class="muted"><?= (int)$l['sort_order'] ?></td>
            <td><?= h($l['title']) ?> <span class="badge badge-gray"><?= h(lesson_type_label($l['type'])) ?></span><?= $l['is_preview'] ? ' <span class="badge badge-primary">ดูฟรี</span>' : '' ?></td>
            <td class="num small muted"><?= (int)$l['duration_min'] ?> นาที</td>
            <td class="right nowrap"><?php if(!$locked): ?><a class="btn btn-sm" href="<?= h(iu('lesson-edit', ['course' => $c['id'], 'id' => $l['id']])) ?>">แก้ไข</a>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="lesson_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-danger" name="action" value="delete_lesson" onclick="return confirm('ลบบทเรียนนี้?')">ลบ</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php if(!$locked): ?><a class="btn btn-sm mt" href="<?= h(iu('lesson-edit', ['course' => $c['id'], 'section' => $s['id']])) ?>">+ เพิ่มบทเรียน</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if(PermissionService::can($uid, 'vc')): ?>
<div class="card">
  <h2><i class="fi fi-rr-users-alt"></i> ห้องเรียนเสมือน (Aleanor VC)</h2>
  <?php if(!IntegrationService::vcEnabled()): ?><p class="small muted">ผู้ดูแลระบบยังไม่ได้ตั้งค่าการเชื่อมต่อ VC</p><?php endif; ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="vc">
    <label class="tgl" style="margin:0"><input type="checkbox" name="vc_enabled" value="1" <?= (int)$c['vc_enabled'] ? 'checked' : '' ?> <?= $dis ?>><span class="tgl-track"></span> เปิดห้องให้ผู้เรียนของคอร์สนี้</label>
    <span class="small muted">ห้อง: course-<?= (int)$c['id'] ?></span>
    <button class="btn btn-sm" <?= $dis ?>>บันทึก</button>
    <?php if((int)$c['vc_enabled'] && IntegrationService::vcEnabled()): ?><a class="btn btn-sm btn-outline" href="<?= h(asset('vc.php').'?course='.(int)$c['id']) ?>" target="_blank">เข้าห้องในฐานะผู้สอน ↗</a><?php endif; ?>
  </form>
</div>
<?php endif; ?>
