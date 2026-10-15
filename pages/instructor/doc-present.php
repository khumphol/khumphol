<?php
// Aleanor Present — สร้างสไลด์ (พอร์ตจาก aleanor_ai/dashboard/document/doc-present.php)
// ไม่ใช่ route — ถูก include จาก doc-edit.php เท่านั้น ($doc = แถวเอกสารที่ตรวจสิทธิ์แล้ว)
if(!isset($doc) || !class_exists('DocsService')) exit;
$TI      = DocsService::typeInfo('present');
$deck    = presentSanitize($doc['content']);
$LAYOUTS = presentLayouts();
$THEMES  = presentThemes();
$FONTS   = presentFonts();
$courses = DocsService::coursesFor(current_user_id());
?><style>
  .ap-bar { display:flex; align-items:center; gap:.55rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .ap-title { flex:1; min-width:220px; font-size:1rem; font-weight:600; font-family:inherit;
    background:var(--card); color:var(--text); border:1.5px solid var(--border); border-radius:10px;
    padding:10px 13px; min-height:44px; transition:border-color .15s, box-shadow .15s; }
  .ap-title::placeholder { font-weight:400; color:var(--text-muted); }
  .ap-title:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 14%,transparent); }
  .ap-state { font-size:.78rem; font-weight:600; color:var(--text-muted); min-width:92px; text-align:right; }

  /* ── แผงออกแบบ (ธีม สี ฟอนต์ เอฟเฟกต์) ── */
  .ap-design-btn { display:inline-flex; align-items:center; gap:8px; height:44px; padding:0 14px 0 8px; border-radius:11px; cursor:pointer;
                   border:1.5px solid var(--border); background:var(--card); color:var(--text); font-family:inherit; font-weight:600; font-size:.88rem; }
  .ap-design-btn .sw { width:28px; height:28px; border-radius:8px; border:1px solid var(--border); background:var(--sw-bg); position:relative; overflow:hidden; }
  .ap-design-btn .sw::after { content:''; position:absolute; right:0; bottom:0; width:12px; height:12px; border-radius:7px 0 6px 0; background:var(--sw-ac); }
  .ap-design-btn.on { border-color:var(--primary); box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 16%,transparent); }
  .ap-design { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:1rem 1.1rem; margin-bottom:.9rem; }
  .ap-design[hidden] { display:none; }
  .ap-dz-h { font-size:.78rem; font-weight:700; color:var(--text-secondary); margin:0 0 .5rem; display:flex; align-items:center; gap:.5rem; }
  .ap-themes { display:grid; grid-template-columns:repeat(auto-fill,minmax(112px,1fr)); gap:.55rem; margin-bottom:1rem; }
  .th-card { border:1.5px solid var(--border); border-radius:11px; padding:0; cursor:pointer; overflow:hidden; background:var(--card); text-align:left;
             font-family:inherit; transition:transform .12s, box-shadow .15s, border-color .15s; }
  .th-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.08); }
  .th-card.on { border-color:var(--primary); box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 22%,transparent); }
  .th-card .pv-deck { pointer-events:none; }
  .th-card span { display:block; padding:5px 8px; font-size:.74rem; font-weight:600; color:var(--text-secondary); }
  .ap-dz-g { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:.8rem; align-items:end; }
  .ap-dz-g label { display:flex; flex-direction:column; gap:4px; font-size:.78rem; font-weight:600; color:var(--text-secondary); margin:0; }
  .ap-dz-g .row1 { display:flex; gap:6px; align-items:center; }
  .ap-dz-g input[type=color] { width:44px; height:36px; padding:2px; border-radius:8px; border:1px solid var(--border); background:var(--card); cursor:pointer; }
  .ap-chk { flex-direction:row !important; align-items:center; gap:8px !important; cursor:pointer; }
  .ap-state.saving { color:var(--primary); } .ap-state.saved { color:#059669; } .ap-state.error { color:#dc2626; }

  .ap-work { display:grid; grid-template-columns:186px 1fr; gap:1rem; align-items:start; }
  @media (max-width:900px){ .ap-work { grid-template-columns:1fr; } }

  /* ── รายการสไลด์: ตัวอย่างย่อของจริง ── */
  .ap-side { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:.55rem; max-height:76vh; overflow:auto; }
  .ap-thumb { display:flex; gap:.5rem; align-items:flex-start; padding:.35rem; border-radius:10px; cursor:grab;
              border:1.5px solid transparent; margin-bottom:6px; }
  .ap-thumb:hover { background:var(--bg); }
  .ap-thumb.on { border-color:var(--primary); background:color-mix(in srgb,var(--primary) 7%,transparent); }
  .ap-thumb.drag { opacity:.4; }
  .ap-thumb.over { border-color:var(--primary); border-style:dashed; }
  .ap-thumb .n { font-size:.7rem; color:var(--text-muted); width:14px; flex-shrink:0; padding-top:2px; text-align:right; }
  .ap-mini { flex:1; min-width:0; border-radius:6px; overflow:hidden; border:1px solid var(--border); position:relative; pointer-events:none; }
  .ap-mini .pv-deck { border:none; border-radius:0; }

  /* ── ฝั่งขวา: ตัวอย่างใหญ่อยู่บน ฟอร์มอยู่ล่าง ── */
  .ap-main { display:flex; flex-direction:column; gap:.9rem; }
  .ap-stage-card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:.8rem; }
  .ap-stage-head { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-bottom:.6rem; }
  .ap-stage-head .sp { flex:1 }
  .ap-fields-card { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:1.1rem; }
  .ap-fields { display:flex; flex-direction:column; gap:.75rem; }
  .ap-fields label { font-size:.8rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:4px; }
  .ap-fields textarea { min-height:96px; resize:vertical; font-family:inherit; }
  .ap-img-box { display:flex; gap:.7rem; align-items:center; flex-wrap:wrap; }
  .ap-img-box img { width:112px; height:64px; object-fit:cover; border-radius:8px; border:1px solid var(--border); }
  .ap-two { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; }
  @media (max-width:640px){ .ap-two { grid-template-columns:1fr; } }

  /* ── โหมดผู้บรรยาย ── */
  .pr-view { position:fixed; inset:0; z-index:9999; background:#0b1220; color:#e2e8f0; display:none;
             grid-template-rows:auto 1fr auto; gap:1rem; padding:1.2rem; }
  .pr-view.on { display:grid; }
  .pr-top { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
  .pr-clock { font-size:1.5rem; font-weight:700; font-variant-numeric:tabular-nums; }
  .pr-top .pr-pos { font-size:.9rem; opacity:.75; }
  .pr-grid { display:grid; grid-template-columns:1.55fr 1fr; gap:1rem; min-height:0; }
  @media (max-width:860px){ .pr-grid { grid-template-columns:1fr; } }
  .pr-pane { display:flex; flex-direction:column; gap:.5rem; min-height:0; }
  .pr-pane h4 { font-size:.76rem; text-transform:uppercase; letter-spacing:.06em; opacity:.6; margin:0; font-weight:700; }
  .pr-box { flex:1; min-height:0; border-radius:12px; overflow:hidden; border:1px solid rgba(255,255,255,.14); position:relative; }
  .pr-notes { flex:1; min-height:0; overflow:auto; background:rgba(255,255,255,.06); border-radius:12px; padding:1rem 1.2rem;
              font-size:1.02rem; line-height:1.75; white-space:pre-wrap; }
  .pr-bot { display:flex; align-items:center; justify-content:center; gap:.6rem; flex-wrap:wrap; }
  .pr-btn { padding:8px 15px; border-radius:9px; border:1px solid rgba(255,255,255,.25); background:transparent;
            color:#e2e8f0; cursor:pointer; font-family:inherit; font-size:.86rem; }
  .pr-btn:hover { background:rgba(255,255,255,.12); }
  .pr-btn.pri { background:#6366f1; border-color:#6366f1; color:#fff; }

  .ap-opts { display:flex; flex-wrap:wrap; gap:.6rem .9rem; align-items:center; padding:.7rem .8rem; border:1px dashed var(--border); border-radius:11px; }
  .ap-opts label { display:flex; align-items:center; gap:6px; font-size:.8rem; font-weight:600; color:var(--text-secondary); margin:0; }
  .ap-opts input[type=color] { width:34px; height:30px; padding:2px; border-radius:8px; border:1px solid var(--border); background:var(--card); cursor:pointer; }
  .ap-opts .form-select { height:32px; padding:2px 30px 2px 10px; font-size:.82rem; min-width:150px; }
  .ap-opts .btn { padding:.3rem .7rem; font-size:.8rem; }
  #apLayoutPick { flex-wrap:wrap; }
  .ap-hint { font-size:.76rem; color:var(--text-muted); margin-top:4px; display:block; }
  .ap-stage-card .pv-img.free { cursor:grab; outline:2px dashed transparent; outline-offset:3px; }
  .ap-stage-card .pv-img.free:hover { outline-color:color-mix(in srgb,var(--primary) 55%,transparent); }
  .ap-stage-card .pv-img.free.dragging { cursor:grabbing; outline-color:var(--primary); }
<?php echo presentCss(); ?>
  .pr-box .pv-deck { border:none; border-radius:0; height:100%; display:flex; align-items:center; background:transparent; }
  .pr-box .pv-stage { max-height:100%; }
  .ap-show { position:fixed; inset:0; z-index:9998; background:#000; display:flex; flex-direction:column; justify-content:center; }
  .ap-show .pv-deck { border:none; border-radius:0; }
  .ap-show .pv-stage { width:min(100vw, calc((100vh - 52px) * 16 / 9)); margin:0 auto; }
  .ap-show-x { position:absolute; top:12px; right:14px; z-index:30; width:38px; height:38px; border-radius:50%; border:0; background:rgba(255,255,255,.14); color:#fff; cursor:pointer; font-size:16px; }
</style>
<script><?php echo presentJs(); ?></script>

<div class="page-header">
  <div><h1 style="color:<?php echo $TI['color'];?>"><?php echo DocsService::typeIcon('present', 26);?> <?php echo htmlspecialchars($TI['name']);?></h1>
       <p><?php echo docs_t('doc.present_desc');?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-outline" href="<?php echo h(iu('docs', ['f' => $doc['folder_id'] ?? null]));?>"><i class="fi fi-rr-list"></i> <?php echo docs_t('doc.all_docs');?></a>
    <button class="btn btn-outline" onclick="apOutline()"><i class="fi fi-rr-list-check"></i> <?php echo docs_t('doc.outline');?></button>
    <button class="btn btn-outline" onclick="apPresenter()"><i class="fi fi-rr-screen"></i> <?php echo docs_t('doc.presenter');?></button>
    <button class="btn btn-outline" onclick="apShow()"><i class="fi fi-rr-play"></i> <?php echo docs_t('doc.present_here');?></button>
    <?php if($doc){ ?>
      <a class="btn btn-outline" target="_blank" href="<?php echo h(iu('doc-view', ['id' => $doc['id']]));?>"><i class="fi fi-rr-arrow-up-right-from-square"></i> <?php echo docs_t('doc.present_run');?></a>
      <a class="btn btn-outline" href="<?php echo h(asset('api/docs.php').'?action=export&id='.(int)$doc['id']);?>"><i class="fi fi-rr-download"></i> .pptx</a>
    <?php } ?>
    <button class="btn btn-primary" onclick="apSave(true)"><i class="fi fi-rr-disk"></i> <?php echo docs_t('common.save');?></button>
  </div>
</div>

<div class="ap-bar">
  <input id="apTitle" class="ap-title" value="<?php echo htmlspecialchars($doc['title'] ?? '');?>"
         placeholder="<?php echo docs_t('doc.title_ph');?>" maxlength="200">
  <button type="button" class="ap-design-btn" id="apDesignBtn"><span class="sw" id="apDesignSw"></span> <i class="fi fi-rr-palette"></i> <?php echo docs_t('doc.design');?></button>
  <select id="apCourse" class="form-control form-select" style="max-width:230px">
    <option value=""><?php echo docs_t('doc.no_course');?></option>
    <?php foreach($courses as $c){ ?>
      <option value="<?php echo (int)$c['id'];?>" <?php if((int)($doc['course_id'] ?? 0)===(int)$c['id']) echo 'selected';?>><?php echo h($c['title']);?></option>
    <?php } ?>
  </select>
  <span id="apState" class="ap-state"></span>
</div>

<div class="ap-design" id="apDesign" hidden>
  <div class="ap-dz-h"><i class="fi fi-rr-palette"></i> <?php echo docs_t('doc.theme');?>
    <button type="button" class="btn btn-outline btn-sm" style="margin-left:auto" onclick="apShuffle()"><i class="fi fi-rr-shuffle"></i> <?php echo docs_t('doc.shuffle');?></button></div>
  <div class="ap-themes" id="apThemes"></div>
  <div class="ap-dz-g">
    <label><?php echo docs_t('doc.accent');?>
      <span class="row1"><input type="color" id="apAccent"><button type="button" class="btn btn-outline btn-sm" onclick="apAccentReset()"><?php echo docs_t('doc.accent_auto');?></button></span></label>
    <label><?php echo docs_t('doc.font_head');?>
      <select id="apFont" class="form-control form-select"><option value=""><?php echo docs_t('doc.font_auto');?></option>
        <?php foreach($FONTS as $f){ ?><option value="<?php echo $f;?>"><?php echo htmlspecialchars(ucwords(str_replace(['chakra','bai'],['Chakra Petch','Bai Jamjuree'],$f)));?></option><?php } ?></select></label>
    <label><?php echo docs_t('doc.fx');?>
      <select id="apFx" class="form-control form-select"><?php foreach(presentFx() as $fx){ ?><option value="<?php echo $fx;?>"><?php echo docs_t('doc.fx_'.$fx);?></option><?php } ?></select></label>
    <label><?php echo docs_t('doc.anim');?>
      <select id="apAnim" class="form-control form-select"><?php foreach(presentAnims() as $an){ ?><option value="<?php echo $an;?>"><?php echo docs_t('doc.anim_'.$an);?></option><?php } ?></select></label>
    <label><?php echo docs_t('doc.auto_sec');?><input type="number" id="apAutoSec" class="form-control" min="2" max="120"></label>
    <label class="ap-chk"><input type="checkbox" id="apProgress"> <?php echo docs_t('doc.progress');?></label>
  </div>
</div>

<div class="ap-work">
  <div class="ap-side">
    <div id="apThumbs"></div>
    <button type="button" class="btn btn-outline" style="width:100%;margin-top:6px" onclick="apAdd()">
      <i class="fi fi-rr-plus"></i> <?php echo docs_t('doc.slide_add');?></button>
  </div>

  <div class="ap-main">
    <div class="ap-stage-card">
      <div class="ap-stage-head">
        <div class="btn-grp" id="apLayoutPick" role="group" aria-label="<?php echo docs_t('doc.slide_layout');?>">
          <?php foreach($LAYOUTS as $lk => $lv){ ?>
            <button type="button" data-l="<?php echo $lk;?>" title="<?php echo htmlspecialchars($lv['name']);?>"
                    aria-label="<?php echo htmlspecialchars($lv['name']);?>"><i class="fi <?php echo $lv['icon'];?>"></i></button>
          <?php } ?>
        </div>
        <input type="hidden" id="apLayout" value="content">
        <span class="sp"></span>
        <button type="button" class="btn btn-outline btn-sm" id="apPlayBtn" onclick="apPlayPreview()"><i class="fi fi-rr-play"></i> <?php echo docs_t('doc.play_preview');?></button>
        <button type="button" class="btn-icon" title="<?php echo docs_t('doc.slide_up');?>"   onclick="apMove(-1)"><i class="fi fi-rr-arrow-up"></i></button>
        <button type="button" class="btn-icon" title="<?php echo docs_t('doc.slide_down');?>" onclick="apMove(1)"><i class="fi fi-rr-arrow-down"></i></button>
        <button type="button" class="btn-icon" title="<?php echo docs_t('doc.slide_dup');?>"  onclick="apDup()"><i class="fi fi-rr-copy"></i></button>
        <button type="button" class="btn-icon" title="<?php echo docs_t('common.delete');?>"   onclick="apDel()"><i class="fi fi-rr-trash"></i></button>
      </div>
      <div id="apPreview"></div>
    </div>

    <div class="ap-fields-card">
      <div class="ap-fields">
        <div id="apTitleGroup"><label><?php echo docs_t('doc.slide_head');?></label>
          <input id="apSTitle" class="form-control" maxlength="300"></div>

        <div id="apVideoGroup" hidden><label><?php echo docs_t('doc.slide_video_url');?></label>
          <input id="apSVideo" class="form-control" placeholder="https://www.youtube.com/watch?v=…">
          <small class="ap-hint" id="apVideoMsg"></small></div>

        <div class="ap-two">
          <div id="apBodyGroup"><label id="apBodyLabel"><?php echo docs_t('doc.slide_body');?></label>
            <textarea id="apSBody" class="form-control" placeholder="<?php echo docs_t('doc.slide_body_ph');?>"></textarea>
            <small class="ap-hint" id="apBodyHint"></small></div>
          <div id="apBody2Group" hidden><label id="apBody2Label"><?php echo docs_t('doc.slide_body2');?></label>
            <textarea id="apSBody2" class="form-control"></textarea></div>
        </div>

        <div class="ap-opts">
          <label><i class="fi fi-rr-magic-wand"></i> <?php echo docs_t('doc.slide_fx');?>
            <select id="apSFx" class="form-control form-select"><option value=""><?php echo docs_t('doc.slide_fx_inherit');?></option>
              <?php foreach(presentFx() as $fx){ ?><option value="<?php echo $fx;?>"><?php echo docs_t('doc.fx_'.$fx);?></option><?php } ?></select></label>
          <label id="apStepWrap"><input type="checkbox" id="apSStep"> <?php echo docs_t('doc.slide_step');?></label>
          <label><?php echo docs_t('doc.slide_bg');?> <input type="color" id="apSBg"></label>
          <button type="button" class="btn btn-outline" onclick="document.getElementById('apBgFile').click()"><i class="fi fi-rr-picture"></i> <?php echo docs_t('doc.slide_bgimg');?></button>
          <button type="button" class="btn btn-outline" id="apBgClear" onclick="apBgClear()"><i class="fi fi-rr-cross-small"></i> <?php echo docs_t('doc.slide_bg_clear');?></button>
        </div>

        <div id="apImgGroup" hidden><label><?php echo docs_t('doc.slide_img');?></label>
          <div class="ap-img-box">
            <img id="apImgPrev" alt="" hidden>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('apImgFile').click()">
              <i class="fi fi-rr-picture"></i> <?php echo docs_t('doc.slide_img_pick');?></button>
            <button type="button" class="btn btn-outline" id="apImgClear" hidden onclick="apImgClear()"><i class="fi fi-rr-cross-small"></i></button>
          </div>
          <div id="apImgTools" hidden style="margin-top:.7rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
            <label class="ws-toggle" style="margin:0;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.84rem">
              <input type="checkbox" id="apImgFree" onchange="apImgFreeToggle()">
              <span><?php echo docs_t('doc.img_free');?></span>
            </label>
            <span class="grp-lb"><?php echo docs_t('doc.img_size');?></span>
            <div class="btn-grp" id="apImgWG">
              <button type="button" class="wide" data-w="25">25%</button>
              <button type="button" class="wide" data-w="40">40%</button>
              <button type="button" class="wide" data-w="60">60%</button>
              <button type="button" class="wide" data-w="80">80%</button>
              <button type="button" class="wide" data-w="100">100%</button>
            </div>
            <span id="apImgSizeWrap" hidden style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--text-secondary)">
              <input type="range" id="apImgW" min="10" max="100" step="1" style="width:120px" oninput="apImgResize(this.value)">
              <b id="apImgWv" style="min-width:38px">45%</b>
            </span>
            <div class="btn-grp" id="apImgMisc">
              <button type="button" onclick="apImgRound()" title="<?php echo docs_t('doc.img_round');?>"><i class="fi fi-rr-frame"></i></button>
              <button type="button" id="apImgReset" hidden onclick="apImgReset()" title="<?php echo docs_t('doc.img_reset');?>"><i class="fi fi-rr-undo"></i></button>
              <button type="button" onclick="apImgClear()" title="<?php echo docs_t('common.delete');?>"><i class="fi fi-rr-trash"></i></button>
            </div>
          </div>
          <small id="apImgHint" hidden class="text-muted" style="font-size:.78rem;display:block;margin-top:.4rem">
            <i class="fi fi-rr-arrows"></i> <?php echo docs_t('doc.img_drag_hint');?></small>
        </div>

        <div><label><?php echo docs_t('doc.slide_notes');?></label>
          <textarea id="apSNotes" class="form-control" style="min-height:64px" placeholder="<?php echo docs_t('doc.slide_notes_ph');?>"></textarea></div>
      </div>
    </div>
  </div>
</div>

<input type="file" id="apImgFile" accept="image/*" hidden>
<input type="file" id="apBgFile" accept="image/*" hidden>

<!-- โหมดผู้บรรยาย -->
<div class="pr-view" id="prView">
  <div class="pr-top">
    <span class="pr-clock" id="prClock">00:00</span>
    <button type="button" class="pr-btn" onclick="prTimer()" id="prTimerBtn"><?php echo docs_t('doc.pr_pause');?></button>
    <button type="button" class="pr-btn" onclick="prReset()"><?php echo docs_t('doc.pr_reset');?></button>
    <span class="sp" style="flex:1"></span>
    <span class="pr-pos" id="prPos"></span>
    <button type="button" class="pr-btn" onclick="prClose()"><?php echo docs_t('common.close');?> (Esc)</button>
  </div>
  <div class="pr-grid">
    <div class="pr-pane"><h4><?php echo docs_t('doc.pr_now');?></h4><div class="pr-box" id="prNow"></div></div>
    <div class="pr-pane">
      <h4><?php echo docs_t('doc.pr_next');?></h4><div class="pr-box" id="prNext" style="flex:0 0 38%"></div>
      <h4 style="margin-top:.4rem"><?php echo docs_t('doc.slide_notes');?></h4><div class="pr-notes" id="prNotes"></div>
    </div>
  </div>
  <div class="pr-bot">
    <button type="button" class="pr-btn" onclick="prGo(-1)"><i class="fi fi-rr-angle-left"></i></button>
    <button type="button" class="pr-btn pri" onclick="prGo(1)"><?php echo docs_t('doc.pr_next_btn');?> <i class="fi fi-rr-angle-right"></i></button>
  </div>
</div>

<script>
(function(){
  var KEY  = <?php echo json_encode((int)$doc['id']);?>;
  var CSRF = <?php echo json_encode(csrf_token());?>;
  var SELF = <?php echo json_encode(iu('doc-edit').'&id=');?>;
  var BASE = <?php echo json_encode(app_base());?>;
  var D    = <?php echo json_encode($deck, JSON_UNESCAPED_UNICODE);?>;
  var THEMES  = <?php echo json_encode($THEMES);?>;
  var THEME_N = <?php $tn=[]; foreach($THEMES as $tk=>$tv) $tn[$tk]=docs_t('doc.theme_'.$tk); echo json_encode($tn, JSON_UNESCAPED_UNICODE);?>;
  var PLBL    = <?php echo json_encode(presentPlayerLabels(), JSON_UNESCAPED_UNICODE);?>;
  var AP      = window.AleanorPresent;
  var LAYOUTS = <?php echo json_encode(array_map(function($l){ return $l['name']; }, $LAYOUTS), JSON_UNESCAPED_UNICODE);?>;
  var T = {
    saving:<?php echo json_encode(docs_t('doc.saving'),JSON_UNESCAPED_UNICODE);?>,
    saved: <?php echo json_encode(docs_t('doc.saved'),JSON_UNESCAPED_UNICODE);?>,
    error: <?php echo json_encode(docs_t('doc.save_error'),JSON_UNESCAPED_UNICODE);?>,
    untitled:<?php echo json_encode(docs_t('doc.slide_untitled'),JSON_UNESCAPED_UNICODE);?>,
    last:  <?php echo json_encode(docs_t('doc.slide_last'),JSON_UNESCAPED_UNICODE);?>,
    outTitle:<?php echo json_encode(docs_t('doc.outline_ask'),JSON_UNESCAPED_UNICODE);?>,
    pause: <?php echo json_encode(docs_t('doc.pr_pause'),JSON_UNESCAPED_UNICODE);?>,
    resume:<?php echo json_encode(docs_t('doc.pr_resume'),JSON_UNESCAPED_UNICODE);?>,
    noNotes:<?php echo json_encode(docs_t('doc.pr_no_notes'),JSON_UNESCAPED_UNICODE);?>,
    endOf: <?php echo json_encode(docs_t('doc.pr_end'),JSON_UNESCAPED_UNICODE);?>,
    videoBad:<?php echo json_encode(docs_t('doc.video_bad'),JSON_UNESCAPED_UNICODE);?>,
    body:  <?php echo json_encode(docs_t('doc.slide_body'),JSON_UNESCAPED_UNICODE);?>,
    body2: <?php echo json_encode(docs_t('doc.slide_body2'),JSON_UNESCAPED_UNICODE);?>,
    head:  <?php echo json_encode(docs_t('doc.slide_head'),JSON_UNESCAPED_UNICODE);?>,
    hint:  <?php $hk=['body_ph'=>'slide_body_ph','quote'=>'hint_quote_body','quote2'=>'hint_quote_body2','stats'=>'hint_stats','timeline'=>'hint_timeline',
                     'quiz'=>'hint_quiz','quiz2'=>'hint_quiz_body2','quizq'=>'quiz_q','section'=>'hint_section','video'=>'hint_video_body','lead'=>'slide_lead'];
                 $ho=[]; foreach($hk as $k=>$v) $ho[$k]=docs_t('doc.'.$v); echo json_encode($ho, JSON_UNESCAPED_UNICODE);?>
  };
  if(!D.anim) D.anim = 'rise';
  if(D.progress === undefined) D.progress = 1;
  if(!D.autoSec) D.autoSec = 6;
  var cur = 0, dirty = false, timer = null;
  var state = document.getElementById('apState');
  function setState(c,t){ state.className='ap-state '+c; state.textContent=t; }
  function touch(){ dirty=true; setState('','•'); clearTimeout(timer); timer=setTimeout(function(){ apSave(false); },2500); }
  function S(){ return D.slides[cur]; }
  function theme(){ return THEMES[D.theme] || THEMES.light; }

  // ── วาดสไลด์ด้วยตัววาดร่วม (core/present/present.js) — หน้าตาเหมือนตอนนำเสนอจริงทุกจุด ──
  function ctx(i){ return { base:BASE, deck:D, themes:THEMES, index:i, sectionNo:AP.sectionNos(D)[i] }; }
  function stageOf(i){ return AP.stage(D.slides[i], ctx(i)); }
  function deckBox(s, withBar){ var i = D.slides.indexOf(s); return stageOf(i < 0 ? cur : i); }

  // ── รายการสไลด์ (ภาพย่อจริง + ลากสลับลำดับ) ──
  var dragFrom = null;
  function drawThumbs(){
    var box = document.getElementById('apThumbs');
    while(box.firstChild) box.removeChild(box.firstChild);
    D.slides.forEach(function(s, i){
      var row = document.createElement('div');
      row.className = 'ap-thumb' + (i === cur ? ' on' : '');
      row.draggable = true;
      var n = document.createElement('span'); n.className='n'; n.textContent = i + 1;
      var mini = document.createElement('div'); mini.className='ap-mini';
      mini.appendChild(stageOf(i));
      row.appendChild(n); row.appendChild(mini);
      row.addEventListener('click', function(){ save(); cur = i; load(); });
      row.addEventListener('dragstart', function(){ dragFrom = i; row.classList.add('drag'); });
      row.addEventListener('dragend',   function(){ dragFrom = null; row.classList.remove('drag'); drawThumbs(); });
      row.addEventListener('dragover',  function(e){ e.preventDefault(); row.classList.add('over'); });
      row.addEventListener('dragleave', function(){ row.classList.remove('over'); });
      row.addEventListener('drop', function(e){
        e.preventDefault(); row.classList.remove('over');
        if(dragFrom === null || dragFrom === i) return;
        save();
        var moved = D.slides.splice(dragFrom, 1)[0];
        D.slides.splice(i, 0, moved);
        cur = i; load(); touch();
      });
      box.appendChild(row);
    });
  }

  // ── ฟอร์ม ──
  function load(){
    var s = S();
    document.getElementById('apLayout').value = s.layout;
    document.querySelectorAll('#apLayoutPick button').forEach(function(x){ x.classList.toggle('on', x.dataset.l === s.layout); });
    document.getElementById('apSTitle').value = s.title || '';
    document.getElementById('apSBody').value  = s.body || '';
    document.getElementById('apSBody2').value = s.body2 || '';
    document.getElementById('apSNotes').value = s.notes || '';
    document.getElementById('apSVideo').value = s.video ? (s.video.indexOf('yt:') === 0 ? 'https://youtu.be/' + s.video.slice(3) : 'https://vimeo.com/' + s.video.slice(6)) : '';
    document.getElementById('apVideoMsg').textContent = '';
    document.getElementById('apSFx').value = s.fx || '';
    document.getElementById('apSStep').checked = !!s.step;
    document.getElementById('apSBg').value = s.bg || '#ffffff';
    document.getElementById('apBgClear').hidden = !(s.bg || s.bgImg);
    var prev = document.getElementById('apImgPrev'), clr = document.getElementById('apImgClear');
    if(s.img){ prev.src = BASE + s.img; prev.hidden = false; clr.hidden = false; }
    else { prev.removeAttribute('src'); prev.hidden = true; clr.hidden = true; }
    var tools=document.getElementById('apImgTools'), free=document.getElementById('apImgFree'),
        sw=document.getElementById('apImgSizeWrap'), rs=document.getElementById('apImgReset'), hint=document.getElementById('apImgHint');
    tools.hidden = !s.img;
    free.checked = !!s.imgPos;
    sw.hidden = !s.imgPos; rs.hidden = !s.imgPos; hint.hidden = !s.imgPos;
    if(s.imgPos){
      document.getElementById('apImgW').value = s.imgPos.w;
      document.getElementById('apImgWv').textContent = Math.round(s.imgPos.w) + '%';
    }
    var wNow = s.imgPos ? Math.round(s.imgPos.w) : (s.imgW ? Math.round(s.imgW) : 0);
    document.querySelectorAll('#apImgWG button').forEach(function(x){ x.classList.toggle('on', +x.dataset.w === wNow); });
    var rd = document.getElementById('apImgMisc');
    if(rd) rd.querySelector('[onclick="apImgRound()"]').classList.toggle('on', !!s.imgRound);
    applyLayout(s.layout);
    drawThumbs(); preview();
  }
  function save(){
    var s = S();
    s.layout = document.getElementById('apLayout').value;
    s.title  = document.getElementById('apSTitle').value;
    s.body   = document.getElementById('apSBody').value;
    s.body2  = document.getElementById('apSBody2').value;
    s.notes  = document.getElementById('apSNotes').value;
  }
  // ช่องกรอก/คำแนะนำเปลี่ยนตามรูปแบบสไลด์
  function applyLayout(l){
    var H = T.hint, set = function(id, t){ document.getElementById(id).textContent = t; };
    document.getElementById('apTitleGroup').hidden = (l === 'blank' || l === 'quote');
    document.getElementById('apBody2Group').hidden = (['two','quote','quiz'].indexOf(l) < 0);
    document.getElementById('apImgGroup').hidden   = (['image','content','title','blank'].indexOf(l) < 0);
    document.getElementById('apVideoGroup').hidden = (l !== 'video');
    document.getElementById('apStepWrap').hidden   = (['content','two','stats','timeline'].indexOf(l) < 0);
    set('apBodyLabel', l === 'quote' ? H.quote : l === 'quiz' ? T.body : T.body);
    set('apBody2Label', l === 'quote' ? H.quote2 : l === 'quiz' ? H.quiz2 : T.body2);
    var tl = document.querySelector('#apTitleGroup label'); if(tl) tl.textContent = l === 'quiz' ? H.quizq : T.head;
    var hint = { stats:H.stats, timeline:H.timeline, quiz:H.quiz, section:H.section, video:H.video, title:H.lead }[l] || '';
    set('apBodyHint', hint);
    document.getElementById('apSBody').placeholder = (l === 'content' || l === 'two') ? H.body_ph : '';
  }
  window.apLayoutChange = function(){ save(); applyLayout(S().layout); drawThumbs(); preview(); touch(); };
  document.getElementById('apLayoutPick').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-l]') : null;
    if(!b) return;
    document.getElementById('apLayout').value = b.dataset.l;
    apLayoutChange();
  });

  function preview(){
    var box = document.getElementById('apPreview');
    while(box.firstChild) box.removeChild(box.firstChild);
    box.appendChild(stageOf(cur));
    bindDrag();
  }
  // เล่นสไลด์นี้พร้อมเอฟเฟกต์/แอนิเมชันในกรอบตัวอย่าง (แก้อะไรก็กลับเป็นภาพนิ่ง)
  window.apPlayPreview = function(){
    save();
    var box = document.getElementById('apPreview');
    while(box.firstChild) box.removeChild(box.firstChild);
    var one = JSON.parse(JSON.stringify(D)); one.slides = [S()];
    var host = document.createElement('div'); box.appendChild(host);
    var pl = AP.player(host, { deck:one, themes:THEMES, base:BASE, lbl:PLBL, controls:false });
    // แสดงทีละข้อ → เปิดทีละข้อให้ดูอัตโนมัติ
    var t = setInterval(function(){ if(!host.isConnected || !pl.next()) clearInterval(t); }, 900);
  };
  // นำเสนอเต็มจอจากสไลด์ที่เลือกอยู่ (ไม่ต้องบันทึกก่อน)
  window.apShow = function(){
    save();
    var wrap = document.createElement('div'); wrap.className = 'ap-show';
    var host = document.createElement('div'); wrap.appendChild(host);
    var x = document.createElement('button'); x.className = 'ap-show-x'; x.type = 'button'; x.textContent = '✕'; wrap.appendChild(x);
    document.body.appendChild(wrap);
    var pl = AP.player(host, { deck:JSON.parse(JSON.stringify(D)), themes:THEMES, base:BASE, lbl:PLBL, start:cur });
    host.focus();
    function close(){ pl.destroy(); wrap.remove(); document.removeEventListener('keydown', esc, true); if(document.fullscreenElement) document.exitFullscreen(); }
    function esc(e){ if(e.key === 'Escape' && !host.querySelector('.pv-ov:not([hidden])')){ e.preventDefault(); close(); } }
    x.addEventListener('click', close);
    document.addEventListener('keydown', esc, true);
    if(wrap.requestFullscreen) wrap.requestFullscreen().catch(function(){});
  };
  // ── แผงออกแบบ ──
  function look(){ return AP.look(D, THEMES); }
  function paintDesignBtn(){
    var L = look(), sw = document.getElementById('apDesignSw');
    sw.style.setProperty('--sw-bg', L.bg); sw.style.setProperty('--sw-ac', L.ac);
  }
  function drawThemes(){
    var box = document.getElementById('apThemes'); box.textContent = '';
    var sample = { layout:'section', title:'Aa กขค', body:'', body2:'', img:'', notes:'' };
    Object.keys(THEMES).forEach(function(k){
      var b = document.createElement('button'); b.type = 'button'; b.className = 'th-card' + (D.theme === k ? ' on' : '');
      var dd = JSON.parse(JSON.stringify(D)); dd.theme = k; dd.accent = ''; dd.font = '';
      b.appendChild(AP.stage(sample, { base:BASE, deck:dd, themes:THEMES, index:0, sectionNo:1 }));
      var n = document.createElement('span'); n.textContent = THEME_N[k] || k; b.appendChild(n);
      b.addEventListener('click', function(){ D.theme = k; D.accent = ''; syncDesign(); drawThemes(); redraw(); });
      box.appendChild(b);
    });
  }
  function syncDesign(){
    var L = look();
    document.getElementById('apAccent').value = /^#[0-9a-f]{6}$/i.test(L.ac) ? L.ac : '#6366f1';
    document.getElementById('apFont').value = D.font || '';
    document.getElementById('apFx').value = D.fx || 'fade';
    document.getElementById('apAnim').value = D.anim || 'rise';
    document.getElementById('apAutoSec').value = D.autoSec || 6;
    document.getElementById('apProgress').checked = D.progress !== 0;
    paintDesignBtn();
  }
  function redraw(){ paintDesignBtn(); drawThumbs(); preview(); touch(); }
  document.getElementById('apDesignBtn').addEventListener('click', function(){
    var p = document.getElementById('apDesign'); p.hidden = !p.hidden; this.classList.toggle('on', !p.hidden);
    if(!p.hidden) drawThemes();
  });
  document.getElementById('apAccent').addEventListener('input', function(){ D.accent = this.value; redraw(); });
  window.apAccentReset = function(){ D.accent = ''; syncDesign(); redraw(); };
  document.getElementById('apFont').addEventListener('change', function(){ D.font = this.value; redraw(); });
  document.getElementById('apFx').addEventListener('change', function(){ D.fx = this.value; touch(); apPlayPreview(); });
  document.getElementById('apAnim').addEventListener('change', function(){ D.anim = this.value; touch(); apPlayPreview(); });
  document.getElementById('apAutoSec').addEventListener('change', function(){ D.autoSec = Math.max(2, Math.min(120, parseInt(this.value, 10) || 6)); this.value = D.autoSec; touch(); });
  document.getElementById('apProgress').addEventListener('change', function(){ D.progress = this.checked ? 1 : 0; touch(); });
  window.apShuffle = function(){
    var ks = Object.keys(THEMES).filter(function(k){ return k !== D.theme; });
    var fx = <?php echo json_encode(array_values(array_diff(presentFx(), ['none'])));?>, an = <?php echo json_encode(array_values(array_diff(presentAnims(), ['none','type'])));?>;
    D.theme = ks[Math.floor(Math.random() * ks.length)]; D.accent = ''; D.font = '';
    D.fx = fx[Math.floor(Math.random() * fx.length)]; D.anim = an[Math.floor(Math.random() * an.length)];
    syncDesign(); drawThemes(); redraw(); apPlayPreview();
  };

  // ── ตัวเลือกของสไลด์แผ่นนี้ ──
  document.getElementById('apSFx').addEventListener('change', function(){ S().fx = this.value; touch(); apPlayPreview(); });
  document.getElementById('apSStep').addEventListener('change', function(){ S().step = this.checked ? 1 : 0; touch(); apPlayPreview(); });
  document.getElementById('apSBg').addEventListener('input', function(){ S().bg = this.value; S().bgImg = ''; document.getElementById('apBgClear').hidden = false; drawThumbs(); preview(); touch(); });
  window.apBgClear = function(){ S().bg = ''; S().bgImg = ''; load(); touch(); };
  document.getElementById('apBgFile').addEventListener('change', function(){
    var f = this.files && this.files[0]; this.value = '';
    if(!f) return;
    upload(f, function(url){ S().bgImg = url; S().bg = ''; load(); touch(); });
  });
  document.getElementById('apSVideo').addEventListener('change', function(){
    var v = this.value.trim(), m, id = '';
    if((m = v.match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|live\/|shorts\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{6,20})/))) id = 'yt:' + m[1];
    else if((m = v.match(/vimeo\.com\/(?:video\/)?([0-9]{4,12})/))) id = 'vimeo:' + m[1];
    document.getElementById('apVideoMsg').textContent = (v && !id) ? T.videoBad : '';
    S().video = id; drawThumbs(); preview(); touch();
  });

  // ── จัดการสไลด์ ──
  window.apAdd = function(){ save(); D.slides.splice(cur+1, 0, {layout:'content',title:'',body:'',body2:'',img:'',notes:'',fx:'',step:0,bg:'',bgImg:'',video:''}); cur++; load(); touch(); };
  window.apDup = function(){ save(); D.slides.splice(cur+1, 0, JSON.parse(JSON.stringify(S()))); cur++; load(); touch(); };
  window.apDel = function(){
    if(D.slides.length <= 1){ alert(T.last); return; }
    D.slides.splice(cur,1); if(cur >= D.slides.length) cur = D.slides.length - 1; load(); touch();
  };
  window.apMove = function(dir){
    save(); var to = cur + dir;
    if(to < 0 || to >= D.slides.length) return;
    var tmp = D.slides[cur]; D.slides[cur] = D.slides[to]; D.slides[to] = tmp;
    cur = to; load(); touch();
  };

  // ── สร้างสไลด์จากเค้าโครงข้อความ ──
  window.apOutline = function(){
    var ask = window.dlgPrompt || function(m,d,cb){ var v=prompt(m,d); if(v!==null) cb(v); };
    ask(T.outTitle, '', function(txt){
      txt = String(txt || '');
      if(!txt.trim()) return;
      var made = [], curS = null;
      txt.split('\n').forEach(function(raw){
        if(!raw.trim()) return;
        var isBullet = /^[\s]*[-*•]/.test(raw) || /^\s+\S/.test(raw);
        var line = raw.replace(/^[\s]*[-*•#]+\s*/, '').trim();
        if(!line) return;
        if(isBullet && curS){ curS.body += (curS.body ? '\n' : '') + line; }
        else { curS = {layout:'content', title:line, body:'', body2:'', img:'', notes:''}; made.push(curS); }
      });
      if(!made.length) return;
      save();
      made.forEach(function(s, k){ D.slides.splice(cur + 1 + k, 0, s); });
      cur = cur + 1; load(); touch();
    });
  };

  // ── รูปในสไลด์ ──
  function upload(f, done){
    setState('saving', T.saving);
    var fd=new FormData(); fd.append('file', f); fd.append('csrf_token', CSRF);
    fetch(DOCS.api+'?action=image_upload',{method:'POST',body:fd}).then(function(r){return r.json();})
      .then(function(j){ if(!j.ok){ setState('error', T.error+' ('+(j.error||'')+')'); return; } done(j.url); })
      .catch(function(){ setState('error', T.error); });
  }
  document.getElementById('apImgFile').addEventListener('change', function(){
    var f = this.files && this.files[0]; this.value='';
    if(!f) return;
    upload(f, function(url){ S().img = url; load(); touch(); });
  });
  window.apImgClear = function(){ S().img=''; S().imgPos=null; load(); touch(); };
  window.apImgFreeToggle = function(){
    var on = document.getElementById('apImgFree').checked;
    S().imgPos = on ? {x:50, y:52, w:45} : null;
    load(); touch();
  };
  window.apImgResize = function(v){
    if(!S().imgPos) return;
    S().imgPos.w = Math.max(10, Math.min(100, parseFloat(v) || 45));
    document.getElementById('apImgWv').textContent = Math.round(S().imgPos.w) + '%';
    preview(); drawThumbs(); touch();
  };
  window.apImgReset = function(){ S().imgPos = {x:50, y:52, w:45}; load(); touch(); };
  window.apImgRound = function(){ S().imgRound = S().imgRound ? 0 : 1; load(); preview(); drawThumbs(); touch(); };
  document.getElementById('apImgWG').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-w]') : null;
    if(!b) return;
    var w = parseFloat(b.dataset.w);
    if(S().imgPos) S().imgPos.w = w; else S().imgW = w;
    load(); touch();
  });

  // ลากรูปในตัวอย่างเพื่อย้ายตำแหน่ง (คิดเป็น % จึงย่อขยายตามจอได้)
  function bindDrag(){
    var box = document.getElementById('apPreview');
    var el  = box.querySelector('.pv-img.free');
    if(!el || !S().imgPos) return;
    var stage = box.querySelector('.pv-stage');
    var moving = false;
    function pt(e){ return e.touches && e.touches[0] ? e.touches[0] : e; }
    function down(e){
      moving = true; el.classList.add('dragging');
      e.preventDefault();
      document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
      document.addEventListener('touchmove', move, {passive:false}); document.addEventListener('touchend', up);
    }
    function move(e){
      if(!moving) return;
      e.preventDefault();
      var r = stage.getBoundingClientRect(), p = pt(e);
      var x = ((p.clientX - r.left) / r.width) * 100;
      var y = ((p.clientY - r.top)  / r.height) * 100;
      S().imgPos.x = Math.max(0, Math.min(100, x));
      S().imgPos.y = Math.max(0, Math.min(100, y));
      el.style.left = S().imgPos.x + '%';
      el.style.top  = S().imgPos.y + '%';
    }
    function up(){
      if(!moving) return;
      moving = false; el.classList.remove('dragging');
      document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up);
      document.removeEventListener('touchmove', move); document.removeEventListener('touchend', up);
      drawThumbs(); touch();
    }
    el.addEventListener('mousedown', down);
    el.addEventListener('touchstart', down, {passive:false});
  }

  ['apSTitle','apSBody','apSBody2','apSNotes'].forEach(function(id){
    document.getElementById(id).addEventListener('input', function(){ save(); drawThumbs(); preview(); touch(); });
  });
  document.getElementById('apTitle').addEventListener('input', touch);
  document.getElementById('apCourse').addEventListener('change', touch);

  // ── โหมดผู้บรรยาย ──
  var prI = 0, prT0 = 0, prRun = false, prTick = null, prEl = document.getElementById('prView');
  function prPaint(){
    var now = D.slides[prI], nxt = D.slides[prI+1];
    var a = document.getElementById('prNow'); while(a.firstChild) a.removeChild(a.firstChild);
    a.appendChild(stageOf(prI));
    var b = document.getElementById('prNext'); while(b.firstChild) b.removeChild(b.firstChild);
    if(nxt) b.appendChild(stageOf(prI + 1));
    else { var e=document.createElement('div'); e.style.cssText='display:flex;align-items:center;justify-content:center;height:100%;opacity:.5;font-size:.9rem'; e.textContent=T.endOf; b.appendChild(e); }
    document.getElementById('prNotes').textContent = (now.notes || '').trim() || T.noNotes;
    document.getElementById('prPos').textContent = (prI+1) + ' / ' + D.slides.length;
  }
  function prClock(){
    var s = Math.floor((Date.now() - prT0)/1000);
    var m = Math.floor(s/60), ss = s%60;
    document.getElementById('prClock').textContent = (m<10?'0':'')+m + ':' + (ss<10?'0':'')+ss;
  }
  window.prGo = function(d){ prI = Math.max(0, Math.min(D.slides.length-1, prI+d)); prPaint(); };
  window.prTimer = function(){
    prRun = !prRun;
    document.getElementById('prTimerBtn').textContent = prRun ? T.pause : T.resume;
    if(prRun){ prT0 = Date.now() - (prT0 ? Date.now()-prT0 : 0); prT0 = Date.now() - ((window.__prEl)||0); prTick = setInterval(function(){ window.__prEl = Date.now()-prT0; prClock(); }, 250); }
    else { clearInterval(prTick); }
  };
  window.prReset = function(){ window.__prEl = 0; prT0 = Date.now(); prClock(); };
  window.prClose = function(){ prEl.classList.remove('on'); clearInterval(prTick); prRun=false; document.removeEventListener('keydown', prKey); };
  function prKey(e){
    if(e.key === 'Escape'){ prClose(); }
    else if(e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown'){ e.preventDefault(); prGo(1); }
    else if(e.key === 'ArrowLeft' || e.key === 'PageUp'){ e.preventDefault(); prGo(-1); }
  }
  window.apPresenter = function(){
    save(); prI = cur; window.__prEl = 0; prT0 = Date.now(); prRun = true;
    document.getElementById('prTimerBtn').textContent = T.pause;
    clearInterval(prTick); prTick = setInterval(function(){ window.__prEl = Date.now()-prT0; prClock(); }, 250);
    prClock(); prPaint();
    prEl.classList.add('on');
    document.addEventListener('keydown', prKey);
  };

  window.apSave = function(manual){
    if(!dirty && !manual) return;
    save(); setState('saving', T.saving);
    var fd=new FormData();
    fd.append('action','save'); fd.append('type','present'); fd.append('key', KEY);
    fd.append('title', document.getElementById('apTitle').value);
    fd.append('course', document.getElementById('apCourse').value);
    fd.append('content', JSON.stringify(D));
    fd.append('csrf_token', CSRF);
    return fetch(DOCS.api,{method:'POST',body:fd}).then(function(r){return r.json();})
      .then(function(j){
        if(!j.ok){ setState('error', T.error+(j.error?' ('+j.error+')':'')); return; }
        dirty=false;
        if(!KEY){ KEY=j.key; history.replaceState(null,'',SELF+encodeURIComponent(KEY)); }
        setState('saved', T.saved);
      }).catch(function(){ setState('error', T.error); });
  };
  document.addEventListener('keydown', function(e){
    if(prEl.classList.contains('on')) return;
    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){ e.preventDefault(); apSave(true); }
    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='m'){ e.preventDefault(); apAdd(); }
  });
  window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue=''; } });
  window.addEventListener('resize', function(){ drawThumbs(); });

  syncDesign();
  load();
})();
</script>
