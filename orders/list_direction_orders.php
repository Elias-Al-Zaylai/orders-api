<?php

// إرجاع الاستجابة بصيغة JSON ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// يجب أن يمتلك المستخدم صلاحية عرض الطلبات
requirePermission('direct_requirement');

// البحث
$search = trim($_GET['search'] ?? '');

// حالة الطلب المطلوبة
$status = trim($_GET['status'] ?? '');

// رقم الصفحة
$page = (int) ($_GET['page'] ?? 1);

// عدد الطلبات في كل صفحة
$perPage = (int) ($_GET['per_page'] ?? 20);

// حماية قيم الصفحة
if ($page < 1) {
    $page = 1;
}

if ($perPage < 1) {
    $perPage = 20;
}

if ($perPage > 100) {
    $perPage = 100;
}

// الحالات المسموح بها
$allowedStatuses = [
    'submitted',
    'under_direction',
    'directed',
    'in_execution',
    'waiting_receipt',
    'waiting_approval',
    'completed',
    'cancelled'
];

// التأكد من صحة الحالة
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "حالة الطلب غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    /*
     * التأكد هل المستخدم مدير نظام.
     *
     * مدير النظام يستطيع مشاهدة جميع الطلبات.
     * أما الموجّه فيشاهد الطلبات المرسلة إلى إدارته فقط.
     * لا نربطها بالشركة لأن المطلوب حسب النظام على مستوى الإدارة.
     */
    $adminStatement = $pdo->prepare("
        SELECT 1

        FROM user_roles ur

        INNER JOIN roles r
            ON r.id = ur.role_id

        WHERE ur.user_id = ?
          AND r.name = 'admin'
          AND r.is_active = TRUE

        LIMIT 1
    ");

    $adminStatement->execute([
        $authUser['id']
    ]);

    $isAdmin = (bool) $adminStatement->fetchColumn();

    // شروط الاستعلام
    $conditions = [];

    // قيم الاستعلام
    $parameters = [];

    /*
     * إذا لم يكن المستخدم مدير النظام،
     * يتم عرض الطلبات المرسلة إلى إدارة الموجّه فقط.
     */
    if (!$isAdmin) {

        if (empty($authUser['department_id'])) {
            echo json_encode([
                "status" => true,
                "message" => "لا توجد طلبات",
                "data" => [],
                "pagination" => [
                    "current_page" => $page,
                    "per_page" => $perPage,
                    "total" => 0,
                    "last_page" => 1,
                    "has_more" => false
                ]
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $conditions[] = "o.to_department_id = :department_id";
        $parameters[':department_id'] = (int) $authUser['department_id'];
    }

    // فلترة حالة الطلب
    if ($status !== '') {
        $conditions[] = "o.status = :status";
        $parameters[':status'] = $status;
    }

    // البحث
    if ($search !== '') {
        $conditions[] = "(
            o.order_number ILIKE :search_1
            OR o.document_number ILIKE :search_2
            OR o.statement ILIKE :search_3
            OR requester.name ILIKE :search_4
            OR request_type.name ILIKE :search_5
            OR from_company.name ILIKE :search_6
            OR from_department.name ILIKE :search_7
            OR to_company.name ILIKE :search_8
            OR to_department.name ILIKE :search_9
        )";
        $searchValue = '%' . $search . '%';

        for ($searchIndex = 1; $searchIndex <= 9; $searchIndex++) {
            $parameters[':search_' . $searchIndex] = $searchValue;
        }
    }

    // تكوين WHERE
    $whereSql = '';

    if (!empty($conditions)) {
        $whereSql = 'WHERE ' . implode(' AND ', $conditions);
    }

    /*
     * حساب إجمالي عدد الطلبات.
     */
    $countSql = "
        SELECT COUNT(DISTINCT o.id)

        FROM orders o

        INNER JOIN users requester
            ON requester.id = o.requester_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        LEFT JOIN companies from_company
            ON from_company.id = o.from_company_id

        LEFT JOIN departments from_department
            ON from_department.id = o.from_department_id

        INNER JOIN companies to_company
            ON to_company.id = o.to_company_id

        INNER JOIN departments to_department
            ON to_department.id = o.to_department_id

        $whereSql
    ";

    $countStatement = $pdo->prepare($countSql);

    foreach ($parameters as $key => $value) {
        $countStatement->bindValue($key, $value);
    }

    $countStatement->execute();

    $total = (int) $countStatement->fetchColumn();

    // حساب الإزاحة
    $offset = ($page - 1) * $perPage;

    // حساب آخر صفحة
    $lastPage = max(
        1,
        (int) ceil($total / $perPage)
    );

    /*
     * جلب الطلبات مع عدد المطاليب داخل كل طلب.
     */
    $ordersSql = "
        SELECT
            o.id AS order_id,
            o.order_number,
            o.document_number,
            o.statement,
            o.notes,
            o.status,
            o.created_at,
            o.updated_at,

            requester.name AS requester_name,

            request_type.name AS request_type_name,

            from_company.name AS from_company_name,
            from_department.name AS from_department_name,

            to_company.name AS to_company_name,
            to_department.name AS to_department_name,

            COUNT(requirements.id) AS requirements_count,

            SUM(
                CASE
                    WHEN requirements.status = 'new'
                    THEN 1
                    ELSE 0
                END
            ) AS new_requirements_count,

            SUM(
                CASE
                    WHEN requirements.status = 'directed'
                    THEN 1
                    ELSE 0
                END
            ) AS directed_requirements_count,

            SUM(
                CASE
                    WHEN requirements.status IN (
                        'received_by_executor',
                        'action_done',
                        'returned_to_executor'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS execution_requirements_count,

            SUM(
                CASE
                    WHEN requirements.status IN (
                        'received_by_requester',
                        'closed'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS completed_requirements_count

        FROM orders o

        INNER JOIN users requester
            ON requester.id = o.requester_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        LEFT JOIN companies from_company
            ON from_company.id = o.from_company_id

        LEFT JOIN departments from_department
            ON from_department.id = o.from_department_id

        INNER JOIN companies to_company
            ON to_company.id = o.to_company_id

        INNER JOIN departments to_department
            ON to_department.id = o.to_department_id

        LEFT JOIN requirements
            ON requirements.order_id = o.id

        $whereSql

        GROUP BY
            o.id,
            o.order_number,
            o.document_number,
            o.statement,
            o.notes,
            o.status,
            o.created_at,
            o.updated_at,
            requester.name,
            request_type.name,
            from_company.name,
            from_department.name,
            to_company.name,
            to_department.name

        ORDER BY o.created_at DESC

        LIMIT :limit
        OFFSET :offset
    ";

    $ordersStatement = $pdo->prepare($ordersSql);

    foreach ($parameters as $key => $value) {
        $ordersStatement->bindValue($key, $value);
    }

    $ordersStatement->bindValue(
        ':limit',
        $perPage,
        PDO::PARAM_INT
    );

    $ordersStatement->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $ordersStatement->execute();

    $orders = $ordersStatement->fetchAll(PDO::FETCH_ASSOC);

    // تحويل الأرقام النصية إلى أرقام صحيحة
    foreach ($orders as &$order) {
        $order['order_id'] =
            (int) $order['order_id'];

        $order['requirements_count'] =
            (int) $order['requirements_count'];

        $order['new_requirements_count'] =
            (int) $order['new_requirements_count'];

        $order['directed_requirements_count'] =
            (int) $order['directed_requirements_count'];

        $order['execution_requirements_count'] =
            (int) $order['execution_requirements_count'];

        $order['completed_requirements_count'] =
            (int) $order['completed_requirements_count'];
    }

    unset($order);

    echo json_encode([
        "status" => true,
        "message" => "تم جلب الطلبات بنجاح",
        "data" => $orders,
        "pagination" => [
            "current_page" => $page,
            "per_page" => $perPage,
            "total" => $total,
            "last_page" => $lastPage,
            "has_more" => $page < $lastPage
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل جلب الطلبات",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}