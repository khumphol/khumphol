<?php
$TITLE = 'เข้าสู่ระบบ';
if(current_user()) redirect(u('my-learning'));
$err = '';
if(is_post()){
    require_csrf();
    // กันเดารหัส: เกิน 5 ครั้งใน 10 นาทีต่อ session ให้รอ
    $_SESSION['login_fail'] = array_filter($_SESSION['login_fail'] ?? [], function($t){ return $t > time() - 600; });
    if(count($_SESSION['login_fail']) >= 5){ $err = 'ลองผิดหลายครั้งเกินไป กรุณารอสักครู่'; }
    else {
        $u = db_one("SELECT * FROM users WHERE email = ? AND status = 1", [mb_strtolower(post('email'))]);
        if($u && shVerifyPassword(post('password'), $u['password_hash'])){
            if(shPasswordNeedsRehash($u['password_hash']))
                db_write("UPDATE users SET password_hash = ? WHERE id = ?", [shHashPassword(post('password')), (int)$u['id']]);
            login_user($u['id']);
            $to = $_SESSION['after_login'] ?? ''; unset($_SESSION['after_login'], $_SESSION['login_fail']);
            // กลับได้เฉพาะ path ภายในแอป (กัน open redirect)
            $ok = $to !== '' && strpos($to, app_base()) === 0 && strpos($to, '//') === false
               && ((int)$u['is_admin'] === 1 || strpos($to, app_base().'admin/') !== 0);   // ไม่ใช่แอดมิน อย่าพากลับไปหน้า 403
            redirect($ok ? $to : u('my-learning'));
        }
        $_SESSION['login_fail'][] = time();
        $err = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
    }
}
?>
<div class="auth card">
  <h1>เข้าสู่ระบบ</h1>
  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>อีเมล</label><input type="email" name="email" value="<?= h(post('email')) ?>" required autofocus>
    <label>รหัสผ่าน</label><input type="password" name="password" required>
    <button class="btn btn-primary btn-block mt">เข้าสู่ระบบ</button>
  </form>
  <p class="small muted mt">ยังไม่มีบัญชี? <a href="<?= h(u('register')) ?>">สมัครสมาชิก</a></p>
</div>
