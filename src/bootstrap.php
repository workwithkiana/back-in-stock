<?php
declare(strict_types=1);
error_reporting(E_ALL);

// PHP 8.0 compatibility
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $expectedKey = 0;

        foreach ($array as $key => $_) {
            if ($key !== $expectedKey++) {
                return false;
            }
        }

        return true;
    }
}

function config(string $key, string $default = ''): string
{
    $v = getenv($key);

    if ($v !== false) {
        return $v;
    }

    static $vars = null;

    if ($vars === null) {
        $vars = [];
        $file = dirname(__DIR__) . '/.env';

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);

                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }

                [$k, $value] = explode('=', $line, 2);

                $vars[trim($k)] = trim(trim($value), "\"'");
            }
        }
    }

    return $vars[$key] ?? $default;
}

function db(): PDO
{
    static $db;

    if (!isset($db)) {
        $db = new PDO(
            'mysql:host=' . config('DB_HOST', '127.0.0.1') .
            ';port=' . config('DB_PORT', '3306') .
            ';dbname=' . config('DB_DATABASE', 'back_in_stock') .
            ';charset=utf8mb4',
            config('DB_USERNAME', 'root'),
            config('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $db;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    if (!str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        json_response(['error' => 'JSON required'], 415);
    }

    $raw = file_get_contents('php://input');

    if (strlen($raw) > 16384) {
        json_response(['error' => 'Payload too large'], 413);
    }

    $obj = json_decode($raw, true);

    if (!is_array($obj) || array_is_list($obj)) {
        json_response(['error' => 'Invalid JSON object'], 400);
    }

    return $obj;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function secure_equals(string $a, string $b): bool
{
    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function init_db(): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');

    foreach (explode(';', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            db()->exec($stmt);
        }
    }
}
