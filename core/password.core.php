<?php
// ============================================================
// Password hashing — Aleanor Cloud (copied from aleanor_ai)
// ใช้ bcrypt (password_hash) ที่มี salt สุ่มต่อผู้ใช้
// รองรับการตรวจ hash เดิม (crypt SHA-512 salt คงที่) เพื่อ migrate ตอน login
// ไฟล์นี้ไม่พึ่งพา DB — require ได้ทุกที่
// ============================================================

if(!function_exists('shHashPassword')){
    // สร้าง hash ใหม่ (bcrypt, salt สุ่มต่อผู้ใช้)
    function shHashPassword($plain){
        return password_hash((string)$plain, PASSWORD_DEFAULT);
    }
}

if(!function_exists('shVerifyPassword')){
    // ตรวจรหัสผ่านกับ hash ที่เก็บไว้ (รองรับทั้งของใหม่และของเดิม)
    function shVerifyPassword($plain, $hash){
        if($hash === '' || $hash === null) return false;
        // ของเดิม: crypt SHA-512 ($6$). โค้ดเก่า hash ทั้งแบบ raw และแบบ addslashes — ลองทั้งสอง
        if(strncmp($hash, '$6$', 3) === 0){
            $a = crypt((string)$plain, $hash);
            if(is_string($a) && hash_equals($hash, $a)) return true;
            $b = crypt(addslashes((string)$plain), $hash);
            return is_string($b) && hash_equals($hash, $b);
        }
        // ของใหม่: bcrypt/argon
        return password_verify((string)$plain, $hash);
    }
}

if(!function_exists('shPasswordNeedsRehash')){
    // hash เดิม (ไม่ใช่ bcrypt/argon) หรือ bcrypt ที่พารามิเตอร์ล้าสมัย → ควร rehash
    function shPasswordNeedsRehash($hash){
        if($hash === '' || $hash === null) return false;
        if(strncmp($hash, '$6$', 3) === 0) return true;
        return password_needs_rehash((string)$hash, PASSWORD_DEFAULT);
    }
}
?>
