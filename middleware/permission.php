<?php

function requirePermission(string $permissionKey): void
{
    global $pdo, $authUser;

    if (empty($authUser['id'])) {
        http_response_code(401);

        echo json_encode([
            "status" => false,
            "message" => "المستخدم غير مسجل الدخول",
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    try {
        $statement = $pdo->prepare("
            SELECT 1
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            INNER JOIN role_permissions rp ON rp.role_id = r.id
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = ?
              AND r.is_active = TRUE
              AND p.permission_key = ?
            LIMIT 1
        ");

        $statement->execute([
            $authUser['id'],
            $permissionKey,
        ]);

        if (!$statement->fetchColumn()) {
            http_response_code(403);

            echo json_encode([
                "status" => false,
                "message" => "ليس لديك صلاحية لتنفيذ هذه العملية",
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            "status" => false,
            "message" => "حدث خطأ أثناء التحقق من الصلاحية",
            "error" => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}
