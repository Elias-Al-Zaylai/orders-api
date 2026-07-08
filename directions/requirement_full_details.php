<?php

// إرجاع الاستجابة بصيغة JSON مع دعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// ملفات التحقق من تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// السماح فقط لمن يمتلك صلاحية عرض الطلبات
requirePermission('direct_requirement');

// قراءة رقم المطلوب من الرابط
$requirementId = (int) ($_GET['requirement_id'] ?? 0);

// التحقق من صحة رقم المطلوب
if ($requirementId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * جلب بيانات:
     *
     * الطلب.
     * المطلوب.
     * التوجيه.
     * المنفذ.
     * موجه المطلوب.
     * بيانات الإعادة.
     *
     * استخدمنا LEFT JOIN لأن المطلوب الجديد
     * قد لا يحتوي على توجيه أو منفذ.
     */
    $statement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.problem,
            r.status AS requirement_status,
            r.created_at AS requirement_created_at,
            r.updated_at AS requirement_updated_at,

            o.order_number,
            o.document_number,
            o.statement AS order_statement,
            o.notes AS order_notes,
            o.status AS order_status,
            o.created_at AS order_created_at,

            request_type.name AS request_type_name,

            requester.id AS requester_id,
            requester.name AS requester_name,

            from_company.name AS from_company_name,
            from_department.name AS from_department_name,

            to_company.name AS to_company_name,
            to_department.name AS to_department_name,
            o.to_department_id,

            rd.id AS direction_id,
            rd.executor_id,
            rd.directed_by_user_id,
            rd.notes_to_executor,
            rd.allowed_start,
            rd.allowed_end,
            rd.executor_received_at,
            rd.return_reason,
            rd.returned_at,
            rd.returned_by_user_id,
            rd.created_at AS direction_created_at,
            rd.updated_at AS direction_updated_at,

            executor.name AS executor_name,
            executor.username AS executor_username,
            executor.phone AS executor_phone,
            executor.email AS executor_email,

            director.name AS directed_by_name,
            returned_by.name AS returned_by_name,

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

        INNER JOIN orders o
            ON o.id = r.order_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        INNER JOIN users requester
            ON requester.id = o.requester_id

        LEFT JOIN companies from_company
            ON from_company.id = o.from_company_id

        LEFT JOIN departments from_department
            ON from_department.id = o.from_department_id

        LEFT JOIN companies to_company
            ON to_company.id = o.to_company_id

        LEFT JOIN departments to_department
            ON to_department.id = o.to_department_id

        LEFT JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        LEFT JOIN users executor
            ON executor.id = rd.executor_id

        LEFT JOIN users director
            ON director.id = rd.directed_by_user_id

        LEFT JOIN users returned_by
            ON returned_by.id = rd.returned_by_user_id

        WHERE r.id = ?

        LIMIT 1
    ");

    $statement->execute([
        $requirementId
    ]);

    $details = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    // المطلوب غير موجود
    if (!$details) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المطلوب غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
     * تفاصيل المطلوب للموجّه تظهر فقط إذا كان المطلوب تابعًا لإدارته.
     * مدير النظام مستثنى من هذا القيد.
     */
    if (
        !authUserHasRole('admin')
        &&
        (int) ($details['to_department_id'] ?? 0) !== (int) ($authUser['department_id'] ?? 0)
    ) {
        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "ليس لديك صلاحية عرض هذا المطلوب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * جلب بيانات الإجراء المنفذ.
     *
     * النظام يسمح بإجراء واحد لكل مطلوب،
     * لذلك نأخذ آخر إجراء مسجل.
     */
    $actionStatement = $pdo->prepare("
        SELECT
            ra.id AS action_id,
            ra.executor_id,
            ra.action_taken,
            ra.recommendation,
            ra.notes AS action_notes,
            ra.action_start_date,
            ra.action_end_date,
            ra.created_at AS action_created_at,
            ra.updated_at AS action_updated_at,

            action_executor.name AS action_executor_name

        FROM requirement_actions ra

        LEFT JOIN users action_executor
            ON action_executor.id = ra.executor_id

        WHERE ra.requirement_id = ?

        ORDER BY ra.id DESC

        LIMIT 1
    ");

    $actionStatement->execute([
        $requirementId
    ]);

    $action = $actionStatement->fetch(
        PDO::FETCH_ASSOC
    );

    /*
     * جلب بيانات استلام مقدم الطلب.
     *
     * الأعمدة الفعلية الموجودة في جدول
     * execution_receipts هي:
     *
     * requester_id
     * receipt_status
     * requester_notes
     * received_at
     *
     * لذلك لا نستخدم received_by أو status.
     */
    $receiptStatement = $pdo->prepare("
        SELECT
            er.id AS receipt_id,
            er.requirement_id,
            er.action_id,

            er.requester_id AS received_by,
            er.receipt_status,
            er.requester_notes,

            er.received_at AS requester_received_at,
            er.created_at AS receipt_created_at,
            er.updated_at AS receipt_updated_at,

            requester_receiver.name AS received_by_name,

            NULL AS approved_by,
            NULL AS approved_by_name,
            NULL AS approval_status,
            NULL AS approved_at

        FROM execution_receipts er

        LEFT JOIN users requester_receiver
            ON requester_receiver.id = er.requester_id

        WHERE er.requirement_id = ?

        ORDER BY er.id DESC

        LIMIT 1
    ");

    $receiptStatement->execute([
        $requirementId
    ]);

    $receipt = $receiptStatement->fetch(
        PDO::FETCH_ASSOC
    );

    /*
     * جلب آخر اعتماد من جدول الاعتمادات المخصص.
     */
    $approvalStatement = $pdo->prepare("
        SELECT
            approval.id AS approval_id,
            approval.requirement_id,
            approval.approved_by_user_id AS approved_by,
            approver.name AS approved_by_name,
            approval.approval_status,
            approval.approval_notes,
            approval.approved_at,
            approval.created_at AS approval_created_at
        FROM receipt_approvals approval
        LEFT JOIN users approver
            ON approver.id = approval.approved_by_user_id
        WHERE approval.requirement_id = ?
        ORDER BY approval.id DESC
        LIMIT 1
    ");

    $approvalStatement->execute([
        $requirementId
    ]);

    $approval = $approvalStatement->fetch(PDO::FETCH_ASSOC);

    /*
     * تحويل القيم الرقمية القادمة من PostgreSQL
     * إلى أرقام صحيحة أو null.
     */
    $details['requirement_id'] =
        (int) $details['requirement_id'];

    $details['order_id'] =
        (int) $details['order_id'];

    $details['requester_id'] =
        (int) $details['requester_id'];

    $details['direction_id'] =
        $details['direction_id'] !== null
            ? (int) $details['direction_id']
            : null;

    $details['executor_id'] =
        $details['executor_id'] !== null
            ? (int) $details['executor_id']
            : null;

    $details['directed_by_user_id'] =
        $details['directed_by_user_id'] !== null
            ? (int) $details['directed_by_user_id']
            : null;

    $details['returned_by_user_id'] =
        $details['returned_by_user_id'] !== null
            ? (int) $details['returned_by_user_id']
            : null;

    // تحويل قيمة التأخير إلى boolean
    $details['is_delayed'] =
        (bool) $details['is_delayed'];

    /*
     * تحويل بيانات الإجراء المنفذ.
     */
    if ($action) {
        $action['action_id'] =
            (int) $action['action_id'];

        $action['executor_id'] =
            $action['executor_id'] !== null
                ? (int) $action['executor_id']
                : null;
    }

    /*
     * تحويل بيانات استلام مقدم الطلب.
     */
    if ($receipt) {
        $receipt['receipt_id'] =
            (int) $receipt['receipt_id'];

        $receipt['requirement_id'] =
            (int) $receipt['requirement_id'];

        $receipt['action_id'] =
            (int) $receipt['action_id'];

        $receipt['received_by'] =
            $receipt['received_by'] !== null
                ? (int) $receipt['received_by']
                : null;

        if ($approval) {
            $receipt['approved_by'] =
                $approval['approved_by'] !== null
                    ? (int) $approval['approved_by']
                    : null;

            $receipt['approved_by_name'] =
                $approval['approved_by_name'] ?? null;

            $receipt['approval_status'] =
                $approval['approval_status'] ?? null;

            $receipt['approval_notes'] =
                $approval['approval_notes'] ?? null;

            $receipt['approved_at'] =
                $approval['approved_at'] ?? null;
        }
    }

    if ($approval) {
        $approval['approval_id'] = (int) $approval['approval_id'];
        $approval['requirement_id'] = (int) $approval['requirement_id'];
        $approval['approved_by'] =
            $approval['approved_by'] !== null
                ? (int) $approval['approved_by']
                : null;
    }

    /*
     * تحديد العمليات المتاحة حسب حالة المطلوب.
     */
    $requirementStatus =
        $details['requirement_status'];

    $actions = [
        /*
         * المطلوب الجديد يمكن توجيهه.
         */
        "can_direct" =>
            $requirementStatus === 'new',

        /*
         * يمكن تعديل التوجيه فقط قبل
         * استلام المنفذ للمطلوب.
         */
        "can_update_direction" =>
            $requirementStatus === 'directed',

        /*
         * الاعتماد متاح بعد استلام
         * مقدم الطلب للإجراء.
         */
        "can_approve" =>
            $requirementStatus ===
            'received_by_requester',

        /*
         * إعادة المطلوب للمنفذ متاحة بعد
         * استلام مقدم الطلب للإجراء.
         */
        "can_return_to_executor" =>
            $requirementStatus ===
            'received_by_requester',

        /*
         * عرض التفاصيل متاح لجميع الحالات.
         */
        "can_view_details" => true
    ];

    // إرجاع البيانات بنجاح
    echo json_encode([
        "status" => true,
        "message" => "تم جلب تفاصيل المطلوب بنجاح",
        "data" => [
            "requirement" => $details,
            "action" => $action ?: null,
            "receipt" => $receipt ?: null,
            "approval" => $approval ?: null,
            "actions" => $actions
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل جلب تفاصيل المطلوب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}