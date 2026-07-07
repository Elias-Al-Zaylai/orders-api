<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';

try {
    $rolesStatement = $pdo->prepare("
        SELECT
            r.id,
            r.name,
            r.display_name
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
          AND r.is_active = TRUE
        ORDER BY r.id ASC
    ");

    $rolesStatement->execute([$authUser['id']]);
    $roles = $rolesStatement->fetchAll();

    $permissionsStatement = $pdo->prepare("
        SELECT DISTINCT
            p.permission_key
        FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        INNER JOIN role_permissions rp ON rp.role_id = r.id
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = ?
          AND r.is_active = TRUE
        ORDER BY p.permission_key ASC
    ");

    $permissionsStatement->execute([$authUser['id']]);
    $permissions = $permissionsStatement->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "status" => true,
        "message" => "تم جلب بيانات الحساب بنجاح",
        "user" => $authUser,
        "roles" => $roles,
        "permissions" => $permissions,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب بيانات الحساب",
        "error" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
