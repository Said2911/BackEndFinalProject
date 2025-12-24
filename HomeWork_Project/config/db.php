<?php
declare(strict_types=1);
$DB_HOST = "mysql-miri.alwaysdata.net";
$DB_NAME = "miri_driving";
$DB_USER = "miri";        
$DB_PASS = "9ZS!j*_Mek3#FB3";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        $options
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit("Database connection failed");
}
