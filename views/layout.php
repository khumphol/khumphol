<?php
// โครงหน้าบ้าน (public) — หลังบ้านใช้ views/dashboard.php
// ตัวแปร: $TITLE, $CONTENT (HTML ที่ buffer ไว้), $PAGE (route), $MENU (เมนูข้างที่ไฮไลต์ ถ้าไม่ตรงกับ route)
$me = current_user();
$siteName = setting('site_name', 'Aleanor Cloud');
$cartN = $me ? (int)db_val("SELECT COUNT(*) FROM cart_items WHERE user_id = ?", [(int)$me['id']]) : 0;
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($TITLE ?? '') !== '' ? $TITLE.' · '.$siteName : $siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset('assets/app.css').'?v='.filemtime(dirname(__DIR__).'/assets/app.css')) ?>">
</head>
<body>
<header class="topbar"><div class="container">
  <a class="brand" href="<?= h(u()) ?>">Aleanor <span>Cloud</span></a>
  <nav class="nav">
    <a href="<?= h(u('courses')) ?>">คอร์สทั้งหมด</a>
    <?php if($me): ?>
      <a href="<?= h(u('my-learning')) ?>">การเรียนของฉัน</a>
      <a href="<?= h(u('cart')) ?>">ตะกร้า<?= $cartN ? ' ('.$cartN.')' : '' ?></a>
      <?php if(is_instructor()): ?><a href="<?= h(iu()) ?>">ผู้สอน</a>
      <?php elseif(!$me['instructor_status']): ?><a href="<?= h(u('become-instructor')) ?>">สมัครเป็นผู้สอน</a><?php endif; ?>
      <?php if(is_admin()): ?><a href="<?= h(au()) ?>">แอดมิน</a><?php endif; ?>
      <a href="<?= h(u('logout')) ?>" title="<?= h($me['email']) ?>">ออกจากระบบ</a>
    <?php else: ?>
      <a href="<?= h(u('login')) ?>">เข้าสู่ระบบ</a>
      <a class="btn btn-primary btn-sm" href="<?= h(u('register')) ?>">สมัครสมาชิก</a>
    <?php endif; ?>
  </nav>
</div></header>

<main class="page"><div class="container"><?= flashes() ?><?= $CONTENT ?></div></main>

<footer class="footer"><div class="container row between">
  <span>© <?= date('Y') ?> <?= h($siteName) ?></span>
  <?php if(gwProvider() === 'mock'): ?><span class="badge badge-amber">โหมดทดสอบ: gateway จำลอง</span><?php endif; ?>
</div></footer>
</body>
</html>
