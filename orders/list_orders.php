<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// استدعاء التحقق من تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// التحقق من امتلاك المستخدم صلاحية عرض الطلبات
requirePermission('view_orders');

/*
 * استقبال بيانات الترقيم.
 */
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 10);

// التأكد من أن رقم الصفحة صحيح
if ($page < 1) {
    $page = 1;
}

// الحد المسموح به من 1 إلى 50 طلبًا
if ($limit < 1 || $limit > 50) {
    $limit = 10;
}

// حساب نقطة بداية النتائج
$offset = ($page - 1) * $limit;

/*
 * استقبال بيانات الفلترة والبحث.
 */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$requestTypeId = (int) ($_GET['request_type_id'] ?? 0);
$date = trim($_GET['date'] ?? '');

// شروط الاستعلام
$where = [];

// قيم شروط الاستعلام
$params = [];

/*
 * عرض الطلبات التي أنشأها المستخدم الحالي فقط.
 */
$where[] = "o.requester_id = ?";
$params[] = (int) $authUser['id'];

/*
 * البحث برقم الطلب أو رقم الوثيقة أو البيان
 * أو الشركة أو الإدارة أو نوع الطلب.
 */
if ($search !== '') {
    $where[] = "(
        o.order_number ILIKE ?
        OR o.document_number ILIKE ?
        OR o.statement ILIKE ?
        OR destination_company.name ILIKE ?
        OR destination_department.name ILIKE ?
        OR request_type.name ILIKE ?
    )";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

/*
 * الفلترة حسب حالة الطلب.
 */
if ($status !== '') {

    // الحالات المسموح بها للطلب
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

    if (!in_array($status, $allowedStatuses, true)) {
        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "حالة الطلب غير صحيحة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $where[] = "o.status = ?";
    $params[] = $status;
}

/*
 * الفلترة حسب نوع الطلب.
 */
if ($requestTypeId > 0) {
    $where[] = "o.request_type_id = ?";
    $params[] = $requestTypeId;
}

/*
 * الفلترة حسب تاريخ إنشاء الطلب.
 */
if ($date !== '') {

    // التحقق من تنسيق التاريخ YYYY-MM-DD
    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    $isValidDate =
        $dateObject !== false
        &&
        $dateObject->format('Y-m-d') === $date;

    if (!$isValidDate) {
        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "صيغة التاريخ يجب أن تكون YYYY-MM-DD"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // PostgreSQL يقبل تحويل التاريخ بهذا الشكل
    $where[] = "DATE(o.created_at) = ?";
    $params[] = $date;
}

// تكوين شروط WHERE
$whereSql = " WHERE " . implode(
    " AND ",
    $where
);

try {

    /*
     * حساب إجمالي الطلبات بعد تطبيق البحث والفلاتر.
     */
    $countSql = "
        SELECT
            COUNT(*) AS total

        FROM orders o

        INNER JOIN users requester
            ON requester.id = o.requester_id

        INNER JOIN companies destination_company
            ON destination_company.id = o.to_company_id

        INNER JOIN departments destination_department
            ON destination_department.id = o.to_department_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        $whereSql
    ";

    $countStatement = $pdo->prepare($countSql);

    $countStatement->execute($params);

    $countResult = $countStatement->fetch();

    $total = (int) ($countResult['total'] ?? 0);

    /*
     * جلب طلبات مقدم الطلب.
     *
     * يتم أيضًا حساب عدد المطاليب وحالاتها
     * حتى تستفيد منها شاشة طلباتي.
     */
    $ordersSql = "
        SELECT
            o.id,
            o.order_number,
            o.document_number,
            o.requester_id,
            o.statement,
            o.notes,
            o.status,
            o.created_at,
            o.updated_at,

            requester.name AS requester_name,

            destination_company.name AS to_company_name,

            destination_department.name AS to_department_name,

            request_type.name AS request_type_name,

            (
                SELECT COUNT(*)

                FROM requirements requirement

                WHERE requirement.order_id = o.id
            ) AS requirements_count,

            (
                SELECT COUNT(*)

                FROM requirements requirement

                WHERE requirement.order_id = o.id
                AND requirement.status = 'action_done'
            ) AS actions_waiting_receipt_count,

            (
                SELECT COUNT(*)

                FROM requirements requirement

                WHERE requirement.order_id = o.id
                AND requirement.status = 'received_by_requester'
            ) AS requirements_waiting_approval_count,

            (
                SELECT COUNT(*)

                FROM requirements requirement

                WHERE requirement.order_id = o.id
                AND requirement.status = 'closed'
            ) AS closed_requirements_count

        FROM orders o

        INNER JOIN users requester
            ON requester.id = o.requester_id

        INNER JOIN companies destination_company
            ON destination_company.id = o.to_company_id

        INNER JOIN departments destination_department
            ON destination_department.id = o.to_department_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        $whereSql

        ORDER BY o.id DESC

        LIMIT $limit OFFSET $offset
    ";

    $ordersStatement = $pdo->prepare($ordersSql);

    $ordersStatement->execute($params);

    $orders = $ordersStatement->fetchAll();

    /*
     * تحويل الأعداد القادمة من PostgreSQL إلى أعداد صحيحة.
     */
    foreach ($orders as &$order) {

        $order['id'] =
            (int) $order['id'];

        $order['requester_id'] =
            (int) $order['requester_id'];

        $order['requirements_count'] =
            (int) $order['requirements_count'];

        $order['actions_waiting_receipt_count'] =
            (int) $order['actions_waiting_receipt_count'];

        $order['requirements_waiting_approval_count'] =
            (int) $order['requirements_waiting_approval_count'];

        $order['closed_requirements_count'] =
            (int) $order['closed_requirements_count'];

        /*
         * true عندما يوجد مطلوب واحد على الأقل
         * أنهى المنفذ إجراءه وينتظر استلام مقدم الطلب.
         */
        $order['has_actions_waiting_receipt'] =
            $order['actions_waiting_receipt_count'] > 0;
    }

    unset($order);

    // إرسال النتيجة
    echo json_encode([
        "status" => true,
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "has_more" => ($offset + count($orders)) < $total,
        "orders" => $orders
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب الطلبات",
        "error" => $error->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}