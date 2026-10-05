<?php
// ออกจากระบบ — ต้องเป็น POST พร้อม CSRF (กันเว็บอื่นสั่งให้ออกจากระบบ)
if(!is_post()) redirect(u());
require_csrf();
$_SESSION = [];
session_regenerate_id(true);
redirect(u());
