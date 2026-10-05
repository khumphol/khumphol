<?php
// โครงหน้าบ้าน (public) — header/footer/สไตล์ตาม aleanor_ai (pages/themes/header, footer + index.php)
// หลังบ้านใช้ views/dashboard.php · ตัวแปร: $TITLE, $CONTENT, $PAGE, $FULL (true = หน้าเต็มความกว้าง ไม่ครอบ container)
$me = current_user();
$siteName = setting('site_name', 'Aleanor Cloud');
$cartN = $me ? (int)db_val("SELECT COUNT(*) FROM cart_items WHERE user_id = ?", [(int)$me['id']]) : 0;
$v = function($f){ return h(asset($f).'?v='.@filemtime(dirname(__DIR__).'/'.$f)); };
$nav = [['home', 'หน้าแรก', u()], ['courses', 'คอร์สเรียน', u('courses')]];
if($me) $nav[] = ['my-learning', 'การเรียนของฉัน', u('my-learning')];
if(!$me || !$me['instructor_status']) $nav[] = ['become-instructor', 'สอนกับเรา', u('become-instructor')];
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($TITLE ?? '') !== '' ? $TITLE.' · '.$siteName : $siteName) ?></title>
<meta name="description" content="คอร์สออนไลน์จากผู้สอนตัวจริง — เรียนได้ทุกที่ ทุกเวลา">
<link rel="icon" href="<?= h(asset('assets/brand/favicon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=K2D:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn-uicons.flaticon.com/4.0.0/uicons-regular-rounded/css/uicons-regular-rounded.css">
<link rel="stylesheet" href="<?= $v('assets/front.css') ?>">
<link rel="stylesheet" href="<?= $v('assets/cloud.css') ?>">
</head>
<body>
<header class="site-header" id="siteHeader">
  <div class="header-inner">
    <a href="<?= h(u()) ?>" class="header-logo"><img src="<?= h(asset('assets/brand/logo.png')) ?>" alt="<?= h($siteName) ?>"></a>
    <nav class="header-nav" id="mainNav">
      <?php foreach($nav as $n): ?><a href="<?= h($n[2]) ?>" class="<?= ($PAGE === $n[0] || ($n[0] === 'courses' && in_array($PAGE, ['course', 'courses'], true))) ? 'active' : '' ?>"><?= h($n[1]) ?></a><?php endforeach; ?>
      <?php if(is_instructor()): ?><a href="<?= h(iu()) ?>">พื้นที่ผู้สอน</a><?php endif; ?>
      <?php if(is_admin()): ?><a href="<?= h(au()) ?>">หลังบ้าน</a><?php endif; ?>
      <?php if(!$me): ?><div class="nav-auth"><a href="<?= h(u('login')) ?>" class="btn btn-sm btn-outline">เข้าสู่ระบบ</a><a href="<?= h(u('register')) ?>" class="btn btn-sm btn-primary">สมัครสมาชิก</a></div><?php endif; ?>
    </nav>
    <div class="header-actions">
      <?php if(gwProvider() === 'mock'): ?><span class="mode-pill" title="APP_ENV=dev">โหมดทดสอบ</span><?php endif; ?>
      <?php if($me): ?>
        <?php if($cartN > 0): ?><a class="hd-cart" href="<?= h(u('cart')) ?>" title="ตะกร้า"><i class="fi fi-rr-shopping-cart"></i><span class="hd-cart-n"><?= $cartN ?></span></a><?php endif; ?>
        <?php $myCourses = db_all("SELECT c.id, c.title, c.cover, (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) n,
                                     (SELECT COUNT(*) FROM lesson_progress p WHERE p.user_id = e.user_id AND p.course_id = c.id AND p.completed_at IS NOT NULL) d
                                   FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? AND e.status = 'active' ORDER BY e.created_at DESC LIMIT 5", [(int)$me['id']]); ?>
        <div class="um-wrap" id="umWrap">
          <button type="button" class="um-btn" aria-haspopup="true" aria-expanded="false" onclick="toggleHeaderMenu(event,'umWrap')">
            <img class="header-avatar" src="<?= h(asset('assets/brand/avatar.png')) ?>" alt="">
            <span class="um-name"><?= h($me['name']) ?></span>
            <svg class="um-caret" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="um-panel">
            <div class="um-head"><img src="<?= h(asset('assets/brand/avatar.png')) ?>" alt="">
              <div style="min-width:0"><div class="um-head-name"><?= h($me['name']) ?></div><div class="um-head-sub"><?= h($me['email']) ?></div></div></div>
            <div class="um-sec">คอร์สของฉัน</div>
            <div class="um-list">
              <?php if(!$myCourses): ?><div class="um-empty">ยังไม่มีคอร์ส<br><a href="<?= h(u('courses')) ?>">เลือกดูคอร์ส &rarr;</a></div>
              <?php else: foreach($myCourses as $mc): $pct = $mc['n'] ? (int)round($mc['d'] * 100 / $mc['n']) : 0; ?>
                <a class="um-course" href="<?= h(u('learn', ['course' => $mc['id']])) ?>">
                  <?php if($mc['cover']): ?><img class="um-cv" src="<?= h(asset($mc['cover'])) ?>" alt=""><?php else: ?><span class="um-cv" style="background:var(--surface2)"></span><?php endif; ?>
                  <span class="um-c-info"><span class="um-c-name"><?= h($mc['title']) ?></span><span class="um-c-bar"><i style="width:<?= $pct ?>%;<?= $pct >= 100 ? 'background:#10b981' : '' ?>"></i></span></span>
                  <span class="um-c-pct"><?= $pct ?>%</span></a>
              <?php endforeach; ?><a class="um-more" href="<?= h(u('my-learning')) ?>">การเรียนของฉัน &rarr;</a><?php endif; ?>
            </div>
            <div class="um-foot">
              <?php if(is_admin()): ?><a class="um-action" href="<?= h(au()) ?>"><i class="fi fi-rr-apps"></i> หลังบ้าน</a><?php endif; ?>
              <?php if(is_instructor()): ?><a class="um-action" href="<?= h(iu()) ?>"><i class="fi fi-rr-chalkboard-user"></i> พื้นที่ผู้สอน</a><?php endif; ?>
              <a class="um-action" href="<?= h(u('my-learning')) ?>"><i class="fi fi-rr-graduation-cap"></i> การเรียนของฉัน</a>
              <form method="post" action="<?= h(u('logout')) ?>" class="um-logout-form"><?= csrf_field() ?><button class="um-action um-logout"><i class="fi fi-rr-sign-out-alt"></i> ออกจากระบบ</button></form>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="header-auth"><a href="<?= h(u('login')) ?>" class="btn btn-sm btn-outline">เข้าสู่ระบบ</a><a href="<?= h(u('register')) ?>" class="btn btn-sm btn-primary">สมัครสมาชิก</a></div>
      <?php endif; ?>
      <button class="mobile-toggle" onclick="document.getElementById('mainNav').classList.toggle('open')" aria-label="menu">
        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
</header>

<?php if(!empty($FULL)): ?>
  <?php $fl = flashes(); if($fl): ?><div class="container" style="padding-top:1.25rem"><?= $fl ?></div><?php endif; ?>
  <?= $CONTENT ?>
<?php else: ?>
  <main class="page-body"><div class="container"><?= flashes() ?><?= $CONTENT ?></div></main>
<?php endif; ?>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-top">
      <div class="footer-brand">
        <div class="fb-logo"><img src="<?= h(asset('assets/brand/logo.png')) ?>" alt=""></div>
        <p>ตลาดคอร์สออนไลน์จากผู้สอนตัวจริง — เรียนได้ทุกที่ ทุกเวลา และแบ่งรายได้ให้ผู้สอนอย่างโปร่งใส</p>
      </div>
      <div class="footer-links"><h4>เมนูหลัก</h4>
        <a href="<?= h(u()) ?>">หน้าแรก</a><a href="<?= h(u('courses')) ?>">คอร์สเรียน</a>
        <a href="<?= h(u('become-instructor')) ?>">สอนกับเรา</a><a href="<?= h(u('certificate')) ?>">ตรวจสอบใบประกาศ</a></div>
      <div class="footer-links"><h4>บัญชี</h4>
        <?php if($me): ?><a href="<?= h(u('my-learning')) ?>">การเรียนของฉัน</a><a href="<?= h(u('cart')) ?>">ตะกร้า</a>
        <?php else: ?><a href="<?= h(u('login')) ?>">เข้าสู่ระบบ</a><a href="<?= h(u('register')) ?>">สมัครสมาชิก</a><?php endif; ?></div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= h($siteName) ?> &mdash; Online Learning Platform &middot; Powered by Aleanor</span>
      <button class="fb-up" onclick="window.scrollTo({top:0,behavior:'smooth'})" aria-label="back to top">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
      </button>
    </div>
  </div>
</footer>
<script>
// header แบบ aleanor_ai: เงาเมื่อเลื่อน + เมนูผู้ใช้ (ปิดเมื่อคลิกนอก/กด Esc) + scroll reveal
function toggleHeaderMenu(e,id){ e.stopPropagation(); var el=document.getElementById(id); if(!el) return;
  var open=!el.classList.contains('open'); el.classList.toggle('open',open); var b=el.querySelector('[aria-expanded]'); if(b) b.setAttribute('aria-expanded',open?'true':'false'); }
(function(){
  var h=document.getElementById('siteHeader');
  function onScroll(){ h.classList.toggle('scrolled', window.scrollY>8); }
  window.addEventListener('scroll',onScroll,{passive:true}); onScroll();
  document.addEventListener('click',function(e){ var el=document.getElementById('umWrap'); if(el && el.classList.contains('open') && !el.contains(e.target)) el.classList.remove('open'); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ var el=document.getElementById('umWrap'); if(el) el.classList.remove('open'); } });
  var rv=document.querySelectorAll('.rv');
  if('IntersectionObserver' in window){ var io=new IntersectionObserver(function(es){ es.forEach(function(x){ if(x.isIntersecting){ x.target.classList.add('rv-in'); io.unobserve(x.target); } }); },{threshold:.12});
    rv.forEach(function(el){ io.observe(el); }); } else rv.forEach(function(el){ el.classList.add('rv-in'); });
  document.querySelectorAll('[data-count]').forEach(function(el){ var n=+el.getAttribute('data-count'), t=0, st=Math.max(1,Math.ceil(n/30));
    var iv=setInterval(function(){ t=Math.min(n,t+st); el.textContent=t.toLocaleString(); if(t>=n) clearInterval(iv); },30); });
})();
</script>
</body>
</html>
