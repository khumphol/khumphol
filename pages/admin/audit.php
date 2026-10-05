<?php
$TITLE = 'Audit log';
$ent = get('entity');
$rows = db_all("SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ".($ent !== '' ? "WHERE a.entity = ?" : '')." ORDER BY a.id DESC LIMIT 300", $ent !== '' ? [$ent] : []);
$entities = array_column(db_all("SELECT DISTINCT entity FROM audit_logs ORDER BY entity"), 'entity');
?>
<h1>Audit log</h1>
<div class="row mb"><a class="btn btn-sm <?= $ent === '' ? 'btn-primary' : '' ?>" href="<?= h(au('audit')) ?>">ทั้งหมด</a>
  <?php foreach($entities as $e): ?><a class="btn btn-sm <?= $ent === $e ? 'btn-primary' : '' ?>" href="<?= h(au('audit', ['entity' => $e])) ?>"><?= h($e) ?></a><?php endforeach; ?></div>
<div class="card table-wrap"><?php if(!$rows): ?><p class="muted">ยังไม่มีบันทึก</p><?php else: ?><table>
  <tr><th>เวลา</th><th>ผู้ทำ</th><th>การกระทำ</th><th>เป้าหมาย</th><th>ก่อน</th><th>หลัง</th></tr>
  <?php foreach($rows as $r): ?><tr><td class="small nowrap"><?= h($r['created_at']) ?></td><td class="small"><?= h($r['name'] ?? 'ระบบ') ?><div class="muted"><?= h($r['ip']) ?></div></td>
    <td><?= h($r['action']) ?></td><td class="small"><?= h($r['entity']) ?><?= $r['entity_id'] ? ' #'.(int)$r['entity_id'] : '' ?></td>
    <td class="small muted"><code><?= h($r['before_json']) ?></code></td><td class="small"><code><?= h($r['after_json']) ?></code></td></tr><?php endforeach; ?>
</table><?php endif; ?></div>
