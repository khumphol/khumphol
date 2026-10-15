<?php
// คลังบทเรียน Aleanor LC — ค้นหาเทมเพลต พรีวิวโครง และนำเข้าเป็นคอร์สใหม่ (ฉบับร่าง) ของผู้สอน · พอร์ตจาก aleanor_ai lc-library.php
$MENU = 'courses';
$TITLE = 'คลังบทเรียน Aleanor LC';
$uid = current_user_id();
if(!LcService::configured()){
    echo '<div class="alert alert-warning">ผู้ดูแลระบบยังไม่ได้ตั้งค่าการเชื่อมต่อคลังบทเรียน Aleanor LC</div>'; return;
}
$tuid = preg_replace('~[^0-9a-f]~', '', get('uid'));
$self = iu('lc-library');
$err = null;

// ── นำเข้า ──
if(is_post() && post('action') === 'import' && $tuid !== ''){
    require_csrf();
    @set_time_limit(300);
    $r = LcService::api('package', ['uid' => $tuid, 'version' => (int)post('version')]);
    if(!$r['ok']){
        $err = 'ดึงแพ็กเกจไม่สำเร็จ: '.$r['error'];
    }else{
        $pkg = $r['json']['package'];
        $uname = (string)(current_user()['name'] ?? '');
        try {
            $res = LcService::import($pkg, ['quiz' => (bool)post('with_quiz'), 'notes' => (bool)post('with_notes')], $uid);
            LcService::report($pkg['template'], 'imported', $res['course_id'], $res['course_name'], implode(' / ', $res['warnings']), $uname);
            audit('lc.import', 'course', $res['course_id'], null, ['template' => $pkg['template']['uid'], 'version' => (int)$pkg['template']['version']]);
            $c = $res['counts'];
            flash('นำเข้าแล้ว: '.$c['units'].' หน่วย '.$c['lessons'].' บทเรียน — คอร์สเป็นฉบับร่าง ตรวจ ปรับแก้ และตั้งราคา แล้วค่อยส่งตรวจ'
                  .($res['warnings'] ? ' (หมายเหตุ: '.implode(' / ', $res['warnings']).')' : ''), $res['warnings'] ? 'warning' : 'success');
            redirect(iu('course-edit', ['id' => $res['course_id']]));
        } catch(RuntimeException $e){
            LcService::report($pkg['template'], 'failed', 0, $pkg['template']['title'] ?? '', $e->getMessage(), $uname);
            $err = 'นำเข้าไม่สำเร็จ: '.$e->getMessage();
        }
    }
}

$imported = LcService::importedMap($uid);
$f = ['level' => get('level'), 'grade' => get('grade'), 'area' => get('area'), 'term' => get('term'), 'q' => get('q'), 'page' => max(1, (int)get('page', '1'))];
$lcq = function(array $over = []) use($f){ return iu('lc-library', array_filter(array_merge($f, $over), function($v){ return $v !== '' && $v !== null; })); };
?>
<style>
  .lc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem}
  .lc-card{display:flex;flex-direction:column;background:var(--bg-card,var(--card));border:1px solid var(--border);border-radius:16px;overflow:hidden;text-decoration:none;color:inherit;transition:transform .2s,box-shadow .2s}
  .lc-card:hover{transform:translateY(-3px);box-shadow:0 14px 28px -16px var(--primary)}
  .lc-cover{aspect-ratio:16/9;display:flex;align-items:flex-end;padding:.8rem 1rem;color:#fff;font-weight:800;font-size:1.05rem;line-height:1.3;background-size:cover;background-position:center}
  .lc-body{padding:.85rem 1rem 1rem;display:flex;flex-direction:column;gap:.45rem;flex:1}
  .lc-chips{display:flex;gap:5px;flex-wrap:wrap}
  .lc-chip{font-size:.72rem;font-weight:600;padding:2px 8px;border-radius:99px;background:var(--bg);color:var(--text-secondary,var(--muted))}
  .lc-chip.ok{background:rgba(16,185,129,.12);color:#059669}
  .lc-chip.new{background:rgba(245,158,11,.14);color:#b45309}
  .lc-sum{font-size:.82rem;color:var(--text-muted,var(--muted));display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .lc-filters{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
  .lc-filters > *{flex:1 1 160px;margin:0}
  .lc-filters .btn{flex:0 0 auto}
  .lc-unit{border:1px solid var(--border);border-radius:12px;margin-bottom:.7rem}
  .lc-unit summary{padding:.7rem 1rem;font-weight:700;cursor:pointer}
  .lc-unit ul{margin:0 0 .7rem 0;padding:0 1rem 0 1.4rem;font-size:.86rem;list-style:none}
  .lc-unit li{margin:.3rem 0}
  .lc-h1{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
  .lc-h1 img{height:38px;width:auto;max-width:60vw;display:block}
  .lc-two{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:1rem;align-items:start}
  @media(max-width:900px){ .lc-two{grid-template-columns:1fr} }
</style>

<?php if($tuid === ''): // ───────── รายการเทมเพลต ─────────
  $tax = LcService::api('taxonomy');
  $levels = $tax['ok'] ? ($tax['json']['levels'] ?? []) : [];
  $cat = LcService::api('catalog', array_filter(['level' => $f['level'], 'grade' => $f['grade'], 'area' => $f['area'], 'term' => $f['term'], 'q' => $f['q'], 'page' => $f['page']], 'strlen'));
  $areas = [];
  foreach($levels as $l) if($f['level'] === '' || $f['level'] === $l['code']) foreach($l['areas'] as $a) $areas[$a['code']] = $a['name_th'];
?>
<div class="page-header">
  <div><h1 class="lc-h1">คลังบทเรียน <img src="<?= h(LcService::logoUrl()) ?>" alt="Aleanor LC"></h1>
    <p>บทเรียนต้นแบบของแต่ละวิชาตามระดับชั้น — นำเข้าเป็นคอร์สใหม่ของคุณ แล้วปรับเนื้อหา ตั้งราคา และส่งตรวจได้ตามปกติ</p></div>
  <a class="btn btn-outline" href="<?= h(iu('courses')) ?>"><i class="fi fi-rr-arrow-small-left"></i> คอร์สของฉัน</a>
</div>
<?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if(!$cat['ok']): ?><div class="alert alert-danger">เชื่อมต่อคลังไม่สำเร็จ: <?= h($cat['error']) ?></div><?php return; endif; ?>

<form method="get" class="card lc-filters">
  <input type="hidden" name="p" value="lc-library">
  <input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="ค้นหาชื่อหรือรหัสวิชา">
  <select name="level" onchange="this.form.grade.value='';this.form.area.value='';this.form.submit()"><option value="">ทุกระดับ</option>
    <?php foreach($levels as $l): ?><option value="<?= h($l['code']) ?>" <?= $f['level'] === $l['code'] ? 'selected' : '' ?>><?= h($l['name_th']) ?></option><?php endforeach; ?></select>
  <select name="grade"><option value="">ทุกชั้น</option>
    <?php foreach($levels as $l): if($f['level'] !== '' && $f['level'] !== $l['code']) continue; foreach($l['grades'] as $g): ?><option value="<?= h($g['code']) ?>" <?= $f['grade'] === $g['code'] ? 'selected' : '' ?>><?= h($g['name_th']) ?></option><?php endforeach; endforeach; ?></select>
  <select name="area"><option value="">ทุกกลุ่มสาระ</option>
    <?php foreach($areas as $ac => $an): ?><option value="<?= h($ac) ?>" <?= $f['area'] === (string)$ac ? 'selected' : '' ?>><?= h($an) ?></option><?php endforeach; ?></select>
  <select name="term"><option value="">ทุกภาคเรียน</option>
    <?php foreach([1 => 'ภาคเรียนที่ 1', 2 => 'ภาคเรียนที่ 2', 0 => 'ทั้งปี'] as $tk => $tn): ?><option value="<?= $tk ?>" <?= $f['term'] !== '' && (int)$f['term'] === $tk ? 'selected' : '' ?>><?= $tn ?></option><?php endforeach; ?></select>
  <button class="btn btn-primary"><i class="fi fi-rr-search"></i> ค้นหา</button>
</form>

<?php $items = $cat['json']['items'] ?? []; if(!$items): ?>
  <div class="card" style="padding:2.5rem;text-align:center" ><i class="fi fi-rr-books" style="font-size:2rem"></i><p class="muted">ไม่พบเทมเพลตตามเงื่อนไข</p></div>
<?php else: ?>
<p class="small muted">พบ <?= (int)$cat['json']['total'] ?> เทมเพลต</p>
<div class="lc-grid">
  <?php foreach($items as $it):
    $mine = $imported[$it['uid']] ?? [];
    $maxV = $mine ? max(array_map(function($x){ return (int)$x['version']; }, $mine)) : 0;
    $color = preg_match('/^#[0-9a-f]{6}$/i', $it['area']['color'] ?? '') ? $it['area']['color'] : '#6366f1';
    $bg = ($it['cover_url'] ?? '') !== '' && preg_match('~^https?://~i', $it['cover_url'])
        ? "background-image:linear-gradient(180deg,transparent 30%,rgba(0,0,0,.65)),url('".h(str_replace(["'", '\\'], ['%27', ''], $it['cover_url']))."')"
        : 'background:linear-gradient(135deg,'.$color.','.$color.'aa)'; ?>
    <a class="lc-card" href="<?= h(iu('lc-library', ['uid' => $it['uid']])) ?>">
      <div class="lc-cover" style="<?= $bg ?>"><?= h($it['title']) ?></div>
      <div class="lc-body">
        <div class="lc-chips">
          <span class="lc-chip"><?= h($it['grade']['short_th'] ?? '') ?></span>
          <span class="lc-chip"><?= h($it['area']['name_th'] ?? '') ?></span>
          <?php if((int)$it['term']): ?><span class="lc-chip"><?= h(LcService::termLabel($it['term'])) ?></span><?php endif; ?>
          <?php if($mine): ?><span class="lc-chip ok">นำเข้าแล้ว v<?= h(LcService::ver($maxV)) ?></span><?php endif; ?>
          <?php if($mine && $maxV < (int)$it['version']): ?><span class="lc-chip new">มีเวอร์ชันใหม่ v<?= h(LcService::ver($it['version'])) ?></span><?php endif; ?>
        </div>
        <div class="lc-sum"><?= h($it['summary']) ?></div>
        <div class="small muted" style="margin-top:auto"><?= (int)$it['units'] ?> หน่วย · <?= (int)$it['lessons'] ?> บทเรียน<?= $it['code'] ? ' · '.h($it['code']) : '' ?></div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php if(($cat['json']['pages'] ?? 1) > 1): ?>
  <div class="row mt"><?php for($i = 1; $i <= (int)$cat['json']['pages']; $i++): ?><a class="btn btn-sm <?= $i == $f['page'] ? 'btn-primary' : 'btn-outline' ?>" href="<?= h($lcq(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div>
<?php endif; endif; ?>

<?php else: // ───────── รายละเอียด + นำเข้า ─────────
  $d = LcService::api('template', ['uid' => $tuid]);
  if(!$d['ok']){ echo '<div class="alert alert-danger">'.h($d['error']).'</div><a class="btn btn-outline" href="'.h($self).'">← กลับ</a>'; return; }
  $o = $d['json']['outline']; $t = $o['template']; $vers = $d['json']['versions'];
  $mine = $imported[$tuid] ?? [];
  $types = ['doc' => ['Aleanor Docs', 'fi-rr-presentation'], 'text' => ['เนื้อหา', 'fi-rr-document'], 'activity' => ['กิจกรรม', 'fi-rr-puzzle-alt'], 'video' => ['วิดีโอ', 'fi-rr-play-alt'],
            'pdf' => ['PDF', 'fi-rr-file-pdf'], 'link' => ['ลิงก์', 'fi-rr-link-alt'], 'quiz' => ['แบบทดสอบ → ใบงาน', 'fi-rr-list-check'], 'playground' => ['Playground → คู่มือ', 'fi-rr-cube']];
  $nLessons = 0; $nQuiz = 0; foreach($o['units'] as $u) foreach($u['lessons'] as $l){ $nLessons++; if($l['type'] === 'quiz') $nQuiz++; }
?>
<div class="page-header">
  <div><h1><i class="fi fi-rr-book-open-cover"></i> <?= h($t['title']) ?></h1>
    <p><a href="<?= h($self) ?>">← คลังบทเรียน</a> · <?= h(($t['grade']['name_th'] ?? '').' · '.($t['area']['name_th'] ?? '')) ?> · <?= h(LcService::termLabel($t['term'] ?? 0)) ?> · เวอร์ชัน <?= h(LcService::ver($t['version'])) ?></p></div>
</div>
<?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="lc-two">
  <div>
    <div class="card">
      <p style="white-space:pre-line;margin-top:0"><?= h($t['description'] ?? '') ?></p>
      <?php if(!empty($t['objectives'])): ?><h3>จุดประสงค์การเรียนรู้</h3><ul><?php foreach($t['objectives'] as $x) echo '<li>'.h($x).'</li>'; ?></ul><?php endif; ?>
      <p class="small muted"><?= count($o['units']) ?> หน่วย · <?= $nLessons ?> บทเรียน · <?= (int)($t['hours'] ?? 0) ?> ชั่วโมง · สัญญาอนุญาต <?= h($t['license'] ?? '-') ?></p>
    </div>
    <div class="card"><h2>โครงสร้างบทเรียน</h2>
      <?php foreach($o['units'] as $i => $u): ?>
        <details class="lc-unit" <?= $i < 2 ? 'open' : '' ?>>
          <summary><?= h($u['title']) ?> <span class="small muted" style="font-weight:400">· <?= count($u['lessons']) ?> บท</span></summary>
          <ul>
            <?php foreach($u['lessons'] as $l): $ty = $types[$l['type']] ?? [$l['type'], 'fi-rr-document']; ?>
              <li><i class="fi <?= h($ty[1]) ?>" style="color:var(--primary)"></i> <?= h($l['title']) ?>
                <span class="small muted">· <?= h($ty[0]) ?><?= !empty($l['minutes']) ? ' · '.(int)$l['minutes'].' นาที' : '' ?><?= !empty($l['questions']) ? ' · '.(int)$l['questions'].' ข้อ' : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <form method="post" class="card" onsubmit="var b=this.querySelector('.lc-go');b.disabled=true;b.textContent='กำลังนำเข้า…'"><?= csrf_field() ?><input type="hidden" name="action" value="import">
      <h2>นำเข้าเป็นคอร์สของฉัน</h2>
      <label>เวอร์ชัน</label>
      <select name="version"><?php foreach($vers as $v): ?><option value="<?= (int)$v['version'] ?>">v<?= h(LcService::ver($v['version'])) ?> — <?= h($v['changelog'] ?: date('d/m/Y', strtotime($v['published_at']))) ?></option><?php endforeach; ?></select>
      <?php if($nQuiz): ?><label class="tgl mt"><input type="checkbox" name="with_quiz" value="1" checked><span class="tgl-track"></span> นำแบบทดสอบมาเป็นใบงาน (<?= $nQuiz ?> ชุด)</label><?php endif; ?>
      <label class="tgl mt"><input type="checkbox" name="with_notes" value="1" checked><span class="tgl-track"></span> สร้างเอกสาร "สำหรับผู้สอน" (เฉลย · บันทึกผู้สอน · โครงคะแนน)</label>
      <p class="small muted">ระบบจะสร้างคอร์สใหม่เป็น<b>ฉบับร่าง</b> เนื้อหาบทเรียนเป็นเอกสาร Aleanor Docs ของคุณ (ใช้พื้นที่ Docs) — แก้ไข เพิ่ม/ลบบทเรียน ตั้งราคา แล้วส่งตรวจได้ตามปกติ · คอร์สที่นำเข้าต้องเป็นไปตามสัญญาอนุญาตของเทมเพลต</p>
      <button class="btn btn-primary btn-block lc-go" style="justify-content:center"><i class="fi fi-rr-cloud-download-alt"></i> นำเข้าคอร์ส</button>
    </form>
    <?php if($mine): ?>
    <div class="card"><h2>คอร์สที่นำเข้าจากเทมเพลตนี้</h2>
      <?php foreach($mine as $m): ?>
        <div class="row between small" style="padding:.4rem 0;border-bottom:1px solid var(--border)">
          <a href="<?= h(iu('course-edit', ['id' => $m['course_id']])) ?>"><?= h($m['course_title']) ?></a>
          <span class="muted">v<?= h(LcService::ver($m['version'])) ?> · <?= h(date('d/m/Y', strtotime($m['created_at']))) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if(max(array_map(function($x){ return (int)$x['version']; }, $mine)) < (int)$t['version']): ?><p class="small" style="color:#b45309">มีเวอร์ชันใหม่กว่าที่นำเข้าไว้ — นำเข้าอีกครั้งจะได้คอร์สใหม่ (คอร์สเดิมไม่ถูกแก้)</p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
