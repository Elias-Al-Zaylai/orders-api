<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية إنشاء طلب
requirePermission('create_order');

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$documentNumber = trim((string) ($data['document_number'] ?? ''));
$toCompanyId = (int) ($data['to_company_id'] ?? 0);
$toDepartmentId = (int) ($data['to_department_id'] ?? 0);
$toSectionId = isset($data['to_section_id']) && $data['to_section_id'] !== ''
    ? (int) $data['to_section_id']
    : null;
$requestTypeId = (int) ($data['request_type_id'] ?? 0);
$statement = trim((string) ($data['statement'] ?? ''));
$notes = trim((string) ($data['notes'] ?? ''));

if ($toSectionId !== null && $toSectionId <= 0) {
    $toSectionId = null;
}

if ($toCompanyId <= 0 || $toDepartmentId <= 0 || $requestTypeId <= 0 || $statement === '') {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "الشركة والإدارة ونوع الطلب والبيان مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * التأكد أن الشركة المستقبلة مفعلة.
     */
    $companyStatement = $pdo->prepare(" 
        SELECT id
        FROM companies
        WHERE id = ?
          AND is_active = TRUE
        LIMIT 1
    ");

    $companyStatement->execute([$toCompanyId]);

    if (!$companyStatement->fetchColumn()) {
        throw new Exception("الشركة المستقبلة غير موجودة أو غير مفعلة");
    }

    /*
     * التأكد أن الإدارة تابعة للشركة المختارة ومفعلة.
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

    if (!$departmentStatement->fetchColumn()) {
        throw new Exception("الإدارة المستقبلة لا تتبع الشركة المختارة أو غير مفعلة");
    }

    /*
     * إذا تم إرسال قسم، نتأكد أنه يتبع الإدارة المستقبلة.
     */
    if ($toSectionId !== null) {
        $sectionStatement = $pdo->prepare(" 
            SELECT id
            FROM sections
            WHERE id = ?
              AND department_id = ?
              AND is_active = TRUE
            LIMIT 1
        ");

        $sectionStatement->execute([
            $toSectionId,
            $toDepartmentId
        ]);

        if (!$sectionStatement->fetchColumn()) {
            throw new Exception("القسم المستفيد لا يتبع الإدارة المختارة أو غير مفعل");
        }
    }

    /*
     * التأكد أن نوع الطلب مفعل.
     */
    $typeStatement = $pdo->prepare(" 
        SELECT id
        FROM request_types
        WHERE id = ?
          AND is_active = TRUE
        LIMIT 1
    ");

    $typeStatement->execute([$requestTypeId]);

    if (!$typeStatement->fetchColumn()) {
        throw new Exception("نوع الطلب غير موجود أو غير مفعل");
    }

    /*
     * توليد رقم طلب واضح.
     * ملاحظة: الرقم للعرض فقط، والاعتماد الحقيقي على id.
     */
    $year = date('Y');
    $counterStatement = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM orders");
    $nextNumber = (int) $counterStatement->fetchColumn();

    $orderNumber =
        'ORD-' .
        $year .
        '-' .
        str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);

    $insert = $pdo->prepare(" 
        INSERT INTO orders (
            order_number,
            document_number,
            requester_id,
            from_company_id,
            from_department_id,
            from_section_id,
            to_company_id,
            to_department_id,
            to_section_id,
            request_type_id,
            statement,
            notes,
            status,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW(), NOW())
        RETURNING id
    ");

    $insert->execute([
        $orderNumber,
        $documentNumber !== '' ? $documentNumber : null,
        (int) $authUser['id'],
        $authUser['company_id'] ?? null,
        $authUser['department_id'] ?? null,
        $authUser['section_id'] ?? null,
        $toCompanyId,
        $toDepartmentId,
        $toSectionId,
        $requestTypeId,
        $statement,
        $notes !== '' ? $notes : null
    ]);

    $orderId = (int) $insert->fetchColumn();

    $notify = $pdo->prepare(" 
        INSERT INTO notifications (
            user_id,
            title,
            message,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, FALSE, NOW())
    ");

    // إشعار لمقدم الطلب نفسه
    $notify->execute([
        (int) $authUser['id'],
        'تم إنشاء طلب جديد',
        'تم إنشاء طلبك رقم ' . $orderNumber . ' بنجاح.'
    ]);

    /*
     * إشعار لموجهي الإدارة المستقبلة فقط.
     * مدير النظام يستثنى ويستقبل أيضًا حتى يستطيع المتابعة.
     */
    $directionManagers = $pdo->prepare(" 
        SELECT DISTINCT u.id
        FROM users u
        INNER JOIN user_roles ur ON ur.user_id = u.id
        INNER JOIN roles role_item ON role_item.id = ur.role_id
        INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE u.is_active = TRUE
          AND p.permission_key = 'direct_requirement'
          AND (
              u.department_id = ?
              OR role_item.name = 'admin'
          )
    ");

    $directionManagers->execute([$toDepartmentId]);
    $managers = $directionManagers->fetchAll(PDO::FETCH_ASSOC);

    foreach ($managers as $manager) {
        $notify->execute([
            (int) $manager['id'],
            'طلب جديد يحتاج توجيه',
            'لديك طلب جديد رقم ' . $orderNumber . ' مرسل إلى إدارتك ويحتاج إلى توجيه المطاليب.'
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم إنشاء الطلب بنجاح",
        "order_id" => $orderId,
        "order_number" => $orderNumber
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
