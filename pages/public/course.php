<?php
$c = db_one("SELECT c.*, COALESCE(ip.display_name, u.name) AS teacher, ip.headline, ip.bio
             FROM courses c JOIN users u ON u.id = c.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = c.instructor_id
             WHERE c.slug = ?", [get('slug')]);
// คอร์สที่ยังไม่เผยแพร่ ให้ดูได้เฉพาะเจ้าของ/แอดมิน (preview)
$canPreview = $c && (is_admin() || (int)$c['instructor_id'] === current_user_id());
if(!$c || ($c['status'] !== 'published' && !$canPreview)){ http_response_code(404); echo '<div class="card"><h1>ไม่พบคอร์ส</h1></div>'; return; }
$TITLE = $c['title'];
$FULL = true;
$me = current_user();
$enrolled = $me && OrderService::isEnrolled($me['id'], $c['id']);

// คอร์สฟรี: ลงทะเบียนทันที
if(is_post() && post('action') === 'enroll_free'){
    require_csrf(); require_login();
    if((float)$c['price'] <= 0 && $c['status'] === 'published'){ OrderService::enrollFree($me['id'], $c['id']); flash('ลงทะเบียนเรียบร้อย'); }
    redirect(u('learn', ['course' => $c['id']]));
}
$sections = db_all("SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id", [$c['id']]);
$lessons = [];
foreach(db_all("SELECT id, section_id, title, type, duration_min, is_preview FROM lessons WHERE course_id = ? ORDER BY sort_order, id", [$c['id']]) as $l) $lessons[$l['section_id']][] = $l;
$totalMin = (int)db_val("SELECT COALESCE(SUM(duration_min),0) FROM lessons WHERE course_id = ?", [$c['id']]);
$nLessons = (int)db_val("SELECT COUNT(*) FROM lessons WHERE course_id = ?", [$c['id']]);
$students = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND status = 'active'", [$c['id']]);
$inCart = $me ? (bool)db_val("SELECT id FROM cart_items WHERE user_id = ? AND course_id = ?", [(int)$me['id'], (int)$c['id']]) : false;
$levels = ['beginner' => 'เริ่มต้น', 'intermediate' => 'ระดับกลาง', 'advanced' => 'ขั้นสูง', 'all' => 'ทุกระดับ'];
$typeLabel = ['video' => 'วิดีโอ', 'text' => 'บทความ', 'indy' => 'วิดีโอเลือกเส้นทาง', 'doc' => 'เอกสาร', 'playground' => 'กิจกรรม Playground'];
$rating = db_one("SELECT AVG(rating) avg, COUNT(*) n FROM reviews WHERE course_id = ?", [$c['id']]);
?>
<div class="lay-minimal">
<section class="course-hero">
  <div class="course-hero-inner">
    <?php if($c['cover']): ?><img class="course-hero-cover" src="<?= h(cover_url($c)) ?>" alt=""><?php else: ?><div class="course-hero-cover" style="overflow:hidden;padding:0"><?= course_cover($c, '') ?></div><?php endif; ?>
    <div class="course-hero-info">
      <a href="<?= h(u('courses')) ?>" class="back-link"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg> คอร์สทั้งหมด</a>
      <span class="badge badge-primary"><?= h($levels[$c['level']] ?? 'ทุกระดับ') ?></span>
      <?php if($c['status'] !== 'published'): ?><span class="badge badge-amber">ตัวอย่างก่อนเผยแพร่ · <?= strip_tags(status_badge($c['status'])) ?></span><?php endif; ?>
      <h1><?= h($c['title']) ?></h1>
      <?php if($c['subtitle']): ?><p style="color:var(--text-secondary);margin:-.2rem 0 .6rem"><?= h($c['subtitle']) ?></p><?php endif; ?>
      <div class="course-hero-meta">
        <span><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?= h($c['teacher']) ?></span>
        <span><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> <?= $nLessons ?> บทเรียน · <?= $totalMin ?> นาที</span>
        <span><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg> <?= $students ?> ผู้เรียน</span>
        <?php if((int)$rating['n']): ?><span>★ <?= number_format($rating['avg'], 1) ?> (<?= (int)$rating['n'] ?> รีวิว)</span><?php endif; ?>
      </div>
    </div>
  </div>
</section>
<div class="course-content">
  <div class="course-left">
    <div class="sidebar-card mb-2"><h3>เกี่ยวกับคอร์ส</h3><p class="prose" style="color:var(--text-secondary);font-size:.95rem;line-height:1.8"><?= h($c['description']) ?></p></div>
    <div class="sidebar-card">
      <h3>บทเรียน (<?= $nLessons ?>)</h3>
      <ul class="ep-list">
      <?php $i = 0; foreach($sections as $s): ?>
        <li class="ep-unit-head"><i class="fi fi-rr-folder"></i> <?= h($s['title']) ?></li>
        <?php foreach($lessons[$s['id']] ?? [] as $l): $i++; $open = $l['is_preview'] || $enrolled || $canPreview; ?>
        <li class="ep-item" style="position:relative;<?= $open ? '' : 'cursor:default;opacity:.85' ?>">
          <?php if($open): ?><a class="ep-link" href="<?= h(u('learn', ['course' => $c['id'], 'lesson' => $l['id']])) ?>" aria-label="<?= h($l['title']) ?>"></a><?php endif; ?>
          <div class="ep-check"><?= $open ? $i : '<i class="fi fi-rr-lock"></i>' ?></div>
          <div class="ep-meta"><div class="en"><?= h($l['title']) ?><?php if($l['is_preview'] && !$enrolled): ?><span class="ep-free">ดูฟรี</span><?php endif; ?></div>
            <div class="et"><?= h($typeLabel[$l['type']] ?? $l['type']) ?><?= (int)$l['duration_min'] ? ' · '.(int)$l['duration_min'].' นาที' : '' ?></div></div>
          <?php if($open): ?><span class="ep-play"><i class="fi fi-rr-play"></i></span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if(!$nLessons): ?><p style="color:var(--text-muted);text-align:center;padding:2rem 0">ยังไม่มีบทเรียน</p><?php endif; ?>
      </ul>
    </div>
    <?php $reviews = db_all("SELECT r.*, u.name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.course_id = ? ORDER BY r.id DESC LIMIT 6", [$c['id']]); if($reviews): ?>
    <div class="sidebar-card mt-2"><h3>รีวิวจากผู้เรียน</h3>
      <?php foreach($reviews as $rv): ?><div style="padding:.6rem 0;border-bottom:1px solid var(--border)"><strong><?= h($rv['name']) ?></strong> <span style="color:#f59e0b"><?= str_repeat('★', (int)$rv['rating']) ?></span>
        <?php if($rv['body']): ?><p style="color:var(--text-secondary);font-size:.9rem;margin-top:.2rem"><?= h($rv['body']) ?></p><?php endif; ?></div><?php endforeach; ?>
    </div><?php endif; ?>
  </div>
  <div class="course-side">
    <div class="sidebar-card buy-card" style="margin-bottom:1rem">
      <div class="buy-price"><?= (float)$c['price'] > 0 ? baht($c['price']) : 'ฟรี' ?></div>
      <?php if($enrolled): ?>
        <a class="btn btn-primary btn-lg" href="<?= h(u('learn', ['course' => $c['id']])) ?>"><i class="fi fi-rr-play"></i> เข้าเรียน</a>
      <?php elseif($c['status'] !== 'published'): ?>
        <button class="btn btn-lg" disabled>ยังไม่เปิดขาย</button>
      <?php elseif((float)$c['price'] <= 0): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enroll_free"><button class="btn btn-primary btn-lg"><i class="fi fi-rr-graduation-cap"></i> ลงทะเบียนเรียนฟรี</button></form>
      <?php else: ?>
        <a class="btn btn-primary btn-lg" href="<?= h(u('checkout', ['course' => $c['id']])) ?>"><i class="fi fi-rr-shopping-bag"></i> ซื้อคอร์สนี้</a>
        <?php if($me): ?><form method="post" action="<?= h(u('cart')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="back" value="course"><input type="hidden" name="course" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-outline btn-lg" <?= $inCart ? 'disabled' : '' ?>><i class="fi fi-rr-shopping-cart"></i> <?= $inCart ? 'อยู่ในตะกร้าแล้ว' : 'ใส่ตะกร้า' ?></button></form><?php endif; ?>
      <?php endif; ?>
      <ul>
        <li><i class="fi fi-rr-infinity"></i>เรียนได้ไม่จำกัดเวลา</li>
        <li><i class="fi fi-rr-diploma"></i>เรียนจบรับใบประกาศ</li>
        <?php if((int)$c['vc_enabled'] && IntegrationService::vcEnabled()): ?><li><i class="fi fi-rr-users-alt"></i>มีห้องเรียนเสมือน (Aleanor VC)</li><?php endif; ?>
        <li><i class="fi fi-rr-shield-check"></i>ชำระเงินปลอดภัยผ่าน <?= h(gwProvider() === 'mock' ? 'ระบบทดสอบ' : ucfirst(gwProvider() ?: 'gateway')) ?></li>
      </ul>
    </div>
    <div class="sidebar-card" style="margin-bottom:1rem"><h3>ผู้สอน</h3>
      <div class="teacher-info"><img class="teacher-avatar" src="<?= h(asset('assets/brand/avatar.png')) ?>" alt="">
        <div><div class="teacher-name"><?= h($c['teacher']) ?></div><div class="teacher-role"><?= h($c['headline'] ?: 'ผู้สอน') ?></div></div></div>
      <?php if($c['bio']): ?><p class="prose" style="color:var(--text-secondary);font-size:.88rem;margin-top:.8rem"><?= h($c['bio']) ?></p><?php endif; ?>
    </div>
    <div class="sidebar-card"><h3>ข้อมูลคอร์ส</h3>
      <div class="detail-row"><span class="detail-label">ระดับ</span><span class="detail-value"><?= h($levels[$c['level']] ?? '-') ?></span></div>
      <div class="detail-row"><span class="detail-label">บทเรียน</span><span class="detail-value"><?= $nLessons ?></span></div>
      <div class="detail-row"><span class="detail-label">ความยาวรวม</span><span class="detail-value"><?= $totalMin ?> นาที</span></div>
      <div class="detail-row"><span class="detail-label">ราคา</span><span class="detail-value" style="color:var(--primary);font-weight:800"><?= (float)$c['price'] > 0 ? baht($c['price']) : 'ฟรี' ?></span></div>
      <?php if($c['published_at']): ?><div class="detail-row"><span class="detail-label">เปิดขายเมื่อ</span><span class="detail-value"><?= h(date('d/m/Y', strtotime($c['published_at']))) ?></span></div><?php endif; ?>
    </div>
  </div>
</div>
</div>
