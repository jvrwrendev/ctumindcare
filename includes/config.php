<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ctumindcares');
define('SITE_NAME', 'CTU MindCare');
define('SITE_URL',  'http://localhost/ctumindcare');
define('SITE_ROOT', __DIR__ . '/..');
define('SESSION_LIFETIME', 7200);
define('MOOD_RISK_DAYS', 3);
define('MOOD_RISK_LEVEL', 2);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;color:#8B2020;background:#FBEDED;border:1px solid #F5C6C2;border-radius:8px;max-width:500px;margin:40px auto">
                <strong>Database connection failed.</strong><br><br>
                Make sure:<br>
                1. XAMPP is running (Apache + MySQL both green)<br>
                2. You created the <b>ctumindcare</b> database in phpMyAdmin<br>
                3. You imported <b>database.sql</b><br><br>
                Error: ' . $e->getMessage() . '
            </div>');
        }
    }
    return $pdo;
}