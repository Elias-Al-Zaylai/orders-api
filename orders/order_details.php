<?php

// تحديد نوع الاستجابة بصيغة JSON ودعم العربية
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية عرض الطلبات
requirePermission('view_orders');

// استقبال رقم الطلب
$orderId = (int) ($_GET['id'] ?? 0);

// التحقق من رقم الطلب
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
     * جلب بيانات الطلب الأساسية.
     */
    $orderStatement = $pdo->prepare("
        SELECT
            orders.*,

            requester.name AS requester_name,

            destination_company.name AS company_name,

            destination_department.name AS department_name,

            request_type.name AS request_type_name

        FROM orders

        INNER JOIN users requester
            ON requester.id = orders.requester_id

        INNER JOIN companies destination_company
            ON destination_company.id = orders.to_company_id

        INNER JOIN departments destination_department
            ON destination_department.id = orders.to_department_id

        INNER JOIN request_types request_type
            ON request_type.id = orders.request_type_id

        WHERE orders.id = ?

        LIMIT 1
    ");

    $orderStatement->execute([
        $orderId
    ]);

    $order = $orderStatement->fetch();

    // التحقق من وجود الطلب
    if (!$order) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الطلب غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * السماح لمقدم الطلب بعرض طلبه فقط.
     *
     * المستخدم الذي يمتلك أدوارًا أخرى يمكنه عرض الطلب
     * إذا كان هو مقدم الطلب أو يمتلك صلاحية العرض حسب نظامك.
     */
    $isOrderRequester =
        (int) $order['requester_id']
        ===
        (int) $authUser['id'];

    /*
     * جلب جميع مطاليب الطلب.
     */
    $requirementsStatement = $pdo->prepare("
        SELECT
            id,
            order_id,
            requirement,
            problem,
            status,
            created_at,
            updated_at

        FROM requirements

        WHERE order_id = ?

        ORDER BY id ASC
    ");

    $requirementsStatement->execute([
        $orderId
    ]);

    $requirements =
        $requirementsStatement->fetchAll();

    /*
     * جلب آخر توجيه للمطلوب.
     *
     * استخدمنا direction.* حتى لا يحدث خطأ
     * إذا أضفت أعمدة جديدة إلى الجدول لاحقًا.
     */
    $directionStatement = $pdo->prepare("
        SELECT
            direction.*,

            executor.name AS executor_name

        FROM requirement_directions direction

        LEFT JOIN users executor
            ON executor.id = direction.executor_id

        WHERE direction.requirement_id = ?

        ORDER BY direction.id DESC

        LIMIT 1
    ");

    /*
     * جلب آخر إجراء سجله المنفذ.
     *
     * استخدمنا action.* بدل كتابة action_text
     * لأن جدولك يعتمد حقولًا مثل:
     *
     * action_taken
     * recommendation
     * notes
     * action_start_date
     * action_end_date
     */
    $actionStatement = $pdo->prepare("
        SELECT
            action.*,

            executor.name AS executor_name

        FROM requirement_actions action

        LEFT JOIN users executor
            ON executor.id = action.executor_id

        WHERE action.requirement_id = ?

        ORDER BY action.id DESC

        LIMIT 1
    ");

    /*
     * جلب آخر سجل استلام للإجراء.
     *
     * استخدمنا receipt.* لتجنب أي اختلاف
     * في أسماء أعمدة جدول execution_receipts.
     */
    $receiptStatement = $pdo->prepare("
        SELECT
            receipt.*,

            requester.name AS requester_name

        FROM execution_receipts receipt

        LEFT JOIN users requester
            ON requester.id = receipt.requester_id

        WHERE receipt.requirement_id = ?

        ORDER BY receipt.id DESC

        LIMIT 1
    ");

    /*
     * التحقق هل جدول receipt_approvals موجود.
     *
     * إذا لم يكن موجودًا لن يحدث خطأ 500،
     * وستظهر قيمة approval تساوي null.
     */
    $approvalTableStatement = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = 'receipt_approvals'
        LIMIT 1
    ");

    $approvalTableStatement->execute();

    $approvalTableExists =
        (bool) $approvalTableStatement->fetchColumn();

    $approvalStatement = null;

    if ($approvalTableExists) {
        $approvalStatement = $pdo->prepare("
            SELECT
                approval.*

            FROM receipt_approvals approval

            WHERE approval.requirement_id = ?

            ORDER BY approval.id DESC

            LIMIT 1
        ");
    }

    /*
     * إضافة بيانات التوجيه والإجراء والاستلام
     * والاعتماد لكل مطلوب.
     */
    foreach ($requirements as &$requirement) {

        $requirementId =
            (int) $requirement['id'];

        /*
         * جلب آخر توجيه.
         */
        $directionStatement->execute([
            $requirementId
        ]);

        $direction =
            $directionStatement->fetch();

        /*
         * جلب آخر إجراء.
         */
        $actionStatement->execute([
            $requirementId
        ]);

        $action =
            $actionStatement->fetch();

        /*
         * جلب آخر استلام.
         */
        $receiptStatement->execute([
            $requirementId
        ]);

        $receipt =
            $receiptStatement->fetch();

        /*
         * جلب آخر اعتماد إذا كان الجدول موجودًا.
         */
        $approval = false;

        if ($approvalStatement !== null) {
            $approvalStatement->execute([
                $requirementId
            ]);

            $approval =
                $approvalStatement->fetch();
        }

        /*
         * تحويل false إلى null حتى تكون استجابة JSON مرتبة.
         */
        $requirement['direction'] =
            $direction ?: null;

        $requirement['action'] =
            $action ?: null;

        $requirement['receipt'] =
            $receipt ?: null;

        $requirement['approval'] =
            $approval ?: null;

        /*
         * السماح باستلام الإجراء فقط عندما:
         *
         * 1- المستخدم الحالي هو مقدم الطلب.
         * 2- حالة المطلوب action_done.
         * 3- يوجد إجراء مسجل.
         * 4- لا يوجد استلام سابق.
         */
        $requirement['can_receive_execution'] =
            $isOrderRequester
            &&
            $requirement['status'] === 'action_done'
            &&
            $action !== false
            &&
            $receipt === false;
    }

    // إزالة المرجع بعد انتهاء الحلقة
    unset($requirement);

    /*
     * إرسال النتيجة إلى Flutter.
     */
    echo json_encode([
        "status" => true,
        "order" => $order,
        "requirements" => $requirements
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب تفاصيل الطلب",
        "error" => $error->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}