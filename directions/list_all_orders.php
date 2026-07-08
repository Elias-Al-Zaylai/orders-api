<?php

// تحديد نوع الاستجابة بصيغة JSON مع دعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// هذه الشاشة خاصة بموجّه الطلبات
requirePermission('direct_requirement');

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = (int) ($_GET['page'] ?? 1);
$perPage = (int) ($_GET['per_page'] ?? 20);

if ($page < 1) {
    $page = 1;
}

if ($perPage < 1 || $perPage > 100) {
    $perPage = 20;
}

$allowedStatuses = allowedOrderStatusesSafe();

if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "حالة الطلب غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $isAdmin = authUserHasRole('admin');
    $departmentId = (int) ($authUser['department_id'] ?? 0);

    if (!$isAdmin && $departmentId <= 0) {
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

    $conditions = [];
    $params = [];

    // الموجّه يشاهد الطلبات المرسلة إلى إدارته فقط
    if (!$isAdmin) {
        $conditions[] = "o.to_department_id = :department_id";
        $params[':department_id'] = $departmentId;
    }

    if ($status !== '') {
        $conditions[] = "o.status = :status";
        $params[':status'] = $status;
    }

    if ($search !== '') {
        $conditions[] = "(
            o.order_number ILIKE :search
            OR o.document_number ILIKE :search
            OR o.statement ILIKE :search
            OR requester.name ILIKE :search
            OR request_type.name ILIKE :search
            OR from_company.name ILIKE :search
            OR from_department.name ILIKE :search
            OR to_company.name ILIKE :search
            OR to_department.name ILIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }

    $whereSql = empty($conditions)
        ? ''
        : 'WHERE ' . implode(' AND ', $conditions);

    $countSql = "
        SELECT COUNT(DISTINCT o.id)
        FROM orders o
        INNER JOIN users requester ON requester.id = o.requester_id
        INNER JOIN request_types request_type ON request_type.id = o.request_type_id
        LEFT JOIN companies from_company ON from_company.id = o.from_company_id
        LEFT JOIN departments from_department ON from_department.id = o.from_department_id
        INNER JOIN companies to_company ON to_company.id = o.to_company_id
        INNER JOIN departments to_department ON to_department.id = o.to_department_id
        $whereSql
    ";

    $countStatement = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStatement->bindValue($key, $value);
    }
    $countStatement->execute();

    $total = (int) $countStatement->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $lastPage = max(1, (int) ceil($total / $perPage));

    $sql = "
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
            COUNT(r.id) AS requirements_count,
            SUM(CASE WHEN r.status = 'new' THEN 1 ELSE 0 END) AS new_requirements_count,
            SUM(CASE WHEN r.status = 'directed' THEN 1 ELSE 0 END) AS directed_requirements_count,
            SUM(CASE WHEN r.status IN ('received_by_executor', 'returned_to_executor') THEN 1 ELSE 0 END) AS in_execution_requirements_count,
            SUM(CASE WHEN r.status = 'action_done' THEN 1 ELSE 0 END) AS waiting_receipt_requirements_count,
            SUM(CASE WHEN r.status = 'received_by_requester' THEN 1 ELSE 0 END) AS waiting_approval_requirements_count,
            SUM(CASE WHEN r.status = 'closed' THEN 1 ELSE 0 END) AS closed_requirements_count,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_requirements_count
        FROM orders o
        INNER JOIN users requester ON requester.id = o.requester_id
        INNER JOIN request_types request_type ON request_type.id = o.request_type_id
        LEFT JOIN companies from_company ON from_company.id = o.from_company_id
        LEFT JOIN departments from_department ON from_department.id = o.from_department_id
        INNER JOIN companies to_company ON to_company.id = o.to_company_id
        INNER JOIN departments to_department ON to_department.id = o.to_department_id
        LEFT JOIN requirements r ON r.order_id = o.id
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
        LIMIT :limit OFFSET :offset
    ";

    $statement = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $orders = $statement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$order) {
        $order['order_id'] = (int) $order['order_id'];
        foreach ([
            'requirements_count',
            'new_requirements_count',
            'directed_requirements_count',
            'in_execution_requirements_count',
            'waiting_receipt_requirements_count',
            'waiting_approval_requirements_count',
            'closed_requirements_count',
            'cancelled_requirements_count'
        ] as $field) {
            $order[$field] = (int) ($order[$field] ?? 0);
        }
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

/**
 * دالة محلية بسيطة لتجنب كسر الملف إذا لم يتم استدعاء helper.
 */
function allowedOrderStatusesSafe(): array
{
    return [
        'submitted',
        'under_direction',
        'directed',
        'in_execution',
        'waiting_receipt',
        'waiting_approval',
        'completed',
        'cancelled'
    ];
}
