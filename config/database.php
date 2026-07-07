<?php

// نوع الاستجابة JSON
header("Content-Type: application/json; charset=UTF-8");

// =====================================================
// اتصال قاعدة بيانات Neon PostgreSQL
// يتم قراءة البيانات من Environment Variables في Render
// =====================================================

$host = getenv("DB_HOST");
$port = getenv("DB_PORT") ?: "5432";
$db_name = getenv("DB_NAME");
$username = getenv("DB_USER");
$password = getenv("DB_PASSWORD");
$endpointId = getenv("DB_ENDPOINT_ID");

try {
    // تجهيز رابط الاتصال بقاعدة PostgreSQL
    $dsn =
        "pgsql:" .
        "host=$host;" .
        "port=$port;" .
        "dbname=$db_name;" .
        "sslmode=require";

    // هذا الخيار يفيد مع Neon إذا احتاج السيرفر إلى endpoint ID
    if (!empty($endpointId)) {
        $dsn .= ";options='endpoint=$endpointId'";
    }

    // إنشاء الاتصال باستخدام PDO
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            // إظهار أخطاء قاعدة البيانات
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // جلب البيانات بأسماء الأعمدة
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // تعطيل التحضير الوهمي للاستعلامات
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل الاتصال بقاعدة بيانات Neon",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
