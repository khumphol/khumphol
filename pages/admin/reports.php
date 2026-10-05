<?php
$TITLE = 'รายงาน';
$from = get('from', date('Y-m-01', strtotime('-5 months'))); $to = get('to', date('Y-m-d'));
if(!preg_match('~^\d{4}-\d{2}-\d{2}$~', $from)) $from = date('Y-m-01');
if(!preg_match('~^\d{4}-\d{2}-\d{2}$~', $to)) $to = date('Y-m-d');
$by = in_array(get('by'), ['month', 'instructor', 'course'], true) ? get('by') : 'month';
$range = [$from.' 00:00:00', $to.' 23:59:59'];
$base = "FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN courses c ON c.id = oi.course_id
         JOIN users u ON u.id = oi.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = oi.instructor_id
         WHERE o.paid_at BETWEEN ? AND ?";
$sum = "COUNT(*) n, SUM(oi.paid_amount) gross, SUM(oi.gateway_fee) fee, SUM(oi.platform_amount) platform, SUM(oi.instructor_amount) instructor,
        SUM(CASE WHEN oi.refunded_at IS NOT NULL THEN oi.paid_amount ELSE 0 END) refunded,
        SUM(CASE WHEN oi.refunded_at IS NOT NULL THEN oi.platform_amount ELSE 0 END) refunded_platform,
        SUM(CASE WHEN oi.refunded_at IS NOT NULL THEN oi.instructor_amount ELSE 0 END) refunded_instructor";
$group = ['month' => ["DATE_FORMAT(o.paid_at, '%Y-%m')", 'เดือน'], 'instructor' => ["COALESCE(ip.display_name, u.name)", 'ผู้สอน'], 'course' => ["c.title", 'คอร์ส']][$by];
$rows = db_all("SELECT {$group[0]} AS k, $sum $base GROUP BY k ORDER BY ".($by === 'month' ? 'k' : 'gross DESC'), $range);
$tot = db_one("SELECT $sum $base", $range);
$cols = ['n' => 'รายการ', 'gross' => 'ยอดขาย', 'fee' => 'ค่าธรรมเนียม', 'platform' => 'แพลตฟอร์ม', 'instructor' => 'ผู้สอน', 'refunded' => 'คืนเงิน'];

if(get('export') === 'csv'){
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-'.$by.'-'.$from.'-'.$to.'.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    csv_row($out, array_merge([$group[1]], array_values($cols), ['แพลตฟอร์มสุทธิ', 'ผู้สอนสุทธิ']));
    foreach($rows as $r) csv_row($out, [$r['k'], $r['n'], $r['gross'], $r['fee'], $r['platform'], $r['instructor'], $r['refunded'],
        number_format($r['platform'] - $r['refunded_platform'], 2, '.', ''), number_format($r['instructor'] - $r['refunded_instructor'], 2, '.', '')]);
    fclose($out); exit;
}
?>
<h1>รายงานรายได้</h1>
<form class="row mb" method="get"><input type="hidden" name="p" value="reports">
  <input type="date" name="from" value="<?= h($from) ?>" style="max-width:170px"> – <input type="date" name="to" value="<?= h($to) ?>" style="max-width:170px">
  <select name="by" style="max-width:160px"><?php foreach(['month' => 'รายเดือน', 'instructor' => 'รายผู้สอน', 'course' => 'รายคอร์ส'] as $k => $v): ?><option value="<?= $k ?>" <?= $by === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <button class="btn">ดู</button>
  <a class="btn" href="<?= h(au('reports', ['from' => $from, 'to' => $to, 'by' => $by, 'export' => 'csv'])) ?>">ส่งออก CSV</a>
</form>
<div class="grid g4 mb">
  <div class="card stat"><div class="label">ยอดขาย</div><div class="value"><?= baht($tot['gross']) ?></div></div>
  <div class="card stat"><div class="label">ค่าธรรมเนียม</div><div class="value"><?= baht($tot['fee']) ?></div></div>
  <div class="card stat"><div class="label">แพลตฟอร์มสุทธิ (หลังคืนเงิน)</div><div class="value"><?= baht($tot['platform'] - $tot['refunded_platform']) ?></div></div>
  <div class="card stat"><div class="label">ผู้สอนสุทธิ (หลังคืนเงิน)</div><div class="value"><?= baht($tot['instructor'] - $tot['refunded_instructor']) ?></div></div>
</div>
<div class="card table-wrap"><?php if(!$rows): ?><p class="muted">ไม่มียอดขายในช่วงนี้</p><?php else: ?><table>
  <tr><th><?= h($group[1]) ?></th><?php foreach($cols as $l): ?><th class="num"><?= h($l) ?></th><?php endforeach; ?></tr>
  <?php foreach($rows as $r): ?><tr><td><?= h($r['k']) ?></td><td class="num"><?= (int)$r['n'] ?></td>
    <?php foreach(['gross', 'fee', 'platform', 'instructor', 'refunded'] as $k): ?><td class="num"><?= money($r[$k]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <tr style="font-weight:700"><td>รวม</td><td class="num"><?= (int)$tot['n'] ?></td><?php foreach(['gross', 'fee', 'platform', 'instructor', 'refunded'] as $k): ?><td class="num"><?= money($tot[$k]) ?></td><?php endforeach; ?></tr>
</table><?php endif; ?>
<p class="small muted">นับตามวันที่ชำระเงิน · แพลตฟอร์ม/ผู้สอนเป็นยอดตอนขาย — รายการที่คืนเงินแสดงแยกในคอลัมน์ "คืนเงิน"</p></div>
