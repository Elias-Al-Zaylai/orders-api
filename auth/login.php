<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';

$data = json_decode(file_get_contents("php://input"), true);

$username = trim($data['username'] ?? '');
$password = (string) ($data['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "اسم المستخدم وكلمة المرور مطلوبان",
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // جلب المستخدم من PostgreSQL.
    // في PostgreSQL نستخدم TRUE بدل 1 في الحقول المنطقية.
    $statement = $pdo->prepare("
        SELECT
            id,
            name,
            username,
            email,
            phone,
            password,
            company_id,
            department_id,
            section_id,
            is_active
        FROM users
        WHERE username = ?
        LIMIT 1
    ");

    $statement->execute([$username]);
    $user = $statement->fetch();

    if (!$user || !$user['is_active']) {
        http_response_code(401);

        echo json_encode([
            "status" => false,
            "message" => "بيانات الدخول غير صحيحة أو المستخدم غير مفعل",
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // دعم password_hash، ومعه دعم مؤقت لكلمات المرور القديمة المشفرة sha256 إن وجدت.
    $storedPassword = (string) $user['password'];

    $passwordIsValid = password_verify($password, $storedPassword);

    if (!$passwordIsValid && hash('sha256', $password) === $storedPassword) {
        $passwordIsValid = true;
    }

    if (!$passwordIsValid) {
        http_response_code(401);

        echo json_encode([
            "status" => false,
            "message" => "بيانات الدخول غير صحيحة",
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // إنشاء التوكن وتخزين الهاش فقط داخل قاعدة البيانات.
    $token = bin2hex(random_bytes(64));
    $tokenHash = hash('sha256', $token);

    $insertToken = $pdo->prepare("
        INSERT INTO api_tokens (token_hash, user_id, created_at)
        VALUES (?, ?, NOW())
    ");

    $insertToken->execute([
        $tokenHash,
        $user['id'],
    ]);

    // تحديث آخر دخول.
    $updateLogin = $pdo->prepare("
        UPDATE users
        SET last_login = NOW()
        WHERE id = ?
    ");

    $updateLogin->execute([$user['id']]);

    // جلب أدوار المستخدم.
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

    $rolesStatement->execute([$user['id']]);
    $roles = $rolesStatement->fetchAll();

    // جلب صلاحيات المستخدم بدون تكرار.
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

    $permissionsStatement->execute([$user['id']]);
    $permissions = $permissionsStatement->fetchAll(PDO::FETCH_COLUMN);

    unset($user['password']);
    $user['id'] = (int) $user['id'];
    $user['company_id'] = isset($user['company_id']) ? (int) $user['company_id'] : null;
    $user['department_id'] = isset($user['department_id']) ? (int) $user['department_id'] : null;
    $user['section_id'] = isset($user['section_id']) ? (int) $user['section_id'] : null;
    $user['is_active'] = (bool) $user['is_active'];

    echo json_encode([
        "status" => true,
        "message" => "تم تسجيل الدخول بنجاح",
        "token" => $token,
        "user" => $user,
        "roles" => $roles,
        "permissions" => $permissions,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تسجيل الدخول",
        "error" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
