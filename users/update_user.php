<?php

// تحديد نوع الاستجابة بصيغة JSON وترميز UTF-8.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول ومن صلاحية إدارة المستخدمين.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

// هذا الملف يقبل طلبات POST فقط.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'status' => false,
        'message' => 'طريقة الطلب غير مسموحة'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'بيانات الطلب غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($data['id'] ?? $data['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'رقم المستخدم مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // جلب البيانات الحالية حتى يمكن دعم التعديل الكامل أو الجزئي بأمان.
    $currentStatement = $pdo->prepare("
        SELECT
            id,
            name,
            username,
            phone,
            email,
            company_id,
            department_id,
            section_id,
            role,
            role_id,
            is_active
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $currentStatement->execute([$userId]);
    $currentUser = $currentStatement->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser) {
        http_response_code(404);
        echo json_encode(['status' => false, 'message' => 'المستخدم غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $name = array_key_exists('name', $data)
        ? trim((string) $data['name'])
        : (string) $currentUser['name'];
    $username = array_key_exists('username', $data)
        ? trim((string) $data['username'])
        : (string) $currentUser['username'];
    $phone = array_key_exists('phone', $data)
        ? trim((string) $data['phone'])
        : (string) ($currentUser['phone'] ?? '');
    $email = array_key_exists('email', $data)
        ? trim((string) $data['email'])
        : (string) ($currentUser['email'] ?? '');

    $companyId = array_key_exists('company_id', $data)
        ? (($data['company_id'] === null || $data['company_id'] === '') ? null : (int) $data['company_id'])
        : ($currentUser['company_id'] !== null ? (int) $currentUser['company_id'] : null);
    $departmentId = array_key_exists('department_id', $data)
        ? (($data['department_id'] === null || $data['department_id'] === '') ? null : (int) $data['department_id'])
        : ($currentUser['department_id'] !== null ? (int) $currentUser['department_id'] : null);
    $sectionId = array_key_exists('section_id', $data)
        ? (($data['section_id'] === null || $data['section_id'] === '') ? null : (int) $data['section_id'])
        : ($currentUser['section_id'] !== null ? (int) $currentUser['section_id'] : null);

    $isActive = array_key_exists('is_active', $data)
        ? ((int) $data['is_active'] === 1 ? 1 : 0)
        : (int) $currentUser['is_active'];

    $newPassword = isset($data['password'])
        ? (string) $data['password']
        : '';

    $hasRoleIds = array_key_exists('role_ids', $data);
    $roleIds = [];

    if ($hasRoleIds) {
        $roleIds = is_array($data['role_ids'])
            ? array_values(array_unique(array_filter(
                array_map('intval', $data['role_ids']),
                static fn(int $id): bool => $id > 0
            )))
            : [];
    }

    // التحقق من القيم المدخلة.
    if ($name === '' || mb_strlen($name) > 150) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'اسم المستخدم مطلوب ويجب ألا يتجاوز 150 حرفًا'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($username === '' || mb_strlen($username) < 3 || mb_strlen($username) > 100 || preg_match('/\s/u', $username)) {
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'message' => 'اسم الدخول مطلوب، ويجب أن يكون من 3 إلى 100 حرف وبدون مسافات'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($phone !== '' && mb_strlen($phone) > 30) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'رقم الهاتف يجب ألا يتجاوز 30 حرفًا'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'البريد الإلكتروني غير صحيح'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($newPassword !== '' && mb_strlen($newPassword) < 6) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'كلمة المرور الجديدة يجب ألا تقل عن 6 أحرف'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($hasRoleIds && empty($roleIds)) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'يجب اختيار دور واحد على الأقل'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // منع المستخدم الحالي من تعطيل حسابه بنفسه.
    $authenticatedUserId = (int) ($authUser['id'] ?? 0);
    if ($authenticatedUserId === $userId && $isActive === 0) {
        http_response_code(422);
        echo json_encode(['status' => false, 'message' => 'لا يمكنك تعطيل حسابك الحالي'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التحقق من عدم تكرار اسم الدخول أو البريد عند مستخدم آخر.
    $duplicateStatement = $pdo->prepare("
        SELECT id, username, email
        FROM users
        WHERE id <> :id
          AND (
              username = :username
              OR (:email_check <> '' AND email = :email_match)
          )
        LIMIT 1
    ");
    $duplicateStatement->execute([
        ':id' => $userId,
        ':username' => $username,
        ':email_check' => $email,
        ':email_match' => $email
    ]);

    $duplicateUser = $duplicateStatement->fetch(PDO::FETCH_ASSOC);

    if ($duplicateUser) {
        http_response_code(409);
        echo json_encode([
            'status' => false,
            'message' => $duplicateUser['username'] === $username
                ? 'اسم الدخول مستخدم مسبقًا'
                : 'البريد الإلكتروني مستخدم مسبقًا'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // التحقق من تسلسل الشركة ثم الإدارة ثم القسم.
    if ($companyId !== null) {
        $companyStatement = $pdo->prepare('SELECT id FROM companies WHERE id = ? AND is_active = TRUE LIMIT 1');
        $companyStatement->execute([$companyId]);

        if (!$companyStatement->fetchColumn()) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'الشركة المختارة غير موجودة أو غير مفعلة'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($departmentId !== null) {
        if ($companyId === null) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'يجب اختيار الشركة قبل الإدارة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $departmentStatement = $pdo->prepare("
            SELECT id
            FROM departments
            WHERE id = ?
              AND company_id = ?
              AND is_active = TRUE
            LIMIT 1
        ");
        $departmentStatement->execute([$departmentId, $companyId]);

        if (!$departmentStatement->fetchColumn()) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'الإدارة لا تتبع الشركة المختارة أو أنها غير مفعلة'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($sectionId !== null) {
        if ($departmentId === null) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'يجب اختيار الإدارة قبل القسم'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sectionStatement = $pdo->prepare("
            SELECT id
            FROM sections
            WHERE id = ?
              AND department_id = ?
              AND is_active = TRUE
            LIMIT 1
        ");
        $sectionStatement->execute([$sectionId, $departmentId]);

        if (!$sectionStatement->fetchColumn()) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'القسم لا يتبع الإدارة المختارة أو أنه غير مفعل'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $primaryRoleId = $currentUser['role_id'] !== null
        ? (int) $currentUser['role_id']
        : null;
    $primaryRoleName = (string) $currentUser['role'];

    if ($hasRoleIds) {
        $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
        $rolesStatement = $pdo->prepare("
            SELECT id, name
            FROM roles
            WHERE id IN ($rolePlaceholders)
              AND is_active = TRUE
        ");
        $rolesStatement->execute($roleIds);
        $rolesRows = $rolesStatement->fetchAll(PDO::FETCH_ASSOC);
        $rolesById = [];

        foreach ($rolesRows as $role) {
            $rolesById[(int) $role['id']] = $role;
        }

        if (count($rolesById) !== count($roleIds)) {
            http_response_code(422);
            echo json_encode(['status' => false, 'message' => 'أحد الأدوار المختارة غير موجود أو غير مفعل'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $primaryRoleId = $roleIds[0];
        $primaryRoleName = (string) $rolesById[$primaryRoleId]['name'];
        $allowedLegacyRoles = ['admin', 'requester', 'direction_manager', 'executor'];

        if (!in_array($primaryRoleName, $allowedLegacyRoles, true)) {
            http_response_code(422);
            echo json_encode([
                'status' => false,
                'message' => 'اسم الدور الأساسي غير متوافق مع حقل role الموجود في جدول users'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $pdo->beginTransaction();

    // بناء أمر التعديل، وإضافة كلمة المرور فقط عند إرسالها.
    $updateSql = "
        UPDATE users
        SET
            name = :name,
            username = :username,
            phone = :phone,
            email = :email,
            role = :role,
            role_id = :role_id,
            company_id = :company_id,
            department_id = :department_id,
            section_id = :section_id,
            is_active = :is_active
    ";

    $updateParameters = [
        ':name' => $name,
        ':username' => $username,
        ':phone' => $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':role' => $primaryRoleName,
        ':role_id' => $primaryRoleId,
        ':company_id' => $companyId,
        ':department_id' => $departmentId,
        ':section_id' => $sectionId,
        ':is_active' => $isActive,
        ':id' => $userId
    ];

    if ($newPassword !== '') {
        $updateSql .= ', password = :password';
        $updateParameters[':password'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    $updateSql .= ' WHERE id = :id';

    $updateStatement = $pdo->prepare($updateSql);
    $updateStatement->execute($updateParameters);

    // عند إرسال role_ids تتم مزامنة أدوار المستخدم بالكامل.
    if ($hasRoleIds) {
        $deleteRolesStatement = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $deleteRolesStatement->execute([$userId]);

        $insertRoleStatement = $pdo->prepare("
            INSERT INTO user_roles (user_id, role_id, created_at)
            VALUES (?, ?, NOW())
        ");

        foreach ($roleIds as $roleId) {
            $insertRoleStatement->execute([$userId, $roleId]);
        }
    }

    // عند تغيير كلمة المرور نحذف جلسات المستخدم لإجباره على تسجيل الدخول مجددًا.
    if ($newPassword !== '') {
        $deleteTokensStatement = $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?');
        $deleteTokensStatement->execute([$userId]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'تم تعديل المستخدم بنجاح'
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (in_array($exception->getCode(), ['23000', '23505', '23503'], true)) {
        http_response_code(409);
        echo json_encode([
            'status' => false,
            'message' => 'اسم الدخول أو البريد الإلكتروني مستخدم مسبقًا'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء تعديل المستخدم',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
