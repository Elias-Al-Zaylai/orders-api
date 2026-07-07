<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

requirePermission('create_order');

/*
المستخدم الحالي موجود في:
$authUser
*/

$data = json_decode(file_get_contents("php://input"), true);

$document_number = trim($data['document_number'] ?? '');
$to_company_id = $data['to_company_id'] ?? null;
$to_department_id = $data['to_department_id'] ?? null;
$to_section_id = $data['to_section_id'] ?? null;
$request_type_id = $data['request_type_id'] ?? null;
$statement = trim($data['statement'] ?? '');
$notes = trim($data['notes'] ?? '');

if (
    empty($to_company_id) ||
    empty($to_department_id) ||
    empty($request_type_id) ||
    empty($statement)
) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المطلوبة ناقصة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
توليد رقم الطلب
ORD-2026-00001
*/

$year = date('Y');

$stmt = $pdo->query("
    SELECT COUNT(*) as total
    FROM orders
");

$count = $stmt->fetch();

$nextNumber = $count['total'] + 1;

$orderNumber =
    'ORD-' .
    $year .
    '-' .
    str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

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
    VALUES (
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        NOW(), NOW()
    )
    RETURNING id
");

$insert->execute([
    $orderNumber,
    $document_number,
    $authUser['id'],

    $authUser['company_id'],
    $authUser['department_id'],
    $authUser['section_id'],

    $to_company_id,
    $to_department_id,
    $to_section_id,

    $request_type_id,
    $statement,
    $notes,
    'submitted'
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

/*
إشعار لمقدم الطلب
*/
$notify->execute([
    $authUser['id'],
    'تم إنشاء طلب جديد',
    'تم إنشاء طلبك رقم ' . $orderNumber . ' بنجاح'
]);

/*
إشعار لمدير التوجيه / كل من لديه صلاحية توجيه المطلوب
*/
$directionManagers = $pdo->prepare("
    SELECT DISTINCT u.id
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
    INNER JOIN permissions p ON p.id = rp.permission_id
    WHERE p.permission_key = 'direct_requirement'
    AND u.is_active = TRUE
");

$directionManagers->execute();

$managers = $directionManagers->fetchAll();

foreach ($managers as $manager) {
    $notify->execute([
        $manager['id'],
        'طلب جديد يحتاج توجيه',
        'لديك طلب جديد رقم ' . $orderNumber . ' يحتاج إلى توجيه المطاليب.'
    ]);
}

echo json_encode([
    "status" => true,
    "message" => "تم إنشاء الطلب بنجاح",
    "order_id" => $orderId,
    "order_number" => $orderNumber
], JSON_UNESCAPED_UNICODE);