<?php
$TITLE = 'สมัครสมาชิก';
if(current_user()) redirect(u('my-learning'));
$err = '';
if(is_post()){
    require_csrf();
    $email = mb_strtolower(post('email')); $name = post('name'); $pw = post('password');
    if($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'กรุณากรอกชื่อและอีเมลให้ถูกต้อง';
    elseif(mb_strlen($pw) < 8) $err = 'รหัสผ่านอย่างน้อย 8 ตัวอักษร';
    elseif(db_val("SELECT id FROM users WHERE email = ?", [$email])) $err = 'อีเมลนี้มีบัญชีอยู่แล้ว';
    else {
        $id = db_insert("INSERT INTO users (email, password_hash, name, created_at) VALUES (?,?,?,?)", [$email, shHashPassword($pw), mb_substr($name, 0, 150), now()]);
        login_user($id);
        flash('สมัครสมาชิกเรียบร้อย ยินดีต้อนรับ!');
        redirect(u('courses'));
    }
}
?>
<div class="auth card">
  <h1>สมัครสมาชิก</h1><p class="auth-sub">สมัครฟรี เริ่มเรียนคอร์สตัวอย่างได้ทันที</p>
  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>ชื่อ-นามสกุล</label><input type="text" name="name" value="<?= h(post('name')) ?>" required>
    <label>อีเมล</label><input type="email" name="email" value="<?= h(post('email')) ?>" required>
    <label>รหัสผ่าน (อย่างน้อย 8 ตัว)</label><input type="password" name="password" minlength="8" required>
    <button class="btn btn-primary btn-lg btn-block mt">สมัครสมาชิก</button>
  </form>
  <p class="small muted mt text-center">มีบัญชีแล้ว? <a href="<?= h(u('login')) ?>">เข้าสู่ระบบ</a></p>
</div>
