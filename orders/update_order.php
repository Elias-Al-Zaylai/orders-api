<?php

// نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية تعديل الطلب
requirePermission('edit_order');

// قراءة بيانات JSON
$data = json_decode(
    file_get_contents("php://input"),
    true
);

// التحقق من صحة البيانات المرسلة
if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// رقم الطلب
$orderId = (int) ($data['order_id'] ?? 0);

// رقم المعاملة اختياري
$documentNumber = trim(
    (string) ($data['document_number'] ?? '')
);

// الشركة المستفيدة
$toCompanyId = (int) (
    $data['to_company_id'] ?? 0
);

// الإدارة المستفيدة
$toDepartmentId = (int) (
    $data['to_department_id'] ?? 0
);

// القسم اختياري
$toSectionId = isset($data['to_section_id'])
    ? (int) $data['to_section_id']
    : null;

if ($toSectionId !== null && $toSectionId <= 0) {
    $toSectionId = null;
}

// نوع الطلب
$requestTypeId = (int) (
    $data['request_type_id'] ?? 0
);

// البيان
$statement = trim(
    (string) ($data['statement'] ?? '')
);

// الملاحظات اختيارية
$notes = trim(
    (string) ($data['notes'] ?? '')
);

// التحقق من رقم الطلب
if ($orderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من الشركة
if ($toCompanyId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "الشركة مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من الإدارة
if ($toDepartmentId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "الإدارة مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من نوع الطلب
if ($requestTypeId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "نوع الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من البيان
if ($statement === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "بيان الطلب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    // بدء المعاملة
    $pdo->beginTransaction();

    /*
     * جلب الطلب وقفل السجل أثناء التعديل.
     */
    $orderStatement = $pdo->prepare("
        SELECT
            id,
            requester_id,
            status

        FROM orders

        WHERE id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $orderStatement->execute([
        $orderId
    ]);

    $order = $orderStatement->fetch();

    // التحقق من وجود الطلب
    if (!$order) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الطلب غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق هل المستخدم مدير نظام.
     *
     * النظام يعتمد على جدول user_roles
     * ولا يعتمد على users.role_id.
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
        (int) $order['requester_id']
        ===
        (int) $authUser['id'];

    /*
     * يسمح فقط:
     * - لمدير النظام.
     * - أو مقدم الطلب صاحب الطلب.
     */
    if (!$isAdmin && !$isRequester) {
        $pdo->rollBack();

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "غير مسموح لك بتعديل هذا الطلب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * مقدم الطلب يستطيع تعديل الطلب
     * فقط عندما تكون حالته submitted.
     */
    if (
        !$isAdmin
        &&
        $order['status'] !== 'submitted'
    ) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن تعديل الطلب بعد بدء توجيه المطاليب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق أن الشركة موجودة ومفعلة.
     */
    $companyStatement = $pdo->prepare("
        SELECT id

        FROM companies

        WHERE id = ?
        AND is_active = TRUE

        LIMIT 1
    ");

    $companyStatement->execute([
        $toCompanyId
    ]);

    if (!$companyStatement->fetch()) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "الشركة المحددة غير موجودة أو غير مفعلة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق أن الإدارة تابعة للشركة المحددة.
     */
    $departmentStatement = $pdo->prepare("
        SELECT id

        FROM departments

        WHERE id = ?
        AND company_id = ?
        AND is_active = TRUE

        LIMIT 1
    ");

    $departmentStatement->execute([
        $toDepartmentId,
        $toCompanyId
    ]);

    if (!$departmentStatement->fetch()) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "الإدارة المحددة لا تتبع الشركة المختارة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق من نوع الطلب.
     */
    $requestTypeStatement = $pdo->prepare("
        SELECT id

        FROM request_types

        WHERE id = ?
        AND is_active = TRUE

        LIMIT 1
    ");

    $requestTypeStatement->execute([
        $requestTypeId
    ]);

    if (!$requestTypeStatement->fetch()) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "نوع الطلب غير موجود أو غير مفعل"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * تحديث الطلب.
     */
    $updateStatement = $pdo->prepare("
        UPDATE orders

        SET
            document_number = ?,
            to_company_id = ?,
            to_department_id = ?,
            to_section_id = ?,
            request_type_id = ?,
            statement = ?,
            notes = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $updateStatement->execute([
        $documentNumber !== ''
            ? $documentNumber
            : null,

        $toCompanyId,
        $toDepartmentId,
        $toSectionId,
        $requestTypeId,
        $statement,

        $notes !== ''
            ? $notes
            : null,

        $orderId
    ]);

    // تثبيت التعديل
    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم تعديل الطلب بنجاح",
        "data" => [
            "order_id" => $orderId,
            "document_number" => $documentNumber,
            "to_company_id" => $toCompanyId,
            "to_department_id" => $toDepartmentId,
            "request_type_id" => $requestTypeId,
            "statement" => $statement,
            "notes" => $notes
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        "Update order error: "
        . $error->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تعديل الطلب"
    ], JSON_UNESCAPED_UNICODE);
}