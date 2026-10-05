<?php
// ============================================================
// อีเมล — คิวใน mail_queue แล้ว cron/send-mail.php เป็นคนส่ง (หน้าเว็บไม่ต้องรอ SMTP)
// ตัวส่ง SMTP คัดลอกจาก aleanor_ai/core/mail.core.php (tls/ssl/none + AUTH LOGIN ไม่พึ่งไลบรารีนอก)
// ============================================================

function mailEnabled(){ return setting('mail_enabled', '0') === '1' && trim(setting('mail_host', '')) !== ''; }
function mailConfig(){
    return [
        'host' => trim(setting('mail_host', '')), 'port' => (int)setting('mail_port', '587'),
        'user' => setting('mail_user', ''), 'pass' => setting('mail_pass', ''),
        'enc'  => strtolower(setting('mail_encryption', 'tls')),
        'from' => setting('mail_from', '') ?: setting('mail_user', ''),
        'from_name' => setting('mail_from_name', '') ?: setting('site_name', 'Aleanor Cloud'),
    ];
}
function mailEncodeHeader($s){ return '=?UTF-8?B?'.base64_encode($s).'?='; }
function mailEncodeName($s){ return ($s !== '' && preg_match('/[^ -~]/', $s)) ? mailEncodeHeader($s) : $s; }

function mailSmtp($cfg,$to,$subject,$html){
  $enc  = $cfg['enc'];
  $host = ($enc==='ssl' ? 'ssl://' : '').$cfg['host'];
  $ctx  = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true]]);
  $fp   = @stream_socket_client($host.':'.$cfg['port'],$errno,$errstr,15,STREAM_CLIENT_CONNECT,$ctx);
  if(!$fp) return ['ok'=>false,'error'=>'connect: '.($errstr ?: $errno)];
  stream_set_timeout($fp,15);
  $read = function() use($fp){ $data=''; while(($line=fgets($fp,515))!==false){ $data.=$line; if(strlen($line)<4 || $line[3]===' ') break; } return $data; };
  $send = function($c) use($fp,$read){ if($c!==null) fwrite($fp,$c."\r\n"); return $read(); };
  $code = function($r){ return (int)substr(ltrim($r),0,3); };
  $fail = function($e) use($fp){ @fwrite($fp,"QUIT\r\n"); @fclose($fp); return ['ok'=>false,'error'=>$e]; };

  $r=$read();                       if($code($r)!==220) return $fail('greeting: '.trim($r));
  $ehlo = $_SERVER['SERVER_NAME'] ?? 'localhost';
  $r=$send('EHLO '.$ehlo);          if($code($r)!==250) return $fail('ehlo: '.trim($r));
  if($enc==='tls'){
    $r=$send('STARTTLS');           if($code($r)!==220) return $fail('starttls: '.trim($r));
    $ok=@stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
    if(!$ok) return $fail('tls_handshake_failed');
    $r=$send('EHLO '.$ehlo);        if($code($r)!==250) return $fail('ehlo2: '.trim($r));
  }
  if($cfg['user']!==''){
    $r=$send('AUTH LOGIN');         if($code($r)!==334) return $fail('auth: '.trim($r));
    $r=$send(base64_encode($cfg['user'])); if($code($r)!==334) return $fail('auth_user: '.trim($r));
    $r=$send(base64_encode($cfg['pass'])); if($code($r)!==235) return $fail('auth_failed: '.trim($r));
  }
  $r=$send('MAIL FROM:<'.$cfg['from'].'>'); if($code($r)!==250) return $fail('mail_from: '.trim($r));
  $r=$send('RCPT TO:<'.$to.'>');    $cc=$code($r); if($cc!==250 && $cc!==251) return $fail('rcpt: '.trim($r));
  $r=$send('DATA');                 if($code($r)!==354) return $fail('data: '.trim($r));

  $h  = 'From: '.mailEncodeName($cfg['from_name']).' <'.$cfg['from'].'>'."\r\n";
  $h .= 'To: <'.$to.'>'."\r\n";
  $h .= 'Subject: '.mailEncodeHeader($subject)."\r\n";
  $h .= 'Date: '.date('r')."\r\n";
  $h .= 'MIME-Version: 1.0'."\r\n";
  $h .= 'Content-Type: text/html; charset=UTF-8'."\r\n";
  $h .= 'Content-Transfer-Encoding: base64'."\r\n";
  $body = chunk_split(base64_encode($html));
  fwrite($fp,$h."\r\n".$body."\r\n.\r\n");
  $r=$read();                       if($code($r)!==250) return $fail('send: '.trim($r));
  $send('QUIT'); @fclose($fp);
  return ['ok'=>true];
}

