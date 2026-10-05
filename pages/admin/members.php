<?php
$TITLE = 'สมาชิก';
if(is_post()){
    require_csrf();
    $id = (int)post('id');
    if($id === current_user_id()){ flash('แก้สิทธิ์บัญชีตัวเองไม่ได้', 'danger'); redirect(au('members')); }
    $u = db_one("SELECT id, status, is_admin FROM users WHERE id = ?", [$id]);
    if($u && post('action') === 'toggle_status'){
        db_write("UPDATE users SET status = 1 - status WHERE id = ?", [$id]);
        audit('user_status', 'user', $id, ['status' => (int)$u['status']], ['status' => 1 - (int)$u['status']]);
    }
    if($u && post('action') === 'toggle_admin'){
        db_write("UPDATE users SET is_admin = 1 - is_admin WHERE id = ?", [$id]);
        audit('user_admin', 'user', $id, ['is_admin' => (int)$u['is_admin']], ['is_admin' => 1 - (int)$u['is_admin']]);
    }
    flash('อัปเดตแล้ว'); redirect(au('members', ['q' => get('q')]));
}
$q = get('q');
$rows = db_all("SELECT u.*, ip.status ist, (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.status = 'active') n_courses,
                  (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.user_id = u.id AND o.status IN ('paid','partially_refunded')) spent
                FROM users u LEFT JOIN instructor_profiles ip ON ip.user_id = u.id
                ".($q !== '' ? "WHERE u.email LIKE ? OR u.name LIKE ?" : '')." ORDER BY u.id DESC LIMIT 300", $q !== '' ? ["%$q%", "%$q%"] : []);
?>
<div class="page-header"><div><h1><i class="fi fi-rr-users"></i> สมาชิก</h1><p>ผู้ใช้ทั้งหมด <?= (int)db_val("SELECT COUNT(*) FROM users") ?> คน</p></div>
  <form class="row" method="get"><input type="hidden" name="p" value="members"><input type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหาชื่อ / อีเมล" style="width:240px"><button class="btn btn-sm">ค้นหา</button></form></div>
<div class="card"><div class="table-wrap"><table>
  <thead><tr><th>ผู้ใช้</th><th>บทบาท</th><th class="num">คอร์สที่เรียน</th><th class="num">ยอดซื้อ</th><th>สมัครเมื่อ</th><th>สถานะ</th><th></th></tr></thead>
  <tbody><?php foreach($rows as $r): ?>
  <tr><td><strong><?= h($r['name']) ?></strong><div class="small muted"><?= h($r['email']) ?></div></td>
    <td><?= (int)$r['is_admin'] ? '<span class="badge badge-primary">แอดมิน</span> ' : '' ?><?= $r['ist'] === 'approved' ? '<span class="badge badge-success">ผู้สอน</span>' : ($r['ist'] ? status_badge($r['ist']) : '<span class="badge badge-secondary">ผู้เรียน</span>') ?></td>
    <td class="num"><?= (int)$r['n_courses'] ?></td><td class="num"><?= baht($r['spent']) ?></td><td class="small muted"><?= h(substr($r['created_at'], 0, 10)) ?></td>
    <td><?= (int)$r['status'] ? '<span class="badge badge-success">ใช้งาน</span>' : '<span class="badge badge-danger">ระงับ</span>' ?></td>
    <td class="right nowrap"><?php if((int)$r['id'] !== current_user_id()): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="btn btn-sm" name="action" value="toggle_status"><?= (int)$r['status'] ? 'ระงับ' : 'เปิดใช้' ?></button>
      <button class="btn btn-sm" name="action" value="toggle_admin" onclick="return confirm('เปลี่ยนสิทธิ์แอดมินของผู้ใช้นี้?')"><?= (int)$r['is_admin'] ? 'ถอดแอดมิน' : 'ตั้งเป็นแอดมิน' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div></div>
