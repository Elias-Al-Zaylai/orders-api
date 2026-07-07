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

// قراءة بيانات JSON المرسلة من التطبيق.
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        'status' => false,
        'message' => 'بيانات الطلب غير صحيحة'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$name = trim((string) ($data['name'] ?? ''));
$username = trim((string) ($data['username'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

$companyId = isset($data['company_id']) && $data['company_id'] !== ''
    ? (int) $data['company_id']
    : null;
$departmentId = isset($data['department_id']) && $data['department_id'] !== ''
    ? (int) $data['department_id']
    : null;
$sectionId = isset($data['section_id']) && $data['section_id'] !== ''
    ? (int) $data['section_id']
    : null;

$isActive = isset($data['is_active'])
    ? ((int) $data['is_active'] === 1 ? 1 : 0)
    : 1;

// تنظيف أرقام الأدوار وحذف القيم المكررة وغير الصحيحة.
$roleIds = $data['role_ids'] ?? [];
$roleIds = is_array($roleIds)
    ? array_values(array_unique(array_filter(
        array_map('intval', $roleIds),
        static fn(int $id): bool => $id > 0
    )))
    : [];

// التحقق من الحقول الأساسية.
if ($name === '') {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'اسم المستخدم مطلوب'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($name) > 150) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'اسم المستخدم يجب ألا يتجاوز 150 حرفًا'], JSON_UNESCAPED_UNICODE);
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

if (mb_strlen($password) < 6) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'كلمة المرور يجب ألا تقل عن 6 أحرف'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($roleIds)) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'يجب اختيار دور واحد على الأقل'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // التحقق من عدم تكرار اسم الدخول أو البريد الإلكتروني.
    $duplicateStatement = $pdo->prepare("
        SELECT id, username, email
        FROM users
        WHERE username = :username
           OR (:email_check <> '' AND email = :email_match)
        LIMIT 1
    ");

    $duplicateStatement->execute([
        ':username' => $username,
        ':email_check' => $email,
        ':email_match' => $email
    ]);

    $duplicateUser = $duplicateStatement->fetch(PDO::FETCH_ASSOC);

    if ($duplicateUser) {
        http_response_code(409);

        $message = $duplicateUser['username'] === $username
            ? 'اسم الدخول مستخدم مسبقًا'
            : 'البريد الإلكتروني مستخدم مسبقًا';

        echo json_encode([
            'status' => false,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // التحقق من صحة الشركة والإدارة والقسم وتسلسل ارتباطها.
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

    // جلب الأدوار والتأكد أن جميع الأرقام المرسلة موجودة ومفعلة.
    $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
    $rolesStatement = $pdo->prepare("
        SELECT id, name, display_name
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

    // الحقل users.role قديم لكنه إجباري، لذلك نضع فيه اسم أول دور مختار.
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

    $pdo->beginTransaction();

    // إنشاء المستخدم مع تشفير كلمة المرور بطريقة آمنة.
    $insertUserStatement = $pdo->prepare("
        INSERT INTO users (
            name,
            username,
            phone,
            email,
            password,
            role,
            role_id,
            company_id,
            department_id,
            section_id,
            is_active
        ) VALUES (
            :name,
            :username,
            :phone,
            :email,
            :password,
            :role,
            :role_id,
            :company_id,
            :department_id,
            :section_id,
            :is_active
        )
        RETURNING id
    ");

    $insertUserStatement->execute([
        ':name' => $name,
        ':username' => $username,
        ':phone' => $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => $primaryRoleName,
        ':role_id' => $primaryRoleId,
        ':company_id' => $companyId,
        ':department_id' => $departmentId,
        ':section_id' => $sectionId,
        ':is_active' => $isActive
    ]);

    $newUserId = (int) $insertUserStatement->fetchColumn();

    // ربط المستخدم بجميع الأدوار المختارة.
    $insertRoleStatement = $pdo->prepare("
        INSERT INTO user_roles (user_id, role_id, created_at)
        VALUES (?, ?, NOW())
    ");

    foreach ($roleIds as $roleId) {
        $insertRoleStatement->execute([$newUserId, $roleId]);
    }

    $pdo->commit();

    http_response_code(201);

    echo json_encode([
        'status' => true,
        'message' => 'تم إنشاء المستخدم بنجاح',
        'data' => [
            'user_id' => $newUserId
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // معالجة إضافية في حال حدوث تكرار بسبب طلبين في نفس اللحظة.
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
        'message' => 'حدث خطأ أثناء إنشاء المستخدم',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
