<?php

require_once __DIR__ . '/../config/database.php';

function getAuthorizationHeader(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            return trim($value);
        }
    }

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return '';
}

$authorizationHeader = getAuthorizationHeader();

if ($authorizationHeader === '' || !preg_match('/Bearer\s+(\S+)/i', $authorizationHeader, $matches)) {
    http_response_code(401);

    echo json_encode([
        "status" => false,
        "message" => "Authorization Token Required",
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$token = $matches[1];
$tokenHash = hash('sha256', $token);

try {
    $statement = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.username,
            u.email,
            u.phone,
            u.company_id,
            u.department_id,
            u.section_id,
            u.is_active
        FROM api_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ?
          AND u.is_active = TRUE
        LIMIT 1
    ");

    $statement->execute([$tokenHash]);
    $authUser = $statement->fetch();

    if (!$authUser) {
        http_response_code(401);

        echo json_encode([
            "status" => false,
            "message" => "Invalid Token",
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $authUser['id'] = (int) $authUser['id'];
    $authUser['company_id'] = isset($authUser['company_id']) ? (int) $authUser['company_id'] : null;
    $authUser['department_id'] = isset($authUser['department_id']) ? (int) $authUser['department_id'] : null;
    $authUser['section_id'] = isset($authUser['section_id']) ? (int) $authUser['section_id'] : null;
    $authUser['is_active'] = (bool) $authUser['is_active'];
} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء التحقق من المستخدم",
        "error" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/**
 * التحقق من امتلاك المستخدم الحالي لدور معين.
 */
function authUserHasRole(string $roleName): bool
{
    global $pdo, $authUser;

    if (empty($authUser['id'])) {
        return false;
    }

    $statement = $pdo->prepare("
        SELECT 1
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
          AND r.name = ?
          AND r.is_active = TRUE
        LIMIT 1
    ");

    $statement->execute([
        $authUser['id'],
        $roleName,
    ]);

    return (bool) $statement->fetchColumn();
}
