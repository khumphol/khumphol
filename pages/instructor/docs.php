<?php
// ============================================================
// Aleanor Docs — ที่เก็บเอกสารของแต่ละคน (route 'docs') พอร์ตจาก aleanor_ai/dashboard/document/doc-list.php
//   โฟลเดอร์ซ้อนกัน / เอกสารในระบบ (Write · Grid · Present) / ไฟล์ที่อัปโหลด / ถังขยะ / โควตา
//   GET f=<folder id>  q=<ค้นหา>  type=<write|grid|present>  view=trash
//   POST action=new type=.. folder=..  → สร้างเอกสารเปล่าแล้วเปิดตัวแก้ไข
// ============================================================
require_once dirname(__DIR__, 2).'/services/DocsService.php';
$MENU  = 'docs';
$TITLE = 'Aleanor Docs';
$me    = current_user_id();
$TYPES = DocsService::types();

// ── สร้างเอกสารใหม่ (POST) ──
if(is_post() && post('action') === 'new'){
    require_csrf();
    $type = post('type');
    $fol  = (int)post('folder');
    if(!DocsService::typeValid($type)){ flash('ชนิดเอกสารไม่ถูกต้อง', 'danger'); redirect(iu('docs', ['f' => $fol ?: null])); }
    if(!DocsService::quotaAllows($me, 1)){ flash(docs_t('doc.storage_full_msg'), 'danger'); redirect(iu('docs', ['f' => $fol ?: null])); }
    $title = $type === 'grid' ? 'ตารางใหม่' : ($type === 'present' ? 'งานนำเสนอใหม่' : 'เอกสารใหม่');
    $id = DocsService::save(0, ['type' => $type, 'title' => $title, 'folder_id' => $fol], $me);
    audit('docs.create', 'docs_document', $id, null, ['type' => $type]);
    redirect(iu('doc-edit', ['id' => $id]));
}

$folder  = (int)get('f');
$q       = mb_substr(get('q'), 0, 100);
$filter  = DocsService::typeValid(get('type')) ? get('type') : '';
$inTrash = get('view') === 'trash';

DocsService::trashAutoPurge($me);                    // ของที่ค้างในถังเกินกำหนดให้ลบเองอัตโนมัติ

$curFolder = $folder ? DocsService::folderGet($folder) : null;
if(!$curFolder || (int)$curFolder['owner_id'] !== $me){ $curFolder = null; $folder = 0; }
$crumbs = $folder ? DocsService::folderPath($folder) : [];

$folders = ($q === '' && !$inTrash && $filter === '') ? DocsService::folders($me, $folder) : [];
$docs    = $inTrash ? [] : DocsService::listIn($me, $folder, ['q' => $q, 'type' => $filter]);
$files   = ($filter === '' && !$inTrash) ? DocsService::filesIn($me, $folder, ['q' => $q]) : [];
$trash   = $inTrash ? DocsService::trashList($me) : [];
$trashN  = DocsService::trashCount($me);
$quota   = DocsService::quotaInfo($me);
$counts  = DocsService::countByType($me);
$totalDocs = array_sum($counts);
$allFolders = $inTrash ? [] : DocsService::allFolders($me);
$convMap = DocsService::convertibleExt();
$fmtDate = function($d){ return $d ? date('d/m/Y H:i', strtotime($d)) : ''; };
$here    = function(array $q = []) use($folder){ return iu('docs', array_merge(['f' => $folder ?: null], $q)); };
echo DocsService::pageHead();
?>
<style>
  .ad-quota { display:flex; align-items:center; gap:.9rem; flex-wrap:wrap; padding:.8rem 1.1rem; margin-bottom:1rem;
              background:var(--card); border:1px solid var(--border); border-radius:var(--radius); }
  .ad-quota .q-ic { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center;
                    background:color-mix(in srgb,var(--primary) 12%,transparent); color:var(--primary); font-size:1rem; }
  .ad-quota .q-mid { flex:1; min-width:190px; }
  .ad-quota .q-txt { font-size:.82rem; color:var(--text-secondary); display:flex; justify-content:space-between; gap:1rem; }
  .ad-quota .q-txt b { color:var(--text); }
  .q-bar { height:7px; border-radius:20px; background:var(--bg); overflow:hidden; margin-top:6px; }
  .q-bar span { display:block; height:100%; border-radius:20px; background:var(--primary); transition:width .3s; }
  .ad-quota.warn .q-bar span { background:#f59e0b; } .ad-quota.warn .q-ic { color:#d97706; background:rgba(245,158,11,.13); }
  .ad-quota.full .q-bar span { background:#dc2626; } .ad-quota.full .q-ic { color:#dc2626; background:rgba(220,38,38,.13); }

  .ad-new-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:.8rem; margin-bottom:1.1rem; }
  .ad-new-row form { display:contents; }
  .ad-new { display:flex; align-items:center; gap:.8rem; padding:.9rem 1rem; border-radius:var(--radius); border:1.5px solid var(--border);
            background:var(--card); text-decoration:none; color:inherit; transition:.18s; cursor:pointer; font-family:inherit; text-align:left; width:100%; }
  .ad-new:hover { border-color:var(--dc); box-shadow:0 6px 18px rgba(0,0,0,.07); transform:translateY(-1px); }
  .ad-new[disabled] { opacity:.45; cursor:not-allowed; transform:none; box-shadow:none; }
  .ad-new .big { display:inline-flex; align-items:center; justify-content:center; width:34px; flex-shrink:0; }
  .ad-new .big i, .ad-new i.big { font-size:1.4rem; color:var(--dc); }
  .ad-new strong { display:block; font-size:.93rem; color:var(--text); } .ad-new span { font-size:.76rem; color:var(--text-muted); }

  .ad-bar { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-bottom:.9rem; }
  .ad-crumb { display:flex; align-items:center; gap:5px; flex-wrap:wrap; font-size:.86rem; flex:1; min-width:180px; }
  .ad-crumb a { color:var(--text-secondary); text-decoration:none; padding:3px 8px; border-radius:7px; }
  .ad-crumb a:hover { background:color-mix(in srgb,var(--primary) 10%,transparent); color:var(--primary); }
  .ad-crumb .sep { color:var(--text-muted); font-size:.75rem; }
  .ad-crumb .now { font-weight:700; color:var(--text); padding:3px 8px; }
  .ad-view { display:flex; gap:2px; padding:3px; background:var(--bg); border:1px solid var(--border); border-radius:9px; }
  .ad-view button { width:30px; height:26px; border:none; background:transparent; border-radius:6px; cursor:pointer; color:var(--text-muted); }
  .ad-view button.on { background:var(--card); color:var(--primary); box-shadow:0 1px 3px rgba(0,0,0,.08); }

  .ad-tabs { display:flex; gap:7px; flex-wrap:wrap; margin-bottom:1rem; }
  .ad-tab { padding:5px 13px; border-radius:20px; font-size:.82rem; font-weight:600; text-decoration:none;
            border:1.5px solid var(--border); color:var(--text-secondary); background:var(--card); }
  .ad-tab.on { background:var(--primary); border-color:var(--primary); color:#fff; }

  .ad-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(178px,1fr)); gap:.85rem; }
  .ad-item { position:relative; display:block; padding:1rem .9rem; border:1.5px solid var(--border); border-radius:var(--radius);
             background:var(--card); text-decoration:none; color:inherit; transition:.16s; }
  .ad-item:hover { border-color:color-mix(in srgb,var(--primary) 45%,transparent); box-shadow:0 6px 16px rgba(0,0,0,.06); }
  .ad-item .ic { font-size:1.7rem; color:var(--dc,#64748b); display:block; margin-bottom:.55rem; line-height:1; }
  .ad-item .ic img { display:block; }
  .ad-item .nm { font-size:.87rem; font-weight:600; line-height:1.45; word-break:break-word; color:var(--text);
                 display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .ad-item .sub { font-size:.73rem; color:var(--text-muted); margin-top:.3rem; display:block; }
  .ad-item .act { position:absolute; top:6px; right:6px; display:flex; gap:2px; opacity:0; transition:.15s; }
  .ad-item:hover .act, .ad-item:focus-within .act { opacity:1; }
  .ad-item .act button { width:26px; height:26px; border-radius:7px; border:none; background:var(--bg); color:var(--text-muted); cursor:pointer; font-size:.72rem; }
  .ad-item .act button:hover { background:var(--primary); color:#fff; }
  @media (hover:none){ .ad-item .act { opacity:1; } }

  .ad-list .ad-item { display:flex; align-items:center; gap:.85rem; padding:.65rem .9rem; margin-bottom:6px; border-radius:11px; }
  .ad-list .ad-item .ic { font-size:1.15rem; margin:0; width:26px; text-align:center; flex-shrink:0; }
  .ad-list .ad-item .ic img { width:22px !important; height:22px !important; margin:0 auto; }
  .ad-list .ad-item .nm { -webkit-line-clamp:1; flex:1; min-width:0; }
  .ad-list .ad-item .sub { margin:0; white-space:nowrap; }
  .ad-list .ad-item .act { position:static; opacity:1; }
  .ad-list.ad-grid, .ad-list .ad-grid { display:block; }

  .q-trash-note { display:flex; align-items:center; gap:.45rem; font-size:.76rem; color:var(--text-muted); margin-top:6px; }
  .q-trash-note button { border:none; background:none; padding:0; font:inherit; color:var(--primary); text-decoration:underline; cursor:pointer; }
  .ad-trash-btn { position:relative; }
  .ad-trash-btn .n { position:absolute; top:-6px; right:-6px; min-width:18px; height:18px; padding:0 5px; border-radius:20px;
                     background:#ef4444; color:#fff; font-size:.68rem; font-weight:700; display:flex; align-items:center; justify-content:center; }
  .ad-tr-head { display:flex; align-items:center; gap:.7rem; flex-wrap:wrap; margin-bottom:1rem;
                padding:.75rem .95rem; border-radius:var(--radius); background:var(--bg); border:1px solid var(--border); }
  .ad-tr-head i.big { font-size:1.15rem; color:#64748b; }
  .ad-tr-head .tx { flex:1; min-width:180px; font-size:.82rem; color:var(--text-secondary); line-height:1.6; }
  .ad-tr-head .tx b { color:var(--text); display:block; font-size:.92rem; margin-bottom:.1rem; }
  .ad-item .open-in { font-weight:600; }
  .ad-item.busy { pointer-events:none; opacity:.7; cursor:progress; }
  .ad-item.trashed { opacity:.86; }
  .ad-item.trashed .nm { text-decoration:line-through; text-decoration-color:color-mix(in srgb,var(--text-muted) 60%,transparent); }
  .ad-item .act.always { opacity:1; }
  .ad-item .act button.danger:hover { background:#dc2626; }
  .ad-empty { text-align:center; padding:2.6rem 1rem; color:var(--text-muted); }
  .ad-drop { border:2px dashed transparent; transition:.15s; }
  .ad-drop.over { border-color:var(--primary); background:color-mix(in srgb,var(--primary) 5%,transparent); }
</style>

<div class="page-header">
  <div><h1><img src="<?= h(asset('assets/docs/docs.png')) ?>" alt="" style="width:28px;height:28px;object-fit:contain;vertical-align:middle"> Aleanor Docs</h1>
       <p><?= h(docs_t('doc.subtitle')) ?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if($inTrash): ?>
      <a class="btn btn-outline" href="<?= h(iu('docs')) ?>"><i class="fi fi-rr-arrow-left"></i> <?= h(docs_t('doc.back_to_docs')) ?></a>
      <?php if($trash): ?><button class="btn btn-danger" onclick="adEmptyTrash()"><i class="fi fi-rr-trash"></i> <?= h(docs_t('doc.trash_empty')) ?></button><?php endif; ?>
    <?php else: ?>
      <a class="btn btn-outline ad-trash-btn" href="<?= h(iu('docs', ['view' => 'trash'])) ?>" title="<?= h(docs_t('doc.trash')) ?>">
        <i class="fi fi-rr-trash"></i> <?= h(docs_t('doc.trash')) ?><?php if($trashN > 0): ?><span class="n"><?= $trashN ?></span><?php endif; ?></a>
      <button class="btn btn-outline" onclick="adNewFolder()"><i class="fi fi-rr-add-folder"></i> <?= h(docs_t('doc.new_folder')) ?></button>
      <button class="btn btn-outline" id="adUpBtn" onclick="document.getElementById('adFile').click()" <?= $quota['full'] ? 'disabled' : '' ?>>
        <i class="fi fi-rr-cloud-upload"></i> <?= h(docs_t('doc.upload_file')) ?></button>
    <?php endif; ?>
  </div>
</div>
<input type="file" id="adFile" hidden accept="<?= h('.'.implode(',.', DocsService::allowedUploadExt())) ?>">
<input type="file" id="adImportFile" accept=".docx,.xlsx,.csv,.pptx" hidden>

<!-- พื้นที่ใช้งาน -->
<div class="ad-quota <?= $quota['full'] ? 'full' : ($quota['pct'] >= 80 ? 'warn' : '') ?>" id="adQuota">
  <span class="q-ic"><i class="fi fi-rr-database"></i></span>
  <div class="q-mid">
    <div class="q-txt">
      <span><?= h(docs_t('doc.storage_used')) ?> <b id="adUsed"><?= h(DocsService::formatBytes($quota['used'])) ?></b>
        <?= $quota['unlimited'] ? '' : ' / '.h(DocsService::formatBytes($quota['limit'])) ?></span>
      <span id="adPct"><?= $quota['unlimited'] ? h(docs_t('doc.unlimited')) : $quota['pct'].'%' ?></span>
    </div>
    <div class="q-bar"><span id="adBar" style="width:<?= $quota['unlimited'] ? 0 : $quota['pct'] ?>%"></span></div>
    <?php if($quota['trash'] > 0): ?>
    <div class="q-trash-note"><i class="fi fi-rr-trash"></i>
      <span><?= h(docs_t('doc.trash_using', [':size' => DocsService::formatBytes($quota['trash'])])) ?>
        <button type="button" onclick="adEmptyTrash()"><?= h(docs_t('doc.trash_empty_free')) ?></button></span>
    </div>
    <?php endif; ?>
  </div>
  <?php if($quota['full']): ?><span class="badge badge-danger"><?= h(docs_t('doc.storage_full')) ?></span><?php endif; ?>
</div>

<?php if(!$inTrash): ?>
<!-- สร้างใหม่ -->
<div class="ad-new-row">
  <?php foreach($TYPES as $tk => $ti): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new"><input type="hidden" name="type" value="<?= h($tk) ?>">
      <input type="hidden" name="folder" value="<?= (int)$folder ?>">
      <button class="ad-new" style="--dc:<?= h($ti['color']) ?>" <?= $quota['full'] ? 'type="button" onclick="adFullWarn()"' : '' ?>>
        <span class="big"><?= DocsService::typeIcon($tk, 30) ?></span>
        <span style="flex:1"><strong><?= h($ti['name']) ?></strong><span><?= h(docs_t('doc.new_'.$tk)) ?></span></span>
        <i class="fi fi-rr-plus" style="font-size:.85rem;opacity:.5"></i>
      </button></form>
  <?php endforeach; ?>
  <button type="button" class="ad-new" style="--dc:#64748b" onclick="document.getElementById('adImportFile').click()" <?= $quota['full'] ? 'disabled' : '' ?>>
    <i class="fi fi-rr-file-import big"></i>
    <span style="flex:1"><strong><?= h(docs_t('doc.import_docx')) ?></strong><span><?= h(docs_t('doc.import_hint')) ?></span></span>
  </button>
</div>
<?php endif; ?>

<div class="card ad-drop" id="adDrop">
  <div class="card-body">
  <?php if(!$inTrash): ?>
    <div class="ad-bar">
      <div class="ad-crumb">
        <a href="<?= h(iu('docs')) ?>"><i class="fi fi-rr-home"></i> <?= h(docs_t('doc.my_docs')) ?></a>
        <?php foreach($crumbs as $i => $cb): ?>
          <span class="sep">/</span>
          <?php if($i === count($crumbs) - 1): ?><span class="now"><?= h($cb['name']) ?></span>
          <?php else: ?><a href="<?= h(iu('docs', ['f' => $cb['id']])) ?>"><?= h($cb['name']) ?></a><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <form method="get" action="<?= h(iu()) ?>" style="display:flex;gap:6px">
        <input type="hidden" name="p" value="docs">
        <?php if($folder): ?><input type="hidden" name="f" value="<?= (int)$folder ?>"><?php endif; ?>
        <input type="text" name="q" class="form-control" style="min-width:170px" value="<?= h($q) ?>" placeholder="<?= h(docs_t('doc.search_ph')) ?>">
        <button class="btn btn-primary"><i class="fi fi-rr-search"></i></button>
        <?php if($q !== ''): ?><a class="btn btn-outline" href="<?= h($here()) ?>"><i class="fi fi-rr-cross-small"></i></a><?php endif; ?>
      </form>
      <div class="ad-view">
        <button type="button" id="adVGrid" onclick="adSetView('grid')" title="<?= h(docs_t('doc.view_grid')) ?>"><i class="fi fi-rr-apps"></i></button>
        <button type="button" id="adVList" onclick="adSetView('list')" title="<?= h(docs_t('doc.view_list')) ?>"><i class="fi fi-rr-list"></i></button>
      </div>
    </div>

    <div class="ad-tabs">
      <a class="ad-tab <?= $filter === '' ? 'on' : '' ?>" href="<?= h($here()) ?>"><?= h(docs_t('common.all')) ?> <?= $totalDocs ?></a>
      <?php foreach($TYPES as $tk => $ti): ?>
        <a class="ad-tab <?= $filter === $tk ? 'on' : '' ?>" href="<?= h(iu('docs', ['type' => $tk])) ?>"><?= h($ti['name']) ?> <?= $counts[$tk] ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if($inTrash): ?>
    <div class="ad-tr-head">
      <i class="fi fi-rr-time-past big"></i>
      <span class="tx"><b><?= h(docs_t('doc.trash')) ?></b>
        <?= h(DocsService::trashDays() > 0 ? docs_t('doc.trash_hint_days', [':d' => DocsService::trashDays()]) : docs_t('doc.trash_hint')) ?></span>
    </div>
    <?php if(!$trash): ?>
      <div class="ad-empty"><i class="fi fi-rr-trash" style="font-size:2.4rem;opacity:.3"></i>
        <p style="margin-top:.8rem"><?= h(docs_t('doc.trash_empty_msg')) ?></p></div>
    <?php else: ?>
    <div id="adItems" class="ad-grid">
      <?php foreach($trash as $it):
        if($it['kind'] === 'folder'){ $col = '#f59e0b'; $ico = '<i class="fi fi-rr-folder ic"></i>'; }
        elseif($it['kind'] === 'file'){ $fi = DocsService::fileIcon(strtolower($it['meta'])); $col = $fi[1]; $ico = '<i class="fi '.h($fi[0]).' ic"></i>'; }
        else { $col = DocsService::typeInfo($it['type'])['color']; $ico = '<span class="ic">'.DocsService::typeIcon($it['type'], 30).'</span>'; } ?>
      <div class="ad-item trashed" style="--dc:<?= h($col) ?>" data-kind="<?= h($it['kind']) ?>" data-id="<?= (int)$it['id'] ?>">
        <?= $ico ?>
        <span class="nm"><?= h($it['name']) ?></span>
        <span class="sub"><?= $it['meta'] !== '' ? h($it['meta']).' · ' : ($it['kind'] === 'folder' ? h(docs_t('doc.folder')).' · ' : '') ?><?= h(DocsService::formatBytes($it['size'])) ?>
          <?php if($it['deleted']): ?><br><?= h(docs_t('doc.deleted_at')) ?> <?= h($fmtDate($it['deleted'])) ?><?php endif; ?></span>
        <span class="act always">
          <button type="button" title="<?= h(docs_t('doc.restore')) ?>" onclick="adRestore(this)"><i class="fi fi-rr-undo"></i></button>
          <button type="button" class="danger" title="<?= h(docs_t('doc.delete_forever')) ?>" onclick="adPurge(this)"><i class="fi fi-rr-trash"></i></button>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php elseif(!$folders && !$docs && !$files): ?>
    <div class="ad-empty"><i class="fi fi-rr-folder-open" style="font-size:2.4rem;opacity:.35"></i>
      <p style="margin-top:.8rem"><?= h($q !== '' ? docs_t('doc.empty_search') : docs_t('doc.empty_folder')) ?></p></div>

  <?php else: ?>
    <div id="adItems" class="ad-grid">
      <?php foreach($folders as $f): ?>
        <a class="ad-item" style="--dc:#f59e0b" href="<?= h(iu('docs', ['f' => $f['id']])) ?>" data-kind="folder" data-id="<?= (int)$f['id'] ?>">
          <i class="fi fi-rr-folder ic"></i>
          <span class="nm"><?= h($f['name']) ?></span>
          <span class="sub"><?= h(docs_t('doc.folder')) ?></span>
          <span class="act">
            <button type="button" title="<?= h(docs_t('common.edit')) ?>" data-do="rename"><i class="fi fi-rr-pencil"></i></button>
            <button type="button" title="ย้ายไปโฟลเดอร์" data-do="move"><i class="fi fi-rr-folder-download"></i></button>
            <button type="button" title="<?= h(docs_t('common.delete')) ?>" data-do="delete"><i class="fi fi-rr-trash"></i></button>
          </span>
        </a>
      <?php endforeach; ?>

      <?php foreach($docs as $d): $ti = DocsService::typeInfo($d['type']); ?>
        <a class="ad-item" style="--dc:<?= h($ti['color']) ?>" href="<?= h(iu('doc-edit', ['id' => $d['id']])) ?>" data-kind="doc" data-id="<?= (int)$d['id'] ?>">
          <span class="ic"><?= DocsService::typeIcon($d['type'], 30) ?></span>
          <span class="nm"><?= h($d['title']) ?></span>
          <span class="sub"><?= h($ti['name']) ?> · <?= h($fmtDate($d['updated_at'])) ?></span>
          <span class="act">
            <button type="button" title="<?= h(docs_t('doc.open_read')) ?>" data-do="view" data-url="<?= h(iu('doc-view', ['id' => $d['id']])) ?>"><i class="fi fi-rr-eye"></i></button>
            <button type="button" title="<?= h(docs_t('common.edit')) ?>" data-do="rename"><i class="fi fi-rr-pencil"></i></button>
            <button type="button" title="ย้ายไปโฟลเดอร์" data-do="move"><i class="fi fi-rr-folder-download"></i></button>
            <button type="button" title="<?= h(docs_t('common.delete')) ?>" data-do="delete"><i class="fi fi-rr-trash"></i></button>
          </span>
        </a>
      <?php endforeach; ?>

      <?php foreach($files as $f):
        $ic  = DocsService::fileIcon($f['ext']);
        $raw = asset(DocsService::userRel($me).rawurlencode($f['stored_name']));
        $app = $convMap[$f['ext']] ?? '';                 // แปลงเข้าแอปในระบบได้ → คลิกแล้วเปิดในแอป ?>
        <a class="ad-item" style="--dc:<?= h($ic[1]) ?>" target="_blank" rel="noopener" href="<?= h($raw) ?>"
           <?php if($app): ?>data-open="<?= h($app) ?>"<?php endif; ?> data-kind="file" data-id="<?= (int)$f['id'] ?>">
          <i class="fi <?= h($ic[0]) ?> ic"></i>
          <span class="nm"><?= h($f['name']) ?></span>
          <span class="sub"><?= h(strtoupper($f['ext'])) ?> · <?= h(DocsService::formatBytes($f['size'])) ?><?php if($app): ?> · <span class="open-in" style="color:<?= h(DocsService::typeInfo($app)['color']) ?>"><?= h(docs_t('doc.open_in', [':app' => DocsService::typeInfo($app)['name']])) ?></span><?php endif; ?></span>
          <span class="act">
            <button type="button" title="<?= h(docs_t('reading.download')) ?>" data-do="download" data-url="<?= h(asset('api/docs.php').'?action=file&id='.(int)$f['id']) ?>"><i class="fi fi-rr-download"></i></button>
            <button type="button" title="ย้ายไปโฟลเดอร์" data-do="move"><i class="fi fi-rr-folder-download"></i></button>
            <button type="button" title="<?= h(docs_t('common.delete')) ?>" data-do="delete"><i class="fi fi-rr-trash"></i></button>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<!-- กล่องย้ายไปโฟลเดอร์ -->
<div class="modal-overlay" id="adMoveModal">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-header"><div class="modal-title">ย้ายไปโฟลเดอร์</div>
      <button type="button" class="modal-close" onclick="adMoveClose()">&times;</button></div>
    <div class="modal-body">
      <div class="small muted" id="adMoveName" style="margin-bottom:.6rem"></div>
      <select id="adMoveTo" class="form-control form-select">
        <option value="0"><?= h(docs_t('doc.my_docs')) ?> (ชั้นบนสุด)</option>
        <?php foreach($allFolders as $af): ?><option value="<?= (int)$af['id'] ?>"><?= h($af['path']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="adMoveClose()"><?= h(docs_t('common.cancel')) ?></button>
      <button type="button" class="btn btn-primary" onclick="adMoveGo()"><?= h(docs_t('common.confirm')) ?></button>
    </div>
  </div>
</div>

<script>
(function(){
  var API    = DOCS.api;
  var FOLDER = <?= (int)$folder ?>;
  var EDIT   = <?= json_encode(iu('doc-edit').'&id=') ?>;
  var T = <?= json_encode([
    'folderName' => docs_t('doc.folder_name'), 'rename' => docs_t('doc.rename_folder'), 'renameDoc' => 'ชื่อเอกสาร',
    'delFolder' => docs_t('doc.confirm_del_folder'), 'delDoc' => docs_t('doc.confirm_delete'), 'delFile' => docs_t('doc.confirm_del_file'),
    'full' => docs_t('doc.storage_full_msg'), 'failed' => docs_t('doc.import_failed'), 'badType' => docs_t('doc.bad_file_type'),
    'uploading' => docs_t('doc.uploading'), 'purge' => docs_t('doc.confirm_purge'), 'emptyTrash' => docs_t('doc.confirm_empty_trash'),
    'opening' => docs_t('doc.opening'), 'openFailed' => docs_t('doc.open_failed'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  function ask(msg, def, cb){ dlgPrompt(msg, def, function(v){ if(v !== null && v !== undefined && String(v).trim() !== '') cb(String(v).trim()); }); }
  function post(data, done){
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fetch(API, {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(done)
      .catch(function(){ alert(T.failed); });
  }
  function reloadOr(j){ if(j && j.ok) location.reload(); else alert(T.failed + (j && j.error ? ' (' + j.error + ')' : '')); }
  function itemName(el){ var n = el.querySelector('.nm'); return n ? n.textContent : ''; }

  // ── มุมมอง grid / list ──
  window.adSetView = function(v){
    var box = document.getElementById('adItems');
    if(box) box.classList.toggle('ad-list', v === 'list');
    var bg = document.getElementById('adVGrid'), bl = document.getElementById('adVList');
    if(bg) bg.classList.toggle('on', v !== 'list');
    if(bl) bl.classList.toggle('on', v === 'list');
    try { localStorage.setItem('aleanorDocsView', v); } catch(e){}
  };
  var saved = 'grid';
  try { saved = localStorage.getItem('aleanorDocsView') || 'grid'; } catch(e){}
  adSetView(saved);

  window.adNewFolder = function(){
    ask(T.folderName, '', function(name){ post({action:'folder_add', name:name, parent:FOLDER}, reloadOr); });
  };
  window.adFullWarn = function(){ alert(T.full); };

  // ── ปุ่มบนการ์ด (เปลี่ยนชื่อ / ย้าย / ลบ / ดู / ดาวน์โหลด) ──
  var moving = null;
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.ad-item .act button[data-do]') : null;
    if(!b) return;
    e.preventDefault(); e.stopPropagation();
    var it = b.closest('.ad-item'), kind = it.dataset.kind, id = it.dataset.id, name = itemName(it), act = b.dataset.do;
    if(act === 'view'){ window.open(b.dataset.url, '_blank'); return; }
    if(act === 'download'){ location.href = b.dataset.url; return; }
    if(act === 'rename'){
      if(kind === 'folder') ask(T.rename, name, function(v){ post({action:'folder_rename', id:id, name:v}, reloadOr); });
      else ask(T.renameDoc, name, function(v){ post({action:'rename', id:id, title:v}, reloadOr); });
      return;
    }
    if(act === 'move'){
      moving = {kind:kind, id:id};
      document.getElementById('adMoveName').textContent = name;
      var sel = document.getElementById('adMoveTo');
      Array.prototype.forEach.call(sel.options, function(o){ o.disabled = (kind === 'folder' && o.value === id); });
      sel.value = String(FOLDER);
      document.getElementById('adMoveModal').classList.add('open');
      return;
    }
    if(act === 'delete'){
      var msg = kind === 'folder' ? T.delFolder : (kind === 'file' ? T.delFile : T.delDoc);
      var action = kind === 'folder' ? 'folder_delete' : (kind === 'file' ? 'file_delete' : 'delete');
      dlgConfirm(msg + ' “' + name + '”', function(){ post({action:action, id:id}, reloadOr); });
    }
  }, true);
  window.adMoveClose = function(){ document.getElementById('adMoveModal').classList.remove('open'); moving = null; };
  window.adMoveGo = function(){
    if(!moving) return;
    var to = document.getElementById('adMoveTo').value;
    post({action:'move', kind:moving.kind, id:moving.id, folder:to}, reloadOr);
    adMoveClose();
  };

  // ── เปิดไฟล์ Office ที่อัปโหลดไว้ด้วยแอปในระบบ (แปลงครั้งแรกครั้งเดียว) ──
  var opening = false;
  document.addEventListener('click', function(e){
    var a = e.target.closest ? e.target.closest('.ad-item[data-open]') : null;
    if(!a || e.target.closest('.act') || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    if(opening) return;
    opening = true; a.classList.add('busy');
    var sub = a.querySelector('.open-in'), old = sub ? sub.textContent : '';
    if(sub) sub.textContent = T.opening;
    post({action:'open_file', id:a.dataset.id}, function(j){
      opening = false; a.classList.remove('busy');
      if(sub) sub.textContent = old;
      if(j.ok){ location.href = EDIT + encodeURIComponent(j.id); return; }
      if(j.error === 'quota') alert(T.full + ' (' + (j.used || '') + ' / ' + (j.limit || '') + ')');
      else alert(T.openFailed + (j.message ? '\n' + j.message : (j.error ? ' (' + j.error + ')' : '')));
    });
  });

  // ── ถังขยะ ──
  window.adRestore = function(b){ var it = b.closest('.ad-item'); post({action:'trash_restore', kind:it.dataset.kind, id:it.dataset.id}, reloadOr); };
  window.adPurge = function(b){
    var it = b.closest('.ad-item');
    dlgConfirm(T.purge + ' “' + itemName(it) + '”', function(){ post({action:'trash_purge', kind:it.dataset.kind, id:it.dataset.id}, reloadOr); });
  };
  window.adEmptyTrash = function(){ dlgConfirm(T.emptyTrash, function(){ post({action:'trash_empty'}, reloadOr); }); };

  // ── อัปโหลดไฟล์ (ปุ่ม + ลากมาวาง) ──
  function upload(file){
    if(!file) return;
    var fd = new FormData();
    fd.append('action', 'file_upload'); fd.append('file', file); fd.append('folder', FOLDER);
    var btn = document.getElementById('adUpBtn');
    if(btn){ btn.disabled = true; btn.textContent = T.uploading; }
    fetch(API, {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(!j.ok){
          if(j.error === 'quota') alert(T.full + ' (' + (j.used || '') + ' / ' + (j.limit || '') + ')');
          else if(j.error === 'bad_type') alert(T.badType + (j.ext ? ' .' + j.ext : ''));
          else alert(T.failed + ' (' + (j.error || '') + ')');
          setTimeout(function(){ location.reload(); }, 1800);
          return;
        }
        location.reload();
      })
      .catch(function(){ alert(T.failed); if(btn) btn.disabled = false; });
  }
  var fileIn = document.getElementById('adFile');
  if(fileIn) fileIn.addEventListener('change', function(){ upload(this.files && this.files[0]); this.value = ''; });
  var drop = document.getElementById('adDrop');
  if(drop && document.getElementById('adUpBtn')){
    ['dragenter','dragover'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave','drop'].forEach(function(ev){
      drop.addEventListener(ev, function(e){ e.preventDefault(); if(ev === 'dragleave' && drop.contains(e.relatedTarget)) return; drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function(e){ if(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) upload(e.dataTransfer.files[0]); });
  }

  // ── นำเข้า .docx / .xlsx / .csv / .pptx → เอกสารในระบบ ──
  document.getElementById('adImportFile').addEventListener('change', function(){
    var file = this.files && this.files[0]; this.value = '';
    if(!file) return;
    var fd = new FormData();
    fd.append('action', 'import'); fd.append('file', file); fd.append('folder', FOLDER);
    fetch(API, {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(!j.ok){ alert(T.failed + (j.message ? '\n' + j.message : ' (' + (j.error || '') + ')')); return; }
        location.href = EDIT + encodeURIComponent(j.id);
      })
      .catch(function(){ alert(T.failed); });
  });
})();
</script>
