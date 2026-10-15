<?php
// ============================================================
// Aleanor Cover — ออกแบบภาพปก (พอร์ตจาก aleanor_ai/dashboard/document/doc-cover.php · JS ชุดเดียวกัน assets/docs/aleanor-cover.js)
//   เปิดจาก doc-edit (มี $doc แล้ว) หรือ doc-edit&type=cover ไม่มี id = ภาพใหม่ที่ยังไม่สร้างเอกสาร (ตัวเลือกภาพปกในฟอร์ม)
//   &preset=course|…  ขนาดตั้งต้น · &title=… ชื่อเรื่องตั้งต้น · &f=โฟลเดอร์ · &embed=1&pick=1 เปิดในกรอบจากฟอร์ม มีปุ่ม "ใช้ภาพนี้"
// ============================================================
$me       = current_user_id();
$cvEmbed  = get('embed') !== '';
$cvPick   = get('pick') !== '';
$PRESETS  = coverPresets();
$reqPre   = isset($PRESETS[get('preset')]) ? get('preset') : 'course';
$reqTitle = mb_substr(get('title'), 0, 120);
$design   = $doc ? coverSanitize($doc['content']) : coverBlank($reqPre, $reqTitle);
$folder   = (int)get('f');
$quota    = DocsService::quotaInfo($me);
$docTitle = $doc ? $doc['title'] : ($reqTitle !== '' ? $reqTitle : docs_t('cover.untitled'));
$presetOut = [];
foreach($PRESETS as $pk => $pv) $presetOut[$pk] = ['w' => $pv['w'], 'h' => $pv['h'], 'icon' => $pv['icon'], 'label' => docs_t($pv['label'])];
$API = asset('api/docs.php');
?>
<link rel="stylesheet" href="<?php echo h(coverFontsCssUrl());?>">
<?php if($cvEmbed){ ?>
<style>
  .sidebar, .topbar, .footer, .chat-fab, .fab, #toTop { display:none !important; }
  .main { margin-left:0 !important; min-height:100vh; }
  .content { padding:.75rem 1rem 1rem !important; max-width:none !important; }
</style>
<?php } ?>
<style>
  .cv-top { display:flex; align-items:center; gap:.55rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .cv-back { width:40px; height:40px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center;
             border:1.5px solid var(--border); color:var(--text-secondary); text-decoration:none; background:var(--card); }
  .cv-back:hover { color:var(--primary); border-color:var(--primary); }
  .cv-title { flex:1; min-width:200px; font-size:1rem; font-weight:600; font-family:inherit; background:var(--card); color:var(--text);
              border:1.5px solid var(--border); border-radius:10px; padding:9px 13px; min-height:42px; }
  .cv-title:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 14%,transparent); }
  .cv-size { font-size:.76rem; font-weight:700; color:var(--text-muted); background:var(--bg); border:1px solid var(--border);
             padding:5px 10px; border-radius:20px; white-space:nowrap; }
  .cv-state { font-size:.76rem; font-weight:600; color:var(--text-muted); min-width:84px; text-align:right; }
  .cv-state.saving { color:var(--primary); } .cv-state.saved { color:#059669; } .cv-state.error { color:#dc2626; }
  .cv-grp { display:inline-flex; background:var(--card); border:1.5px solid var(--border); border-radius:11px; overflow:hidden; }
  .cv-grp button, .cv-grp a { height:38px; min-width:38px; padding:0 11px; border:0; background:transparent; color:var(--text-secondary);
             cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:6px; font:inherit; font-size:.84rem;
             text-decoration:none; }
  .cv-grp > * + * { border-left:1px solid var(--border) !important; }
  .cv-grp button:hover, .cv-grp a:hover { background:color-mix(in srgb,var(--primary) 9%,transparent); color:var(--primary); }
  .cv-grp button:disabled { opacity:.35; cursor:default; background:transparent; color:var(--text-secondary); }

  .cv-work { display:grid; grid-template-columns:292px 1fr 270px; gap:.9rem; align-items:start; }
  @media(max-width:1280px){ .cv-work { grid-template-columns:270px 1fr; } .cv-right { grid-column:1 / -1; } }
  @media(max-width:860px){ .cv-work { grid-template-columns:1fr; } }

  .cv-panel { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
  .cv-tabs { display:grid; grid-template-columns:repeat(5,1fr); border-bottom:1px solid var(--border); background:var(--bg); }
  .cv-tabs button { border:0; background:transparent; padding:.6rem .2rem .5rem; cursor:pointer; color:var(--text-muted);
                    font:inherit; font-size:.7rem; display:flex; flex-direction:column; align-items:center; gap:4px; border-bottom:2px solid transparent; }
  .cv-tabs button i { font-size:1.05rem; }
  .cv-tabs button.on { color:var(--primary); border-bottom-color:var(--primary); background:var(--card); }
  .cv-pane { display:none; padding:.9rem; max-height:calc(100vh - 230px); overflow:auto; }
  .cv-pane.on { display:block; }
  .cv-h { font-size:.74rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; margin:.9rem 0 .5rem; }
  .cv-h:first-child { margin-top:0; }

  .cv-presets { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
  .cv-pre { border:1.5px solid var(--border); border-radius:11px; padding:.55rem .6rem; cursor:pointer; background:var(--card);
            text-align:left; font:inherit; color:var(--text); display:flex; flex-direction:column; gap:3px; }
  .cv-pre:hover { border-color:color-mix(in srgb,var(--primary) 55%,transparent); }
  .cv-pre.on { border-color:var(--primary); background:color-mix(in srgb,var(--primary) 7%,var(--card)); }
  .cv-pre i { color:var(--primary); font-size:.95rem; }
  .cv-pre b { font-size:.78rem; line-height:1.3; }
  .cv-pre span { font-size:.68rem; color:var(--text-muted); }

  .cv-bgs { display:grid; grid-template-columns:repeat(3,1fr); gap:.45rem; }
  .cv-bgs canvas { width:100%; aspect-ratio:16/10; border-radius:9px; cursor:pointer; display:block; border:2px solid transparent; }
  .cv-bgs canvas:hover { border-color:color-mix(in srgb,var(--primary) 55%,transparent); }

  .cv-row { display:flex; align-items:center; gap:.5rem; margin-bottom:.55rem; }
  .cv-row > label { font-size:.78rem; color:var(--text-secondary); min-width:78px; }
  .cv-row input[type=range] { flex:1; accent-color:var(--primary); }
  .cv-row input[type=number] { width:74px; }
  .cv-row .v { font-size:.72rem; color:var(--text-muted); min-width:34px; text-align:right; }
  .cv-row select, .cv-row input[type=number], .cv-row input[type=text] { height:34px; border-radius:9px; border:1.5px solid var(--border);
            background:var(--card); color:var(--text); font:inherit; font-size:.82rem; padding:0 8px; }
  .cv-row select { flex:1; }
  .cv-col { width:34px; height:34px; padding:0; border:1.5px solid var(--border); border-radius:9px; cursor:pointer; background:none; }
  .cv-seg { display:inline-flex; background:var(--bg); border:1.5px solid var(--border); border-radius:10px; padding:2px; flex:1; }
  .cv-seg button { flex:1; height:30px; border:0; background:transparent; border-radius:8px; cursor:pointer; color:var(--text-secondary); font:inherit; font-size:.78rem; }
  .cv-seg button.on { background:var(--card); color:var(--primary); box-shadow:0 1px 3px rgba(0,0,0,.08); font-weight:600; }
  .cv-sw { display:flex; flex-wrap:wrap; gap:5px; margin:.2rem 0 .7rem; }
  .cv-sw button { width:24px; height:24px; border-radius:7px; border:1.5px solid rgba(0,0,0,.08); cursor:pointer; padding:0; }

  .cv-addtxt { display:flex; flex-direction:column; gap:.45rem; }
  .cv-addtxt button { text-align:left; border:1.5px solid var(--border); background:var(--card); border-radius:11px; padding:.6rem .8rem;
                      cursor:pointer; color:var(--text); font-family:inherit; }
  .cv-addtxt button:hover { border-color:var(--primary); }
  .cv-shapes { display:grid; grid-template-columns:repeat(3,1fr); gap:.45rem; }
  .cv-shapes button { aspect-ratio:1; border:1.5px solid var(--border); background:var(--card); border-radius:11px; cursor:pointer;
                      display:flex; align-items:center; justify-content:center; }
  .cv-shapes button:hover { border-color:var(--primary); }
  .cv-imgs { display:grid; grid-template-columns:repeat(3,1fr); gap:.4rem; }
  .cv-imgs button { aspect-ratio:1; border:1.5px solid var(--border); border-radius:9px; padding:0; overflow:hidden; cursor:pointer; background:var(--bg); }
  .cv-imgs button:hover { border-color:var(--primary); }
  .cv-imgs img { width:100%; height:100%; object-fit:cover; display:block; }
  .cv-drop { border:2px dashed var(--border); border-radius:12px; padding:1rem; text-align:center; color:var(--text-muted); font-size:.8rem; cursor:pointer; margin-bottom:.7rem; }
  .cv-drop:hover, .cv-drop.over { border-color:var(--primary); color:var(--primary); background:color-mix(in srgb,var(--primary) 5%,transparent); }
  .cv-drop i { font-size:1.4rem; display:block; margin-bottom:.25rem; }
  .cv-empty { font-size:.76rem; color:var(--text-muted); text-align:center; padding:.8rem .3rem; line-height:1.6; }

  /* พื้นที่วาด */
  .cv-stage-wrap { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:1rem; }
  .cv-stage { position:relative; margin:0 auto; background:
      conic-gradient(#e2e8f0 25%, #f8fafc 0 50%, #e2e8f0 0 75%, #f8fafc 0) 0 0/18px 18px;
      box-shadow:0 10px 30px rgba(15,23,42,.12); touch-action:none; user-select:none; }
  .cv-stage canvas { position:absolute; inset:0; width:100%; height:100%; display:block; }
  .cv-sel { position:absolute; border:1.5px solid #6366f1; pointer-events:none; transform-origin:center center; box-shadow:0 0 0 1px rgba(255,255,255,.7); }
  .cv-sel .hd { position:absolute; width:12px; height:12px; background:#fff; border:1.5px solid #6366f1; border-radius:4px; pointer-events:auto; }
  .cv-sel .hd.nw{left:-7px;top:-7px;cursor:nwse-resize} .cv-sel .hd.ne{right:-7px;top:-7px;cursor:nesw-resize}
  .cv-sel .hd.sw{left:-7px;bottom:-7px;cursor:nesw-resize} .cv-sel .hd.se{right:-7px;bottom:-7px;cursor:nwse-resize}
  .cv-sel .hd.e{right:-7px;top:calc(50% - 6px);cursor:ew-resize} .cv-sel .hd.w{left:-7px;top:calc(50% - 6px);cursor:ew-resize}
  .cv-sel .hd.n{top:-7px;left:calc(50% - 6px);cursor:ns-resize} .cv-sel .hd.s{bottom:-7px;left:calc(50% - 6px);cursor:ns-resize}
  .cv-sel .rot { position:absolute; left:calc(50% - 8px); top:-34px; width:16px; height:16px; border-radius:50%; background:#6366f1;
                 border:2px solid #fff; pointer-events:auto; cursor:grab; box-shadow:0 1px 4px rgba(0,0,0,.25); }
  .cv-sel .rot::after { content:''; position:absolute; left:6px; top:14px; width:1.5px; height:13px; background:#6366f1; }
  .cv-sel.locked { border-style:dashed; border-color:#94a3b8; }
  .cv-sel.locked .hd, .cv-sel.locked .rot { display:none; }
  .cv-guide { position:absolute; background:#ec4899; pointer-events:none; display:none; }
  .cv-guide.v { width:1px; top:0; bottom:0; } .cv-guide.h { height:1px; left:0; right:0; }
  .cv-hint { font-size:.74rem; color:var(--text-muted); text-align:center; margin-top:.7rem; }
  .cv-zoom { display:flex; align-items:center; justify-content:center; gap:.4rem; margin-top:.55rem; font-size:.76rem; color:var(--text-muted); }

  /* คุณสมบัติ + ชั้น */
  .cv-right .cv-pane { display:block; max-height:none; }
  .cv-layers { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:4px; }
  .cv-layers li { display:flex; align-items:center; gap:6px; padding:6px 7px; border-radius:9px; border:1.5px solid transparent; cursor:pointer; font-size:.8rem; }
  .cv-layers li:hover { background:var(--bg); }
  .cv-layers li.on { border-color:var(--primary); background:color-mix(in srgb,var(--primary) 6%,transparent); }
  .cv-layers li .nm { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .cv-layers li > i { color:var(--text-muted); width:16px; text-align:center; }
  .cv-layers li button { border:0; background:none; color:var(--text-muted); cursor:pointer; padding:2px 3px; border-radius:6px; font-size:.78rem; }
  .cv-layers li button:hover { color:var(--primary); background:var(--card); }
  .cv-mini { display:grid; grid-template-columns:repeat(6,1fr); gap:4px; margin-bottom:.7rem; }
  .cv-mini button { height:32px; border:1.5px solid var(--border); background:var(--card); border-radius:9px; cursor:pointer; color:var(--text-secondary); }
  .cv-mini button:hover { color:var(--primary); border-color:var(--primary); }
  .cv-mini button.on { color:var(--primary); border-color:var(--primary); background:color-mix(in srgb,var(--primary) 8%,transparent); }
  .cv-ta { width:100%; min-height:74px; border:1.5px solid var(--border); border-radius:10px; padding:8px 10px; font:inherit; font-size:.88rem;
           background:var(--card); color:var(--text); resize:vertical; margin-bottom:.6rem; }
  .cv-ta:focus { outline:none; border-color:var(--primary); }
  .cv-chk { display:flex; align-items:center; gap:7px; font-size:.8rem; color:var(--text-secondary); margin:.35rem 0 .55rem; cursor:pointer; }
  .cv-chk input { accent-color:var(--primary); width:16px; height:16px; }
  .cv-sub { border-top:1px dashed var(--border); margin-top:.6rem; padding-top:.6rem; }
  .ic-flip { transform:scaleX(-1); display:inline-block; }
  .cv-pick-bar { display:flex; align-items:center; gap:.7rem; padding:.6rem .9rem; margin-bottom:.8rem; border-radius:12px;
                 background:color-mix(in srgb,var(--primary) 8%,var(--card)); border:1px solid color-mix(in srgb,var(--primary) 25%,transparent); font-size:.84rem; }
  .cv-pick-bar b { color:var(--primary); }
</style>

<?php if($cvPick){ ?>
<div class="cv-pick-bar"><i class="fi fi-rr-magic-wand" style="color:var(--primary)"></i>
  <span><?php echo docs_t('cover.pick_hint',[':size'=>'<b>'.(int)$design['w'].' × '.(int)$design['h'].'</b>']);?></span></div>
<?php } ?>

<div class="cv-top">
  <?php if(!$cvEmbed){ ?>
  <a class="cv-back" href="<?php echo h(iu('docs', $folder ? ['f' => $folder] : []));?>" title="<?php echo docs_t('doc.back_to_docs');?>"><i class="fi fi-rr-arrow-left"></i></a>
  <?php } ?>
  <span style="display:inline-flex;align-items:center"><?php echo DocsService::typeIcon('cover', 30);?></span>
  <input class="cv-title" id="cvTitle" value="<?php echo h($docTitle);?>" maxlength="200" placeholder="<?php echo docs_t('cover.title_ph');?>">
  <span class="cv-size" id="cvSize"><?php echo (int)$design['w'];?> × <?php echo (int)$design['h'];?> px</span>
  <span class="cv-state" id="cvState"></span>
  <div class="cv-grp">
    <button type="button" id="cvUndo" title="<?php echo docs_t('cover.undo');?> (Ctrl+Z)"><i class="fi fi-rr-undo"></i></button>
    <button type="button" id="cvRedo" title="<?php echo docs_t('cover.redo');?> (Ctrl+Shift+Z)"><i class="fi fi-rr-redo"></i></button>
  </div>
  <div class="cv-grp">
    <button type="button" id="cvDlPng" title="<?php echo docs_t('cover.download_png');?>"><i class="fi fi-rr-download"></i> PNG</button>
    <button type="button" id="cvDlJpg" title="<?php echo docs_t('cover.download_jpg');?>">JPG</button>
  </div>
  <button type="button" class="btn btn-outline" id="cvSave"><i class="fi fi-rr-disk"></i> <?php echo docs_t('common.save');?></button>
  <?php if($cvPick){ ?>
  <button type="button" class="btn btn-primary" id="cvUse"><i class="fi fi-rr-check"></i> <?php echo docs_t('cover.use_this');?></button>
  <?php } ?>
</div>

<div class="cv-work">
  <!-- ซ้าย: เครื่องมือ -->
  <div class="cv-panel">
    <div class="cv-tabs">
      <button type="button" data-tab="size"><i class="fi fi-rr-expand"></i><?php echo docs_t('cover.tab_size');?></button>
      <button type="button" data-tab="bg" class="on"><i class="fi fi-rr-fill"></i><?php echo docs_t('cover.tab_bg');?></button>
      <button type="button" data-tab="text"><i class="fi fi-rr-text"></i><?php echo docs_t('cover.tab_text');?></button>
      <button type="button" data-tab="img"><i class="fi fi-rr-picture"></i><?php echo docs_t('cover.tab_image');?></button>
      <button type="button" data-tab="shape"><i class="fi fi-rr-triangle"></i><?php echo docs_t('cover.tab_shape');?></button>
    </div>

    <div class="cv-pane" data-pane="size">
      <div class="cv-h"><?php echo docs_t('cover.size_for');?></div>
      <div class="cv-presets" id="cvPresets"></div>
      <div class="cv-h"><?php echo docs_t('cover.custom_size');?></div>
      <div class="cv-row"><label>W</label><input type="number" id="cvW" min="200" max="4000"><label style="min-width:auto">H</label><input type="number" id="cvH" min="200" max="4000"></div>
      <p class="cv-empty" style="text-align:left;padding:0"><?php echo docs_t('cover.size_hint');?></p>
    </div>

    <div class="cv-pane on" data-pane="bg">
      <div class="cv-h"><?php echo docs_t('cover.bg_ready');?></div>
      <div class="cv-bgs" id="cvBgs"></div>
      <div class="cv-h"><?php echo docs_t('cover.bg_custom');?></div>
      <div class="cv-row"><div class="cv-seg" id="cvBgType">
        <button type="button" data-v="solid"><?php echo docs_t('cover.bg_solid');?></button>
        <button type="button" data-v="gradient"><?php echo docs_t('cover.bg_gradient');?></button>
        <button type="button" data-v="image"><?php echo docs_t('cover.bg_image');?></button>
      </div></div>
      <div id="cvBgSolid">
        <div class="cv-row"><label><?php echo docs_t('cover.color');?></label><input type="color" class="cv-col" data-bg="color"></div>
      </div>
      <div id="cvBgGrad">
        <div class="cv-row"><label><?php echo docs_t('cover.color_from');?></label><input type="color" class="cv-col" data-bg="c1">
          <label style="min-width:auto"><?php echo docs_t('cover.color_to');?></label><input type="color" class="cv-col" data-bg="c2"></div>
        <div class="cv-row"><label><?php echo docs_t('cover.angle');?></label><input type="range" min="0" max="360" data-bg="angle"><span class="v" data-v="angle"></span></div>
        <label class="cv-chk"><input type="checkbox" data-bg="radial"> <?php echo docs_t('cover.radial');?></label>
      </div>
      <div id="cvBgImg">
        <div class="cv-drop" id="cvBgDrop"><i class="fi fi-rr-cloud-upload"></i><?php echo docs_t('cover.bg_image_drop');?></div>
        <button type="button" class="btn btn-outline btn-sm" id="cvBgFromDocs" style="width:100%;margin-bottom:.7rem"><i class="fi fi-rr-folder-open"></i> <?php echo docs_t('cover.from_docs');?></button>
        <div class="cv-row"><label><?php echo docs_t('cover.fit');?></label><div class="cv-seg" id="cvBgFit">
          <button type="button" data-v="cover"><?php echo docs_t('cover.fit_cover');?></button><button type="button" data-v="contain"><?php echo docs_t('cover.fit_contain');?></button></div></div>
        <div class="cv-row"><label><?php echo docs_t('cover.brightness');?></label><input type="range" min="20" max="200" data-bg="bright"><span class="v" data-v="bright"></span></div>
        <div class="cv-row"><label><?php echo docs_t('cover.blur');?></label><input type="range" min="0" max="40" data-bg="blur"><span class="v" data-v="blur"></span></div>
        <div class="cv-row"><label><?php echo docs_t('cover.overlay');?></label><input type="color" class="cv-col" data-bg="ovColor"><input type="range" min="0" max="1" step="0.01" data-bg="ovOp"><span class="v" data-v="ovOp"></span></div>
      </div>
      <div class="cv-sub">
        <div class="cv-row"><label><?php echo docs_t('cover.pattern');?></label><select data-bg="pattern">
          <option value="none"><?php echo docs_t('cover.pat_none');?></option>
          <option value="dots"><?php echo docs_t('cover.pat_dots');?></option>
          <option value="grid"><?php echo docs_t('cover.pat_grid');?></option>
          <option value="lines"><?php echo docs_t('cover.pat_lines');?></option>
          <option value="waves"><?php echo docs_t('cover.pat_waves');?></option>
          <option value="circles"><?php echo docs_t('cover.pat_circles');?></option>
          <option value="rings"><?php echo docs_t('cover.pat_rings');?></option>
          <option value="triangles"><?php echo docs_t('cover.pat_triangles');?></option>
          <option value="zigzag"><?php echo docs_t('cover.pat_zigzag');?></option>
          <option value="blobs"><?php echo docs_t('cover.pat_blobs');?></option>
        </select></div>
        <div class="cv-row"><label><?php echo docs_t('cover.pattern_color');?></label><input type="color" class="cv-col" data-bg="pcolor">
          <input type="range" min="0" max="1" step="0.01" data-bg="pop"><span class="v" data-v="pop"></span></div>
        <div class="cv-row"><label><?php echo docs_t('cover.pattern_scale');?></label><input type="range" min="0.3" max="4" step="0.05" data-bg="pscale"><span class="v" data-v="pscale"></span></div>
      </div>
    </div>

    <div class="cv-pane" data-pane="text">
      <div class="cv-h"><?php echo docs_t('cover.add_text');?></div>
      <div class="cv-addtxt">
        <button type="button" data-add-text="title" style="font-family:Kanit;font-size:1.35rem;font-weight:700"><?php echo docs_t('cover.txt_title');?></button>
        <button type="button" data-add-text="subtitle" style="font-family:Prompt;font-size:1rem;font-weight:500"><?php echo docs_t('cover.txt_subtitle');?></button>
        <button type="button" data-add-text="body" style="font-family:Sarabun;font-size:.88rem"><?php echo docs_t('cover.txt_body');?></button>
        <button type="button" data-add-text="badge"><span style="display:inline-block;background:#f59e0b;color:#fff;border-radius:20px;padding:2px 12px;font-weight:700;font-size:.8rem;font-family:Kanit"><?php echo docs_t('cover.txt_badge');?></span></button>
        <button type="button" data-add-text="code" style="font-family:'Chakra Petch';font-weight:600;letter-spacing:.08em"><?php echo docs_t('cover.txt_code');?></button>
      </div>
    </div>

    <div class="cv-pane" data-pane="img">
      <div class="cv-drop" id="cvImgDrop"><i class="fi fi-rr-cloud-upload"></i><?php echo docs_t('cover.img_drop');?></div>
      <div class="cv-h"><?php echo docs_t('cover.from_docs');?></div>
      <div class="cv-imgs" id="cvImgs"><div class="cv-empty"><?php echo docs_t('reading.loading');?></div></div>
    </div>

    <div class="cv-pane" data-pane="shape">
      <div class="cv-h"><?php echo docs_t('cover.add_shape');?></div>
      <div class="cv-shapes">
        <button type="button" data-add-shape="rect" title="rect"><svg width="34" height="26"><rect x="2" y="2" width="30" height="22" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="rrect" title="rounded"><svg width="34" height="26"><rect x="2" y="2" width="30" height="22" rx="7" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="pill" title="pill"><svg width="36" height="20"><rect x="2" y="2" width="32" height="16" rx="8" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="circle" title="circle"><svg width="30" height="30"><circle cx="15" cy="15" r="13" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="triangle" title="triangle"><svg width="30" height="28"><path d="M15 2 28 26H2z" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="star" title="star"><svg width="30" height="30"><path d="M15 2l3.9 8 8.8 1.2-6.4 6.1 1.6 8.7L15 21.8 7.1 26l1.6-8.7L2.3 11.2l8.8-1.2z" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="line" title="line"><svg width="34" height="20"><rect x="2" y="8" width="30" height="4" rx="2" fill="#6366f1"/></svg></button>
        <button type="button" data-add-shape="frame" title="frame"><svg width="34" height="26"><rect x="3" y="3" width="28" height="20" rx="4" fill="none" stroke="#6366f1" stroke-width="3"/></svg></button>
        <button type="button" data-add-shape="ring" title="ring"><svg width="30" height="30"><circle cx="15" cy="15" r="11" fill="none" stroke="#6366f1" stroke-width="3.5"/></svg></button>
      </div>
    </div>
  </div>

  <!-- กลาง: พื้นที่วาด -->
  <div class="cv-stage-wrap" id="cvStageWrap">
    <div class="cv-stage" id="cvStage">
      <canvas id="cvCanvas"></canvas>
      <div class="cv-guide v" id="cvGuideV"></div>
      <div class="cv-guide h" id="cvGuideH"></div>
      <div class="cv-sel" id="cvSel" style="display:none">
        <span class="hd nw" data-h="nw"></span><span class="hd ne" data-h="ne"></span>
        <span class="hd sw" data-h="sw"></span><span class="hd se" data-h="se"></span>
        <span class="hd n" data-h="n"></span><span class="hd s" data-h="s"></span>
        <span class="hd e" data-h="e"></span><span class="hd w" data-h="w"></span>
        <span class="rot" data-h="rot"></span>
      </div>
    </div>
    <div class="cv-hint"><?php echo docs_t('cover.stage_hint');?></div>
  </div>

  <!-- ขวา: คุณสมบัติ + ชั้น -->
  <div class="cv-panel cv-right">
    <div class="cv-pane" id="cvProps"></div>
    <div class="cv-pane" style="border-top:1px solid var(--border)">
      <div class="cv-h" style="display:flex;align-items:center;justify-content:space-between"><?php echo docs_t('cover.layers');?>
        <span style="font-weight:500;text-transform:none;letter-spacing:0" id="cvLayerCount"></span></div>
      <ul class="cv-layers" id="cvLayers"></ul>
    </div>
  </div>
</div>

<input type="file" id="cvFileImg" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
<input type="file" id="cvFileBg"  accept="image/png,image/jpeg,image/webp,image/gif" hidden>

<script id="cvData" type="application/json"><?php echo json_encode([
  'key'      => $doc ? (int)$doc['id'] : '',
  'design'   => $design,
  'presets'  => $presetOut,
  'fonts'    => coverFonts(),
  'folder'   => $folder ?: '',
  'embed'    => $cvEmbed,
  'pick'     => $cvPick,
  'csrf'     => csrf_token(),
  'base'     => asset(''),
  'saveUrl'  => $API,
  'coverUrl' => $API.'?cover=1',
  'fsUrl'    => $API,
  'editUrl'  => iu('doc-edit'),
  'sep'      => strpos(iu('doc-edit'), '?') === false ? '?' : '&',   // URL สวย (LC) ไม่มี ? ต่อท้าย
  'quotaFull'=> $quota['full'],
  'assetUrl' => $doc ? DocsService::coverAssetUrl($doc) : '',
  'L'        => [
    'saving'=>docs_t('doc.saving'),'saved'=>docs_t('doc.saved'),'failed'=>docs_t('doc.save_failed'),'full'=>docs_t('doc.storage_full_msg'),
    'text'=>docs_t('cover.layer_text'),'image'=>docs_t('cover.layer_image'),'shape'=>docs_t('cover.layer_shape'),
    'bgProps'=>docs_t('cover.bg_props_hint'),'noImgs'=>docs_t('cover.no_images'),'uploading'=>docs_t('doc.uploading'),
    'badType'=>docs_t('doc.bad_file_type'),'delConfirm'=>docs_t('cover.del_layer'),
    'content'=>docs_t('cover.p_text'),'font'=>docs_t('cover.p_font'),'size'=>docs_t('cover.p_size'),'weight'=>docs_t('cover.p_weight'),
    'color'=>docs_t('cover.color'),'align'=>docs_t('cover.p_align'),'lh'=>docs_t('cover.p_lh'),'ls'=>docs_t('cover.p_ls'),
    'shadow'=>docs_t('cover.p_shadow'),'stroke'=>docs_t('cover.p_stroke'),'box'=>docs_t('cover.p_box'),'boxPad'=>docs_t('cover.p_box_pad'),
    'radius'=>docs_t('cover.p_radius'),'opacity'=>docs_t('cover.p_opacity'),'rotate'=>docs_t('cover.p_rotate'),'fill'=>docs_t('cover.p_fill'),
    'fill2'=>docs_t('cover.p_fill2'),'noFill'=>docs_t('cover.p_nofill'),'border'=>docs_t('cover.p_border'),'flip'=>docs_t('cover.p_flip'),
    'gray'=>docs_t('cover.p_gray'),'brightness'=>docs_t('cover.brightness'),'fit'=>docs_t('cover.fit'),'fitCover'=>docs_t('cover.fit_cover'),
    'fitContain'=>docs_t('cover.fit_contain'),'upper'=>docs_t('cover.p_upper'),'italic'=>docs_t('cover.p_italic'),
    'arrange'=>docs_t('cover.p_arrange'),'alignCanvas'=>docs_t('cover.p_align_canvas'),'replace'=>docs_t('cover.p_replace'),
    'dup'=>docs_t('cover.dup'),'del'=>docs_t('common.delete'),'lock'=>docs_t('cover.lock'),'hide'=>docs_t('cover.hide'),
    'front'=>docs_t('cover.front'),'back'=>docs_t('cover.back'),
    'txtTitle'=>docs_t('cover.txt_title'),'txtSubtitle'=>docs_t('cover.txt_subtitle'),'txtBody'=>docs_t('cover.txt_body'),
    'txtBadge'=>docs_t('cover.txt_badge'),'txtCode'=>docs_t('cover.txt_code'),'untitled'=>docs_t('cover.untitled'),
  ],
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG);?></script>
<script src="<?php echo h(asset('assets/docs/aleanor-cover.js'));?>?v=<?php echo (int)@filemtime(dirname(__DIR__, 2).'/assets/docs/aleanor-cover.js');?>"></script>
