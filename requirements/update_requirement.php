<?php

// نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية تعديل المطلوب
requirePermission('update_requirement');

// قراءة البيانات القادمة من Flutter
$data = json_decode(
    file_get_contents("php://input"),
    true
);

// التحقق من صحة JSON
if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// رقم المطلوب
$requirementId = (int) (
    $data['requirement_id']
    ?? $data['id']
    ?? 0
);

// نص المطلوب
$requirementText = trim(
    (string) (
        $data['requirement']
        ?? $data['title']
        ?? ''
    )
);

// وصف المشكلة
$problem = trim(
    (string) ($data['problem'] ?? '')
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

// التحقق من نص المطلوب
if ($requirementText === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "نص المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من وصف المشكلة
if ($problem === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "وصف المشكلة مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // بدء المعاملة
    $pdo->beginTransaction();

    /*
     * جلب المطلوب وصاحب الطلب.
     */
    $requirementStatement = $pdo->prepare("
        SELECT
            r.id,
            r.order_id,
            r.status,
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

    $requirement =
        $requirementStatement->fetch();

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
     * التحقق هل المستخدم مدير نظام.
     */
    $adminStatement = $pdo->prepare("
        SELECT
            ur.id

        FROM user_roles ur

        INNER JOIN roles r
            ON r.id = ur.role_id

        WHERE ur.user_id = ?
        AND r.name = 'admin'
        AND r.is_active = TRUE

        LIMIT 1
    ");

    $adminStatement->execute([
        (int) $authUser['id']
    ]);

    $isAdmin =
        (bool) $adminStatement->fetch();

    // هل المستخدم هو مقدم الطلب؟
    $isRequester =
        (int) $requirement['requester_id']
        ===
        (int) $authUser['id'];

    /*
     * يسمح فقط لمدير النظام
     * أو مقدم الطلب صاحب المطلوب.
     */
    if (!$isAdmin && !$isRequester) {
        $pdo->rollBack();

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "غير مسموح لك بتعديل هذا المطلوب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * مقدم الطلب يعدل المطلوب فقط
     * عندما تكون حالته new.
     */
    if (
        !$isAdmin
        &&
        $requirement['status'] !== 'new'
    ) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن تعديل المطلوب بعد توجيهه"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * تحديث المطلوب.
     */
    $updateStatement = $pdo->prepare("
        UPDATE requirements

        SET
            requirement = ?,
            problem = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateStatement->execute([
        $requirementText,
        $problem,
        $requirementId
    ]);

    // تثبيت التعديل
    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم تعديل المطلوب بنجاح",
        "data" => [
            "requirement_id" => $requirementId,
            "order_id" => (int) $requirement['order_id'],
            "requirement" => $requirementText,
            "problem" => $problem
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // تسجيل الخطأ في سجل PHP بدل إرساله للمستخدم
    error_log(
        "Update requirement error: "
        . $error->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تعديل المطلوب"
    ], JSON_UNESCAPED_UNICODE);
}