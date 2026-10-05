<?php // ฟอร์ม + ตารางคูปอง ใช้ร่วมกันระหว่างผู้สอน/แอดมิน — ต้องมี $coupons, $myCourses, $couponOwner ?>
<h1><?= $couponOwner === 'platform' ? 'คูปองแพลตฟอร์ม' : 'คูปองของฉัน' ?></h1>
<p class="muted small"><?= $couponOwner === 'platform'
  ? 'ส่วนลดคูปองแพลตฟอร์ม แพลตฟอร์มรับไว้เอง — ผู้สอนได้ส่วนแบ่งเท่ากับขายราคาเต็ม'
  : 'ส่วนลดหักจากราคาก่อนแบ่งรายได้ — คุณและแพลตฟอร์มแบกส่วนลดตามสัดส่วน' ?></p>
<div class="card mb">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="grid g4">
      <div><label>โค้ด</label><input type="text" name="code" required placeholder="SUMMER20"></div>
      <div><label>ประเภท</label><select name="type"><option value="percent">เปอร์เซ็นต์ (%)</option><option value="fixed">จำนวนเงิน (บาท)</option></select></div>
      <div><label>มูลค่า</label><input type="number" name="value" step="0.01" min="0" required></div>
      <div><label>คอร์ส</label><select name="course_id"><option value="0"><?= $couponOwner === 'platform' ? 'ทุกคอร์ส' : 'ทุกคอร์สของฉัน' ?></option>
        <?php foreach($myCourses as $mc): ?><option value="<?= (int)$mc['id'] ?>"><?= h($mc['title']) ?></option><?php endforeach; ?></select></div>
      <div><label>จำนวนครั้งสูงสุด (0 = ไม่จำกัด)</label><input type="number" name="max_uses" min="0" value="0"></div>
      <div><label>เริ่ม</label><input type="date" name="starts_at"></div>
      <div><label>สิ้นสุด</label><input type="date" name="ends_at"></div>
      <div><label>&nbsp;</label><button class="btn btn-primary btn-block">สร้างคูปอง</button></div>
    </div>
  </form>
</div>
<div class="card table-wrap"><?php if(!$coupons): ?><p class="muted">ยังไม่มีคูปอง</p><?php else: ?><table>
  <tr><th>โค้ด</th><th>ส่วนลด</th><th>คอร์ส</th><th class="num">ใช้แล้ว</th><th>ช่วงเวลา</th><th>สถานะ</th><th></th></tr>
  <?php foreach($coupons as $cp): ?>
  <tr><td><strong><?= h($cp['code']) ?></strong></td><td><?= $cp['type'] === 'percent' ? h((float)$cp['value']).'%' : baht($cp['value']) ?></td>
      <td><?= h($cp['title'] ?? 'ทุกคอร์ส') ?></td><td class="num"><?= (int)$cp['used_count'] ?><?= (int)$cp['max_uses'] ? ' / '.(int)$cp['max_uses'] : '' ?></td>
      <td class="small muted"><?= h(substr((string)$cp['starts_at'], 0, 10) ?: '—') ?> → <?= h(substr((string)$cp['ends_at'], 0, 10) ?: '—') ?></td>
      <td><?= (int)$cp['is_active'] ? '<span class="badge badge-green">ใช้งาน</span>' : '<span class="badge badge-gray">ปิด</span>' ?></td>
      <td class="right"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$cp['id'] ?>"><button class="btn btn-sm" name="action" value="toggle"><?= (int)$cp['is_active'] ? 'ปิด' : 'เปิด' ?></button></form></td></tr>
  <?php endforeach; ?>
</table><?php endif; ?></div>
