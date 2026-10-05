<?php
// Aleanor Write — ตัวแก้ไขเอกสารข้อความ (พอร์ตจาก aleanor_ai/dashboard/document/doc-write.php)
// ไม่ใช่ route — ถูก include จาก doc-edit.php เท่านั้น ($doc = แถวเอกสารที่ตรวจสิทธิ์แล้ว)
if(!isset($doc) || !class_exists('DocsService')) exit;
$TI      = DocsService::typeInfo('write');
$content = DocsService::renderWriteHtml($doc['content']);
$courses = DocsService::coursesFor(current_user_id());
?><style>
  .aw-bar { display:flex; align-items:center; gap:.55rem; flex-wrap:wrap; margin-bottom:.9rem; }
  .aw-title { flex:1; min-width:220px; font-size:1rem; font-weight:600; font-family:inherit;
    background:var(--card); color:var(--text); border:1.5px solid var(--border); border-radius:10px;
    padding:10px 13px; min-height:44px; transition:border-color .15s, box-shadow .15s; }
  .aw-title::placeholder { font-weight:400; color:var(--text-muted); }
  .aw-title:focus { outline:none; border-color:var(--primary);
    box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 14%,transparent); }
  .aw-state { font-size:.78rem; font-weight:600; color:var(--text-muted); min-width:96px; text-align:right; }
  .aw-state.saving { color:var(--primary); }
  .aw-state.saved  { color:#059669; }
  .aw-state.error  { color:#dc2626; }

  .aw-tools { display:flex; flex-wrap:wrap; gap:7px; align-items:center; padding:.5rem .6rem; background:var(--card);
              border:1px solid var(--border); border-radius:var(--radius) var(--radius) 0 0; border-bottom:none; position:sticky; top:0; z-index:5; }
  .aw-tools .aw-style { height:32px; max-width:130px; padding:0 28px 0 10px; font-size:.84rem; }
  .aw-imgbar { display:flex; align-items:center; gap:7px; flex-wrap:wrap; padding:.45rem .6rem;
               background:var(--card); border:1px solid var(--border); border-bottom:none; }
  .aw-paper img.aw-sel { outline:2.5px solid var(--primary); outline-offset:2px; border-radius:4px; }

  .aw-paper { background:var(--card); border:1px solid var(--border); border-radius:0 0 var(--radius) var(--radius);
              padding:2.6rem 3rem; min-height:60vh; line-height:1.85; font-size:1rem; color:var(--text); outline:none; }
  .aw-paper:focus { outline:none; }
  .aw-paper h1 { font-size:1.7rem; font-weight:700; margin:1.2rem 0 .6rem; }
  .aw-paper h2 { font-size:1.35rem; font-weight:700; margin:1.1rem 0 .5rem; }
  .aw-paper h3 { font-size:1.13rem; font-weight:700; margin:1rem 0 .45rem; }
  .aw-paper p  { margin:.55rem 0; }
  .aw-paper ul, .aw-paper ol { margin:.55rem 0 .55rem 1.5rem; }
  .aw-paper li { margin:.2rem 0; }
  .aw-paper img { max-width:100%; height:auto; border-radius:8px; }
  .aw-paper table { border-collapse:collapse; width:100%; margin:.8rem 0; }
  .aw-paper th, .aw-paper td { border:1px solid var(--border); padding:7px 10px; }
  .aw-paper th { background:color-mix(in srgb,var(--primary) 8%,transparent); font-weight:700; }
  .aw-paper blockquote { border-left:3px solid var(--primary); margin:.7rem 0; padding:.2rem 0 .2rem 1rem; color:var(--text-secondary); }
  .aw-paper pre { background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:.8rem 1rem; overflow-x:auto; }
  .aw-paper:empty::before { content:attr(data-ph); color:var(--text-muted); }
  .aw-foot { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-top:.7rem; font-size:.8rem; color:var(--text-muted); }
  @media (max-width:640px){ .aw-paper { padding:1.4rem 1.1rem; } }
  @media print {
    .page-header, .aw-bar, .aw-tools, .aw-foot, .sidebar, .topbar, .nav-item { display:none !important; }
    .aw-paper { border:none; padding:0; }
  }
</style>

<div class="page-header">
  <div><h1 style="color:<?php echo $TI['color'];?>"><?php echo DocsService::typeIcon('write', 26);?> <?php echo htmlspecialchars($TI['name']);?></h1>
       <p><?php echo docs_t('doc.write_desc');?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-outline" href="<?php echo h(iu('docs', ['f' => $doc['folder_id'] ?? null]));?>"><i class="fi fi-rr-list"></i> <?php echo docs_t('doc.all_docs');?></a>
    <?php if($doc){ ?>
      <a class="btn btn-outline" target="_blank" href="<?php echo h(iu('doc-view', ['id' => $doc['id']]));?>"><i class="fi fi-rr-eye"></i> <?php echo docs_t('doc.open_read');?></a>
      <a class="btn btn-outline" href="<?php echo h(asset('api/docs.php').'?action=export&id='.(int)$doc['id']);?>"><i class="fi fi-rr-download"></i> .docx</a>
    <?php } ?>
    <button class="btn btn-primary" onclick="awSave(true)"><i class="fi fi-rr-disk"></i> <?php echo docs_t('common.save');?></button>
  </div>
</div>

<div class="aw-bar">
  <input id="awTitle" class="aw-title" value="<?php echo htmlspecialchars($doc['title'] ?? '');?>"
         placeholder="<?php echo docs_t('doc.title_ph');?>" maxlength="200">
  <select id="awCourse" class="form-control form-select" style="max-width:260px">
    <option value=""><?php echo docs_t('doc.no_course');?></option>
    <?php foreach($courses as $c){ ?>
      <option value="<?php echo (int)$c['id'];?>" <?php if((int)($doc['course_id'] ?? 0)===(int)$c['id']) echo 'selected';?>><?php echo h($c['title']);?></option>
    <?php } ?>
  </select>
  <span id="awState" class="aw-state"></span>
</div>

<div class="aw-tools">
  <select class="aw-style form-control form-select" onchange="awBlock(this.value); this.selectedIndex=0">
    <option value=""><?php echo docs_t('doc.tool_style');?></option>
    <option value="p"><?php echo docs_t('doc.tool_para');?></option>
    <option value="h1"><?php echo docs_t('doc.tool_h1');?></option>
    <option value="h2"><?php echo docs_t('doc.tool_h2');?></option>
    <option value="h3"><?php echo docs_t('doc.tool_h3');?></option>
    <option value="blockquote"><?php echo docs_t('doc.tool_quote');?></option>
    <option value="pre"><?php echo docs_t('doc.tool_code');?></option>
  </select>

  <div class="btn-grp">
    <button type="button" class="wide" onclick="awBlock('h1')" title="<?php echo docs_t('doc.tool_h1');?>">H1</button>
    <button type="button" class="wide" onclick="awBlock('h2')" title="<?php echo docs_t('doc.tool_h2');?>">H2</button>
    <button type="button" class="wide" onclick="awBlock('h3')" title="<?php echo docs_t('doc.tool_h3');?>">H3</button>
  </div>

  <div class="btn-grp">
    <button type="button" data-cmd="bold" title="<?php echo docs_t('doc.tool_bold');?>"><b>B</b></button>
    <button type="button" data-cmd="italic" title="<?php echo docs_t('doc.tool_italic');?>"><i>I</i></button>
    <button type="button" data-cmd="underline" title="<?php echo docs_t('doc.tool_underline');?>"><u>U</u></button>
    <button type="button" data-cmd="strikeThrough" title="<?php echo docs_t('doc.tool_strike');?>"><s>S</s></button>
  </div>

  <div class="btn-grp" id="awColor" title="<?php echo docs_t('doc.grid_textcolor');?>">
    <span class="grp-lb"><i class="fi fi-rr-text"></i></span>
    <?php foreach(['#1e293b','#2563eb','#059669','#d97706','#dc2626'] as $c){ ?>
      <button type="button" class="sw" data-color="<?php echo $c;?>" style="background:<?php echo $c;?>"></button>
    <?php } ?>
    <input type="color" id="awColorMore" value="#6366f1" title="<?php echo docs_t('doc.color_more');?>">
  </div>
  <div class="btn-grp" id="awMark" title="<?php echo docs_t('doc.tool_highlight');?>">
    <span class="grp-lb"><i class="fi fi-rr-highlighter"></i></span>
    <?php foreach(['#fef08a','#bbf7d0','#bfdbfe','#fecaca'] as $c){ ?>
      <button type="button" class="sw" data-mark="<?php echo $c;?>" style="background:<?php echo $c;?>"></button>
    <?php } ?>
    <input type="color" id="awMarkMore" value="#fde68a" title="<?php echo docs_t('doc.color_more');?>">
    <button type="button" data-mark="" title="<?php echo docs_t('doc.grid_fill_none');?>"><i class="fi fi-rr-cross-small"></i></button>
  </div>

  <div class="btn-grp">
    <button type="button" data-cmd="insertUnorderedList" title="<?php echo docs_t('doc.tool_ul');?>"><i class="fi fi-rr-list"></i></button>
    <button type="button" data-cmd="insertOrderedList" title="<?php echo docs_t('doc.tool_ol');?>"><i class="fi fi-rr-list-check"></i></button>
  </div>
  <div class="btn-grp">
    <button type="button" data-cmd="justifyLeft" title="<?php echo docs_t('doc.tool_left');?>"><i class="fi fi-rr-align-left"></i></button>
    <button type="button" data-cmd="justifyCenter" title="<?php echo docs_t('doc.tool_center');?>"><i class="fi fi-rr-align-center"></i></button>
    <button type="button" data-cmd="justifyRight" title="<?php echo docs_t('doc.tool_right');?>"><i class="fi fi-rr-align-left ic-flip"></i></button>
  </div>
  <div class="btn-grp">
    <button type="button" onclick="awLink()" title="<?php echo docs_t('doc.tool_link');?>"><i class="fi fi-rr-link"></i></button>
    <button type="button" onclick="awImage()" title="<?php echo docs_t('doc.tool_image');?>"><i class="fi fi-rr-picture"></i></button>
    <button type="button" onclick="awTable()" title="<?php echo docs_t('doc.tool_table');?>"><i class="fi fi-rr-table-layout"></i></button>
    <button type="button" data-cmd="insertHorizontalRule" title="<?php echo docs_t('doc.tool_hr');?>">―</button>
  </div>
  <div class="btn-grp">
    <button type="button" data-cmd="removeFormat" title="<?php echo docs_t('doc.tool_clear');?>"><i class="fi fi-rr-eraser"></i></button>
    <button type="button" data-cmd="undo" title="<?php echo docs_t('doc.tool_undo');?>"><i class="fi fi-rr-undo"></i></button>
    <button type="button" data-cmd="redo" title="<?php echo docs_t('doc.tool_redo');?>"><i class="fi fi-rr-redo"></i></button>
  </div>
  <div class="btn-grp">
    <button type="button" onclick="window.print()" title="<?php echo docs_t('doc.tool_print');?>"><i class="fi fi-rr-print"></i></button>
  </div>
</div>

<!-- แถบจัดการรูป — โผล่เมื่อคลิกที่รูปในเอกสาร -->
<div class="aw-imgbar" id="awImgBar" hidden>
  <span class="grp-lb"><?php echo docs_t('doc.img_size');?></span>
  <div class="btn-grp" id="awImgW">
    <button type="button" class="wide" data-w="25">25%</button>
    <button type="button" class="wide" data-w="50">50%</button>
    <button type="button" class="wide" data-w="75">75%</button>
    <button type="button" class="wide" data-w="100">100%</button>
  </div>
  <div class="btn-grp" id="awImgA">
    <button type="button" data-al="left"   title="<?php echo docs_t('doc.tool_left');?>"><i class="fi fi-rr-align-left"></i></button>
    <button type="button" data-al="center" title="<?php echo docs_t('doc.tool_center');?>"><i class="fi fi-rr-align-center"></i></button>
    <button type="button" data-al="right"  title="<?php echo docs_t('doc.tool_right');?>"><i class="fi fi-rr-align-left ic-flip"></i></button>
  </div>
  <div class="btn-grp">
    <button type="button" onclick="awImgAlt()" title="<?php echo docs_t('doc.img_alt');?>"><i class="fi fi-rr-comment-alt"></i></button>
    <button type="button" onclick="awImgRound()" title="<?php echo docs_t('doc.img_round');?>"><i class="fi fi-rr-frame"></i></button>
    <button type="button" onclick="awImgDel()" title="<?php echo docs_t('common.delete');?>"><i class="fi fi-rr-trash"></i></button>
  </div>
</div>

<div id="awPaper" class="aw-paper" contenteditable="true" spellcheck="false"
     data-ph="<?php echo docs_t('doc.body_ph');?>"><?php echo $content;?></div>

<div class="aw-foot">
  <span id="awCount"></span>
  <span><?php echo docs_t('doc.autosave_hint');?></span>
</div>

<input type="file" id="awFile" accept="image/*" hidden>

<script>
(function(){
  var KEY   = <?php echo json_encode((int)$doc['id']);?>;
  var CSRF  = <?php echo json_encode(csrf_token());?>;
  var SELF  = <?php echo json_encode(iu('doc-edit').'&id=');?>;
  var BASE  = <?php echo json_encode(app_base());?>;
  var paper = document.getElementById('awPaper');
  var state = document.getElementById('awState');
  var T = {
    saving: <?php echo json_encode(docs_t('doc.saving'),JSON_UNESCAPED_UNICODE);?>,
    saved:  <?php echo json_encode(docs_t('doc.saved'),JSON_UNESCAPED_UNICODE);?>,
    error:  <?php echo json_encode(docs_t('doc.save_error'),JSON_UNESCAPED_UNICODE);?>,
    words:  <?php echo json_encode(docs_t('doc.word_count'),JSON_UNESCAPED_UNICODE);?>,
    askUrl: <?php echo json_encode(docs_t('doc.ask_link'),JSON_UNESCAPED_UNICODE);?>,
    askRow: <?php echo json_encode(docs_t('doc.ask_rows'),JSON_UNESCAPED_UNICODE);?>,
    askCol: <?php echo json_encode(docs_t('doc.ask_cols'),JSON_UNESCAPED_UNICODE);?>
  };
  var dirty = false, timer = null;

  function setState(cls, text){ state.className = 'aw-state ' + cls; state.textContent = text; }
  function readBody(){
    // อ่านเนื้อหาออกมาเป็นสตริง โดยไม่แตะ innerHTML
    var s = new XMLSerializer(), out = '';
    for(var i = 0; i < paper.childNodes.length; i++) out += s.serializeToString(paper.childNodes[i]);
    return out.replace(/ xmlns="http:\/\/www\.w3\.org\/1999\/xhtml"/g, '');
  }
  function countWords(){
    var t = (paper.textContent || '').trim();
    var n = t ? t.replace(/\s+/g,' ').length : 0;
    document.getElementById('awCount').textContent = T.words.replace(':n', n);
  }

  function awSave(manual){
    if(!dirty && !manual) return;
    setState('saving', T.saving);
    var fd = new FormData();
    fd.append('action','save'); fd.append('type','write'); fd.append('key', KEY);
    fd.append('title', document.getElementById('awTitle').value);
    fd.append('course', document.getElementById('awCourse').value);
    fd.append('content', readBody());
    fd.append('csrf_token', CSRF);
    return fetch(DOCS.api, {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(!j.ok){ setState('error', T.error + (j.error ? ' ('+j.error+')' : '')); return; }
        dirty = false;
        if(!KEY){ KEY = j.key; history.replaceState(null,'', SELF + encodeURIComponent(KEY)); }
        setState('saved', T.saved);
      })
      .catch(function(){ setState('error', T.error); });
  }
  window.awSave = awSave;

  function touch(){ dirty = true; setState('', '•'); clearTimeout(timer); timer = setTimeout(function(){ awSave(false); }, 2500); countWords(); }
  paper.addEventListener('input', touch);
  document.getElementById('awTitle').addEventListener('input', touch);
  document.getElementById('awCourse').addEventListener('change', touch);

  // สีตัวอักษร / ไฮไลต์
  document.getElementById('awColor').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-color]') : null;
    if(!b) return;
    paper.focus(); document.execCommand('foreColor', false, b.dataset.color); touch();
  });
  // เลือกสีอิสระ — เก็บช่วงที่เลือกไว้ก่อน เพราะเปิดจานสีแล้วโฟกัสหลุดจากเอกสาร
  function bindColorInput(id, apply){
    var el = document.getElementById(id), saved = null;
    el.addEventListener('mousedown', function(){
      var sel = window.getSelection();
      saved = (sel && sel.rangeCount && paper.contains(sel.anchorNode)) ? sel.getRangeAt(0).cloneRange() : null;
    });
    function run(){
      paper.focus();
      if(saved){ var s2 = window.getSelection(); s2.removeAllRanges(); s2.addRange(saved); }
      apply(el.value); touch();
    }
    el.addEventListener('input', run);
    el.addEventListener('change', run);
  }
  bindColorInput('awColorMore', function(c){ document.execCommand('foreColor', false, c); });
  bindColorInput('awMarkMore',  function(c){ document.execCommand('hiliteColor', false, c); });

  document.getElementById('awMark').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-mark]') : null;
    if(!b) return;
    paper.focus();
    if(b.dataset.mark) document.execCommand('hiliteColor', false, b.dataset.mark);
    else document.execCommand('hiliteColor', false, 'transparent');
    touch();
  });

  // ── จัดการรูปในเอกสาร ──
  var selImg = null;
  var imgBar = document.getElementById('awImgBar');
  function pickImg(img){
    if(selImg) selImg.classList.remove('aw-sel');
    selImg = img;
    if(!img){ imgBar.hidden = true; return; }
    img.classList.add('aw-sel');
    imgBar.hidden = false;
    var w = (img.style.width || '').replace('%','');
    document.querySelectorAll('#awImgW button').forEach(function(x){ x.classList.toggle('on', x.dataset.w === w); });
    var al = img.style.float === 'left' ? 'left' : (img.style.float === 'right' ? 'right'
             : (img.style.display === 'block' && img.style.margin ? 'center' : ''));
    document.querySelectorAll('#awImgA button').forEach(function(x){ x.classList.toggle('on', x.dataset.al === al); });
  }
  paper.addEventListener('click', function(e){
    if(e.target && e.target.tagName === 'IMG') pickImg(e.target);
    else pickImg(null);
  });
  document.getElementById('awImgW').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-w]') : null;
    if(!b || !selImg) return;
    selImg.style.width = b.dataset.w + '%';
    selImg.style.height = 'auto';
    selImg.removeAttribute('width'); selImg.removeAttribute('height');
    pickImg(selImg); touch();
  });
  document.getElementById('awImgA').addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('button[data-al]') : null;
    if(!b || !selImg) return;
    var a = b.dataset.al;
    selImg.style.float = ''; selImg.style.display = ''; selImg.style.margin = '';
    if(a === 'center'){ selImg.style.display = 'block'; selImg.style.margin = 'auto'; }
    else if(a === 'left'){ selImg.style.float = 'left';  selImg.style.margin = '0 1rem .5rem 0'; }
    else if(a === 'right'){ selImg.style.float = 'right'; selImg.style.margin = '0 0 .5rem 1rem'; }
    pickImg(selImg); touch();
  });
  window.awImgDel = function(){ if(!selImg) return; var p2 = selImg; pickImg(null); p2.parentNode.removeChild(p2); touch(); };
  window.awImgRound = function(){
    if(!selImg) return;
    selImg.style.borderRadius = selImg.style.borderRadius ? '' : '12px';
    touch();
  };
  window.awImgAlt = function(){
    if(!selImg) return;
    var img = selImg;
    var ask = window.dlgPrompt || function(m,d,cb){ var v=prompt(m,d); if(v!==null) cb(v); };
    ask(<?php echo json_encode(docs_t('doc.img_alt_ask'),JSON_UNESCAPED_UNICODE);?>, img.getAttribute('alt') || '', function(v){
      img.setAttribute('alt', String(v||'').slice(0,200)); touch();
    });
  };

  // แถบเครื่องมือ
  document.querySelectorAll('.aw-tools button[data-cmd]').forEach(function(b){
    b.addEventListener('click', function(){ paper.focus(); document.execCommand(b.dataset.cmd, false, null); touch(); syncTools(); });
  });
  function syncTools(){
    ['bold','italic','underline','strikeThrough'].forEach(function(c){
      var b = document.querySelector('.aw-tools button[data-cmd="'+c+'"]');
      if(b) b.classList.toggle('on', document.queryCommandState(c));
    });
    ['justifyLeft','justifyCenter','justifyRight'].forEach(function(c){
      var b = document.querySelector('.aw-tools button[data-cmd="'+c+'"]');
      if(b) b.classList.toggle('on', document.queryCommandState(c));
    });
  }
  paper.addEventListener('keyup', syncTools);
  paper.addEventListener('mouseup', syncTools);

  window.awBlock = function(tag){
    if(!tag) return;
    paper.focus();
    document.execCommand('formatBlock', false, tag === 'p' ? 'p' : tag);
    touch();
  };
  function ask(msg, def, cb){
    if(window.dlgPrompt) window.dlgPrompt(msg, def, function(v){ if(v!==null && v!==undefined) cb(v); });
    else { var v = prompt(msg, def); if(v !== null) cb(v); }
  }
  window.awLink = function(){
    var sel = window.getSelection(), saved = (sel && sel.rangeCount) ? sel.getRangeAt(0).cloneRange() : null;
    ask(T.askUrl, 'https://', function(url){
      url = (url || '').trim();
      if(!url) return;
      if(!/^(https?:\/\/|mailto:|\/)/i.test(url)) url = 'https://' + url;
      paper.focus();
      if(saved){ var s2 = window.getSelection(); s2.removeAllRanges(); s2.addRange(saved); }
      document.execCommand('createLink', false, url); touch();
    });
  };
  window.awTable = function(){
    ask(T.askRow, '3', function(rv){
      ask(T.askCol, '3', function(cv){ awTableMake(parseInt(rv,10), parseInt(cv,10)); });
    });
  };
  function awTableMake(r, c){
    if(!r || !c || r < 1 || c < 1 || r > 50 || c > 20) return;
    var tbl = document.createElement('table');
    for(var i = 0; i < r; i++){
      var tr = tbl.insertRow();
      for(var j = 0; j < c; j++){
        var cell = document.createElement(i === 0 ? 'th' : 'td');
        cell.appendChild(document.createTextNode(' '));
        tr.appendChild(cell);
      }
    }
    insertNode(tbl); touch();
  }
  function insertNode(node){
    paper.focus();
    var sel = window.getSelection();
    if(sel && sel.rangeCount && paper.contains(sel.anchorNode)){
      var range = sel.getRangeAt(0);
      range.deleteContents(); range.insertNode(node);
      range.setStartAfter(node); range.collapse(true);
      sel.removeAllRanges(); sel.addRange(range);
    }else{
      paper.appendChild(node);
    }
  }
  var fileInput = document.getElementById('awFile');
  window.awImage = function(){ fileInput.click(); };
  fileInput.addEventListener('change', function(){
    var f = fileInput.files && fileInput.files[0];
    if(!f) return;
    setState('saving', T.saving);
    var fd = new FormData();
    fd.append('file', f); fd.append('csrf_token', CSRF);
    fetch(DOCS.api+'?action=image_upload', {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(j){
        fileInput.value = '';
        if(!j.ok){ setState('error', T.error + ' (' + (j.error||'') + ')'); return; }
        var img = document.createElement('img');
        img.setAttribute('src', BASE + j.url);
        img.setAttribute('alt', f.name);
        img.style.width = '100%';
        insertNode(img); touch();
        setTimeout(function(){ pickImg(img); }, 50);
      })
      .catch(function(){ setState('error', T.error); });
  });

  // Ctrl/Cmd+S
  document.addEventListener('keydown', function(e){
    if((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'){ e.preventDefault(); awSave(true); }
  });
  window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue = ''; } });
  countWords();
})();
</script>
