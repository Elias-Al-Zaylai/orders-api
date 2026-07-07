<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// السماح لمن يمتلك صلاحية مشاهدة الطلبات
requirePermission('view_orders');

// رقم الطلب
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * جلب بيانات الطلب.
     */
    $orderStatement = $pdo->prepare("
        SELECT
            o.id,
            o.order_number,
            o.document_number,
            o.statement,
            o.notes,
            o.status,
            o.created_at,

            requester.name AS requester_name,
            request_type.name AS request_type_name,

            from_company.name AS from_company_name,
            from_department.name AS from_department_name,

            to_company.name AS to_company_name,
            to_department.name AS to_department_name

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

        WHERE o.id = ?

        LIMIT 1
    ");

    $orderStatement->execute([$orderId]);

    $order = $orderStatement->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الطلب غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * جلب جميع مطاليب الطلب.
     *
     * المطلوب الجديد لن يحتوي على بيانات توجيه،
     * لذلك استخدمنا LEFT JOIN.
     */
    $requirementsStatement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.problem,
            r.status AS requirement_status,
            r.created_at AS requirement_created_at,
            r.updated_at AS requirement_updated_at,

            rd.id AS direction_id,
            rd.executor_id,
            rd.directed_by_user_id,
            rd.notes_to_executor,
            rd.allowed_start,
            rd.allowed_end,
            rd.created_at AS direction_created_at,
            rd.updated_at AS direction_updated_at,

            executor.name AS executor_name,
            executor.phone AS executor_phone,
            executor.email AS executor_email,

            director.name AS directed_by_name,

            CASE
                WHEN r.status IN (
                    'directed',
                    'received_by_executor',
                    'returned_to_executor'
                )
                AND rd.allowed_end IS NOT NULL
                AND rd.allowed_end < NOW()
                THEN 1
                ELSE 0
            END AS is_delayed

        FROM requirements r

        LEFT JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        LEFT JOIN users executor
            ON executor.id = rd.executor_id

        LEFT JOIN users director
            ON director.id = rd.directed_by_user_id

        WHERE r.order_id = ?

        ORDER BY
            CASE
                WHEN r.status = 'new' THEN 1
                WHEN r.status = 'received_by_requester' THEN 2
                WHEN r.status = 'directed' THEN 3
                WHEN r.status = 'received_by_executor' THEN 4
                WHEN r.status = 'returned_to_executor' THEN 5
                WHEN r.status = 'action_done' THEN 6
                WHEN r.status = 'closed' THEN 7
                WHEN r.status = 'cancelled' THEN 8
                ELSE 9
            END,
            r.id ASC
    ");

    $requirementsStatement->execute([$orderId]);

    $requirements = $requirementsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($requirements as &$requirement) {
        // تحويل الأرقام
        $requirement['requirement_id'] =
            (int) $requirement['requirement_id'];

        $requirement['direction_id'] =
            $requirement['direction_id'] !== null
                ? (int) $requirement['direction_id']
                : null;

        $requirement['executor_id'] =
            $requirement['executor_id'] !== null
                ? (int) $requirement['executor_id']
                : null;

        $requirement['directed_by_user_id'] =
            $requirement['directed_by_user_id'] !== null
                ? (int) $requirement['directed_by_user_id']
                : null;

        $requirement['is_delayed'] =
            (bool) $requirement['is_delayed'];

        /*
         * العمليات التي تظهر في شاشة Flutter
         * حسب حالة المطلوب.
         */
        $status = $requirement['requirement_status'];

        $requirement['actions'] = [
            // توجيه المطلوب الجديد
            "can_direct" =>
                $status === 'new',

            // تعديل التوجيه قبل استلام المنفذ
            "can_update_direction" =>
                $status === 'directed',

            // اعتماد استلام الإجراء
            "can_approve" =>
                $status === 'received_by_requester',

            // إعادة المطلوب إلى نفس المنفذ
            "can_return_to_executor" =>
                $status === 'received_by_requester',

            // عرض التفاصيل متاح دائمًا
            "can_view_details" => true
        ];
    }

    unset($requirement);

    // تحويل رقم الطلب
    $order['id'] = (int) $order['id'];

    echo json_encode([
        "status" => true,
        "message" => "تم جلب مطاليب الطلب بنجاح",
        "order" => $order,
        "requirements_count" => count($requirements),
        "requirements" => $requirements
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل جلب مطاليب الطلب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}