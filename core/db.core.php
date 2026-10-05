<?php
// ============================================================
// ฐานข้อมูล — คอนเนกชันเดียวต่อคำขอ (แนว aleanor_db() ของ aleanor_ai)
// ต่างจาก aleanor_ai: ใช้ prepared statement ทุกคำสั่ง (ไม่ต่อสตริง+addslashes)
// และโยน exception เมื่อผิดพลาด เพื่อให้ db_tx() rollback งานเงินได้ทั้งก้อน
// ============================================================

class DbException extends RuntimeException {}

function db($__close = false){
    static $conn = null;
    if($__close){
        if($conn instanceof mysqli) @mysqli_close($conn);
        $conn = null;
        return null;
    }
    if($conn === null){
        $c = @mysqli_connect(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, (int)DB_PORT);
        if(!$c) throw new DbException('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: '.mysqli_connect_error());
        mysqli_set_charset($c, 'utf8mb4');
        mysqli_query($c, "SET collation_connection='utf8mb4_general_ci', time_zone='+07:00'");
        $conn = $c;
        register_shutdown_function(function(){ db(true); });
    }
    return $conn;
}

/** bind ค่า: int → i, อื่น ๆ → s (DECIMAL รับสตริงได้ตรง ไม่เพี้ยนแบบ float) */
function db_run($sql, array $params = []){
    $c = db();
    $st = mysqli_prepare($c, $sql);
    if(!$st) throw new DbException(mysqli_error($c).' :: '.$sql);
    if($params){
        $types = ''; $vals = [];
        foreach($params as $p){
            if(is_int($p) || is_bool($p)){ $types .= 'i'; $vals[] = (int)$p; }
            elseif(is_float($p))         { $types .= 's'; $vals[] = rtrim(rtrim(sprintf('%.6F', $p), '0'), '.'); }
            elseif($p === null)          { $types .= 's'; $vals[] = null; }
            else                         { $types .= 's'; $vals[] = (string)$p; }
        }
        $refs = [];
        foreach($vals as $i => $v) $refs[$i] = &$vals[$i];
        mysqli_stmt_bind_param($st, $types, ...$refs);
    }
    if(!mysqli_stmt_execute($st)){
        $err = mysqli_stmt_error($st); $no = mysqli_stmt_errno($st);
        mysqli_stmt_close($st);
        throw new DbException($err, $no);
    }
    return $st;
}

function db_all($sql, array $params = []){
    $st = db_run($sql, $params);
    $res = mysqli_stmt_get_result($st);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $rows;
}
function db_one($sql, array $params = []){
    $rows = db_all($sql, $params);
    return $rows ? $rows[0] : null;
}
function db_val($sql, array $params = []){
    $r = db_one($sql, $params);
    return $r ? reset($r) : null;
}
/** INSERT/UPDATE/DELETE — คืนจำนวนแถวที่กระทบ */
function db_write($sql, array $params = []){
    $st = db_run($sql, $params);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return $n;
}
function db_insert($sql, array $params = []){
    db_write($sql, $params);
    return (int)mysqli_insert_id(db());
}

/**
 * ทำงานใน transaction เดียว — ผิดพลาดตรงไหน rollback ทั้งหมด
 * ซ้อนกันได้ (ชั้นในใช้ transaction ของชั้นนอก)
 */
function db_tx(callable $fn){
    static $depth = 0;
    $c = db();
    if($depth > 0){ $depth++; try { return $fn(); } finally { $depth--; } }
    mysqli_begin_transaction($c);
    $depth = 1;
    try {
        $out = $fn();
        mysqli_commit($c);
        return $out;
    } catch(Throwable $e){
        mysqli_rollback($c);
        throw $e;
    } finally {
        $depth = 0;
    }
}
