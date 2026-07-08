<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية إدارة إعدادات النظام
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

// رقم الصفحة
$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

// عدد العناصر في الصفحة
$limit = (int) ($_GET['limit'] ?? 20);

// منع إرسال عدد كبير جدًا
$limit = max(10, min($limit, 100));

// بداية السجلات
$offset = ($page - 1) * $limit;

// نص البحث
$search = trim(
    (string) ($_GET['search'] ?? '')
);

// فلتر الحالة
$statusFilter = trim(
    (string) ($_GET['status'] ?? 'all')
);

// الحالات المسموحة
$allowedStatuses = [
    'all',
    'active',
    'inactive'
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

try {
    $conditions = [];
    $parameters = [];

    /*
     * البحث باسم الشركة.
     */
    if ($search !== '') {
        $conditions[] = "name ILIKE :search";
        $parameters[':search'] = '%' . $search . '%';
    }

    /*
     * فلترة الشركات حسب الحالة.
     */
    if ($statusFilter === 'active') {
        $conditions[] = "is_active = TRUE";
    } elseif ($statusFilter === 'inactive') {
        $conditions[] = "is_active = FALSE";
    }

    $whereSql = '';

    if (!empty($conditions)) {
        $whereSql =
            ' WHERE ' . implode(' AND ', $conditions);
    }

    /*
     * جلب إجمالي عدد الشركات.
     */
    $countStatement = $pdo->prepare("
        SELECT COUNT(*)
        FROM companies
        $whereSql
    ");

    foreach ($parameters as $key => $value) {
        $countStatement->bindValue(
            $key,
            $value,
            PDO::PARAM_STR
        );
    }

    $countStatement->execute();

    $total = (int) $countStatement->fetchColumn();

    /*
     * جلب الشركات.
     */
    $companiesStatement = $pdo->prepare("
        SELECT
            id,
            name,
            is_active,
            created_at,
            updated_at

        FROM companies

        $whereSql

        ORDER BY id DESC

        LIMIT :limit
        OFFSET :offset
    ");

    foreach ($parameters as $key => $value) {
        $companiesStatement->bindValue(
            $key,
            $value,
            PDO::PARAM_STR
        );
    }

    $companiesStatement->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $companiesStatement->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $companiesStatement->execute();

    $companies = $companiesStatement->fetchAll();

    /*
     * تحويل حالة الشركة إلى true أو false.
     */
    foreach ($companies as &$company) {
        $company['id'] =
            (int) $company['id'];

        $company['is_active'] =
            (bool) $company['is_active'];
    }

    unset($company);

    // عدد الصفحات
    $lastPage = $total > 0
        ? (int) ceil($total / $limit)
        : 1;

    echo json_encode([
        "status" => true,
        "message" => "تم جلب الشركات بنجاح",
        "data" => [
            "companies" => $companies,
            "pagination" => [
                "current_page" => $page,
                "last_page" => $lastPage,
                "limit" => $limit,
                "total" => $total,
                "has_more" => $page < $lastPage
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب الشركات",
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}