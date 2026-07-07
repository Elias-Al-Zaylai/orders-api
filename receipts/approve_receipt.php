<?php

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// ملفات التحقق والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية اعتماد استلام الإجراءات
requirePermission('approve_receipt');

// استقبال البيانات
$data = json_decode(
    file_get_contents("php://input"),
    true
);

// رقم المطلوب
$requirementId = (int) ($data['requirement_id'] ?? 0);

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
    // بدء العملية
    $pdo->beginTransaction();

    /*
     * جلب المطلوب وقفل السجل حتى تنتهي عملية الاعتماد.
     */
    $requirementStatement = $pdo->prepare("
        SELECT
            id,
            order_id,
            status

        FROM requirements

        WHERE id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $requirementStatement->execute([
        $requirementId
    ]);

    $requirement = $requirementStatement->fetch(
        PDO::FETCH_ASSOC
    );

    // التحقق من وجود المطلوب
    if (!$requirement) {
        throw new Exception(
            "المطلوب غير موجود"
        );
    }

    /*
     * الاعتماد مسموح فقط بعد استلام مقدم الطلب
     * للإجراء المنفذ.
     */
    if (
        $requirement['status'] !==
        'received_by_requester'
    ) {
        throw new Exception(
            "حالة المطلوب لا تسمح بالاعتماد"
        );
    }

    /*
     * جلب آخر سجل استلام خاص بالمطلوب.
     */
    $receiptStatement = $pdo->prepare("
        SELECT
            id,
            receipt_status,
            approved_by,
            approved_at

        FROM execution_receipts

        WHERE requirement_id = ?

        ORDER BY id DESC

        LIMIT 1

        FOR UPDATE
    ");

    $receiptStatement->execute([
        $requirementId
    ]);

    $receipt = $receiptStatement->fetch(
        PDO::FETCH_ASSOC
    );

    // التحقق من وجود سجل الاستلام
    if (!$receipt) {
        throw new Exception(
            "سجل استلام مقدم الطلب غير موجود"
        );
    }

    /*
     * لا يمكن اعتماد المطلوب إلا إذا كان مقدم الطلب
     * قد استلم التنفيذ بنجاح.
     */
    if ($receipt['receipt_status'] !== 'received') {
        throw new Exception(
            "لا يمكن الاعتماد لأن مقدم الطلب لم يؤكد استلام التنفيذ"
        );
    }

    // منع اعتماد نفس المطلوب أكثر من مرة
    if (!empty($receipt['approved_by'])) {
        throw new Exception(
            "تم اعتماد هذا المطلوب مسبقًا"
        );
    }

    /*
     * تسجيل مدير التوجيه الذي قام بالاعتماد
     * وتاريخ الاعتماد.
     *
     * لا نقوم بتعديل receipt_status؛
     * لأنه يمثل قرار مقدم الطلب.
     */
    $updateReceiptStatement = $pdo->prepare("
        UPDATE execution_receipts

        SET
            approved_by = ?,
            approved_at = NOW(),
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateReceiptStatement->execute([
        $authUser['id'],
        $receipt['id']
    ]);

    // إغلاق المطلوب بعد الاعتماد
    $updateRequirementStatement = $pdo->prepare("
        UPDATE requirements

        SET
            status = 'closed',
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateRequirementStatement->execute([
        $requirementId
    ]);

    /*
     * حساب المطاليب التي لم تنتهِ بعد.
     *
     * المطلوب المغلق أو الملغي لا يدخل في العدد.
     */
    $remainingStatement = $pdo->prepare("
        SELECT COUNT(*)

        FROM requirements

        WHERE order_id = ?
          AND status NOT IN (
              'closed',
              'cancelled'
          )
    ");

    $remainingStatement->execute([
        $requirement['order_id']
    ]);

    $remainingRequirements =
        (int) $remainingStatement->fetchColumn();

    /*
     * إذا انتهت جميع مطاليب الطلب:
     * تصبح حالة الطلب مكتمل.
     *
     * إذا بقيت مطاليب:
     * تبقى حالة الطلب بانتظار الاعتماد.
     */
    if ($remainingRequirements === 0) {
        $orderStatus = 'completed';
    } else {
        $orderStatus = 'waiting_approval';
    }

    // تحديث حالة الطلب
    $updateOrderStatement = $pdo->prepare("
        UPDATE orders

        SET
            status = ?,
            updated_at = NOW()

        WHERE id = ?
          AND status <> 'cancelled'
    ");

    $updateOrderStatement->execute([
        $orderStatus,
        $requirement['order_id']
    ]);

    // تأكيد جميع التغييرات
    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم اعتماد المطلوب وإغلاقه بنجاح",
        "requirement_id" => $requirementId,
        "receipt_id" => (int) $receipt['id'],
        "approved_by" => (int) $authUser['id'],
        "requirement_status" => "closed",
        "order_status" => $orderStatus,
        "remaining_requirements" =>
            $remainingRequirements
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // التراجع عن التغييرات عند حدوث خطأ
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}