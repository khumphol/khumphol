<?php
// โครงหลังบ้าน (แอดมิน/ผู้สอน) — หน้าตาตาม aleanor_ai/dashboard/index.php:
// sidebar 260px + topbar โปร่งแสง + profile dropdown, ไอคอน Flaticon uicons, ฟอนต์ Sarabun
$me = current_user();
$siteName = setting('site_name', 'Aleanor Cloud');
$link = function($p) use($AREA){ return $AREA === 'admin' ? au($p) : iu($p); };
$active = $MENU ?? $PAGE;
$roleLabel = $AREA === 'admin' ? 'ผู้ดูแลระบบ' : 'ผู้สอน';
$initial = mb_strtoupper(mb_substr($me['name'] ?? '?', 0, 1));
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($TITLE ?? '') !== '' ? $TITLE.' · '.$siteName : $siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/4.0.0/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="<?= h(asset('assets/dashboard.css').'?v='.filemtime(dirname(__DIR__).'/assets/dashboard.css')) ?>">
<style>
  .sidebar-brand{font-weight:800;font-size:1.15rem;color:var(--text);letter-spacing:-.02em}
  .sidebar-brand b{color:var(--primary)}
  .brand-area{font-size:.68rem;font-weight:700;background:var(--primary-bg);color:var(--primary);padding:2px 8px;border-radius:99px;margin-left:8px;vertical-align:middle}
  .avatar-initial{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0}
  .nav-item i{font-size:1.05rem;display:inline-flex}
  .dd-icon i{font-size:18px}
</style>
</head>
<body>
<aside class="sidebar" id="sidebar">
  <a href="<?= h($link('dashboard')) ?>" class="sidebar-brand">Aleanor&nbsp;<b>Cloud</b><span class="brand-area"><?= h($roleLabel) ?></span></a>
  <nav class="sidebar-nav">
    <?php foreach(dashboard_menu($AREA) as $m): ?>
      <a class="nav-item<?= $active === $m['p'] ? ' active' : '' ?>" href="<?= h($link($m['p'])) ?>"><i class="fi <?= h($m['icon']) ?> nav-icon"></i><span><?= h($m['label']) ?></span></a>
    <?php endforeach; ?>
    <div class="nav-bottom">
      <a class="nav-item" href="<?= h(u()) ?>"><i class="fi fi-rr-globe nav-icon"></i><span>ไปหน้าเว็บไซต์</span></a>
      <a class="nav-item" href="<?= h(u('logout')) ?>"><i class="fi fi-rr-sign-out-alt nav-icon"></i><span>ออกจากระบบ</span></a>
    </div>
  </nav>
</aside>

<div class="main">
  <header class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="เมนู"><i class="fi fi-rr-menu-burger"></i></button>
      <div class="topbar-title"><?= h($TITLE ?? '') ?></div>
    </div>
    <div class="topbar-right">
      <?php if(gwProvider() === 'mock'): ?><span class="mode-badge">gateway จำลอง</span><?php endif; ?>
      <?php if($AREA === 'admin' && is_instructor()): ?><a class="topbar-btn" href="<?= h(iu()) ?>" title="พื้นที่ผู้สอน"><i class="fi fi-rr-chalkboard-user"></i></a><?php endif; ?>
      <?php if($AREA === 'instructor' && is_admin()): ?><a class="topbar-btn" href="<?= h(au()) ?>" title="หลังบ้านแอดมิน"><i class="fi fi-rr-shield-check"></i></a><?php endif; ?>
      <?php if(setting('help_url', '') !== ''): ?><a class="topbar-btn" href="<?= h(setting('help_url', '')) ?>" target="_blank" rel="noopener" title="คู่มือ"><i class="fi fi-rr-interrogation"></i></a><?php endif; ?>
      <div style="position:relative;">
        <div class="profile-trigger" onclick="document.getElementById('profileDD').classList.toggle('open')">
          <span class="avatar-initial"><?= h($initial) ?></span>
          <div class="profile-info"><div class="profile-name"><?= h($me['name']) ?></div><div class="profile-role"><?= h($roleLabel) ?></div></div>
          <span class="profile-chevron">&#9662;</span>
        </div>
        <div class="profile-dropdown" id="profileDD">
          <div class="dd-header"><span class="avatar-initial" style="width:44px;height:44px"><?= h($initial) ?></span>
            <div><div class="dd-header-name"><?= h($me['name']) ?></div><span class="dd-header-badge"><?= h($roleLabel) ?></span></div></div>
          <div class="dd-grid">
            <a href="<?= h(u('my-learning')) ?>" class="dd-item"><div class="dd-icon" style="background:#eef2ff;color:#6366f1;"><i class="fi fi-rr-book-open-reader"></i></div>การเรียนของฉัน</a>
            <?php if(is_instructor()): ?><a href="<?= h(iu('profile')) ?>" class="dd-item"><div class="dd-icon" style="background:#ecfeff;color:#06b6d4;"><i class="fi fi-rr-id-badge"></i></div>โปรไฟล์ผู้สอน</a><?php endif; ?>
            <?php if(is_admin()): ?><a href="<?= h(au('settings')) ?>" class="dd-item"><div class="dd-icon" style="background:#dcfce7;color:#16a34a;"><i class="fi fi-rr-settings"></i></div>ตั้งค่า</a><?php endif; ?>
          </div>
          <a href="<?= h(u('logout')) ?>" class="dd-logout"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>ออกจากระบบ</a>
        </div>
      </div>
    </div>
  </header>
  <div class="content"><?= flashes() ?><?= $CONTENT ?></div>
  <footer class="footer"><span>© <?= date('Y') ?> <?= h($siteName) ?></span><span class="footer-right">Aleanor Cloud</span></footer>
</div>
<script>
document.addEventListener('click', function(e){
  var dd = document.getElementById('profileDD');
  if(dd && !e.target.closest('.profile-trigger') && !e.target.closest('#profileDD')) dd.classList.remove('open');
  var sb = document.getElementById('sidebar');
  if(sb && sb.classList.contains('open') && !e.target.closest('#sidebar') && !e.target.closest('.mobile-menu-btn')) sb.classList.remove('open');
});
</script>
</body>
</html>
