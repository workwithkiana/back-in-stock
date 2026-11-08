<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/services.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
$method=$_SERVER['REQUEST_METHOD'];
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($path==='/health') {db()->query('SELECT 1');json_response(['ok'=>true]);}
    if ($path==='/api/requests' && $method==='POST') {
        $data=input_json();$email=strtolower(trim((string)($data['email']??'')));$sku=trim((string)($data['sku']??''));
       $domain = substr(strrchr($email, '@') ?: '', 1);

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($email) > 254 ||
    strtolower($domain) === 'example.com' ||
    !preg_match('/^[a-zA-Z0-9._:\/-]{1,191}$/D', $sku)
) {
    json_response(['error'=>'Enter a valid email and SKU'],422);
}
        $s=db()->prepare("INSERT INTO requests(email,sku) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $s->execute([$email,$sku]);$id=(int)db()->lastInsertId();
        // Safe when stock lookup fails: retain pending subscription for later webhook.
        $qty=stock_quantity($sku);
        if ($qty!==null && $qty>0) queue_sku($sku);
        json_response(['ok'=>true,'message'=>'You are on the list! We’ll email you when it is available.','request_id'=>$id],201);
    }
    if ($path==='/webhooks/stock' && $method==='POST') {
        $expected=config('WEBHOOK_SECRET');$provided=$_SERVER['HTTP_X_WEBHOOK_SECRET']??'';
        if (!secure_equals($expected,$provided)) json_response(['error'=>'Unauthorized'],401);
        $data=input_json();$event=trim((string)($data['event_id']??''));$sku=trim((string)($data['sku']??''));$qty=$data['quantity']??null;
        if ($event==='' || strlen($event)>191 || !preg_match('/^[a-zA-Z0-9._:\/-]{1,191}$/D',$sku) || !is_numeric($qty) || (float)$qty<0) json_response(['error'=>'Invalid event'],422);
        // Never trust webhook quantity alone: query the authenticated stock API.
        $actual=stock_quantity($sku);
        if ($actual===null) json_response(['error'=>'Could not verify stock; retry later'],503);
        $s=db()->prepare('INSERT IGNORE INTO webhook_events(event_id,sku) VALUES (?,?)');$s->execute([$event,$sku]);
        $new=$s->rowCount()>0;
        $queued=$actual>0 ? queue_sku($sku) : 0;
        json_response(['ok'=>true,'duplicate'=>!$new,'queued'=>$queued]);
    }
    if ($path==='/embed' && $method==='GET') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: frame-ancestors *');
        readfile(__DIR__.'/embed.html');exit;
    }
    if ($path==='/admin') {
        $password=config('ADMIN_PASSWORD');
        if ($password==='') {http_response_code(503);exit('Configure ADMIN_PASSWORD');}
        $given='';
        if (isset($_SERVER['PHP_AUTH_PW'])) $given=$_SERVER['PHP_AUTH_PW'];
        if (!secure_equals($password,$given)) {
            header('WWW-Authenticate: Basic realm="Stock Alerts Admin"');http_response_code(401);exit('Authentication required');
        }
        if ($method!=='GET') json_response(['error'=>'Method not allowed'],405);
        $rows=db()->query('SELECT r.id,r.email,r.sku,r.status,r.created_at,r.notified_at,o.attempts,o.last_error FROM requests r LEFT JOIN outbox o ON o.request_id=r.id ORDER BY r.created_at DESC LIMIT 300')->fetchAll();
        $stats=db()->query("SELECT COUNT(*) total, SUM(status='pending') pending, SUM(status='queued') queued, SUM(status='notified') notified FROM requests")->fetch();
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__.'/admin.php';exit;
    }
    if ($path==='/' && $method==='GET') {header('Location: /embed');exit;}
    json_response(['error'=>'Not found'],404);
} catch(Throwable $e) {
    error_log((string)$e);
    json_response(['error'=>'Internal server error'],500);
}
