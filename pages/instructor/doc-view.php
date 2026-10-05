<?php
// ============================================================
// Aleanor Docs — หน้าอ่านเอกสาร (route 'doc-view', GET id=) พอร์ตจาก aleanor_ai/pages/document/view-doc.php
// แสดงผลแบบเดียวกับที่ผู้เรียนเห็นในบทเรียน (DocsService::renderBody) + โน้ตผู้สอนของสไลด์
// ============================================================
require_once dirname(__DIR__, 2).'/services/DocsService.php';
$MENU = 'docs';
$doc = DocsService::get((int)get('id'));
if(!$doc || !DocsService::canEdit($doc, current_user_id())){
    $TITLE = docs_t('doc.not_found');
    http_response_code(404);
    echo '<div class="card"><div class="card-body">'.h(docs_t('doc.not_found')).' — <a href="'.h(iu('docs')).'">'.h(docs_t('doc.all_docs')).'</a></div></div>';
    return;
}
$TITLE = $doc['title'];
$ti = DocsService::typeInfo($doc['type']);
$deckN = $doc['type'] === 'present' ? presentSanitize($doc['content']) : null;
$hasNotes = false;
if($deckN) foreach($deckN['slides'] as $sn){ if(trim($sn['notes']) !== ''){ $hasNotes = true; break; } }
?>
<style>
<?php echo DocsService::renderCss(); ?>
  .vd-wrap { max-width:960px; margin:0 auto; }
  .vd-head { display:flex; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1.2rem; }
  .vd-head h1 { font-size:1.5rem; font-weight:700; margin:.5rem 0 0; }
  .vd-meta { font-size:.82rem; color:var(--text-muted); margin-top:.35rem; }
  .vd-pill { display:inline-flex; align-items:center; gap:5px; font-size:.74rem; font-weight:700; padding:3px 11px;
             border-radius:20px; color:var(--dc); background:color-mix(in srgb,var(--dc) 14%,var(--card)); }
  .vd-notes { margin-top:1rem; background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:.8rem 1.1rem; }
  .vd-notes summary { cursor:pointer; font-weight:600; font-size:.9rem; }
  .vd-notes ol { margin:.7rem 0 0 1.2rem; font-size:.88rem; color:var(--text-secondary); line-height:1.7; }
  @media print { .vd-head, .sidebar, .topbar, .footer, .vd-notes, .pv-bar { display:none !important; }
                 .docs-render .vd-paper { border:none; padding:0; } }
</style>
<div class="vd-wrap">
  <div class="vd-head">
    <div style="flex:1;min-width:220px">
      <span class="vd-pill" style="--dc:<?= h($ti['color']) ?>"><?= DocsService::typeIcon($doc['type'], 14) ?> <?= h($ti['name']) ?></span>
      <h1><?= h($doc['title']) ?></h1>
      <div class="vd-meta"><?= h(date('d/m/Y H:i', strtotime($doc['updated_at']))) ?></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-outline" href="<?= h(iu('doc-edit', ['id' => $doc['id']])) ?>"><i class="fi fi-rr-pencil"></i> <?= h(docs_t('common.edit')) ?></a>
      <button class="btn btn-outline" onclick="window.print()"><i class="fi fi-rr-print"></i> <?= h(docs_t('doc.tool_print')) ?></button>
    </div>
  </div>
  <div class="docs-render docs-<?= h($doc['type']) ?>"><?= DocsService::renderBody($doc, 'vdDeck') ?></div>
  <?php if($hasNotes): ?>
    <details class="vd-notes"><summary><?= h(docs_t('doc.slide_notes')) ?></summary>
      <ol>
      <?php foreach($deckN['slides'] as $i => $sn){ if(trim($sn['notes']) === '') continue; ?>
        <li><b><?= ($i + 1) ?>.</b> <?= nl2br(h($sn['notes'])) ?></li>
      <?php } ?>
      </ol>
    </details>
  <?php endif; ?>
</div>
<?php if($doc['type'] === 'present'): ?><script><?= presentJs() ?></script><?php endif; ?>
