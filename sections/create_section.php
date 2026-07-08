<?php

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من المستخدم والصلاحية
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية إدارة الإعدادات
requirePermission('manage_settings');

// السماح بطلب POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        "status" => false,
        "message" => "طريقة الطلب غير مسموحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// استقبال البيانات
$data = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($data)) {
    $data = [];
}

// رقم الإدارة
$departmentId =
    (int) ($data['department_id'] ?? 0);

// اسم القسم
$name =
    trim($data['name'] ?? '');

// حالة القسم
$isActive =
    isset($data['is_active'])
        ? (int) $data['is_active']
        : 1;

// التحقق من الإدارة
if ($departmentId <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "يجب اختيار الإدارة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من الاسم
if ($name === '') {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم القسم مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// التحقق من طول الاسم
if (mb_strlen($name) > 150) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم القسم يجب ألا يتجاوز 150 حرفًا"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// تصحيح قيمة الحالة
$isActive = $isActive === 0 ? 0 : 1;

try {
    /*
     * التحقق من وجود الإدارة.
     */
    $departmentStatement = $pdo->prepare("
        SELECT
            id,
            is_active

        FROM departments

        WHERE id = ?

        LIMIT 1
    ");

    $departmentStatement->execute([
        $departmentId
    ]);

    $department = $departmentStatement->fetch();

    if (!$department) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الإدارة المحددة غير موجودة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ((int) $department['is_active'] !== 1) {
        http_response_code(422);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن إضافة قسم إلى إدارة متوقفة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * منع تكرار اسم القسم داخل نفس الإدارة.
     */
    $duplicateStatement = $pdo->prepare("
        SELECT id

        FROM sections

        WHERE department_id = ?
          AND name = ?

        LIMIT 1
    ");

    $duplicateStatement->execute([
        $departmentId,
        $name
    ]);

    if ($duplicateStatement->fetch()) {
        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "يوجد قسم بنفس الاسم داخل هذه الإدارة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * إضافة القسم.
     */
    $statement = $pdo->prepare("
        INSERT INTO sections (
            department_id,
            name,
            is_active,
            created_at,
            updated_at
        ) VALUES (
            ?,
            ?,
            ?,
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    $statement->execute([
        $departmentId,
        $name,
        $isActive
    ]);

    $sectionId = (int) $statement->fetchColumn();

    /*
     * جلب بيانات القسم بعد الإضافة.
     */
    $sectionStatement = $pdo->prepare("
        SELECT
            s.id,
            s.department_id,
            s.name,
            s.is_active,
            s.created_at,
            s.updated_at,

            d.name AS department_name,
            d.company_id,

            c.name AS company_name

        FROM sections s

        INNER JOIN departments d
            ON d.id = s.department_id

        INNER JOIN companies c
            ON c.id = d.company_id

        WHERE s.id = ?

        LIMIT 1
    ");

    $sectionStatement->execute([
        $sectionId
    ]);

    $section = $sectionStatement->fetch();

    $section['id'] =
        (int) $section['id'];

    $section['department_id'] =
        (int) $section['department_id'];

    $section['company_id'] =
        (int) $section['company_id'];

    $section['is_active'] =
        (int) $section['is_active'];

    http_response_code(201);

    echo json_encode([
        "status" => true,
        "message" => "تمت إضافة القسم بنجاح",
        "data" => [
            "section" => $section
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء إضافة القسم",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}