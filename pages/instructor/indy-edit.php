<?php
// Aleanor Indy — ตัวแก้ไขโปรเจกต์ (ฉาก + ทางเลือก) ตามแนว japaipai: scene → branches
require_once dirname(__DIR__, 2).'/services/IndyService.php';   // เผื่อ bootstrap ยังไม่ได้ require
// พอร์ตจาก aleanor_ai/dashboard/course/indy-edit.php
$MENU = 'indy';
$uid = current_user_id();
if(!PermissionService::can($uid, 'lesson.indy')){
    echo '<div class="alert alert-danger"><i class="fi fi-rr-lock"></i> คุณไม่มีสิทธิ์ใช้ Aleanor Indy หรือโมดูลนี้ถูกปิดอยู่</div>'; return;
}
$pid = (int)get('id');
$proj = $pid ? IndyService::project($pid) : null;
if(!$proj || !IndyService::canEdit($pid, $uid)){
    $TITLE = 'ไม่พบโปรเจกต์';
    echo '<div class="card"><div class="card-body">ไม่พบโปรเจกต์ หรือคุณไม่ใช่เจ้าของ — <a href="'.h(iu('indy')).'">กลับรายการ</a></div></div>'; return;
}
$TITLE = 'Indy: '.$proj['name'];
$selfUrl = iu('indy-edit', ['id' => $pid]);

if(is_post()){
    // ไฟล์ใหญ่เกิน post_max_size → PHP ทิ้ง $_POST ทั้งหมด (token หายด้วย) — แจ้งให้ตรงสาเหตุ
    if(empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0){
        flash('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (post_max_size = '.ini_get('post_max_size').')', 'danger');
        redirect($selfUrl);
    }
    require_csrf();
    $act = post('action');
    try {
        switch($act){
            case 'save_project':
                IndyService::updateProject($pid, [
                    'name' => post('name'), 'description' => mb_substr(post('description'), 0, 2000),
                    'course_id' => (int)post('course_id'), 'hide_controls' => (bool)post('hide_controls'),
                ]);
                flash('บันทึกการตั้งค่าโปรเจกต์แล้ว');
                break;

            case 'toggle_controls':
                IndyService::updateProject($pid, ['hide_controls' => !(int)$proj['hide_controls']]);
                flash((int)$proj['hide_controls'] ? 'แสดงแถบควบคุมวิดีโอแล้ว' : 'ซ่อนแถบควบคุมวิดีโอแล้ว');
                break;

            case 'save_scene':
                $sid = (int)post('scene_id');
                $sc = $sid ? IndyService::scene($sid) : null;
                if($sid && (!$sc || (int)$sc['project_id'] !== $pid)) throw new InvalidArgumentException('ไม่พบฉาก');
                // วิดีโอ: ไฟล์อัปโหลด > ลิงก์ > (แก้ไข) คงของเดิม / ติ๊กเอาวิดีโอออก
                $video = null;
                if(!empty($_FILES['upload']['name'])){
                    $up = IndyService::storeUpload($pid, $_FILES['upload']);
                    if(!$up['ok']) throw new InvalidArgumentException($up['error']);
                    $video = $up['file'];
                } elseif(post('video_url') !== ''){
                    $video = post('video_url');
                } elseif(!$sc || post('clear_video')){
                    $video = '';
                }
                try {
                    if($sc){
                        $f = ['name' => post('name')];
                        if($video !== null) $f['video'] = $video;
                        IndyService::updateScene($sid, $f);
                    } else {
                        IndyService::addScene($pid, post('name'), $video ?? '');
                    }
                } catch(InvalidArgumentException $e){
                    if(isset($up) && $up['ok']) @unlink(IndyService::uploadDir($pid).'/'.$up['file']);   // บันทึกไม่ผ่าน → ทิ้งไฟล์ที่เพิ่งอัป
                    throw $e;
                }
                flash('บันทึกฉากแล้ว');
                break;

            case 'delete_scene':
                $sc = IndyService::scene((int)post('scene_id'));
                if(!$sc || (int)$sc['project_id'] !== $pid) throw new InvalidArgumentException('ไม่พบฉาก');
                IndyService::deleteScene((int)$sc['id']);
                flash('ลบฉากแล้ว (ทางเลือกที่เชื่อมกับฉากนี้ถูกลบด้วย)');
                break;

            case 'set_entry':
                IndyService::setEntry($pid, (int)post('scene_id'));
                flash('ตั้งเป็นฉากเริ่มต้นแล้ว');
                break;

            case 'move_scene':   // เลื่อนลำดับขึ้น/ลง (สลับ sort กับฉากข้างเคียง)
                $list = IndyService::scenes($pid);
                $ids = array_map('intval', array_column($list, 'id'));
                $i = array_search((int)post('scene_id'), $ids, true);
                $j = $i === false ? false : (post('dir') === 'up' ? $i - 1 : $i + 1);
                if($i !== false && isset($ids[$j])){
                    db_tx(function() use ($ids, $i, $j){
                        foreach($ids as $k => $id){
                            $pos = $k === $i ? $j : ($k === $j ? $i : $k);
                            IndyService::updateScene($id, ['sort' => $pos + 1]);
                        }
                    });
                }
                break;

            case 'add_branch':
                $from = IndyService::scene((int)post('scene_id'));
                if(!$from || (int)$from['project_id'] !== $pid) throw new InvalidArgumentException('ไม่พบฉาก');
                IndyService::addBranch((int)$from['id'], (int)post('target_scene_id'), post('label'));
                flash('เพิ่มทางเลือกแล้ว');
                break;

            case 'delete_branch':
                IndyService::deleteBranch((int)post('branch_id'), $pid);
                break;
        }
    } catch(InvalidArgumentException $e){
        flash($e->getMessage(), 'danger');
    }
    redirect($selfUrl);
}

$scenes = IndyService::scenes($pid);
$byScene = IndyService::branchesByScene($pid);
$sceneNames = array_column($scenes, 'name', 'id');
$entryId = (int)$proj['entry_scene_id'];
if((!$entryId || !isset($sceneNames[$entryId])) && $scenes) $entryId = (int)$scenes[0]['id'];
$myCourses = db_all("SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY updated_at DESC", [(int)$proj['owner_id']]);
$lessons = IndyService::lessonsUsing($pid);
$playData = IndyService::playData($pid);
$maxMb = (int)(IndyService::maxUploadBytes() / 1048576);
$jf = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$av = function($f){ $p = dirname(__DIR__, 2).'/assets/indy/'.$f; return asset('assets/indy/'.$f).'?v='.(is_file($p) ? filemtime($p) : 0); };
?>
<link rel="stylesheet" href="<?= h($av('indy-player.css')) ?>">
<div class="page-header">
  <div><h1><i class="fi fi-rr-shuffle"></i> <?= h($proj['name']) ?></h1>
    <p><a href="<?= h(iu('indy')) ?>">&larr; โปรเจกต์ทั้งหมด</a> · เพิ่มฉาก (วิดีโอ) แล้วเชื่อมด้วยปุ่มทางเลือก — ฉากที่ไม่มีทางเลือกคือฉากจบ</p></div>
  <div class="row gap-2">
    <button type="button" class="btn btn-outline" onclick="indyPreview()" <?= $playData ? '' : 'disabled' ?>><i class="fi fi-rr-play"></i> ทดลองเล่น</button>
    <button type="button" class="btn btn-primary" onclick="openScene()"><i class="fi fi-rr-plus"></i> เพิ่มฉาก</button>
  </div>
</div>

<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start">
<div class="card">
  <div class="card-header"><div class="card-header-title">ฉาก (<?= count($scenes) ?>)</div></div>
  <?php $no = 0; foreach($scenes as $sc): $no++; $sid = (int)$sc['id'];
    $isEntry = $sid === $entryId;
    $branches = $byScene[$sid] ?? [];
    $isUrl = IndyService::isUrl($sc['video']);
    $vidShort = $sc['video'] === '' ? '' : ($isUrl ? parse_url($sc['video'], PHP_URL_HOST) : 'ไฟล์ที่อัปโหลด');
  ?>
  <div class="sc-row">
    <div class="sc-head">
      <span class="sc-no"><?= $no ?></span>
      <div class="sc-info">
        <div class="sc-name"><?= h($sc['name']) ?>
          <?php if($isEntry): ?><span class="sc-entry"><i class="fi fi-rr-flag"></i> จุดเริ่มต้น</span><?php endif; ?>
          <?php if(!$branches): ?><span class="sc-end">ฉากจบ</span><?php endif; ?>
        </div>
        <div class="sc-sub">
          <?php if($vidShort !== ''): ?><span><i class="fi fi-rr-play-circle"></i> <?= h($vidShort) ?></span>
          <?php else: ?><span style="color:#ef4444"><i class="fi fi-rr-triangle-warning"></i> ยังไม่มีวิดีโอ</span><?php endif; ?>
        </div>
      </div>
      <div class="sc-acts">
        <?php if($no > 1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="move_scene"><input type="hidden" name="dir" value="up"><input type="hidden" name="scene_id" value="<?= $sid ?>"><button class="btn-icon" title="เลื่อนขึ้น"><i class="fi fi-rr-angle-small-up"></i></button></form><?php endif; ?>
        <?php if($no < count($scenes)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="move_scene"><input type="hidden" name="dir" value="down"><input type="hidden" name="scene_id" value="<?= $sid ?>"><button class="btn-icon" title="เลื่อนลง"><i class="fi fi-rr-angle-small-down"></i></button></form><?php endif; ?>
        <?php if(!$isEntry): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_entry"><input type="hidden" name="scene_id" value="<?= $sid ?>"><button class="btn-icon" title="ตั้งเป็นจุดเริ่มต้น"><i class="fi fi-rr-flag"></i></button></form><?php endif; ?>
        <button type="button" class="btn-icon" title="แก้ไข" onclick='editScene(<?= json_encode(['id' => $sid, 'n' => $sc['name'], 'v' => $isUrl ? $sc['video'] : '', 'f' => !$isUrl && $sc['video'] !== ''], $jf) ?>)'><i class="fi fi-rr-pencil"></i></button>
        <form method="post" onsubmit="return confirm('ลบฉากนี้? ทางเลือกที่เชื่อมเข้า/ออกจากฉากนี้จะถูกลบด้วย')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_scene"><input type="hidden" name="scene_id" value="<?= $sid ?>"><button class="btn-icon" title="ลบ"><i class="fi fi-rr-trash"></i></button></form>
      </div>
    </div>
    <div class="sc-branches">
      <?php foreach($branches as $b): ?>
        <span class="sc-branch">
          <i class="fi fi-rr-arrow-turn-down-right" style="font-size:.7rem"></i>
          <strong><?= h($b['label']) ?></strong>
          <span class="sc-target">→ <?= h($sceneNames[$b['target_scene_id']] ?? '?') ?></span>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_branch"><input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>"><button class="sc-branch-x" title="ลบทางเลือก">&times;</button></form>
        </span>
      <?php endforeach; ?>
      <?php if(count($scenes) > 1): ?><button type="button" class="sc-branch-add" onclick='openBranch(<?= $sid ?>, <?= json_encode($sc['name'], $jf) ?>)'><i class="fi fi-rr-plus"></i> เพิ่มทางเลือก</button><?php endif; ?>
    </div>
  </div>
  <?php endforeach; if($no === 0): ?>
  <div class="card-body text-center" style="padding:3rem 1rem;color:var(--text-muted)">
    <i class="fi fi-rr-film" style="font-size:2.5rem;opacity:.35;display:block;margin-bottom:.6rem"></i>ยังไม่มีฉาก — เพิ่มฉากแรกเพื่อเริ่มต้น
    <div class="mt"><button type="button" class="btn btn-primary btn-sm" onclick="openScene()"><i class="fi fi-rr-plus"></i> เพิ่มฉาก</button></div>
  </div>
  <?php endif; ?>
</div>

<div>
  <div class="card mb">
    <div class="card-header"><div class="card-header-title">ตั้งค่าโปรเจกต์</div></div>
    <div class="card-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_project">
      <div class="form-group"><label class="form-label">ชื่อโปรเจกต์ *</label><input type="text" name="name" class="form-control" maxlength="255" required value="<?= h($proj['name']) ?>"></div>
      <div class="form-group"><label class="form-label">คอร์ส</label>
        <select name="course_id" class="form-control form-select"><option value="0">— ไม่ผูกคอร์ส —</option>
          <?php foreach($myCourses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$proj['course_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-group"><label class="form-label">คำอธิบาย</label><textarea name="description" class="form-control" rows="3" maxlength="2000"><?= h($proj['description']) ?></textarea></div>
      <label class="check"><input type="checkbox" name="hide_controls" value="1" <?= (int)$proj['hide_controls'] ? 'checked' : '' ?>> ซ่อนแถบควบคุมวิดีโอ (ผู้เรียนหยุด/เลื่อนเองไม่ได้)</label>
      <button class="btn btn-primary btn-sm mt">บันทึก</button>
    </form></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-header-title">ใช้ในบทเรียน</div></div>
    <div class="card-body small">
      <?php if(!$lessons): ?><p class="muted">ยังไม่ถูกใช้ — สร้างบทเรียนชนิด “Aleanor Indy” ในหน้าแก้ไขคอร์ส แล้วเลือกโปรเจกต์นี้</p>
      <?php else: foreach($lessons as $l): ?><div><i class="fi fi-rr-book-alt"></i> <?= h($l['course_title']) ?> › <?= h($l['title']) ?></div><?php endforeach; endif; ?>
      <p class="muted mt">ห้ามเลื่อนข้ามวิดีโอไปข้างหน้าเสมอ · อัปโหลดได้ .mp4 / .webm ไม่เกิน <?= $maxMb ?> MB</p>
    </div>
  </div>
</div>
</div>

<!-- Modal: ฉาก -->
<div class="modal-overlay" id="sceneModal"><div class="modal-box"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save_scene"><input type="hidden" name="scene_id" id="sc_id">
  <div class="modal-header"><div class="modal-title" id="sceneTitle">เพิ่มฉาก</div><button type="button" class="modal-close" onclick="closeModal('sceneModal')">&times;</button></div>
  <div class="modal-body">
    <div class="form-group"><label class="form-label">ชื่อฉาก *</label><input type="text" name="name" id="sc_name" class="form-control" maxlength="255" required placeholder="เช่น ลูกค้าเดินเข้าร้าน"></div>
    <div class="form-group"><label class="form-label">ลิงก์วิดีโอ (.mp4 / .webm / Cloudflare Stream)</label><input type="url" name="video_url" id="sc_video" class="form-control" placeholder="https://..."></div>
    <div class="form-group"><label class="form-label">หรืออัปโหลดไฟล์</label><input type="file" name="upload" class="form-control" accept=".mp4,.webm,video/mp4,video/webm">
      <small class="text-muted">ไม่เกิน <?= $maxMb ?> MB — ถ้าเลือกไฟล์ จะใช้แทนลิงก์และแทนไฟล์เดิม</small></div>
    <label class="check" id="sc_clear_wrap" style="display:none"><input type="checkbox" name="clear_video" value="1"> เอาวิดีโอเดิมออก (ฉากไม่มีวิดีโอ)</label>
    <div class="small muted" id="sc_file_note" style="display:none;margin-top:.4rem"><i class="fi fi-rr-info"></i> ฉากนี้ใช้ไฟล์ที่อัปโหลดไว้ — เว้นว่างทั้งสองช่องเพื่อใช้ไฟล์เดิม</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('sceneModal')">ยกเลิก</button><button class="btn btn-primary">บันทึก</button></div>
</form></div></div>

<!-- Modal: ทางเลือก -->
<div class="modal-overlay" id="branchModal"><div class="modal-box"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="add_branch"><input type="hidden" name="scene_id" id="br_from">
  <div class="modal-header"><div class="modal-title">เพิ่มทางเลือก <span class="text-muted" style="font-weight:400;font-size:.82rem" id="br_from_name"></span></div><button type="button" class="modal-close" onclick="closeModal('branchModal')">&times;</button></div>
  <div class="modal-body">
    <div class="form-group"><label class="form-label">ข้อความบนปุ่ม *</label><input type="text" name="label" id="br_label" class="form-control" maxlength="255" required placeholder="เช่น ทักทายลูกค้า"></div>
    <div class="form-group"><label class="form-label">ไปฉาก *</label>
      <select name="target_scene_id" id="br_target" class="form-control form-select" required>
        <option value="">— เลือกฉากปลายทาง —</option>
        <?php foreach($scenes as $sc): ?><option value="<?= (int)$sc['id'] ?>"><?= h($sc['name']) ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('branchModal')">ยกเลิก</button><button class="btn btn-primary">บันทึก</button></div>
</form></div></div>

<!-- Modal: ทดลองเล่น -->
<div class="modal-overlay" id="previewModal"><div class="modal-box" style="max-width:900px;width:96%">
  <div class="modal-header"><div class="modal-title"><i class="fi fi-rr-play"></i> ทดลองเล่น (ไม่บันทึกความคืบหน้า)</div><button type="button" class="modal-close" onclick="indyPreviewClose()">&times;</button></div>
  <div class="modal-body"><div id="indyPreview"></div></div>
</div></div>

<style>
.sc-row{padding:14px 18px;border-bottom:1px solid var(--border)}
.sc-row:last-child{border-bottom:none}
.sc-head{display:flex;align-items:center;gap:12px}
.sc-no{flex:0 0 auto;width:26px;height:26px;border-radius:8px;background:var(--primary-bg);color:var(--primary);font-size:.78rem;font-weight:800;display:flex;align-items:center;justify-content:center}
.sc-info{flex:1;min-width:0}
.sc-name{font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sc-entry{font-size:.68rem;font-weight:700;color:#10b981;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);padding:1px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:4px}
.sc-end{font-size:.68rem;font-weight:700;color:#8b5cf6;background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.3);padding:1px 9px;border-radius:20px}
.sc-sub{font-size:.78rem;color:var(--text-muted);margin-top:2px}
.sc-acts{display:flex;gap:5px;flex:0 0 auto;flex-wrap:wrap;justify-content:flex-end}
.sc-acts form{display:inline}
.sc-branches{display:flex;flex-wrap:wrap;gap:7px;margin:.6rem 0 0 38px}
.sc-branch{display:inline-flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:4px 6px 4px 11px;font-size:.8rem}
.sc-target{color:var(--text-muted)}
.sc-branch-x{border:none;background:transparent;color:var(--text-muted);cursor:pointer;font-size:1rem;line-height:1;padding:0 4px;border-radius:6px}
.sc-branch-x:hover{color:#ef4444}
.sc-branch-add{display:inline-flex;align-items:center;gap:5px;border:1.5px dashed var(--border);background:transparent;color:var(--text-muted);border-radius:10px;padding:4px 12px;font-size:.78rem;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s}
.sc-branch-add:hover{border-color:var(--primary);color:var(--primary)}
@media (max-width:900px){ .grid.g2[style]{grid-template-columns:1fr !important} .sc-head{flex-wrap:wrap} .sc-branches{margin-left:0} }
</style>
<script src="<?= h($av('indy-player.js')) ?>"></script>
<script>
var INDY_DATA = <?= json_encode($playData, $jf) ?>, indyPv = null;
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function openScene(){
  document.getElementById('sceneTitle').textContent='เพิ่มฉาก';
  document.getElementById('sc_id').value=''; document.getElementById('sc_name').value=''; document.getElementById('sc_video').value='';
  document.getElementById('sc_clear_wrap').style.display='none'; document.getElementById('sc_file_note').style.display='none';
  document.getElementById('sceneModal').classList.add('open'); document.getElementById('sc_name').focus();
}
function editScene(d){
  document.getElementById('sceneTitle').textContent='แก้ไขฉาก';
  document.getElementById('sc_id').value=d.id; document.getElementById('sc_name').value=d.n||''; document.getElementById('sc_video').value=d.v||'';
  document.getElementById('sc_clear_wrap').style.display=(d.v||d.f)?'':'none';
  document.getElementById('sc_file_note').style.display=d.f?'':'none';
  document.getElementById('sceneModal').classList.add('open');
}
function openBranch(id,name){
  document.getElementById('br_from').value=id; document.getElementById('br_from_name').textContent='('+name+')';
  document.getElementById('br_label').value='';
  var t=document.getElementById('br_target'); t.value='';
  Array.prototype.forEach.call(t.options,function(o){ o.disabled = (o.value!=='' && +o.value===+id); });   // ไปฉากตัวเองไม่ได้
  document.getElementById('branchModal').classList.add('open'); document.getElementById('br_label').focus();
}
function indyPreview(){
  if(!INDY_DATA) return;
  document.getElementById('previewModal').classList.add('open');
  indyPv = IndyPlayer.mount(document.getElementById('indyPreview'), INDY_DATA, {});
}
function indyPreviewClose(){ if(indyPv){ indyPv.destroy(); indyPv=null; } closeModal('previewModal'); }
</script>
