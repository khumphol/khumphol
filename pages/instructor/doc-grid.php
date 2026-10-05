<?php
// Aleanor Grid — ตารางคำนวณ (พอร์ตจาก aleanor_ai/dashboard/document/doc-grid.php)
// ไม่ใช่ route — ถูก include จาก doc-edit.php เท่านั้น ($doc = แถวเอกสารที่ตรวจสิทธิ์แล้ว)
if(!isset($doc) || !class_exists('DocsService')) exit;
$TI      = DocsService::typeInfo('grid');
// สมุดงานใหญ่ (เช่นนำเข้าจาก Excel หลายหมื่นช่อง) ถูกทำความสะอาดไว้แล้วตอนบันทึก — ส่ง JSON เดิมให้ตัวแก้ไขตรง ๆ
// เลี่ยง json_decode ทั้งก้อนซึ่งกินหน่วยความจำราว 10 เท่าของขนาดไฟล์
$gridRaw  = (string)$doc['content'];
$gridBig  = strlen($gridRaw) > 1048576 && $gridRaw[0] === '{' && substr(rtrim($gridRaw), -1) === '}';
if($gridBig) DocsService::raiseMemory(strlen($gridRaw));
$gridBook = $gridBig ? null : ($gridRaw !== '' ? gridSanitize($gridRaw) : gridBlank());
if(!$gridBig) $gridRaw = '';
$courses = DocsService::coursesFor(current_user_id());
?><style>
  .ag-bar { display:flex; align-items:center; gap:.55rem; flex-wrap:wrap; margin-bottom:.8rem; }
  .ag-title { flex:1; min-width:220px; font-size:1rem; font-weight:600; font-family:inherit;
    background:var(--card); color:var(--text); border:1.5px solid var(--border); border-radius:10px;
    padding:10px 13px; min-height:44px; transition:border-color .15s, box-shadow .15s; }
  .ag-title::placeholder { font-weight:400; color:var(--text-muted); }
  .ag-title:focus { outline:none; border-color:var(--primary);
    box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 14%,transparent); }
  .ag-state { font-size:.78rem; font-weight:600; color:var(--text-muted); min-width:92px; text-align:right; }
  .ag-state.saving { color:var(--primary); } .ag-state.saved { color:#059669; } .ag-state.error { color:#dc2626; }

  .ag-tools { display:flex; flex-wrap:wrap; gap:7px; align-items:center; padding:.5rem .6rem; background:var(--card);
              border:1px solid var(--border); border-bottom:none; border-radius:var(--radius) var(--radius) 0 0; }
  .ag-tools button { height:31px; min-width:31px; padding:0 8px; border-radius:8px; cursor:pointer; border:1px solid transparent;
              background:transparent; color:var(--text-secondary); font-family:inherit; font-size:.84rem; }
  .ag-tools button:hover { background:color-mix(in srgb,var(--primary) 10%,transparent); color:var(--primary); }
  .ag-tools button.on { background:color-mix(in srgb,var(--primary) 16%,transparent); color:var(--primary); }
  .ag-sep { width:1px; background:var(--border); margin:3px 4px; }

  .ag-fx { display:flex; align-items:center; gap:8px; padding:.4rem .6rem; background:var(--card);
           border:1px solid var(--border); border-bottom:none; }
  .ag-fx-ref { min-width:54px; font-weight:700; font-size:.84rem; color:var(--primary); text-align:center;
               border:1px solid var(--border); border-radius:7px; padding:4px 6px; }
  .ag-fx input { flex:1; border:1px solid var(--border); border-radius:7px; padding:5px 10px; font-family:ui-monospace,Menlo,monospace;
                 font-size:.85rem; background:var(--bg); color:var(--text); }
  .ag-fx input:focus { outline:none; border-color:var(--primary); }

  .ag-wrap { overflow:auto; max-height:66vh; border:1px solid var(--border); border-radius:0 0 var(--radius) var(--radius); background:var(--card); }
  .ag-wrap { height:min(70vh, 760px); }
  .ag-wrap.small { height:auto; }
  .ag-grid { border-collapse:separate; border-spacing:0; font-size:.87rem; table-layout:fixed; }
  .ag-grid tbody tr { height:31px; }
  .ag-grid tbody tr.ag-sp { height:auto; }
  .ag-grid tr.ag-sp td { border:0; padding:0; height:0; }
  .ag-grid th, .ag-grid td { border-right:1px solid var(--border); border-bottom:1px solid var(--border); padding:0; }
  .ag-grid thead th { position:sticky; top:0; z-index:3; background:var(--bg); font-weight:600; font-size:.76rem;
                      color:var(--text-secondary); text-align:center; height:28px; overflow:hidden; }
  .ag-grip { position:absolute; top:0; right:-3px; width:7px; height:100%; cursor:col-resize; z-index:4; }
  .ag-grip:hover, .ag-grip.on { background:color-mix(in srgb,var(--primary) 45%,transparent); }
  .ag-grid thead th:first-child .ag-grip { display:none; }
  .ag-grid tbody th { position:sticky; left:0; z-index:2; background:var(--bg); font-weight:600; font-size:.74rem;
                      color:var(--text-muted); text-align:center; font-variant-numeric:tabular-nums; }
  .ag-grid thead th:first-child { left:0; z-index:4; }
  .ag-cell { height:30px; line-height:22px; padding:4px 8px; outline:none; white-space:pre; overflow:hidden; box-sizing:border-box; }
  .ag-cell.err { color:#b91c1c; }
  .ag-busy { font-size:.78rem; color:var(--text-muted); }
  .ag-cell:focus { box-shadow:inset 0 0 0 2px var(--primary); border-radius:2px; }
  .ag-cell.sel { background:color-mix(in srgb,var(--primary) 8%,transparent); }
  .ag-sheets { display:flex; align-items:center; gap:4px; flex-wrap:wrap; margin-top:.55rem; }
  .ag-sheets .sh { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:9px 9px 0 0;
                   border:1px solid var(--border); border-bottom:none; background:var(--bg); color:var(--text-secondary);
                   font-size:.82rem; font-weight:600; cursor:pointer; max-width:190px; }
  .ag-sheets .sh.on { background:var(--card); color:var(--primary); box-shadow:0 -2px 0 var(--primary) inset; }
  .ag-sheets .sh span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .ag-sheets .sh i { font-size:.66rem; opacity:.5; }
  .ag-sheets .sh i:hover { opacity:1; color:#dc2626; }
  .ag-sheets .add { width:30px; height:29px; border-radius:9px; border:1px dashed var(--border); background:transparent;
                    color:var(--text-muted); cursor:pointer; }
  .ag-sheets .add:hover { color:var(--primary); border-color:var(--primary); }
  .ag-foot { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-top:.7rem; font-size:.79rem; color:var(--text-muted); }
</style>

<div class="page-header">
  <div><h1 style="color:<?php echo $TI['color'];?>"><?php echo DocsService::typeIcon('grid', 26);?> <?php echo htmlspecialchars($TI['name']);?></h1>
       <p><?php echo docs_t('doc.grid_desc');?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-outline" href="<?php echo h(iu('docs', ['f' => $doc['folder_id'] ?? null]));?>"><i class="fi fi-rr-list"></i> <?php echo docs_t('doc.all_docs');?></a>
    <?php if($doc){ ?>
      <a class="btn btn-outline" target="_blank" href="<?php echo h(iu('doc-view', ['id' => $doc['id']]));?>"><i class="fi fi-rr-eye"></i> <?php echo docs_t('doc.open_read');?></a>
      <a class="btn btn-outline" href="<?php echo h(asset('api/docs.php').'?action=export&id='.(int)$doc['id']);?>"><i class="fi fi-rr-download"></i> .xlsx</a>
      <a class="btn btn-outline" href="<?php echo h(asset('api/docs.php').'?action=export&format=csv&id='.(int)$doc['id']);?>"><i class="fi fi-rr-download"></i> .csv</a>
    <?php } ?>
    <button class="btn btn-primary" onclick="agSave(true)"><i class="fi fi-rr-disk"></i> <?php echo docs_t('common.save');?></button>
  </div>
</div>

<div class="ag-bar">
  <input id="agTitle" class="ag-title" value="<?php echo htmlspecialchars($doc['title'] ?? '');?>"
         placeholder="<?php echo docs_t('doc.title_ph');?>" maxlength="200">
  <select id="agCourse" class="form-control form-select" style="max-width:250px">
    <option value=""><?php echo docs_t('doc.no_course');?></option>
    <?php foreach($courses as $c){ ?>
      <option value="<?php echo (int)$c['id'];?>" <?php if((int)($doc['course_id'] ?? 0)===(int)$c['id']) echo 'selected';?>><?php echo h($c['title']);?></option>
    <?php } ?>
  </select>
  <span id="agState" class="ag-state"></span>
</div>

<div class="ag-tools">
  <div class="btn-grp">
    <button type="button" id="agB" onclick="agFmt('b')" title="<?php echo docs_t('doc.tool_bold');?>"><b>B</b></button>
    <button type="button" id="agI" onclick="agFmt('i')" title="<?php echo docs_t('doc.tool_italic');?>"><i>I</i></button>
  </div>
  <div class="btn-grp" id="agAlign">
    <button type="button" data-a="left"   title="<?php echo docs_t('doc.tool_left');?>"><i class="fi fi-rr-align-left"></i></button>
    <button type="button" data-a="center" title="<?php echo docs_t('doc.tool_center');?>"><i class="fi fi-rr-align-center"></i></button>
    <button type="button" data-a="right"  title="<?php echo docs_t('doc.tool_right');?>"><i class="fi fi-rr-align-left ic-flip"></i></button>
  </div>
  <div class="btn-grp" id="agFill" title="<?php echo docs_t('doc.grid_fill');?>">
    <span class="grp-lb"><i class="fi fi-rr-fill"></i></span>
    <?php foreach(['#eef2ff','#dcfce7','#fef3c7','#fee2e2','#e0f2fe'] as $c){ ?>
      <button type="button" class="sw" data-bg="<?php echo $c;?>" style="background:<?php echo $c;?>" title="<?php echo $c;?>"></button>
    <?php } ?>
    <input type="color" id="agFillMore" value="#e0e7ff" title="<?php echo docs_t('doc.color_more');?>">
    <button type="button" data-bg="" title="<?php echo docs_t('doc.grid_fill_none');?>"><i class="fi fi-rr-cross-small"></i></button>
  </div>
  <div class="btn-grp" id="agFont" title="<?php echo docs_t('doc.grid_textcolor');?>">
    <span class="grp-lb"><i class="fi fi-rr-text"></i></span>
    <?php foreach(['#1e293b','#2563eb','#059669','#d97706','#dc2626'] as $c){ ?>
      <button type="button" class="sw" data-fc="<?php echo $c;?>" style="background:<?php echo $c;?>" title="<?php echo $c;?>"></button>
    <?php } ?>
    <input type="color" id="agFontMore" value="#6366f1" title="<?php echo docs_t('doc.color_more');?>">
  </div>
  <div class="btn-grp" id="agCalc" title="<?php echo docs_t('doc.grid_quick');?>">
    <span class="grp-lb">Σ</span>
    <button type="button" class="wide" data-fn="SUM">SUM</button>
    <button type="button" class="wide" data-fn="AVERAGE">AVG</button>
    <button type="button" class="wide" data-fn="COUNT">COUNT</button>
  </div>
  <div class="btn-grp">
    <button type="button" class="wide" onclick="agAddRow()" title="<?php echo docs_t('doc.grid_add_row');?>"><i class="fi fi-rr-add"></i> <?php echo docs_t('doc.grid_row');?></button>
    <button type="button" onclick="agDelRow()" title="<?php echo docs_t('doc.grid_del_row');?>"><i class="fi fi-rr-minus-circle"></i></button>
    <button type="button" class="wide" onclick="agAddCol()" title="<?php echo docs_t('doc.grid_add_col');?>"><i class="fi fi-rr-add"></i> <?php echo docs_t('doc.grid_col');?></button>
    <button type="button" onclick="agDelCol()" title="<?php echo docs_t('doc.grid_del_col');?>"><i class="fi fi-rr-minus-circle"></i></button>
  </div>
  <div class="btn-grp" id="agWidth" title="<?php echo docs_t('doc.grid_colw');?>">
    <span class="grp-lb"><i class="fi fi-rr-resize"></i></span>
    <button type="button" class="wide" data-w="90">S</button>
    <button type="button" class="wide" data-w="130">M</button>
    <button type="button" class="wide" data-w="190">L</button>
    <button type="button" class="wide" data-w="270">XL</button>
  </div>
  <div class="btn-grp">
    <button type="button" onclick="agClearCell()" title="<?php echo docs_t('doc.grid_clear');?>"><i class="fi fi-rr-eraser"></i></button>
  </div>
</div>

<div class="ag-fx">
  <span class="ag-fx-ref" id="agRef">A1</span>
  <input id="agFx" placeholder="<?php echo docs_t('doc.grid_fx_ph');?>" spellcheck="false">
</div>

<div class="ag-wrap" id="agWrap"><table class="ag-grid" id="agGrid"><colgroup id="agCols"></colgroup><thead><tr id="agHead"></tr></thead><tbody id="agBody"></tbody></table></div>

<div class="ag-sheets" id="agSheets"></div>

<div class="ag-foot">
  <span id="agInfo"></span>
  <span><?php echo docs_t('doc.grid_hint');?></span>
</div>

<script src="<?php echo htmlspecialchars(asset('assets/docs/grid-engine.js'));?>?v=<?php echo (int)@filemtime(dirname(__DIR__, 2).'/assets/docs/grid-engine.js');?>"></script>
<script>
(function(){
  var KEY   = <?php echo json_encode((int)$doc['id']);?>;
  var CSRF  = <?php echo json_encode(csrf_token());?>;
  var SELF  = <?php echo json_encode(iu('doc-edit').'&id=');?>;
  var BOOK  = <?php echo $gridBig
      ? str_replace(['<', '>', "\u{2028}", "\u{2029}"], ['<', '>', ' ', ' '], $gridRaw)
      : json_encode($gridBook, JSON_UNESCAPED_UNICODE);?>;
  var MAX_ROWS = <?php echo (int)gridMaxRows();?>, MAX_COLS = <?php echo (int)gridMaxCols();?>;
  var G;
  var T = {
    saving:<?php echo json_encode(docs_t('doc.saving'),JSON_UNESCAPED_UNICODE);?>,
    saved: <?php echo json_encode(docs_t('doc.saved'),JSON_UNESCAPED_UNICODE);?>,
    error: <?php echo json_encode(docs_t('doc.save_error'),JSON_UNESCAPED_UNICODE);?>,
    info:  <?php echo json_encode(docs_t('doc.grid_info'),JSON_UNESCAPED_UNICODE);?>,
    shName:<?php echo json_encode(docs_t('doc.sheet_name'),JSON_UNESCAPED_UNICODE);?>,
    shDel: <?php echo json_encode(docs_t('doc.sheet_del'),JSON_UNESCAPED_UNICODE);?>,
    shLast:<?php echo json_encode(docs_t('doc.sheet_last'),JSON_UNESCAPED_UNICODE);?>,
    calc:  <?php echo json_encode(docs_t('doc.grid_computing'),JSON_UNESCAPED_UNICODE);?>,
    big:   <?php echo json_encode(docs_t('doc.grid_too_large'),JSON_UNESCAPED_UNICODE);?>
  };
  if(!BOOK || !BOOK.sheets || !BOOK.sheets.length) BOOK = {sheets:[{name:'Sheet1',rows:20,cols:8,cells:{},fmt:{},widths:{}}], active:0};
  BOOK.sheets.forEach(function(sh){
    if(!sh.cells  || Array.isArray(sh.cells))  sh.cells  = {};
    if(!sh.fmt    || Array.isArray(sh.fmt))    sh.fmt    = {};
    if(!sh.widths || Array.isArray(sh.widths)) sh.widths = {};
    sh.rows = Math.max(1, Math.min(MAX_ROWS, +sh.rows || 20));
    sh.cols = Math.max(1, Math.min(MAX_COLS, +sh.cols || 8));
  });
  if(BOOK.active == null || BOOK.active < 0 || BOOK.active >= BOOK.sheets.length) BOOK.active = 0;
  G = BOOK.sheets[BOOK.active];

  var GE    = window.GridEngine;
  var ENG   = new GE.Engine(BOOK);
  var state = document.getElementById('agState');
  var fx    = document.getElementById('agFx');
  var refEl = document.getElementById('agRef');
  var wrap  = document.getElementById('agWrap');
  var table = document.getElementById('agGrid');
  var body  = document.getElementById('agBody');
  var cur   = 'A1';
  var dirty = false, timer = null, gen = 0;
  var REF_RE = /^([A-Z]{1,3})(\d+)$/;
  var ROWH = 31, BUF = 12, HEADW = 48, DEFW = 110;
  var view = {r1: 1, r2: 0}, rowEls = {}, spTop = null, spBot = null;

  var colName = GE.colName;
  function colIndex(n){ return GE.colIndex(String(n).toUpperCase()); }
  function setState(c,t){ state.className='ag-state '+c; state.textContent=t; }
  function formulaCount(){ var n=0; BOOK.sheets.forEach(function(sh){ for(var k in sh.cells) if(sh.cells[k] && sh.cells[k].f) n++; }); return n; }
  var BIG = formulaCount() > 4000 || JSON.stringify(G.cells).length > 1048576;

  // ── วาดตาราง (เฉพาะแถวที่มองเห็น + กันชนบน/ล่าง) ──
  function colW(i){ return G.widths[colName(i)] || DEFW; }
  function build(){
    var cg = document.getElementById('agCols'), head = document.getElementById('agHead');
    while(cg.firstChild) cg.removeChild(cg.firstChild);
    while(head.firstChild) head.removeChild(head.firstChild);
    while(body.firstChild) body.removeChild(body.firstChild);
    var total = HEADW;
    var c0 = document.createElement('col'); c0.style.width = HEADW + 'px'; cg.appendChild(c0);
    head.appendChild(document.createElement('th'));
    for(var c = 0; c < G.cols; c++){
      var cn = colName(c), w = colW(c);
      var col = document.createElement('col'); col.style.width = w + 'px'; col.dataset.col = cn; cg.appendChild(col);
      total += w;
      var th = document.createElement('th'); th.textContent = cn; th.dataset.col = cn;
      var grip = document.createElement('span'); grip.className = 'ag-grip'; grip.dataset.col = cn;
      th.appendChild(grip); head.appendChild(th);
    }
    table.style.width = total + 'px';
    spTop = spacer(); spBot = spacer();
    body.appendChild(spTop); body.appendChild(spBot);
    rowEls = {}; view = {r1: 1, r2: 0};
    wrap.classList.toggle('small', G.rows <= 20);
    renderRows(true);
    info();
  }
  function spacer(){
    var tr = document.createElement('tr'); tr.className = 'ag-sp';
    var td = document.createElement('td'); td.colSpan = G.cols + 1; tr.appendChild(td);
    return tr;
  }
  function makeRow(r){
    var tr = document.createElement('tr'); tr.dataset.r = r;
    var rh = document.createElement('th'); rh.textContent = r; tr.appendChild(rh);
    for(var c = 0; c < G.cols; c++){
      var ref = colName(c) + r;
      var td = document.createElement('td');
      var d = document.createElement('div');
      d.className = 'ag-cell'; d.contentEditable = 'true'; d.dataset.ref = ref; d.spellcheck = false;
      paint(d, ref);
      if(ref === cur) d.classList.add('sel');
      td.appendChild(d); tr.appendChild(td);
    }
    return tr;
  }
  function renderRows(force){
    var h = wrap.clientHeight || 600, top = wrap.scrollTop;
    var first = Math.max(1, Math.floor(top / ROWH) + 1 - BUF);
    var last  = Math.min(G.rows, Math.ceil((top + h) / ROWH) + BUF);
    if(!force && first === view.r1 && last === view.r2) return;
    var r;
    for(r in rowEls){
      r = +r;
      if(r < first || r > last){
        var tr = rowEls[r], act = document.activeElement;
        if(act && tr.contains(act)){ commit(act); act.blur(); }
        body.removeChild(tr); delete rowEls[r];
      }
    }
    for(r = first; r <= last; r++){
      if(rowEls[r]) continue;
      var el = makeRow(r), next = null;
      for(var k = r + 1; k <= last; k++){ if(rowEls[k]){ next = rowEls[k]; break; } }
      body.insertBefore(el, next || spBot);
      rowEls[r] = el;
    }
    view.r1 = first; view.r2 = last;
    spTop.firstChild.style.height = ((first - 1) * ROWH) + 'px';
    spBot.firstChild.style.height = (Math.max(0, G.rows - last) * ROWH) + 'px';
    // วัดความสูงแถวจริงครั้งแรก (ฟอนต์/ธีมต่างกันได้)
    var any = rowEls[first];
    if(any && any.offsetHeight && Math.abs(any.offsetHeight - ROWH) > 0.5){ ROWH = any.offsetHeight; renderRows(true); }
  }
  // วาดตามการเลื่อน: ใช้ rAF ให้ลื่น + setTimeout สำรอง (แท็บที่ถูกซ่อน rAF จะไม่ทำงาน)
  var pending = false;
  wrap.addEventListener('scroll', function(){
    if(pending) return;
    pending = true;
    var run = function(){ if(!pending) return; pending = false; renderRows(false); };
    requestAnimationFrame(run); setTimeout(run, 80);
  });
  window.addEventListener('resize', function(){ renderRows(true); });

  function paint(el, ref){
    var si = BOOK.active, f = G.fmt[ref] || {};
    var txt = ENG.display(si, ref);
    var v = ENG.valueAt(si, ref);
    el.textContent = txt;
    el.style.fontWeight = f.b ? '700' : '';
    el.style.fontStyle  = f.i ? 'italic' : '';
    el.style.background = f.bg || '';
    el.style.color      = f.fc || '';
    el.style.textAlign  = f.a || (typeof v === 'number' ? 'right' : (typeof v === 'boolean' || v instanceof GE.Err ? 'center' : 'left'));
    el.classList.toggle('err', v instanceof GE.Err);
    el.title = txt.length > 20 ? txt : '';
  }
  function cellEl(ref){ return body.querySelector('.ag-cell[data-ref="'+ref+'"]'); }
  function recalc(){
    body.querySelectorAll('.ag-cell').forEach(function(el){
      if(el === document.activeElement) return;
      paint(el, el.dataset.ref);
    });
    info();
  }
  /** ให้แถวของช่องนี้ถูกวาดอยู่ (เลื่อนไปหาถ้าอยู่นอกจอ) แล้วคืน element */
  function reveal(ref){
    var m = ref.match(REF_RE); if(!m) return null;
    var r = +m[2];
    if(r < view.r1 + 2 || r > view.r2 - 2){
      var h = wrap.clientHeight || 600, top = (r - 1) * ROWH;
      if(top < wrap.scrollTop || top + ROWH * 2 > wrap.scrollTop + h) wrap.scrollTop = Math.max(0, top - h / 2);
      renderRows(false);
    }
    return cellEl(ref);
  }

  function drawSheets(){
    var box = document.getElementById('agSheets');
    while(box.firstChild) box.removeChild(box.firstChild);
    BOOK.sheets.forEach(function(sh, i){
      var t = document.createElement('div');
      t.className = 'sh' + (i === BOOK.active ? ' on' : '');
      var nm = document.createElement('span'); nm.textContent = sh.name || ('Sheet' + (i+1));
      t.appendChild(nm);
      t.addEventListener('click', function(){ if(i !== BOOK.active) switchSheet(i); });
      t.addEventListener('dblclick', function(){ renameSheet(i); });
      if(BOOK.sheets.length > 1){
        var x = document.createElement('i'); x.className = 'fi fi-rr-cross-small';
        x.addEventListener('click', function(e){ e.stopPropagation(); delSheet(i); });
        t.appendChild(x);
      }
      box.appendChild(t);
    });
    var add = document.createElement('button');
    add.type = 'button'; add.className = 'add'; add.title = T.shName;
    add.appendChild(Object.assign(document.createElement('i'), {className:'fi fi-rr-plus'}));
    add.addEventListener('click', addSheet);
    box.appendChild(add);
  }
  function switchSheet(i){
    var act = document.activeElement; if(act && act.classList && act.classList.contains('ag-cell')){ commit(act); act.blur(); }
    BOOK.active = i; G = BOOK.sheets[i];
    wrap.scrollTop = 0; wrap.scrollLeft = 0;
    build(); select('A1'); drawSheets();
  }
  function addSheet(){
    if(BOOK.sheets.length >= 20) return;
    var n = BOOK.sheets.length + 1, names = {};
    BOOK.sheets.forEach(function(sh){ names[String(sh.name).toLowerCase()] = 1; });
    while(names[('sheet' + n)]) n++;
    BOOK.sheets.push({name:'Sheet' + n, rows:20, cols:8, cells:{}, fmt:{}, widths:{}});
    ENG.setBook(BOOK);
    switchSheet(BOOK.sheets.length - 1); touch();
  }
  // เปลี่ยนชื่อชีต → แก้ชื่อที่สูตรอ้างถึงด้วย (เหมือน Excel)
  function renameRefs(oldName, newName){
    var esc = oldName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var q = function(n){ return /^[A-Za-z_฀-๿][A-Za-z0-9_.฀-๿]*$/.test(n) ? n : "'" + n.replace(/'/g, "''") + "'"; };
    var reQ = new RegExp("'" + esc.replace(/'/g, "''") + "'!", 'gi');
    var reP = new RegExp('(^|[^A-Za-z0-9_.\'\\u0E00-\\u0E7F])' + esc + '!', 'gi');
    BOOK.sheets.forEach(function(sh){
      for(var ref in sh.cells){
        var c = sh.cells[ref]; if(!c || !c.f || c.f.toLowerCase().indexOf(oldName.toLowerCase()) < 0) continue;
        c.f = c.f.split(/("(?:[^"]|"")*")/).map(function(part){
          if(part.charAt(0) === '"') return part;
          return part.replace(reQ, q(newName) + '!').replace(reP, function(_, pre){ return pre + q(newName) + '!'; });
        }).join('');
      }
    });
  }
  function renameSheet(i){
    var ask = window.dlgPrompt || function(m,d,cb){ var v = prompt(m,d); if(v!==null) cb(v); };
    ask(T.shName, BOOK.sheets[i].name, function(v){
      v = String(v||'').replace(/[\\\/?*\[\]:]/g, '').trim().slice(0,31);
      if(!v || v === BOOK.sheets[i].name) return;
      var clash = BOOK.sheets.some(function(sh, k){ return k !== i && String(sh.name).toLowerCase() === v.toLowerCase(); });
      if(clash) return;
      renameRefs(BOOK.sheets[i].name, v);
      BOOK.sheets[i].name = v;
      ENG.setBook(BOOK); drawSheets(); recalc(); select(cur); touch();
    });
  }
  function delSheet(i){
    if(BOOK.sheets.length <= 1){ alert(T.shLast); return; }
    var go = function(){
      BOOK.sheets.splice(i,1);
      if(BOOK.active >= BOOK.sheets.length) BOOK.active = BOOK.sheets.length - 1;
      G = BOOK.sheets[BOOK.active];
      ENG.setBook(BOOK);
      build(); select('A1'); drawSheets(); touch();
    };
    (window.dlgConfirm || function(m,cb){ if(confirm(m)) cb(); })(T.shDel + ' “' + BOOK.sheets[i].name + '”', go);
  }

  function info(){
    var n=0, f=0;
    for(var k in G.cells){ if(!G.cells.hasOwnProperty(k)) continue; n++; if(G.cells[k].f) f++; }
    document.getElementById('agInfo').textContent = T.info.replace(':c', n.toLocaleString()).replace(':f', f.toLocaleString())
      .replace(':r', G.rows.toLocaleString()).replace(':n', G.cols);
  }
  function rawOf(ref){ var c = G.cells[ref]; return c ? (c.f ? c.f : (c.v === undefined || c.v === null ? '' : String(c.v))) : ''; }
  function select(ref){
    var prev = body.querySelector('.ag-cell.sel'); if(prev) prev.classList.remove('sel');
    cur = ref; refEl.textContent = ref;
    fx.value = rawOf(ref);
    var el = cellEl(ref); if(el) el.classList.add('sel');
    var f = G.fmt[ref] || {};
    document.getElementById('agB').classList.toggle('on', !!f.b);
    document.getElementById('agI').classList.toggle('on', !!f.i);
    document.querySelectorAll('#agAlign button').forEach(function(x){ x.classList.toggle('on', f.a === x.dataset.a); });
    document.querySelectorAll('#agFill button.sw').forEach(function(x){ x.classList.toggle('on', f.bg === x.dataset.bg); });
    document.querySelectorAll('#agFont button.sw').forEach(function(x){ x.classList.toggle('on', f.fc === x.dataset.fc); });
    var cm = ref.match(REF_RE);
    var cw = cm ? (G.widths[cm[1]] || 0) : 0;
    document.querySelectorAll('#agWidth button[data-w]').forEach(function(x){ x.classList.toggle('on', +x.dataset.w === cw); });
    if(f.bg) document.getElementById('agFillMore').value = f.bg;
    if(f.fc) document.getElementById('agFontMore').value = f.fc;
  }
  /** เขียนค่าลงช่อง — คืน false ถ้าค่าไม่ได้เปลี่ยน (ไม่ต้องบันทึก) */
  function setCell(ref, raw){
    raw = String(raw);
    var old = rawOf(ref);
    if(raw === old || raw === old.trim()) return false;
    if(raw === ''){ delete G.cells[ref]; }
    else if(raw.charAt(0) === '=' && raw.length > 1){ G.cells[ref] = {f: raw.slice(0,2000)}; }
    else { G.cells[ref] = {v: raw.slice(0,2000)}; }
    ENG.touch(BOOK.active, ref);
    touch();
    return true;
  }
  function commit(el){ if(el && el.dataset && el.dataset.ref) setCell(el.dataset.ref, el.textContent.trim()); }
  function touch(){
    dirty = true; gen++; setState('','•');
    clearTimeout(timer); timer = setTimeout(function(){ agSave(false); }, BIG ? 6000 : 2500);
  }

  // ── เหตุการณ์ในตาราง ──
  wrap.addEventListener('focusin', function(e){
    var el = e.target.closest ? e.target.closest('.ag-cell') : null;
    if(!el) return;
    select(el.dataset.ref);
    el.textContent = rawOf(el.dataset.ref);      // ตอนแก้ไขโชว์สูตร/ค่าดิบ
  });
  wrap.addEventListener('focusout', function(e){
    var el = e.target.closest ? e.target.closest('.ag-cell') : null;
    if(!el || !el.isConnected) return;
    if(setCell(el.dataset.ref, el.textContent.trim())) recalc();
    else paint(el, el.dataset.ref);
  });
  wrap.addEventListener('keydown', function(e){
    var el = e.target.closest ? e.target.closest('.ag-cell') : null;
    if(!el) return;
    var m = el.dataset.ref.match(REF_RE); if(!m) return;
    var c = colIndex(m[1]), r = +m[2], to = null;
    if(e.key === 'Enter' && !e.shiftKey){ to = colName(c) + Math.min(G.rows, r+1); }
    else if(e.key === 'Enter' && e.shiftKey){ to = colName(c) + Math.max(1, r-1); }
    else if(e.key === 'Tab'){ to = colName(e.shiftKey ? Math.max(0, c-1) : Math.min(G.cols-1, c+1)) + r; }
    else if(e.key === 'Escape'){ el.textContent = rawOf(el.dataset.ref); el.blur(); return; }
    else if(e.key === 'ArrowDown' && (e.ctrlKey || e.altKey)){ to = colName(c) + Math.min(G.rows, r+1); }
    else if(e.key === 'ArrowUp' && (e.ctrlKey || e.altKey)){ to = colName(c) + Math.max(1, r-1); }
    else if(e.key === 'PageDown'){ to = colName(c) + Math.min(G.rows, r + Math.max(5, Math.floor(wrap.clientHeight / ROWH) - 2)); }
    else if(e.key === 'PageUp'){ to = colName(c) + Math.max(1, r - Math.max(5, Math.floor(wrap.clientHeight / ROWH) - 2)); }
    if(to){ e.preventDefault(); el.blur(); var t = reveal(to); if(t){ t.focus(); placeCaretEnd(t); } }
  });
  function placeCaretEnd(el){
    var rg = document.createRange(); rg.selectNodeContents(el); rg.collapse(false);
    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(rg);
  }

  fx.addEventListener('keydown', function(e){
    if(e.key !== 'Enter') return;
    e.preventDefault();
    if(setCell(cur, fx.value.trim())) recalc();
    var el = reveal(cur); if(el) el.focus();
  });
  // กล่องอ้างอิงช่อง: คลิกแล้วพิมพ์ เช่น D1500 เพื่อกระโดดไป
  refEl.title = 'Go to…';
  refEl.style.cursor = 'pointer';
  refEl.addEventListener('click', function(){
    var ask = window.dlgPrompt || function(m,d,cb){ var v = prompt(m,d); if(v!==null) cb(v); };
    ask('Go to (e.g. A100)', cur, function(v){
      v = String(v || '').trim().toUpperCase();
      var m = v.match(REF_RE); if(!m) return;
      var ci = colIndex(m[1]), ri = +m[2];
      if(ci >= G.cols || ri < 1 || ri > G.rows) return;
      var el = reveal(v); if(el){ el.focus(); }
    });
  });

  // ── ความกว้างคอลัมน์: ลากขอบหัวคอลัมน์ หรือกดปุ่ม S/M/L/XL ──
  function setColWidth(col, w){
    w = Math.max(40, Math.min(600, Math.round(w)));
    G.widths[col] = w;
    var ce = document.querySelector('#agCols col[data-col="'+col+'"]');
    if(ce) ce.style.width = w + 'px';
    var total = HEADW;
    for(var c = 0; c < G.cols; c++) total += colW(c);
    table.style.width = total + 'px';
  }
  (function bindResize(){
    var col = null, x0 = 0, w0 = 0, grip = null;
    wrap.addEventListener('mousedown', function(e){
      var g = e.target.closest ? e.target.closest('.ag-grip') : null;
      if(!g) return;
      e.preventDefault();
      col = g.dataset.col; grip = g; g.classList.add('on');
      x0 = e.clientX; w0 = G.widths[col] || DEFW;
      document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
    });
    function mv(e){ if(col) setColWidth(col, w0 + (e.clientX - x0)); }
    function up(){
      if(!col) return;
      if(grip) grip.classList.remove('on');
      col = null; grip = null;
      document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up);
      touch();
    }
  })();
  document.getElementById('agWidth').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-w]') : null;
    if(!b) return;
    var m = cur.match(REF_RE); if(!m) return;
    setColWidth(m[1], +b.dataset.w);
    this.querySelectorAll('button[data-w]').forEach(function(x){ x.classList.toggle('on', x === b); });
    touch();
  });

  // จัดชิด / สีพื้น / สีอักษร — ผูกเป็นกลุ่ม
  function setFmt(mutate){
    var f = G.fmt[cur] || {};
    mutate(f);
    if(Object.keys(f).length) G.fmt[cur] = f; else delete G.fmt[cur];
    var el = cellEl(cur); if(el && el !== document.activeElement) paint(el, cur);
    select(cur); touch();
  }
  document.getElementById('agAlign').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-a]') : null;
    if(b) agFmt('a', b.dataset.a);
  });
  document.getElementById('agFill').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-bg]') : null;
    if(!b) return;
    setFmt(function(f){ if(b.dataset.bg) f.bg = b.dataset.bg; else delete f.bg; });
  });
  document.getElementById('agFont').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-fc]') : null;
    if(!b) return;
    setFmt(function(f){ if(f.fc === b.dataset.fc) delete f.fc; else f.fc = b.dataset.fc; });
  });
  ['agFillMore','agFontMore'].forEach(function(id){
    var el = document.getElementById(id);
    var key = id === 'agFillMore' ? 'bg' : 'fc';
    var fn = function(){ var val = el.value; setFmt(function(f){ if(val) f[key] = val; else delete f[key]; }); };
    el.addEventListener('input', fn); el.addEventListener('change', fn);
  });

  // สูตรด่วน — ใส่สูตรคิดจากช่องเหนือขึ้นไปในคอลัมน์เดียวกัน
  document.getElementById('agCalc').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-fn]') : null;
    if(!b) return;
    var m = cur.match(REF_RE); if(!m) return;
    var col = m[1], row = +m[2];
    if(row < 2) return;
    var top = 1;
    for(var r = row - 1; r >= 1; r--){ if(!G.cells[col + r]) { top = r + 1; break; } }
    if(top > row - 1) top = row - 1;
    setCell(cur, '=' + b.dataset.fn + '(' + col + top + ':' + col + (row - 1) + ')');
    recalc(); select(cur);
  });
  function moveKeys(obj, map){
    var out = {};
    Object.keys(obj).forEach(function(ref){ var to = map(ref); if(to) out[to] = obj[ref]; });
    return out;
  }
  window.agDelRow = function(){
    if(G.rows <= 1) return;
    var m = cur.match(REF_RE); if(!m) return;
    var row = +m[2];
    var shift = function(ref){
      var x = ref.match(REF_RE); if(!x) return null;
      var r = +x[2];
      return r === row ? null : (r > row ? x[1] + (r - 1) : ref);
    };
    G.cells = moveKeys(G.cells, shift); G.fmt = moveKeys(G.fmt, shift);
    G.rows--; ENG.reindex(BOOK.active);
    build(); select(m[1] + Math.min(row, G.rows)); touch();
  };
  window.agDelCol = function(){
    if(G.cols <= 1) return;
    var m = cur.match(REF_RE); if(!m) return;
    var ci = colIndex(m[1]);
    var shift = function(ref){
      var x = ref.match(REF_RE); if(!x) return null;
      var c = colIndex(x[1]);
      return c === ci ? null : (c > ci ? colName(c - 1) + x[2] : ref);
    };
    G.cells = moveKeys(G.cells, shift); G.fmt = moveKeys(G.fmt, shift);
    var w = {};
    Object.keys(G.widths).forEach(function(k){ var c = colIndex(k); if(c < ci) w[k] = G.widths[k]; else if(c > ci) w[colName(c - 1)] = G.widths[k]; });
    G.widths = w;
    G.cols--; ENG.reindex(BOOK.active);
    build(); select(colName(Math.min(ci, G.cols - 1)) + m[2]); touch();
  };

  window.agFmt = function(k, v){
    setFmt(function(f){
      if(k === 'a'){ f.a = (f.a === v ? '' : v); if(!f.a) delete f.a; }
      else { f[k] = f[k] ? 0 : 1; if(!f[k]) delete f[k]; }
    });
  };
  window.agAddRow = function(){
    if(G.rows >= MAX_ROWS) return;
    G.rows = Math.min(MAX_ROWS, G.rows + (G.rows >= 200 ? 50 : 5));
    build(); select(cur); touch();
  };
  window.agAddCol = function(){ if(G.cols >= MAX_COLS) return; G.cols = Math.min(MAX_COLS, G.cols + 2); build(); select(cur); touch(); };
  window.agClearCell = function(){
    delete G.cells[cur]; delete G.fmt[cur];
    ENG.touch(BOOK.active, cur);
    recalc(); select(cur); touch();
  };

  // ── บันทึก ──
  // ก่อนส่ง: เขียนผลคำนวณล่าสุดของทุกสูตรลง v (หน้าอ่าน/ส่งออก .xlsx ใช้ค่านี้) — สมุดงานใหญ่คิดทีละช่วงไม่ให้หน้าค้าง
  function fillCache(done){
    var jobs = [];
    BOOK.sheets.forEach(function(sh, si){ for(var ref in sh.cells){ var c = sh.cells[ref]; if(c && c.f) jobs.push([si, ref, c]); } });
    var i = 0, startGen = gen;
    (function step(){
      if(gen !== startGen) return done(false);            // มีการแก้ไขระหว่างคิด → รอบันทึกรอบถัดไป
      var t0 = Date.now();
      while(i < jobs.length && Date.now() - t0 < 30){
        var j = jobs[i++];
        j[2].v = ENG.cacheValue(j[0], j[1]);
      }
      if(i < jobs.length){ setTimeout(step, 0); return; }
      done(true);
    })();
  }
  function gzip(text){
    if(typeof CompressionStream === 'undefined' || text.length < 256 * 1024) return Promise.resolve(null);
    try{
      var stream = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'));
      return new Response(stream).blob().catch(function(){ return null; });
    }catch(e){ return Promise.resolve(null); }
  }
  var saving = null, again = false;
  window.agSave = function(manual){
    if(!dirty && !manual) return Promise.resolve();
    if(saving){ again = true; return saving; }
    clearTimeout(timer);
    setState('saving', BIG ? T.calc : T.saving);
    var myGen = gen;
    saving = new Promise(function(resolve){
      fillCache(function(ok){
        if(!ok){ resolve(); return; }
        setState('saving', T.saving);
        var json = JSON.stringify(BOOK);
        gzip(json).then(function(gz){
          var fd = new FormData();
          fd.append('action','save'); fd.append('type','grid'); fd.append('key',KEY);
          fd.append('title', document.getElementById('agTitle').value);
          fd.append('course', document.getElementById('agCourse').value);
          if(gz) fd.append('content_gz', gz, 'content.json.gz'); else fd.append('content', json);
          fd.append('csrf_token', CSRF);
          json = null;
          return fetch(DOCS.api, {method:'POST', body:fd})
            .then(function(r){ return r.text(); })
            .then(function(txt){
              var j; try{ j = JSON.parse(txt); }catch(e){ j = {ok:false, error: txt ? 'server' : 'too_large'}; }
              if(!j.ok){ setState('error', T.error + (j.error === 'too_large' ? ' — ' + T.big : (j.error ? ' ('+j.error+')' : ''))); return; }
              if(gen === myGen) dirty = false;
              if(!KEY){ KEY = j.key; history.replaceState(null,'',SELF+encodeURIComponent(KEY)); }
              setState(dirty ? '' : 'saved', dirty ? '•' : T.saved);
            });
        }).catch(function(){ setState('error', T.error); }).then(resolve);
      });
    }).then(function(){
      saving = null;
      if(again){ again = false; if(dirty){ clearTimeout(timer); timer = setTimeout(function(){ agSave(false); }, 800); } }
    });
    return saving;
  };
  document.getElementById('agTitle').addEventListener('input', touch);
  document.getElementById('agCourse').addEventListener('change', touch);
  document.addEventListener('keydown', function(e){
    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){
      e.preventDefault();
      var act = document.activeElement;
      if(act && act.classList && act.classList.contains('ag-cell') && setCell(act.dataset.ref, act.textContent.trim())) recalc();
      agSave(true);
    }
  });
  window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue=''; } });

  build(); select('A1'); drawSheets();
})();
</script>
