<?php
// ============================================================
// Aleanor Docs — เปิดตัวแก้ไขตามชนิดเอกสาร (route 'doc-edit', GET id=)
//   write → doc-write.php · grid → doc-grid.php · present → doc-present.php
//   สร้างเอกสารใหม่ทำที่หน้า docs (POST action=new) แล้วเด้งมาที่นี่พร้อม id
// ============================================================
require_once dirname(__DIR__, 2).'/services/DocsService.php';
$MENU = 'docs';
$doc = DocsService::get((int)get('id'));
if(!$doc){
    $TITLE = docs_t('doc.not_found');
    http_response_code(404);
    echo '<div class="card"><div class="card-body">'.h(docs_t('doc.not_found')).' — <a href="'.h(iu('docs')).'">'.h(docs_t('doc.all_docs')).'</a></div></div>';
    return;
}
if(!DocsService::canEdit($doc, current_user_id())){
    $TITLE = docs_t('doc.no_permission');
    http_response_code(403);
    echo '<div class="card"><div class="card-body">'.h(docs_t('doc.no_permission')).'</div></div>';
    return;
}
$TITLE = $doc['title'] !== '' ? $doc['title'] : DocsService::typeInfo($doc['type'])['name'];
echo DocsService::pageHead();
require __DIR__.'/doc-'.(DocsService::typeValid($doc['type']) ? $doc['type'] : 'write').'.php';
