<?php
// การ์ดคอร์ส — โครงเดียวกับ aleanor_ai pages/main/main.php (.course-card) + ราคา/สถานะของตลาดคอร์ส
// ต้องมี $c (แถวคอร์ส + teacher, n_lessons, rating, owned), $ci (ลำดับ สำหรับดีเลย์ reveal)
$levels = ['beginner' => 'เริ่มต้น', 'intermediate' => 'ระดับกลาง', 'advanced' => 'ขั้นสูง', 'all' => 'ทุกระดับ'];
?>
<a href="<?= h(u('course', ['slug' => $c['slug']])) ?>" class="card course-card<?= !empty($isHome) ? ' rv' : '' ?>" style="transition-delay:<?= min(($ci ?? 0) * 0.07, 0.35) ?>s">
  <div class="course-cover-wrap">
    <?= course_cover($c) ?>
    <?php if((int)$c['n_lessons'] > 0): ?><span class="course-ep-badge"><i class="fi fi-rr-play-circle"></i> <?= (int)$c['n_lessons'] ?> บทเรียน</span><?php endif; ?>
  </div>
  <div class="card-body" style="display:flex;flex-direction:column;flex:1">
    <div><span class="badge badge-primary"><?= h($levels[$c['level']] ?? 'ทุกระดับ') ?></span></div>
    <h3 class="course-title"><?= h($c['title']) ?></h3>
    <div class="course-teacher"><img src="<?= h(asset('assets/brand/avatar.png')) ?>" alt=""><span><?= h($c['teacher']) ?></span></div>
    <?php if(!empty($c['rating_n'])): ?><div class="course-meta" style="margin-top:.35rem">★ <?= number_format((float)$c['rating_avg'], 1) ?> <span class="muted">(<?= (int)$c['rating_n'] ?>)</span></div><?php endif; ?>
    <div class="cc-price" style="margin-top:auto">
      <?php if(!empty($c['owned'])): ?><span class="owned"><i class="fi fi-rr-check"></i> ลงทะเบียนแล้ว</span>
      <?php else: ?><span class="small muted"><?= (int)$c['students'] ?> ผู้เรียน</span><?php endif; ?>
      <?= (float)$c['price'] > 0 ? '<b>'.baht($c['price']).'</b>' : '<b class="free">ฟรี</b>' ?>
    </div>
  </div>
</a>
