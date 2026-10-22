<?php
declare(strict_types=1);

const APP_NAME = 'Barangay Inosluban';


const DB_HOST       = 'localhost';
const DB_PORT       = '5432';
const DB_NAME       = 'Portal_Inosluban';
const DB_USER       = 'postgres';
const DB_PASSWORD   = 'admin';


try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";";

    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

catch (PDOException $e) {
    die("DAatabase Connection failed: " . $e->getMessage());
}