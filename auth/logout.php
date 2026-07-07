<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';

try {
    $authorizationHeader = getAuthorizationHeader();
    preg_match('/Bearer\s+(\S+)/i', $authorizationHeader, $matches);

    $token = $matches[1] ?? '';
    $tokenHash = hash('sha256', $token);

    $statement = $pdo->prepare("
        DELETE FROM api_tokens
        WHERE token_hash = ?
    ");

    $statement->execute([$tokenHash]);

    echo json_encode([
        "status" => true,
        "message" => "تم تسجيل الخروج بنجاح",
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تسجيل الخروج",
        "error" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
