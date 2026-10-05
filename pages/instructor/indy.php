<?php
// Aleanor Indy — รายการโปรเจกต์ของฉัน + สร้างโปรเจกต์ใหม่
require_once dirname(__DIR__, 2).'/services/IndyService.php';   // เผื่อ bootstrap ยังไม่ได้ require
$TITLE = 'Aleanor Indy';
$MENU = 'indy';
$uid = current_user_id();
if(!PermissionService::can($uid, 'lesson.indy')){
    echo '<div class="alert alert-danger"><i class="fi fi-rr-lock"></i> คุณไม่มีสิทธิ์ใช้ Aleanor Indy หรือโมดูลนี้ถูกปิดอยู่</div>'; return;
}

if(is_post()){
    require_csrf();
    $act = post('action');
    try {
        if($act === 'create'){
            $id = IndyService::createProject($uid, post('name'), (int)post('course_id'), mb_substr(post('description'), 0, 2000), (bool)post('hide_controls'));
            audit('indy.create', 'indy_project', $id, null, ['name' => post('name')]);
            flash('สร้างโปรเจกต์แล้ว — เริ่มเพิ่มฉากได้เลย');
            redirect(iu('indy-edit', ['id' => $id]));
        }
        if($act === 'delete'){
            $pid = (int)post('id');
            if(!IndyService::canEdit($pid, $uid)) throw new InvalidArgumentException('ไม่พบโปรเจกต์');
            $used = IndyService::lessonsUsing($pid);
            if($used) throw new InvalidArgumentException('โปรเจกต์นี้ถูกใช้ในบทเรียน '.count($used).' บท ('.implode(', ', array_column($used, 'title')).') — ลบ/เปลี่ยนบทเรียนเหล่านั้นก่อน');
            $p = IndyService::project($pid);
            IndyService::deleteProject($pid);
            audit('indy.delete', 'indy_project', $pid, ['name' => $p['name']], null);
            flash('ลบโปรเจกต์แล้ว');
        }
    } catch(InvalidArgumentException $e){
        flash($e->getMessage(), 'danger');
    }
    redirect(iu('indy'));
}

$projects = IndyService::projectsFor($uid);
$myCourses = db_all("SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY updated_at DESC", [$uid]);
$usage = [];
if($projects){
    $ids = array_map('intval', array_column($projects, 'id'));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach(db_all("SELECT ref_id, COUNT(*) n FROM lessons WHERE type = 'indy' AND ref_id IN ($ph) GROUP BY ref_id", $ids) as $r) $usage[(int)$r['ref_id']] = (int)$r['n'];
}
?>
<div class="page-header">
  <div><h1><i class="fi fi-rr-shuffle"></i> Aleanor Indy</h1>
    <p class="muted">วิดีโอแบบเลือกเส้นทาง — แต่ละฉากเป็นวิดีโอ เมื่อจบฉากผู้เรียนเลือกปุ่มเพื่อไปฉากถัดไป</p></div>
  <div><button type="button" class="btn btn-primary" onclick="document.getElementById('indyNew').classList.add('open')"><i class="fi fi-rr-plus"></i> สร้างโปรเจกต์</button></div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title">โปรเจกต์ของฉัน</div></div>
  <?php if(!$projects): ?>
  <div class="card-body text-center" style="padding:3rem 1rem">
    <i class="fi fi-rr-film" style="font-size:2.5rem;opacity:.35;display:block;margin-bottom:.6rem"></i>
    <p class="muted">ยังไม่มีโปรเจกต์ Indy</p>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('indyNew').classList.add('open')"><i class="fi fi-rr-plus"></i> สร้างโปรเจกต์แรก</button>
  </div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>โปรเจกต์</th><th>คอร์ส</th><th class="num">ฉาก</th><th class="num">ใช้ในบทเรียน</th><th>แถบควบคุม</th><th class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach($projects as $p): $pid = (int)$p['id']; $n = $usage[$pid] ?? 0; ?>
      <tr>
        <td><a href="<?= h(iu('indy-edit', ['id' => $pid])) ?>"><strong><?= h($p['name']) ?></strong></a>
          <?php if($p['description'] !== null && $p['description'] !== ''): ?><div class="small muted"><?= h(mb_strimwidth($p['description'], 0, 90, '…')) ?></div><?php endif; ?></td>
        <td><?= $p['course_title'] !== null ? h($p['course_title']) : '<span class="muted">—</span>' ?></td>
        <td class="num"><?= (int)$p['n_scenes'] ?></td>
        <td class="num"><?= $n ? '<span class="badge badge-success">'.$n.'</span>' : '<span class="muted">0</span>' ?></td>
        <td><?= (int)$p['hide_controls'] ? '<span class="badge badge-warning">ซ่อน</span>' : '<span class="badge badge-gray">แสดง</span>' ?></td>
        <td class="text-end nowrap">
          <a class="btn btn-sm btn-outline" href="<?= h(iu('indy-edit', ['id' => $pid])) ?>"><i class="fi fi-rr-pencil"></i> แก้ไขฉาก</a>
          <?php if(!$n): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('ลบโปรเจกต์นี้พร้อมฉาก ทางเลือก และไฟล์วิดีโอที่อัปโหลดทั้งหมด?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $pid ?>">
            <button class="btn-icon" title="ลบ"><i class="fi fi-rr-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal-overlay" id="indyNew"><div class="modal-box"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="modal-header"><div class="modal-title">สร้างโปรเจกต์ Indy</div><button type="button" class="modal-close" onclick="document.getElementById('indyNew').classList.remove('open')">&times;</button></div>
  <div class="modal-body">
    <div class="form-group"><label class="form-label">ชื่อโปรเจกต์ *</label><input type="text" name="name" class="form-control" maxlength="255" required placeholder="เช่น สถานการณ์บริการลูกค้า"></div>
    <div class="form-group"><label class="form-label">คอร์ส (ไม่บังคับ)</label>
      <select name="course_id" class="form-control form-select"><option value="0">— ไม่ผูกคอร์ส —</option>
        <?php foreach($myCourses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['title']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group"><label class="form-label">คำอธิบาย</label><textarea name="description" class="form-control" rows="3" maxlength="2000"></textarea></div>
    <label class="check"><input type="checkbox" name="hide_controls" value="1"> ซ่อนแถบควบคุมวิดีโอ (ผู้เรียนหยุด/เลื่อนเองไม่ได้)</label>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="document.getElementById('indyNew').classList.remove('open')">ยกเลิก</button><button class="btn btn-primary">สร้าง</button></div>
</form></div></div>
