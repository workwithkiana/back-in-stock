<?php
declare(strict_types=1);
require_once __DIR__.'/src/services.php';
try {echo json_encode(process_outbox(30),JSON_UNESCAPED_SLASHES).PHP_EOL;} catch(Throwable $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
