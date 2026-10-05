<?php
// โครงหน้าเดียวใช้ 3 พื้นที่: $AREA = public | instructor | admin
// ตัวแปร: $TITLE, $CONTENT (HTML ที่ buffer ไว้), $PAGE (route), $MENU (เมนูข้างที่ไฮไลต์ ถ้าไม่ตรงกับ route)
$me = current_user();
$siteName = setting('site_name', 'Aleanor Cloud');
$sideMenus = [
  'instructor' => [
    'ภาพรวม' => ['dashboard' => 'แดชบอร์ด', 'courses' => 'คอร์สของฉัน', 'coupons' => 'คูปอง'],
    'การเงิน' => ['earnings' => 'รายได้', 'profile' => 'โปรไฟล์ & บัญชีรับเงิน'],
  ],
  'admin' => [
    'ภาพรวม'   => ['dashboard' => 'แดชบอร์ด', 'reports' => 'รายงาน'],
    'เนื้อหา'   => ['instructors' => 'ผู้สอน', 'courses' => 'คอร์ส'],
    'การเงิน'   => ['revenue' => 'ส่วนแบ่งรายได้', 'orders' => 'คำสั่งซื้อ & คืนเงิน', 'coupons' => 'คูปองแพลตฟอร์ม'],
    'ระบบ'     => ['settings' => 'ตั้งค่า', 'audit' => 'Audit log'],
  ],
];
$link = function($p) use($AREA){ return $AREA === 'admin' ? au($p) : iu($p); };
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($TITLE ?? '') !== '' ? $TITLE.' · '.$siteName : $siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body>
<header class="topbar"><div class="container">
  <a class="brand" href="<?= h(u()) ?>">Aleanor <span>Cloud</span></a>
  <?php if($AREA === 'instructor'): ?><span class="brand-tag">ผู้สอน</span><?php elseif($AREA === 'admin'): ?><span class="brand-tag">แอดมิน</span><?php endif; ?>
  <nav class="nav">
    <a href="<?= h(u('courses')) ?>">คอร์สทั้งหมด</a>
    <?php if($me): ?>
      <a href="<?= h(u('my-learning')) ?>">การเรียนของฉัน</a>
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

<?php if($AREA === 'public'): ?>
  <main class="page"><div class="container"><?= flashes() ?><?= $CONTENT ?></div></main>
<?php else: ?>
  <div class="container shell">
    <aside class="side">
      <?php foreach($sideMenus[$AREA] as $group => $items): ?>
        <div class="group"><?= h($group) ?></div>
        <?php foreach($items as $p => $label): ?>
          <a class="<?= ($MENU ?? $PAGE) === $p ? 'on' : '' ?>" href="<?= h($link($p)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </aside>
    <div><?= flashes() ?><?= $CONTENT ?></div>
  </div>
<?php endif; ?>

<footer class="footer"><div class="container row between">
  <span>© <?= date('Y') ?> <?= h($siteName) ?></span>
  <?php if(gwProvider() === 'mock'): ?><span class="badge badge-amber">โหมดทดสอบ: gateway จำลอง</span><?php endif; ?>
</div></footer>
</body>
</html>
