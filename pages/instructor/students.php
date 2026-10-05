<?php
$TITLE = 'ผู้เรียน';
$uid = current_user_id();
$cid = (int)get('course');
$myCourses = db_all("SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY title", [$uid]);
$rows = db_all("SELECT e.created_at enrolled_at, e.source, u.name, c.id course_id, c.title,
                  (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) n,
                  (SELECT COUNT(*) FROM lesson_progress p WHERE p.user_id = u.id AND p.course_id = c.id AND p.completed_at IS NOT NULL) done,
                  (SELECT MAX(last_seen_at) FROM lesson_progress p WHERE p.user_id = u.id AND p.course_id = c.id) last_seen,
                  (SELECT serial FROM certificates ct WHERE ct.user_id = u.id AND ct.course_id = c.id AND ct.revoked_at IS NULL) cert
                FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN users u ON u.id = e.user_id
                WHERE c.instructor_id = ? AND e.status = 'active'".($cid ? " AND c.id = ".$cid : '')." ORDER BY e.created_at DESC LIMIT 500", [$uid]);
?>
<div class="page-header"><div><h1><i class="fi fi-rr-graduation-cap"></i> ผู้เรียน</h1><p>ผู้เรียน <?= count($rows) ?> คน (แสดงชื่อเท่านั้น — อีเมลผู้เรียนเป็นข้อมูลส่วนบุคคลของแพลตฟอร์ม)</p></div>
  <form class="row" method="get"><input type="hidden" name="p" value="students"><select name="course" style="width:260px" onchange="this.form.submit()"><option value="">ทุกคอร์ส</option>
    <?php foreach($myCourses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?></option><?php endforeach; ?></select></form></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>ผู้เรียน</th><th>คอร์ส</th><th>ความคืบหน้า</th><th>เข้าเรียนล่าสุด</th><th>ลงทะเบียน</th><th>ใบประกาศ</th></tr></thead><tbody>
<?php foreach($rows as $r): $pct = $r['n'] ? round($r['done'] * 100 / $r['n']) : 0; ?>
  <tr><td><strong><?= h($r['name']) ?></strong></td><td><?= h($r['title']) ?></td>
    <td style="min-width:150px"><div class="progress"><span style="width:<?= $pct ?>%"></span></div><span class="small muted"><?= $pct ?>% (<?= (int)$r['done'] ?>/<?= (int)$r['n'] ?>)</span></td>
    <td class="small muted"><?= h($r['last_seen'] ?: '—') ?></td><td class="small muted"><?= h(substr($r['enrolled_at'], 0, 10)) ?> · <?= $r['source'] === 'free' ? 'ฟรี' : 'ซื้อ' ?></td>
    <td><?= $r['cert'] ? '<span class="badge badge-success">'.h($r['cert']).'</span>' : '—' ?></td></tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="6" class="muted">ยังไม่มีผู้เรียน</td></tr><?php endif; ?>
</tbody></table></div></div>
