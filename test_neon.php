<?php

require_once __DIR__ . '/config/database.php';

echo json_encode([
    "status" => true,
    "message" => "تم الاتصال بقاعدة Neon من السيرفر بنجاح",
], JSON_UNESCAPED_UNICODE);
