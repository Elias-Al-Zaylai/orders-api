<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

// صلاحية اعتماد استلام الإجراءات
requirePermission('approve_receipt');

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$requirementId = (int) ($data['requirement_id'] ?? 0);
$approvalNotes = trim((string) ($data['approval_notes'] ?? $data['notes'] ?? ''));

if ($requirementId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * جلب المطلوب مع إدارة الطلب المستقبلة.
     * الموجّه يعتمد فقط مطاليب إدارته، والمدير مستثنى.
     */
    $requirementStatement = $pdo->prepare(" 
        SELECT
            r.id,
            r.order_id,
            r.status,
            o.to_department_id
        FROM requirements r
        INNER JOIN orders o ON o.id = r.order_id
        WHERE r.id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $requirementStatement->execute([$requirementId]);
    $requirement = $requirementStatement->fetch(PDO::FETCH_ASSOC);

    if (!$requirement) {
        throw new Exception("المطلوب غير موجود");
    }

    if (
        !authUserHasRole('admin')
        && (int) ($requirement['to_department_id'] ?? 0) !== (int) ($authUser['department_id'] ?? 0)
    ) {
        throw new Exception("ليس لديك صلاحية اعتماد هذا المطلوب");
    }

    if ($requirement['status'] !== 'received_by_requester') {
        throw new Exception("حالة المطلوب لا تسمح بالاعتماد");
    }

    /*
     * يجب أن يكون مقدم الطلب قد استلم الإجراء فعليًا.
     */
    $receiptStatement = $pdo->prepare(" 
        SELECT
            id,
            requirement_id,
            action_id,
            requester_id,
            receipt_status,
            received_at
        FROM execution_receipts
        WHERE requirement_id = ?
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $receiptStatement->execute([$requirementId]);
    $receipt = $receiptStatement->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        throw new Exception("سجل استلام مقدم الطلب غير موجود");
    }

    if ($receipt['receipt_status'] !== 'received') {
        throw new Exception("لا يمكن الاعتماد لأن مقدم الطلب لم يؤكد الاستلام");
    }

    /*
     * منع تكرار الاعتماد لنفس المطلوب.
     */
    $approvalCheck = $pdo->prepare(" 
        SELECT id
        FROM receipt_approvals
        WHERE requirement_id = ?
          AND approval_status = 'approved'
        LIMIT 1
        FOR UPDATE
    ");

    $approvalCheck->execute([$requirementId]);

    if ($approvalCheck->fetchColumn()) {
        throw new Exception("تم اعتماد هذا المطلوب مسبقًا");
    }

    /*
     * تسجيل الاعتماد في جدول الاعتمادات المخصص.
     */
    $insertApproval = $pdo->prepare(" 
        INSERT INTO receipt_approvals (
            requirement_id,
            approved_by_user_id,
            approval_status,
            approval_notes,
            approved_at,
            created_at,
            updated_at
        )
        VALUES (?, ?, 'approved', ?, NOW(), NOW(), NOW())
        RETURNING id
    ");

    $insertApproval->execute([
        $requirementId,
        (int) $authUser['id'],
        $approvalNotes !== '' ? $approvalNotes : null
    ]);

    $approvalId = (int) $insertApproval->fetchColumn();

    // إغلاق المطلوب بعد الاعتماد
    $updateRequirement = $pdo->prepare(" 
        UPDATE requirements
        SET status = 'closed',
            updated_at = NOW()
        WHERE id = ?
          AND status = 'received_by_requester'
    ");

    $updateRequirement->execute([$requirementId]);

    if ($updateRequirement->rowCount() !== 1) {
        throw new Exception("تعذر إغلاق المطلوب");
    }

    $orderStatus = updateOrderStatus($pdo, (int) $requirement['order_id']);

    $remainingStatement = $pdo->prepare(" 
        SELECT COUNT(*)
        FROM requirements
        WHERE order_id = ?
          AND status NOT IN ('closed', 'cancelled')
    ");

    $remainingStatement->execute([(int) $requirement['order_id']]);
    $remainingRequirements = (int) $remainingStatement->fetchColumn();

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم اعتماد المطلوب وإغلاقه بنجاح",
        "approval_id" => $approvalId,
        "requirement_id" => $requirementId,
        "receipt_id" => (int) $receipt['id'],
        "approved_by" => (int) $authUser['id'],
        "requirement_status" => "closed",
        "order_status" => $orderStatus,
        "remaining_requirements" => $remainingRequirements
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
