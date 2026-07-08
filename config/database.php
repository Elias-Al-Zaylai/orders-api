<?php

header("Content-Type: application/json; charset=UTF-8");

$host = getenv("DB_HOST");
$port = getenv("DB_PORT") ?: "5432";
$db_name = getenv("DB_NAME");
$username = getenv("DB_USER");
$password = getenv("DB_PASSWORD");
$endpointId = getenv("DB_ENDPOINT_ID");

try {
    $dsn =
        "pgsql:" .
        "host=$host;" .
        "port=$port;" .
        "dbname=$db_name;" .
        "sslmode=require";

    if (!empty($endpointId)) {
        $dsn .= ";options='endpoint=$endpointId'";
    }

    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل الاتصال بقاعدة بيانات Neon",
        "error" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
