<?php

// تحديد نوع الاستجابة بصيغة JSON وترميز UTF-8.
header('Content-Type: application/json; charset=UTF-8');

// التحقق من تسجيل الدخول ومن صلاحية إدارة المستخدمين.
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_users');

// هذا الملف يقبل طلبات GET فقط.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'status' => false,
        'message' => 'طريقة الطلب غير مسموحة'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // بيانات البحث والتصفية.
    $search = trim((string) ($_GET['search'] ?? ''));
    $companyId = (int) ($_GET['company_id'] ?? 0);
    $departmentId = (int) ($_GET['department_id'] ?? 0);
    $sectionId = (int) ($_GET['section_id'] ?? 0);
    $roleId = (int) ($_GET['role_id'] ?? 0);

    // is_active يقبل 0 أو 1، وإذا لم يرسل فلن تتم التصفية بالحالة.
    $isActive = null;
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $isActive = (int) $_GET['is_active'] === 1 ? 1 : 0;
    }

    // بيانات الصفحات مع وضع حدود آمنة لعدد النتائج.
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 20);
    $perPage = max(1, min($perPage, 100));
    $offset = ($page - 1) * $perPage;

    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = "(
            u.name ILIKE :search_1
            OR u.username ILIKE :search_2
            OR u.phone ILIKE :search_3
            OR u.email ILIKE :search_4
            OR c.name ILIKE :search_5
            OR d.name ILIKE :search_6
            OR s.name ILIKE :search_7
        )";
        $searchValue = '%' . $search . '%';

        for ($searchIndex = 1; $searchIndex <= 7; $searchIndex++) {
            $parameters[':search_' . $searchIndex] = $searchValue;
        }
    }

    if ($companyId > 0) {
        $conditions[] = 'u.company_id = :company_id';
        $parameters[':company_id'] = $companyId;
    }

    if ($departmentId > 0) {
        $conditions[] = 'u.department_id = :department_id';
        $parameters[':department_id'] = $departmentId;
    }

    if ($sectionId > 0) {
        $conditions[] = 'u.section_id = :section_id';
        $parameters[':section_id'] = $sectionId;
    }

    if ($isActive !== null) {
        $conditions[] = 'u.is_active = :is_active';
        $parameters[':is_active'] = $isActive;
    }

    if ($roleId > 0) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM user_roles role_filter
            WHERE role_filter.user_id = u.id
              AND role_filter.role_id = :role_id
        )";
        $parameters[':role_id'] = $roleId;
    }

    $whereSql = empty($conditions)
        ? ''
        : 'WHERE ' . implode(' AND ', $conditions);

    // حساب العدد الكلي قبل تطبيق حدود الصفحة.
    $countStatement = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        LEFT JOIN companies c
            ON c.id = u.company_id
        LEFT JOIN departments d
            ON d.id = u.department_id
        LEFT JOIN sections s
            ON s.id = u.section_id
        $whereSql
    ");

    foreach ($parameters as $key => $value) {
        $countStatement->bindValue(
            $key,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $countStatement->execute();
    $total = (int) $countStatement->fetchColumn();

    // جلب المستخدمين الموجودين في الصفحة الحالية.
    $usersStatement = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.username,
            u.phone,
            u.email,
            u.company_id,
            u.department_id,
            u.section_id,
            u.is_active,
            u.last_login,
            u.created_at,
            u.updated_at,
            c.name AS company_name,
            d.name AS department_name,
            s.name AS section_name
        FROM users u
        LEFT JOIN companies c
            ON c.id = u.company_id
        LEFT JOIN departments d
            ON d.id = u.department_id
        LEFT JOIN sections s
            ON s.id = u.section_id
        $whereSql
        ORDER BY u.id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($parameters as $key => $value) {
        $usersStatement->bindValue(
            $key,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $usersStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $usersStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $usersStatement->execute();

    $users = $usersStatement->fetchAll(PDO::FETCH_ASSOC);

    // جلب أدوار المستخدمين في استعلام واحد لتجنب تكرار الاستعلام لكل مستخدم.
    $rolesByUser = [];
    $userIds = array_map(
        static fn(array $user): int => (int) $user['id'],
        $users
    );

    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $rolesStatement = $pdo->prepare("
            SELECT
                ur.user_id,
                r.id,
                r.name,
                r.display_name,
                r.is_active
            FROM user_roles ur
            INNER JOIN roles r
                ON r.id = ur.role_id
            WHERE ur.user_id IN ($placeholders)
            ORDER BY ur.user_id ASC, r.display_name ASC
        ");

        $rolesStatement->execute($userIds);

        while ($role = $rolesStatement->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int) $role['user_id'];

            $rolesByUser[$userId][] = [
                'id' => (int) $role['id'],
                'name' => $role['name'],
                'display_name' => $role['display_name'],
                'is_active' => (bool) $role['is_active']
            ];
        }
    }

    foreach ($users as &$user) {
        $userId = (int) $user['id'];

        $user['id'] = $userId;
        $user['company_id'] = $user['company_id'] !== null
            ? (int) $user['company_id']
            : null;
        $user['department_id'] = $user['department_id'] !== null
            ? (int) $user['department_id']
            : null;
        $user['section_id'] = $user['section_id'] !== null
            ? (int) $user['section_id']
            : null;
        $user['is_active'] = (bool) $user['is_active'];
        $user['roles'] = $rolesByUser[$userId] ?? [];
    }
    unset($user);

    $lastPage = $total === 0
        ? 1
        : (int) ceil($total / $perPage);

    echo json_encode([
        'status' => true,
        'message' => 'تم جلب المستخدمين بنجاح',
        'data' => [
            'users' => $users,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        'status' => false,
        'message' => 'حدث خطأ أثناء جلب المستخدمين',
        'error' => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
