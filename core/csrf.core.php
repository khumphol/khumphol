<?php
// CSRF Token Protection for Aleanor Cloud (copied from aleanor_ai)
// ป้องกัน Cross-Site Request Forgery

/**
 * สร้างหรือดึง CSRF token จาก session
 */
function csrf_token(){
    if(empty($_SESSION['csrf_token'])){
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * คืน HTML hidden input สำหรับฝังในฟอร์ม
 */
function csrf_field(){
    return '<input type="hidden" name="csrf_token" value="'.csrf_token().'">';
}

/**
 * ตรวจสอบ CSRF token จาก POST request
 * คืน true ถ้าตรง, false ถ้าไม่ตรง
 * aleanor_cloud: ใช้ token เดียวทั้ง session (ไม่ one-time แบบ aleanor_ai)
 * เพราะหน้าแอดมิน/ผู้สอนมีหลายฟอร์มในหน้าเดียวและเปิดหลายแท็บ
 */
function csrf_verify(){
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $session_token = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';

    if(empty($token) || empty($session_token)){
        return false;
    }

    return hash_equals($session_token, $token);
}
?>
