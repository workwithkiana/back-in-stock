<?php
declare(strict_types=1);
$name=getenv('DB_DATABASE') ?: 'back_in_stock';
$user=getenv('DB_USERNAME') ?: 'stock_app';
$pass=getenv('DB_PASSWORD') ?: '';
if (!preg_match('/^[A-Za-z0-9_]+$/D',$name) || !preg_match('/^[A-Za-z0-9_]+$/D',$user)) throw new RuntimeException('Invalid database name/user');
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$quoted=$db->quote($pass);
$db->exec("CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY $quoted");
$db->exec("ALTER USER '$user'@'127.0.0.1' IDENTIFIED BY $quoted");
$db->exec("GRANT ALL PRIVILEGES ON `$name`.* TO '$user'@'127.0.0.1'");
