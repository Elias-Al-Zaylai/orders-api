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

// رقم القسم
$id =
    (int) ($data['id'] ?? 0);

// رقم الإدارة
$departmentId =
    (int) ($data['department_id'] ?? 0);

// اسم القسم
$name =
    trim($data['name'] ?? '');

// التحقق من رقم القسم
if ($id <= 0) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "رقم القسم مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

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

if (mb_strlen($name) > 150) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "message" => "اسم القسم يجب ألا يتجاوز 150 حرفًا"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    /*
     * التحقق من وجود القسم.
     */
    $existingStatement = $pdo->prepare("
        SELECT id

        FROM sections

        WHERE id = ?

        LIMIT 1
    ");

    $existingStatement->execute([
        $id
    ]);

    if (!$existingStatement->fetch()) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "القسم غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التحقق من وجود الإدارة وحالتها.
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
            "message" => "لا يمكن نقل القسم إلى إدارة متوقفة"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * منع تكرار الاسم داخل نفس الإدارة.
     */
    $duplicateStatement = $pdo->prepare("
        SELECT id

        FROM sections

        WHERE department_id = ?
          AND name = ?
          AND id <> ?

        LIMIT 1
    ");

    $duplicateStatement->execute([
        $departmentId,
        $name,
        $id
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
     * تعديل القسم.
     */
    $statement = $pdo->prepare("
        UPDATE sections

        SET
            department_id = ?,
            name = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $statement->execute([
        $departmentId,
        $name,
        $id
    ]);

    /*
     * جلب القسم بعد التعديل.
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
        $id
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

    echo json_encode([
        "status" => true,
        "message" => "تم تعديل القسم بنجاح",
        "data" => [
            "section" => $section
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تعديل القسم",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}