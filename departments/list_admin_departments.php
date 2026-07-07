<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('manage_settings');

try {
    // الصفحة الحالية
    $page = max(
        1,
        (int) ($_GET['page'] ?? 1)
    );

    // عدد العناصر في الصفحة
    $limit = (int) ($_GET['limit'] ?? 10);
    $limit = max(1, min($limit, 100));

    // بداية البيانات
    $offset = ($page - 1) * $limit;

    // البحث
    $search = trim(
        $_GET['search'] ?? ''
    );

    // تصفية حسب الشركة
    $companyId = (int) (
        $_GET['company_id'] ?? 0
    );

    // تصفية حسب الحالة
    $isActive = $_GET['is_active'] ?? null;

    $conditions = [];
    $parameters = [];

    /*
     * البحث باسم الإدارة أو اسم الشركة.
     */
    if ($search !== '') {
        $conditions[] = "
            (
                d.name ILIKE :search_1
                OR c.name ILIKE :search_2
            )
        ";
        $searchValue = '%' . $search . '%';

        for ($searchIndex = 1; $searchIndex <= 2; $searchIndex++) {
            $parameters[':search_' . $searchIndex] = $searchValue;
        }
    }

    /*
     * التصفية حسب الشركة.
     */
    if ($companyId > 0) {
        $conditions[] =
            "d.company_id = :company_id";

        $parameters[':company_id'] =
            $companyId;
    }

    /*
     * التصفية حسب الحالة.
     */
    if (
        $isActive !== null &&
        $isActive !== ''
    ) {
        if (
            !in_array(
                (string) $isActive,
                ['0', '1'],
                true
            )
        ) {
            http_response_code(422);

            echo json_encode([
                "status" => false,
                "message" => "حالة الإدارة غير صحيحة"
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $conditions[] =
            "d.is_active = :is_active";

        $parameters[':is_active'] =
            (int) $isActive;
    }

    $whereSql = '';

    if (!empty($conditions)) {
        $whereSql =
            'WHERE ' .
            implode(' AND ', $conditions);
    }

    /*
     * حساب العدد الإجمالي.
     */
    $countStatement = $pdo->prepare("
        SELECT COUNT(*)

        FROM departments d

        INNER JOIN companies c
            ON c.id = d.company_id

        $whereSql
    ");

    foreach ($parameters as $key => $value) {
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
     * جلب الإدارات.
     */
    $statement = $pdo->prepare("
        SELECT
            d.id,
            d.company_id,
            d.name,
            d.is_active,
            d.created_at,
            d.updated_at,
            c.name AS company_name

        FROM departments d

        INNER JOIN companies c
            ON c.id = d.company_id

        $whereSql

        ORDER BY d.id DESC

        LIMIT :limit
        OFFSET :offset
    ");

    foreach ($parameters as $key => $value) {
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

    $departments = [];

    while ($row = $statement->fetch()) {
        $departments[] = [
            "id" => (int) $row['id'],
            "company_id" =>
                (int) $row['company_id'],
            "company_name" =>
                $row['company_name'],
            "name" => $row['name'],
            "is_active" =>
                (bool) $row['is_active'],
            "created_at" =>
                $row['created_at'],
            "updated_at" =>
                $row['updated_at']
        ];
    }

    $lastPage = max(
        1,
        (int) ceil($total / $limit)
    );

    echo json_encode([
        "status" => true,
        "message" =>
            "تم جلب الإدارات بنجاح",
        "data" => [
            "departments" => $departments,
            "pagination" => [
                "current_page" => $page,
                "last_page" => $lastPage,
                "limit" => $limit,
                "total" => $total,
                "has_more" =>
                    $page < $lastPage
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" =>
            "حدث خطأ أثناء جلب الإدارات",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}