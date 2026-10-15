<?php
// Aleanor Streaming — สตูดิโอสอนสดของคอร์ส (ผู้สอนเจ้าของคอร์ส) · พอร์ตจากแท็บสอนสดของ aleanor_ai
$MENU = 'courses';
$uid = current_user_id();
$c = db_one("SELECT * FROM courses WHERE id = ? AND instructor_id = ?", [(int)get('course'), $uid]);
if(!$c){ echo '<div class="card">ไม่พบคอร์ส</div>'; return; }
$TITLE = 'สอนสด: '.$c['title'];
$L = function($k, array $r = []){ return h(LiveService::L($k, $r)); };
$lsActive = LiveService::active((int)$c['id']);
$lsMode = LiveService::mode();
$lsHist = LiveService::history((int)$c['id'], 10);
$me = current_user();
$hostName = (string)($me['name'] ?? '');
$jsL = LiveService::jsLabels(['idle','connecting','reconnecting','start','stop','started','confirm_stop','ended_elsewhere','start_fail','cf_fail',
    'cam_fail','mic_fail','no_support','screen_stopped','waiting_cam','cam_off_note','no_viewers','rec_fail',
    'mic_mute','mic_unmute','cam_off','cam_on','camera','mic','mode_cf','mode_p2p','resume_mine','other_live']);
$jsL['started'] = 'ออกอากาศแล้ว — ผู้เรียนเห็นไลฟ์ในหน้าเรียนของคอร์ส และได้รับอีเมลแจ้ง';
$chatL = LiveService::jsLabels(['chat_empty','chat_ph','chat_send','chat_host','chat_slow','chat_closed']);
$enrolled = (int)db_val("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND status = 'active'", [(int)$c['id']]);
$v = function($f){ return (int)@filemtime(dirname(__DIR__, 2).'/assets/live/'.$f); };
?>
<link rel="stylesheet" href="<?= h(asset('assets/live/live.css')) ?>?v=<?= $v('live.css') ?>">
<div class="page-header"><div><h1><i class="fi fi-rr-broadcast-tower"></i> Aleanor Streaming</h1>
  <p><?= h($c['title']) ?> · ผู้เรียนที่ลงทะเบียน <?= $enrolled ?> คน · <?= $L('sw_studio_sub') ?></p></div>
  <div class="row"><a class="btn btn-outline" href="<?= h(iu('course-edit', ['id' => $c['id']])) ?>"><i class="fi fi-rr-arrow-small-left"></i> กลับไปแก้ไขคอร์ส</a>
    <a class="btn btn-outline" href="<?= h(u('learn', ['course' => $c['id']])) ?>" target="_blank">ดูหน้าเรียน ↗</a></div></div>
<?php if($c['status'] !== 'published'): ?><div class="alert alert-warning">คอร์สยังไม่ได้เผยแพร่ — มีเฉพาะผู้ที่ลงทะเบียนแล้วเท่านั้นที่ดูไลฟ์ได้</div><?php endif; ?>

<div class="ls-grid">
  <div class="card ls-studio" id="lsStudio">
    <div class="ls-head">
      <div class="ls-head-t"><i class="fi fi-rr-broadcast-tower"></i> Aleanor Streaming</div>
      <span class="ls-status" data-r="status" data-k="idle"></span>
      <span class="ls-timer" data-r="timer">0:00</span>
      <span class="ls-viewc" title="<?= $L('viewers') ?>"><i class="fi fi-rr-eye"></i> <b data-r="count">0</b></span>
      <span class="ls-mode" data-r="modeTxt"><?= $lsMode === 'cf' ? $L('mode_cf') : $L('mode_p2p', [':n' => LiveService::p2pMax()]) ?></span>
    </div>
    <div class="ls-stage">
      <video data-r="preview" playsinline muted></video>
      <div class="ls-rec" data-r="recDot" hidden><span></span> REC</div>
      <div class="ls-meter" title="<?= $L('mic_level') ?>"><i class="fi fi-rr-microphone"></i><div><div data-r="meterFill"></div></div></div>
      <div class="ls-setup" data-r="setup">
        <div class="ls-setup-ic"><i class="fi fi-rr-video-camera-alt"></i></div>
        <h3><?= $L('setup_title') ?></h3>
        <p><?= $L('setup_hint') ?></p>
        <button type="button" class="btn btn-primary" data-r="setupGo"><i class="fi fi-rr-camera"></i> <?= $L('setup_go') ?></button>
      </div>
      <div class="ls-resume" data-r="resume" hidden>
        <i class="fi fi-rr-signal-stream"></i><span data-r="resumeTxt"></span>
        <button type="button" class="btn btn-primary btn-sm" data-r="resumeGo"><?= $L('resume_go') ?></button>
        <button type="button" class="btn btn-outline btn-sm" data-r="resumeEnd"><?= $L('resume_end') ?></button>
      </div>
      <div class="ls-msg" data-r="msg" hidden></div>
    </div>
    <div class="ls-ctrl">
      <div class="ls-grp ls-src">
        <button type="button" data-src="camera" title="<?= $L('src_camera') ?>"><i class="fi fi-rr-video-camera-alt"></i><span><?= $L('src_camera') ?></span></button>
        <button type="button" data-src="screen" class="need-screen" title="<?= $L('src_screen') ?>"><i class="fi fi-rr-screen"></i><span><?= $L('src_screen') ?></span></button>
        <button type="button" data-src="both" class="need-screen" title="<?= $L('src_both') ?>"><i class="fi fi-rr-chalkboard-user"></i><span><?= $L('src_both') ?></span></button>
      </div>
      <button type="button" class="ls-b" data-r="corner" hidden title="<?= $L('corner') ?>"><i class="fi fi-rr-arrows-repeat"></i></button>
      <button type="button" class="ls-b" data-r="size" hidden title="<?= $L('bubble_size') ?>"><i class="fi fi-rr-expand-arrows-alt"></i></button>
      <button type="button" class="ls-b" data-r="stopShare" hidden title="<?= $L('stop_share') ?>"><i class="fi fi-rr-screen"></i><i class="fi fi-rr-cross-small ls-x"></i></button>
      <span class="ls-sp"></span>
      <button type="button" class="ls-b" data-r="mic" title="<?= $L('mic_mute') ?>"><i class="fi fi-rr-microphone"></i></button>
      <button type="button" class="ls-b" data-r="cam" title="<?= $L('cam_off') ?>"><i class="fi fi-rr-video-camera-alt"></i></button>
      <button type="button" class="ls-b" data-r="gear" title="<?= $L('settings') ?>"><i class="fi fi-rr-settings"></i></button>
    </div>
    <div class="ls-settings" data-r="settings" hidden>
      <label><span><?= $L('camera') ?></span><select data-r="camSel"></select></label>
      <label><span><?= $L('mic') ?></span><select data-r="micSel"></select></label>
      <label><span><?= $L('quality') ?></span><select data-r="qSel">
        <option value="sd"><?= $L('q_sd') ?></option><option value="hd"><?= $L('q_hd') ?></option><option value="fhd"><?= $L('q_fhd') ?></option>
      </select></label>
      <label class="ls-chk"><input type="checkbox" data-r="rec" checked> <span><?= $L('record') ?><small><?= $L('record_hint') ?></small></span></label>
    </div>
    <div class="ls-go">
      <input type="text" data-r="title" maxlength="200" placeholder="<?= $L('title_ph') ?>" value="">
      <button type="button" class="btn ls-gobtn" data-r="go"><i class="fi fi-rr-signal-stream"></i> <span data-r="goTxt"><?= $L('start') ?></span></button>
    </div>
    <div class="ls-summary" data-r="summary" hidden>
      <div class="ls-sum-h"><i class="fi fi-rr-flag-checkered"></i> <?= $L('summary') ?></div>
      <div class="ls-sum-g">
        <div><b data-r="sumDur">—</b><span><?= $L('duration') ?></span></div>
        <div><b data-r="sumViewers">—</b><span><?= $L('viewers_total') ?></span></div>
        <div><b data-r="sumPeak">—</b><span><?= $L('peak') ?></span></div>
      </div>
      <a class="btn btn-primary" data-r="sumRec" hidden><i class="fi fi-rr-download"></i> <?= $L('download_rec') ?> <small data-r="sumRecSize"></small></a>
      <p class="ls-sum-note"><?= $L('rec_note') ?></p>
    </div>
  </div>

  <div class="card ls-side">
    <div class="ls-tabs">
      <button type="button" class="on" data-lst="chat"><i class="fi fi-rr-comments"></i> <?= $L('chat') ?></button>
      <button type="button" data-lst="viewers"><i class="fi fi-rr-users"></i> <?= $L('viewers') ?> <span class="crs-n" id="lsViewN">0</span></button>
    </div>
    <div class="ls-tabp" data-lsp2="chat"><div id="lsChat"></div></div>
    <div class="ls-tabp" data-lsp2="viewers" hidden><div id="lsViewers" class="lsv-list"><div class="lsv-empty"><?= $L('no_viewers') ?></div></div></div>
  </div>
</div>

<div class="card ls-hist">
  <h2><i class="fi fi-rr-time-past"></i> <?= $L('history') ?></h2>
  <?php if(!$lsHist): ?><p class="small muted" style="margin:0"><?= $L('history_empty') ?></p>
  <?php else: ?>
  <div class="table-wrap"><table class="ls-htable">
    <thead><tr><th><?= $L('h_date') ?></th><th><?= $L('h_title') ?></th><th><?= $L('duration') ?></th><th><?= $L('viewers_total') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach($lsHist as $hx): $dur = max(0, strtotime((string)$hx['ended_at']) - strtotime((string)$hx['started_at'])); ?>
      <tr>
        <td class="nowrap"><?= h(date('d/m/Y H:i', strtotime($hx['started_at']))) ?></td>
        <td><?= h($hx['title']) ?></td>
        <td><?= sprintf('%d:%02d:%02d', intdiv($dur, 3600), intdiv($dur % 3600, 60), $dur % 60) ?></td>
        <td><?= (int)$hx['viewers'] ?><?php if((int)$hx['peak']): ?> <small class="muted">(<?= $L('peak_short', [':n' => (int)$hx['peak']]) ?>)</small><?php endif; ?></td>
        <td class="right"><button type="button" class="btn btn-sm btn-outline" data-att="<?= h($hx['session_key']) ?>"><i class="fi fi-rr-list-check"></i> <?= $L('attendance') ?></button></td>
      </tr>
      <tr class="ls-att" data-attrow="<?= h($hx['session_key']) ?>" hidden><td colspan="5"></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script src="<?= h(asset('assets/live/live-studio.js')) ?>?v=<?= $v('live-studio.js') ?>"></script>
<script src="<?= h(asset('assets/live/live-chat.js')) ?>?v=<?= $v('live-chat.js') ?>"></script>
<script>
(function(){
  var CID = <?= (int)$c['id'] ?>, CSRF = <?= json_encode(csrf_token()) ?>, API = <?= json_encode(asset('api/live.php')) ?>;
  document.querySelectorAll('[data-lst]').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('[data-lst]').forEach(function(x){ x.classList.toggle('on', x === b); });
      document.querySelectorAll('[data-lsp2]').forEach(function(p){ p.hidden = p.dataset.lsp2 !== b.dataset.lst; });
    });
  });
  var chat = AleanorLive.chat(document.getElementById('lsChat'), { api: API, csrf: CSRF, lbl: <?= json_encode($chatL, JSON_UNESCAPED_UNICODE) ?> });
  var css = getComputedStyle(document.documentElement);
  var studio = AleanorLive.studio(document.getElementById('lsStudio'), {
    api: API, csrf: CSRF, course: CID, lbl: <?= json_encode($jsL, JSON_UNESCAPED_UNICODE) ?>,
    hostName: <?= json_encode($hostName, JSON_UNESCAPED_UNICODE) ?>, initials: <?= json_encode(mb_strtoupper(mb_substr(trim($hostName) ?: 'A', 0, 1)), JSON_UNESCAPED_UNICODE) ?>,
    font: getComputedStyle(document.body).fontFamily || 'sans-serif', color: css.getPropertyValue('--primary').trim() || '#6366f1',
    photoBase: '', fileBase: <?= json_encode('live-'.preg_replace('~[^A-Za-z0-9ก-๙_-]+~u', '-', mb_substr($c['title'], 0, 40)), JSON_UNESCAPED_UNICODE) ?>,
    viewersBox: document.getElementById('lsViewers'),
    onCount: function(n){ document.getElementById('lsViewN').textContent = n; },
    onLive: function(on){
      if(on && studio && studio.state.info) chat.setSession(studio.state.info.session);
      else if(!on) chat.end();
    }
  });
  // รายชื่อผู้เข้าชมไลฟ์ย้อนหลัง
  document.querySelectorAll('[data-att]').forEach(function(b){
    b.addEventListener('click', function(){
      var row = document.querySelector('[data-attrow="' + b.dataset.att + '"]'), td = row.firstElementChild;
      if(!row.hidden){ row.hidden = true; return; }
      row.hidden = false; td.textContent = '…';
      AleanorLive.post(API, { csrf_token: CSRF, action: 'attendance', session: b.dataset.att }).then(function(j){
        td.textContent = '';
        if(!j.ok || !j.list.length){ td.textContent = <?= json_encode(LiveService::L('no_attendance'), JSON_UNESCAPED_UNICODE) ?>; return; }
        var g = document.createElement('div'); g.className = 'ls-att-grid';
        j.list.forEach(function(v){
          var r = document.createElement('div'); r.className = 'lsv-row';
          var av = document.createElement('span'); av.className = 'lsv-ava lsv-ini'; av.textContent = ((v.name || '?').trim().charAt(0) || '?').toUpperCase();
          var nm = document.createElement('span'); nm.className = 'lsv-name'; nm.textContent = v.name || '—';
          var mt = document.createElement('span'); mt.className = 'lsv-meta'; mt.textContent = Math.floor(v.seconds / 60) + ' ' + <?= json_encode(LiveService::L('minutes'), JSON_UNESCAPED_UNICODE) ?>;
          r.appendChild(av); r.appendChild(nm); r.appendChild(mt); g.appendChild(r);
        });
        td.appendChild(g);
      }).catch(function(){ td.textContent = '—'; });
    });
  });
})();
</script>
