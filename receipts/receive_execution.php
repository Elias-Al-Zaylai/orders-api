<?php

// تحديد نوع الاستجابة JSON ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// استدعاء ملفات التحقق من تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

// التحقق من امتلاك المستخدم صلاحية استلام الإجراء
requirePermission('receive_execution');

// قراءة البيانات المرسلة من Flutter
$data = json_decode(
    file_get_contents("php://input"),
    true
);

// التأكد أن البيانات المرسلة JSON صحيحة
if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// رقم المطلوب
$requirementId = (int) ($data['requirement_id'] ?? 0);

// ملاحظات مقدم الطلب اختيارية
$requesterNotes = trim(
    (string) ($data['requester_notes'] ?? '')
);

// التحقق من رقم المطلوب
if ($requirementId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    // بدء المعاملة لضمان تنفيذ العملية كاملة
    $pdo->beginTransaction();

    /*
     * جلب بيانات المطلوب والطلب المرتبط به.
     *
     * اسم عمود المطلوب في قاعدة بياناتك هو requirement
     * وليس title.
     *
     * FOR UPDATE تمنع أي عملية أخرى من تعديل السجل
     * أثناء تنفيذ عملية الاستلام.
     */
    $requirementStatement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.requirement AS requirement_title,
            r.status AS requirement_status,

            o.id AS order_id,
            o.order_number,
            o.requester_id

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        WHERE r.id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $requirementStatement->execute([
        $requirementId
    ]);

    $requirement = $requirementStatement->fetch();

    // التحقق من وجود المطلوب
    if (!$requirement) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المطلوب غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التأكد أن المستخدم الحالي هو مقدم الطلب نفسه.
     * لا يستطيع مستخدم آخر استلام الإجراء.
     */
    if (
        (int) $requirement['requester_id']
        !==
        (int) $authUser['id']
    ) {
        $pdo->rollBack();

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "غير مسموح لك باستلام إجراء هذا المطلوب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * لا يمكن استلام الإجراء إلا بعد أن يسجل المنفذ الإجراء.
     * عند تسجيل الإجراء تصبح حالة المطلوب action_done.
     */
    if (
        $requirement['requirement_status']
        !==
        'action_done'
    ) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن استلام الإجراء قبل أن يسجل المنفذ الإجراء المطلوب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * جلب آخر إجراء مسجل للمطلوب.
     *
     * لا نحتاج إلى action_text لأن هذا العمود
     * غير موجود في جدول requirement_actions.
     *
     * نحتاج رقم الإجراء فقط لتسجيل الاستلام.
     */
    $actionStatement = $pdo->prepare("
        SELECT
            id,
            requirement_id,
            executor_id,
            created_at,
            updated_at

        FROM requirement_actions

        WHERE requirement_id = ?

        ORDER BY id DESC

        LIMIT 1
    ");

    $actionStatement->execute([
        $requirementId
    ]);

    $action = $actionStatement->fetch();

    // التأكد من أن المنفذ سجل إجراء بالفعل
    if (!$action) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "لا يوجد إجراء مسجل لهذا المطلوب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * منع تكرار استلام نفس نسخة الإجراء.
     * إذا رجع مدير التوجيه المطلوب للمنفذ وتم تعديل الإجراء،
     * يكون updated_at أحدث من الاستلام القديم، لذلك نسمح باستلام جديد.
     */
    $receiptCheckStatement = $pdo->prepare("
        SELECT id
        FROM execution_receipts
        WHERE requirement_id = ?
          AND action_id = ?
          AND received_at >= ?
        LIMIT 1
    ");

    $actionVersionTime = $action['updated_at'] ?? $action['created_at'];

    $receiptCheckStatement->execute([
        $requirementId,
        (int) $action['id'],
        $actionVersionTime
    ]);

    $existingReceipt = $receiptCheckStatement->fetch();

    if ($existingReceipt) {
        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "تم استلام هذا الإجراء مسبقًا"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * تسجيل استلام مقدم الطلب للإجراء.
     */
    $insertReceiptStatement = $pdo->prepare("
        INSERT INTO execution_receipts (
            requirement_id,
            action_id,
            requester_id,
            receipt_status,
            requester_notes,
            received_at,
            created_at,
            updated_at
        )
        VALUES (
            ?,
            ?,
            ?,
            'received',
            ?,
            NOW(),
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    $insertReceiptStatement->execute([
        $requirementId,
        (int) $action['id'],
        (int) $authUser['id'],
        $requesterNotes !== ''
            ? $requesterNotes
            : null
    ]);

    // رقم سجل الاستلام الجديد
    $receiptId = (int) $insertReceiptStatement->fetchColumn();

    /*
     * تغيير حالة المطلوب إلى:
     * received_by_requester
     *
     * بعدها ينتظر المطلوب اعتماد مدير التوجيه.
     */
    $updateRequirementStatement = $pdo->prepare("
        UPDATE requirements

        SET
            status = 'received_by_requester',
            updated_at = NOW()

        WHERE id = ?
        AND status = 'action_done'
    ");

    $updateRequirementStatement->execute([
        $requirementId
    ]);

    /*
     * التأكد أن حالة المطلوب تم تحديثها.
     */
    if (
        $updateRequirementStatement->rowCount()
        ===
        0
    ) {
        throw new RuntimeException(
            "تعذر تحديث حالة المطلوب"
        );
    }

    // تحديث حالة الطلب تلقائيًا بعد استلام مقدم الطلب للإجراء
    $newOrderStatus = updateOrderStatus($pdo, (int) $requirement['order_id']);

    // تثبيت جميع العمليات
    $pdo->commit();

    // الاستجابة الناجحة
    echo json_encode([
        "status" => true,
        "message" => "تم استلام الإجراء بنجاح",
        "data" => [
            "receipt_id" => $receiptId,
            "requirement_id" => $requirementId,
            "requirement_title" =>
                $requirement['requirement_title'],
            "action_id" => (int) $action['id'],
            "order_id" =>
                (int) $requirement['order_id'],
            "order_number" =>
                $requirement['order_number'],
            "requirement_status" =>
                "received_by_requester",
            "order_status" =>
                $newOrderStatus
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {

    // التراجع عن العمليات عند حدوث خطأ
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    /*
     * error موجود مؤقتًا حتى يظهر اسم العمود
     * أو الجدول المسبب للمشكلة إن بقي خطأ.
     */
    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء استلام الإجراء",
        "error" => $error->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}