<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function stock_quantity(string $sku): ?int {
    $base=rtrim(config('STOCK_API_URL'),'/');
    if ($base==='' || config('STOCK_API_KEY')==='') return null;
    $ch=curl_init($base.'/'.rawurlencode($sku));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.config('STOCK_API_KEY'),'Accept: application/json']]);
    $body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if (!is_string($body) || $code!==200) return null;
    $v=json_decode($body,true);
    if (!is_array($v)) return null;
    if (isset($v['quantity']) && is_numeric($v['quantity'])) return max(0,(int)$v['quantity']);
    if (isset($v['in_stock'])) return $v['in_stock'] ? 1 : 0;
    return null;
}
function queue_sku(string $sku): int {
    $db=db();$db->beginTransaction();
    try {
        $s=$db->prepare("SELECT id FROM requests WHERE sku=? AND status='pending' FOR UPDATE");$s->execute([$sku]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
        $insert=$db->prepare("INSERT IGNORE INTO outbox(request_id) VALUES (?)");
        $update=$db->prepare("UPDATE requests SET status='queued' WHERE id=? AND status='pending'");
        foreach ($ids as $id) {$insert->execute([$id]);$update->execute([$id]);}
        $db->commit();return count($ids);
    } catch(Throwable $e){$db->rollBack();throw $e;}
}
function email_html(string $sku): string {
    $name=h($sku);
    return '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;color:#0f172a"><div style="max-width:540px;margin:32px auto;background:#fff;border-radius:18px;overflow:hidden"><div style="background:#0f172a;color:#fff;padding:28px;font-size:20px;font-weight:bold">RESTOCK<span style="color:#38bdf8">.</span></div><div style="padding:32px"><p style="color:#0284c7;font-weight:bold">GOOD NEWS</p><h1 style="font-size:28px;line-height:1.2">It’s back in stock!</h1><p style="line-height:1.7;color:#475569">The item you asked about is available again.</p><div style="padding:20px;background:#f8fafc;border-radius:12px;margin:20px 0"><b>Product SKU</b><p style="margin-bottom:0">'.$name.'</p></div><p style="color:#64748b;font-size:14px">Stock may sell out again. Visit the store to purchase your item.</p></div></div></body></html>';
}
function send_email(string $email,string $sku,string $idempotency): array {
    $url=rtrim(config('RESEND_BASE_URL'),'/');
    if ($url==='' || config('RESEND_API_KEY')==='') return [false,'Email API is not configured'];
    // Resend-compatible gateways typically accept /emails; some provide a full /emails URL.
    if (!str_ends_with($url,'/emails')) $url.='/emails';
    $payload=['from'=>config('MAIL_FROM','Stock Alerts <alerts@example.com>'),'to'=>[$email],'subject'=>'Back in stock: '.$sku,'html'=>email_html($sku)];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.config('RESEND_API_KEY'),'Content-Type: application/json','Idempotency-Key: stock-request-'.$idempotency]]);
    $response=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return [$response!==false && $status>=200 && $status<300, $error ?: ('HTTP '.$status.' '.substr((string)$response,0,300))];
}
function process_outbox(int $limit=20): array {
    $sent=0;$failed=0;$db=db();
    for ($i=0;$i<$limit;$i++) {
        $db->beginTransaction();
        try {
            $s=$db->query("SELECT o.id,o.request_id,r.email,r.sku FROM outbox o JOIN requests r ON r.id=o.request_id WHERE o.status='pending' OR (o.status='sending' AND o.locked_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)) ORDER BY o.id LIMIT 1 FOR UPDATE");
            $job=$s->fetch();
            if (!$job) {$db->commit();break;}
            $db->prepare("UPDATE outbox SET status='sending',locked_at=NOW(),attempts=attempts+1 WHERE id=?")->execute([$job['id']]);
            $db->commit();
        } catch(Throwable $e){$db->rollBack();throw $e;}
        [$ok,$error]=send_email($job['email'],$job['sku'],(string)$job['request_id']);
        if ($ok) {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE outbox SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?")->execute([$job['id']]);
                $db->prepare("UPDATE requests SET status='notified',notified_at=NOW() WHERE id=?")->execute([$job['request_id']]);
                $db->commit();$sent++;
            } catch(Throwable $e){$db->rollBack();throw $e;}
        } else {
            $db->prepare("UPDATE outbox SET status='pending',locked_at=NULL,last_error=? WHERE id=?")->execute([$error,$job['id']]);
            error_log('Email send failed for outbox '.$job['id'].': '.$error);$failed++;
            // Avoid repeatedly retrying a broken gateway within the same run.
            break;
        }
    }
    return ['sent'=>$sent,'failed'=>$failed];
}
