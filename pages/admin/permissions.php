<?php
// สิทธิ์ของผู้สอน — แถวบนสุด = ค่าเริ่มต้นของผู้สอนทุกคน, แถวถัดไป = override รายคน (สืบทอด/อนุญาต/ไม่อนุญาต)
$TITLE = 'สิทธิ์ของผู้สอน';
$reg = PermissionService::registry();
$instructors = db_all("SELECT ip.user_id, ip.display_name, u.email FROM instructor_profiles ip JOIN users u ON u.id = ip.user_id WHERE ip.status = 'approved' ORDER BY ip.display_name");
$rows = [];
foreach(db_all("SELECT scope_id, feature, allowed FROM instructor_permissions") as $r) $rows[(int)$r['scope_id']][$r['feature']] = (int)$r['allowed'];

if(is_post()){
    require_csrf();
    $n = 0;
    foreach($reg as $f => $def){
        $d = isset($_POST['def'][$f]) ? 1 : 0;
        $cur = array_key_exists($f, $rows[0] ?? []) ? $rows[0][$f] : (int)$def['default'];
        if($d !== $cur) $n += PermissionService::set(null, $f, $d) ? 1 : 0;
        foreach($instructors as $i){
            $v = $_POST['ov'][$i['user_id']][$f] ?? '';
            $want = $v === '' ? null : (int)$v;
            $have = $rows[(int)$i['user_id']][$f] ?? null;
            if($want !== $have) $n += PermissionService::set((int)$i['user_id'], $f, $want) ? 1 : 0;
        }
    }
    flash($n ? "บันทึกแล้ว ($n รายการ — ลง audit log)" : 'ไม่มีการเปลี่ยนแปลง');
    redirect(au('permissions'));
}
$groups = [];
foreach($reg as $f => $d) $groups[$d['group']][] = $f;
?>
<div class="page-header"><div><h1><i class="fi fi-rr-shield-check"></i> สิทธิ์ของผู้สอน</h1>
  <p>ติ๊ก "ค่าเริ่มต้น" = ผู้สอนทุกคนได้สิทธิ์นั้น · ตั้งรายคนเพื่อยกเว้น (สืบทอด = ใช้ค่าเริ่มต้น) · ฟีเจอร์ของโมดูลที่ปิดจะใช้ไม่ได้เสมอ — <a href="<?= h(au('modules')) ?>">จัดการโมดูล</a></p></div></div>
<form method="post"><?= csrf_field() ?>
<div class="card"><div class="table-wrap" style="max-height:72vh">
<table class="perm-matrix">
  <thead>
    <tr><th>ผู้สอน</th><?php foreach($groups as $g => $fs): ?><th class="c" colspan="<?= count($fs) ?>" style="border-left:1px solid var(--border)"><?= h($g) ?></th><?php endforeach; ?></tr>
    <tr><th></th><?php foreach($groups as $fs) foreach($fs as $f): $mod = $reg[$f]['module']; ?>
      <th class="c small" style="text-transform:none;min-width:96px"><?= h($reg[$f]['label']) ?><?php if($mod && !PermissionService::moduleOn($mod)): ?><div><span class="badge badge-gray">โมดูลปิด</span></div><?php endif; ?></th>
    <?php endforeach; ?></tr>
  </thead>
  <tbody>
    <tr style="background:var(--primary-bg)"><td><strong>ค่าเริ่มต้น</strong><div class="small muted">ผู้สอนทุกคน</div></td>
      <?php foreach($groups as $fs) foreach($fs as $f): $on = array_key_exists($f, $rows[0] ?? []) ? $rows[0][$f] : (int)$reg[$f]['default']; ?>
        <td class="c"><input type="checkbox" name="def[<?= h($f) ?>]" value="1" <?= $on ? 'checked' : '' ?> style="width:18px;height:18px"></td>
      <?php endforeach; ?></tr>
    <?php foreach($instructors as $i): $iid = (int)$i['user_id']; ?>
    <tr><td><a href="<?= h(au('instructor', ['id' => $iid])) ?>"><?= h($i['display_name']) ?></a><div class="small muted"><?= h($i['email']) ?></div></td>
      <?php foreach($groups as $fs) foreach($fs as $f): $ov = $rows[$iid][$f] ?? null; $eff = PermissionService::can($iid, $f); ?>
        <td class="c"><select name="ov[<?= $iid ?>][<?= h($f) ?>]" title="ผลจริง: <?= $eff ? 'ใช้ได้' : 'ใช้ไม่ได้' ?>" style="<?= $ov === null ? '' : ($ov ? 'border-color:var(--success)' : 'border-color:var(--danger)') ?>">
          <option value="" <?= $ov === null ? 'selected' : '' ?>>สืบทอด (<?= $eff ? '✓' : '✗' ?>)</option>
          <option value="1" <?= $ov === 1 ? 'selected' : '' ?>>อนุญาต</option>
          <option value="0" <?= $ov === 0 ? 'selected' : '' ?>>ไม่อนุญาต</option></select></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if(!$instructors): ?><tr><td colspan="99" class="muted">ยังไม่มีผู้สอนที่อนุมัติแล้ว</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<button class="btn btn-primary"><i class="fi fi-rr-disk"></i> บันทึกสิทธิ์</button>
</form>
