<?php
$TITLE = 'โปรไฟล์ผู้สอน';
$uid = current_user_id();
$p = db_one("SELECT * FROM instructor_profiles WHERE user_id = ?", [$uid]);
if(is_post()){
    require_csrf();
    db_write("UPDATE instructor_profiles SET display_name=?, headline=?, bio=?, expertise=?, bank_name=?, bank_account_no=?, bank_account_name=?, updated_at=? WHERE user_id=?", [
        mb_substr(post('display_name'), 0, 150) ?: $p['display_name'], mb_substr(post('headline'), 0, 255), post('bio'), mb_substr(post('expertise'), 0, 255),
        mb_substr(post('bank_name'), 0, 100), preg_replace('~[^0-9-]~', '', post('bank_account_no')), mb_substr(post('bank_account_name'), 0, 150), now(), $uid]);
    flash('บันทึกแล้ว'); redirect(iu('profile'));
}
?>
<h1>โปรไฟล์ & บัญชีรับเงิน</h1>
<form method="post" class="card"><?= csrf_field() ?>
  <h2>โปรไฟล์ที่แสดงในหน้าคอร์ส</h2>
  <label>ชื่อที่แสดง</label><input type="text" name="display_name" value="<?= h($p['display_name']) ?>">
  <label>Headline</label><input type="text" name="headline" value="<?= h($p['headline']) ?>">
  <label>ความเชี่ยวชาญ</label><input type="text" name="expertise" value="<?= h($p['expertise']) ?>">
  <label>แนะนำตัว</label><textarea name="bio"><?= h($p['bio']) ?></textarea>
  <h2 class="mt2">บัญชีรับเงิน</h2>
  <div class="grid g3">
    <div><label>ธนาคาร</label><input type="text" name="bank_name" value="<?= h($p['bank_name']) ?>"></div>
    <div><label>เลขที่บัญชี</label><input type="text" name="bank_account_no" value="<?= h($p['bank_account_no']) ?>"></div>
    <div><label>ชื่อบัญชี</label><input type="text" name="bank_account_name" value="<?= h($p['bank_account_name']) ?>"></div>
  </div>
  <button class="btn btn-primary mt">บันทึก</button>
</form>
