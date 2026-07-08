<?php

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من المستخدم والصلاحية
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية إدارة الإعدادات
requirePermission('manage_settings');

// السماح بطلب GET فقط
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        "status" => false,
        "message" => "طريقة الطلب غير مسموحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// البحث
$search = trim($_GET['search'] ?? '');

// فلترة الشركة
$companyId = (int) ($_GET['company_id'] ?? 0);

// فلترة الإدارة
$departmentId = (int) ($_GET['department_id'] ?? 0);

// فلترة الحالة
$isActive = $_GET['is_active'] ?? null;

// رقم الصفحة
$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

// عدد السجلات في الصفحة
$limit = (int) ($_GET['limit'] ?? 20);

if ($limit < 1) {
    $limit = 20;
}

if ($limit > 100) {
    $limit = 100;
}

// بداية النتائج
$offset = ($page - 1) * $limit;

// شروط الاستعلام
$where = [];
$params = [];

// البحث باسم القسم أو الإدارة أو الشركة
if ($search !== '') {
    $where[] = "
        (
            s.name ILIKE :search_1
            OR d.name ILIKE :search_2
            OR c.name ILIKE :search_3
        )
    ";
        $searchValue = '%' . $search . '%';

        for ($searchIndex = 1; $searchIndex <= 3; $searchIndex++) {
            $params[':search_' . $searchIndex] = $searchValue;
        }
}

// فلترة حسب الشركة
if ($companyId > 0) {
    $where[] = "d.company_id = :company_id";
    $params[':company_id'] = $companyId;
}

// فلترة حسب الإدارة
if ($departmentId > 0) {
    $where[] = "s.department_id = :department_id";
    $params[':department_id'] = $departmentId;
}

// فلترة حسب الحالة
if (
    $isActive !== null &&
    $isActive !== ''
) {
    $activeValue = (int) $isActive;

    if (
        $activeValue === 0 ||
        $activeValue === 1
    ) {
        $where[] = "s.is_active = :is_active";
        $params[':is_active'] = $activeValue;
    }
}

// تكوين WHERE
$whereSql = '';

if (!empty($where)) {
    $whereSql =
        ' WHERE ' .
        implode(' AND ', $where);
}

try {
    /*
     * حساب العدد الإجمالي.
     */
    $countStatement = $pdo->prepare("
        SELECT COUNT(*)

        FROM sections s

        INNER JOIN departments d
            ON d.id = s.department_id

        INNER JOIN companies c
            ON c.id = d.company_id

        {$whereSql}
    ");

    foreach ($params as $key => $value) {
        $countStatement->bindValue(
            $key,
            $value,
            is_int($value)
                ? PDO::PARAM_INT
                : PDO::PARAM_STR
        );
    }

    $countStatement->execute();

    $total = (int) $countStatement->fetchColumn();

    /*
     * جلب الأقسام.
     */
    $statement = $pdo->prepare("
        SELECT
            s.id,
            s.department_id,
            s.name,
            s.is_active,
            s.created_at,
            s.updated_at,

            d.name AS department_name,
            d.company_id,

            c.name AS company_name

        FROM sections s

        INNER JOIN departments d
            ON d.id = s.department_id

        INNER JOIN companies c
            ON c.id = d.company_id

        {$whereSql}

        ORDER BY
            s.id DESC

        LIMIT :limit
        OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $statement->bindValue(
            $key,
            $value,
            is_int($value)
                ? PDO::PARAM_INT
                : PDO::PARAM_STR
        );
    }

    $statement->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $statement->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $statement->execute();

    $sections = $statement->fetchAll();

    // تحويل الحالة إلى رقم واضح
    foreach ($sections as &$section) {
        $section['id'] =
            (int) $section['id'];

        $section['department_id'] =
            (int) $section['department_id'];

        $section['company_id'] =
            (int) $section['company_id'];

        $section['is_active'] =
            (int) $section['is_active'];
    }

    unset($section);

    // آخر صفحة
    $lastPage = $total > 0
        ? (int) ceil($total / $limit)
        : 1;

    echo json_encode([
        "status" => true,
        "message" => "تم جلب الأقسام بنجاح",
        "data" => [
            "sections" => $sections,
            "pagination" => [
                "current_page" => $page,
                "per_page" => $limit,
                "total" => $total,
                "last_page" => $lastPage,
                "has_more" => $page < $lastPage
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب الأقسام",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}