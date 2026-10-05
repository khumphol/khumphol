<?php
// หน้าแรก + รายการคอร์ส — โครง/คลาสตาม aleanor_ai (pages/main/main.php, pages/course/all-course.php)
$isHome = $PAGE === 'home';
$TITLE = $isHome ? '' : 'คอร์สเรียนทั้งหมด';
$FULL = true;
$q = get('q'); $lv = get('level');
$uid = current_user_id();
$where = "c.status = 'published'"; $params = [$uid];
if($q !== ''){ $where .= " AND (c.title LIKE ? OR c.subtitle LIKE ? OR c.description LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if(in_array($lv, ['beginner', 'intermediate', 'advanced'], true)){ $where .= " AND c.level = ?"; $params[] = $lv; }
$courses = db_all("SELECT c.*, COALESCE(ip.display_name, u.name) AS teacher,
                     (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS n_lessons,
                     (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS students,
                     (SELECT AVG(rating) FROM reviews r WHERE r.course_id = c.id) AS rating_avg, (SELECT COUNT(*) FROM reviews r WHERE r.course_id = c.id) AS rating_n,
                     EXISTS(SELECT 1 FROM enrollments e2 WHERE e2.course_id = c.id AND e2.user_id = ? AND e2.status = 'active') AS owned
                   FROM courses c JOIN users u ON u.id = c.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id
                   WHERE $where ORDER BY c.published_at DESC LIMIT ".($isHome ? 6 : 60), $params);
$arrow = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
?>
<?php if($isHome):
  $stat = db_one("SELECT (SELECT COUNT(*) FROM courses WHERE status='published') c, (SELECT COUNT(*) FROM lessons l JOIN courses c ON c.id = l.course_id WHERE c.status='published') l,
                         (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM instructor_profiles WHERE status='approved') t"); ?>
<div class="fp-slides full">
  <div class="fp-slide-track" id="slideTrack">
    <?php foreach(['assets/front/hero-1.jpg', 'assets/front/hero-2.jpg'] as $i => $img): ?>
      <div class="fp-slide <?= $i === 0 ? 'active' : '' ?>"><div class="fp-slide-bg" style="background-image:url('<?= h(asset($img)) ?>')"></div></div>
    <?php endforeach; ?>
    <button class="fp-arrow prev" onclick="slideStep(-1)" aria-label="previous"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
    <button class="fp-arrow next" onclick="slideStep(1)" aria-label="next"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
  </div>
  <div class="fp-dots" id="slideDots"><button class="fp-dot active" onclick="goSlide(0)" aria-label="slide 1"></button><button class="fp-dot" onclick="goSlide(1)" aria-label="slide 2"></button></div>
</div>

<section class="fp-hero">
  <div class="fp-hero-deco"><div class="blob blob-1"></div><div class="blob blob-2"></div><div class="dots"></div><div class="ring"></div></div>
  <div class="fp-hero-inner">
    <div class="fp-hero-badge"><span class="dot"></span>ตลาดคอร์สออนไลน์</div>
    <h1 style="transition-delay:.07s">เรียนจากผู้สอนตัวจริง<br>ได้ทุกที่ ทุกเวลา</h1>
    <p style="transition-delay:.14s">คอร์สคุณภาพจากผู้สอนหลากหลายสาขา ผ่านการตรวจโดยทีมงานก่อนเปิดขาย เรียนจบรับใบประกาศ</p>
    <div class="fp-actions" style="transition-delay:.21s">
      <a href="<?= h(u('courses')) ?>" class="fp-btn fp-btn-1">เริ่มเรียนเลย <?= $arrow ?></a>
      <?php if(!current_user()): ?><a href="<?= h(u('register')) ?>" class="fp-btn fp-btn-2">สมัครสมาชิกฟรี</a>
      <?php elseif(!is_instructor()): ?><a href="<?= h(u('become-instructor')) ?>" class="fp-btn fp-btn-2">สมัครเป็นผู้สอน</a><?php endif; ?>
    </div>
    <div class="fp-hero-chips" style="transition-delay:.3s">
      <div class="fp-chip"><b data-count="<?= (int)$stat['c'] ?>">0</b><span>คอร์สเรียน</span></div>
      <div class="fp-chip"><b data-count="<?= (int)$stat['l'] ?>">0</b><span>บทเรียน</span></div>
      <div class="fp-chip"><b data-count="<?= (int)$stat['t'] ?>">0</b><span>ผู้สอน</span></div>
      <div class="fp-chip"><b data-count="<?= (int)$stat['u'] ?>">0</b><span>สมาชิก</span></div>
    </div>
  </div>
</section>

<section class="section"><div class="container">
  <div class="text-center rv"><div class="section-eyebrow">คอร์สเรียน</div><h2 class="section-title">คอร์สเรียนล่าสุด</h2><p class="section-sub">คอร์สที่เพิ่งเปิดขาย คัดสรรและตรวจคุณภาพโดยทีมงาน</p></div>
  <?php if(!$courses): ?><div class="no-data"><p>ยังไม่มีคอร์สที่เปิดขาย</p></div><?php else: ?>
  <div class="grid grid-3"><?php foreach($courses as $ci => $c) require __DIR__.'/_course-card.php'; ?></div>
  <div class="text-center" style="margin-top:2rem"><a class="btn btn-outline" href="<?= h(u('courses')) ?>">ดูคอร์สทั้งหมด <?= $arrow ?></a></div>
  <?php endif; ?>
</div></section>

<section class="section" style="padding-top:0"><div class="container">
  <div class="text-center rv"><div class="section-eyebrow">ทำไมต้องเรา</div><h2 class="section-title">เรียนอย่างมั่นใจ สอนอย่างคุ้มค่า</h2></div>
  <div class="grid grid-3 why-grid">
    <?php foreach([['fi-rr-shield-check', 'ตรวจคุณภาพทุกคอร์ส', 'ทีมงานตรวจเนื้อหาก่อนเปิดขาย ดูบทเรียนตัวอย่างได้ฟรีก่อนซื้อ'],
                   ['fi-rr-diploma', 'เรียนจบรับใบประกาศ', 'ติดตามความคืบหน้าทุกบท เรียนครบรับใบประกาศพร้อมเลขตรวจสอบ'],
                   ['fi-rr-sack-dollar', 'ผู้สอนได้ส่วนแบ่งชัดเจน', 'ระบบแบ่งรายได้อัตโนมัติ ดูยอดได้ทุกรายการ โอนให้ทุกเดือน']] as $i => $w): ?>
      <div class="card why rv" style="transition-delay:<?= $i * .08 ?>s"><div class="why-ico"><i class="fi <?= $w[0] ?>"></i></div><h3><?= h($w[1]) ?></h3><p><?= h($w[2]) ?></p></div>
    <?php endforeach; ?>
  </div>
</div></section>

<section class="section" style="padding-top:0"><div class="container"><div class="fp-cta rv">
  <h2><?= is_instructor() ? 'สร้างคอร์สใหม่ของคุณวันนี้' : 'มีความรู้ที่อยากแบ่งปัน?' ?></h2>
  <p>สมัครเป็นผู้สอน สร้างคอร์ส ตั้งราคาเอง แล้วรับส่วนแบ่งรายได้ทุกเดือน</p>
  <a href="<?= h(is_instructor() ? iu('courses') : u('become-instructor')) ?>" class="fp-btn fp-btn-1"><?= is_instructor() ? 'ไปที่พื้นที่ผู้สอน' : 'สมัครเป็นผู้สอน' ?> <?= $arrow ?></a>
</div></div></section>
<script>
// slideshow (จาก aleanor_ai main.php)
var slideI = 0, slideEls = document.querySelectorAll('.fp-slide'), dotEls = document.querySelectorAll('.fp-dot'), slideTimer;
function goSlide(n){ if(!slideEls.length) return; slideI = (n + slideEls.length) % slideEls.length;
  slideEls.forEach(function(s, i){ s.classList.toggle('active', i === slideI); }); dotEls.forEach(function(d, i){ d.classList.toggle('active', i === slideI); });
  clearInterval(slideTimer); slideTimer = setInterval(function(){ goSlide(slideI + 1); }, 6000); }
function slideStep(d){ goSlide(slideI + d); }
goSlide(0);
</script>

<?php else: ?>
<section class="page-hero"><div class="container"><h1>คอร์สเรียนทั้งหมด</h1><p>เลือกคอร์สที่คุณสนใจและเริ่มเรียนได้เลย</p></div></section>
<div class="container" style="padding-bottom:3rem">
  <form method="get" class="course-search">
    <input type="hidden" name="p" value="courses"><?php if($lv): ?><input type="hidden" name="level" value="<?= h($lv) ?>"><?php endif; ?>
    <i class="fi fi-rr-search"></i>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหาชื่อคอร์ส หรือคำอธิบาย" aria-label="ค้นหาคอร์ส">
    <?php if($q !== ''): ?><a href="<?= h(u('courses', ['level' => $lv])) ?>" class="cs-clear" title="ล้าง"><i class="fi fi-rr-cross-small"></i></a><?php endif; ?>
    <button type="submit">ค้นหา</button>
  </form>
  <?php if($q !== ''): ?><p class="cs-result">ผลการค้นหา “<?= h($q) ?>” — <?= count($courses) ?> คอร์ส</p><?php endif; ?>
  <div class="filter-bar">
    <?php foreach(['' => 'ทั้งหมด', 'beginner' => 'เริ่มต้น', 'intermediate' => 'ระดับกลาง', 'advanced' => 'ขั้นสูง'] as $k => $l): ?>
      <a href="<?= h(u('courses', ['level' => $k, 'q' => $q])) ?>" class="filter-btn <?= $lv === $k ? 'active' : '' ?>"><?= h($l) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if(!$courses): ?>
    <div class="cs-empty"><i class="fi fi-rr-search"></i><p>ไม่พบคอร์สที่ตรงกับการค้นหา</p><a class="btn btn-outline" href="<?= h(u('courses')) ?>">ดูคอร์สทั้งหมด</a></div>
  <?php else: ?>
    <div class="grid grid-3"><?php foreach($courses as $ci => $c) require __DIR__.'/_course-card.php'; ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>
