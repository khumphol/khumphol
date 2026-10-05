<?php
$TITLE = 'สมัครเป็นผู้สอน';
require_login();
$me = current_user();
$prof = db_one("SELECT * FROM instructor_profiles WHERE user_id = ?", [(int)$me['id']]);
if($prof && $prof['status'] === 'approved') redirect(iu());

if(is_post() && (!$prof || $prof['status'] === 'rejected')){
    require_csrf();
    $f = ['display_name' => mb_substr(post('display_name'), 0, 150), 'headline' => mb_substr(post('headline'), 0, 255), 'bio' => post('bio'),
          'expertise' => mb_substr(post('expertise'), 0, 255), 'sample_url' => mb_substr(post('sample_url'), 0, 500)];
    if($f['display_name'] === '' || $f['bio'] === '') { flash('กรุณากรอกชื่อที่แสดงและแนะนำตัว', 'danger'); }
    else {
        if($prof){
            db_write("UPDATE instructor_profiles SET display_name=?, headline=?, bio=?, expertise=?, sample_url=?, status='pending', review_note='', updated_at=? WHERE id=?",
                [$f['display_name'], $f['headline'], $f['bio'], $f['expertise'], $f['sample_url'], now(), (int)$prof['id']]);
        } else {
            db_insert("INSERT INTO instructor_profiles (user_id, display_name, headline, bio, expertise, sample_url, status, created_at, updated_at) VALUES (?,?,?,?,?,?,'pending',?,?)",
                [(int)$me['id'], $f['display_name'], $f['headline'], $f['bio'], $f['expertise'], $f['sample_url'], now(), now()]);
        }
        flash('ส่งใบสมัครแล้ว ทีมงานจะตรวจสอบโดยเร็ว');
        redirect(u('become-instructor'));
    }
}
?>
<div class="card" style="max-width:680px;margin:0 auto">
  <h1>สมัครเป็นผู้สอน</h1>
  <?php if($prof && $prof['status'] === 'pending'): ?>
    <div class="alert alert-info">ใบสมัครของคุณอยู่ระหว่างการตรวจสอบ (ส่งเมื่อ <?= h($prof['updated_at']) ?>)</div>
  <?php elseif($prof && $prof['status'] === 'suspended'): ?>
    <div class="alert alert-danger">บัญชีผู้สอนของคุณถูกระงับ กรุณาติดต่อผู้ดูแลระบบ</div>
  <?php else: ?>
    <?php if($prof && $prof['status'] === 'rejected'): ?><div class="alert alert-warning">ใบสมัครก่อนหน้าไม่ผ่าน: <?= h($prof['review_note']) ?> — แก้ไขแล้วส่งใหม่ได้</div><?php endif; ?>
    <p class="muted">สร้างคอร์สและขายบนแพลตฟอร์ม แบ่งรายได้อัตโนมัติ ทีมงานจะตรวจใบสมัครก่อนเปิดสิทธิ์</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>ชื่อที่แสดง</label><input type="text" name="display_name" value="<?= h($prof['display_name'] ?? $me['name']) ?>" required>
      <label>คำอธิบายสั้น (headline)</label><input type="text" name="headline" value="<?= h($prof['headline'] ?? '') ?>" placeholder="เช่น วิศวกรซอฟต์แวร์ 10 ปี">
      <label>ความเชี่ยวชาญ</label><input type="text" name="expertise" value="<?= h($prof['expertise'] ?? '') ?>">
      <label>แนะนำตัว</label><textarea name="bio" required><?= h($prof['bio'] ?? '') ?></textarea>
      <label>ลิงก์ผลงาน / ตัวอย่างการสอน</label><input type="url" name="sample_url" value="<?= h($prof['sample_url'] ?? '') ?>">
      <button class="btn btn-primary mt">ส่งใบสมัคร</button>
    </form>
  <?php endif; ?>
</div>
