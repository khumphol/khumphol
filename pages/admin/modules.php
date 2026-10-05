<?php
// เปิด/ปิดโมดูลทั้งระบบ (แบบ aleanor_ai admin-modules: setting mod_<key>)
$TITLE = 'โมดูล';
$mods = PermissionService::modules();
if(is_post()){
    require_csrf();
    $changed = [];
    foreach($mods as $k => $m){
        $v = post('mod_'.$k) ? '1' : '0';
        if(setting('mod_'.$k, '1') !== $v){ setting_set('mod_'.$k, $v); $changed[$k] = $v; }
    }
    foreach(['docs_quota_mb', 'docs_trash_days', 'cert_min_progress', 'indy_max_upload_mb'] as $k)
        if(isset($_POST[$k]) && is_numeric(post($k)) && setting($k, '') !== post($k)){ setting_set($k, (string)(int)post($k)); $changed[$k] = post($k); }
    if($changed) audit('modules_updated', 'settings', null, null, $changed);
    flash($changed ? 'บันทึกแล้ว' : 'ไม่มีการเปลี่ยนแปลง'); redirect(au('modules'));
}
?>
<div class="page-header"><div><h1><i class="fi fi-rr-apps"></i> โมดูล</h1><p>ปิดโมดูล = ซ่อนเมนูและบล็อกการใช้งานของทุกคน (บทเรียนที่สร้างไว้จะไม่แสดงต่อผู้เรียนจนกว่าจะเปิดอีกครั้ง)</p></div></div>
<form method="post"><?= csrf_field() ?>
<div class="card"><div class="card-body">
  <?php foreach($mods as $k => $m): ?>
    <div class="row between" style="padding:.75rem 0;border-bottom:1px solid var(--border)">
      <div class="row"><span class="stat-icon" style="width:40px;height:40px;border-radius:12px;background:var(--primary-bg);color:var(--primary);display:inline-flex;align-items:center;justify-content:center"><i class="fi <?= h($m['icon']) ?>"></i></span>
        <div><strong><?= h($m['label']) ?></strong><div class="small muted"><?= h($m['desc']) ?></div></div></div>
      <label class="tgl" style="margin:0"><input type="checkbox" name="mod_<?= h($k) ?>" value="1" <?= PermissionService::moduleOn($k) ? 'checked' : '' ?>><span class="tgl-track"></span></label>
    </div>
  <?php endforeach; ?>
  <div class="grid g4 mt">
    <div><label>โควตา Docs ต่อคน (MB, 0 = ไม่จำกัด)</label><input type="number" name="docs_quota_mb" min="0" value="<?= h(setting('docs_quota_mb', '1024')) ?>"></div>
    <div><label>ลบจากถังขยะ Docs อัตโนมัติ (วัน)</label><input type="number" name="docs_trash_days" min="0" value="<?= h(setting('docs_trash_days', '30')) ?>"></div>
    <div><label>อัปโหลดวิดีโอ Indy สูงสุด (MB)</label><input type="number" name="indy_max_upload_mb" min="1" value="<?= h(setting('indy_max_upload_mb', '200')) ?>"></div>
    <div><label>ออกใบประกาศเมื่อเรียนครบ (%)</label><input type="number" name="cert_min_progress" min="1" max="100" value="<?= h(setting('cert_min_progress', '100')) ?>"></div>
  </div>
  <button class="btn btn-primary mt"><i class="fi fi-rr-disk"></i> บันทึก</button>
</div></div>
</form>
