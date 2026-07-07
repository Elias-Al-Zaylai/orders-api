<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// ملفات المصادقة والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// السماح فقط لمن يمتلك صلاحية توجيه المطاليب
requirePermission('direct_requirement');

try {
    /*
     * جلب المنفذين المتاحين فقط.
     *
     * المنفذ لا يظهر إذا كان لديه مطلوب حالته:
     *
     * directed
     * received_by_executor
     * returned_to_executor
     */
    $statement = $pdo->prepare("
        SELECT DISTINCT
            u.id AS executor_id,
            u.name AS executor_name,
            u.username,
            u.phone,
            u.email,

            c.name AS company_name,
            d.name AS department_name,
            s.name AS section_name

        FROM users u

        INNER JOIN user_roles ur
            ON ur.user_id = u.id

        INNER JOIN role_permissions rp
            ON rp.role_id = ur.role_id

        INNER JOIN permissions p
            ON p.id = rp.permission_id

        LEFT JOIN companies c
            ON c.id = u.company_id

        LEFT JOIN departments d
            ON d.id = u.department_id

        LEFT JOIN sections s
            ON s.id = u.section_id

        WHERE u.is_active = TRUE
          AND p.permission_key = 'execute_requirement'

          AND NOT EXISTS (
              SELECT 1

              FROM requirement_directions rd

              INNER JOIN requirements r
                  ON r.id = rd.requirement_id

              WHERE rd.executor_id = u.id
                AND r.status IN (
                    'directed',
                    'received_by_executor',
                    'returned_to_executor'
                )
          )

        ORDER BY u.name ASC
    ");

    $statement->execute();

    $executors = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    // تحويل رقم المنفذ إلى رقم صحيح
    foreach ($executors as &$executor) {
        $executor['executor_id'] =
            (int) $executor['executor_id'];
    }

    unset($executor);

    echo json_encode([
        "status" => true,
        "message" => "تم جلب المنفذين المتاحين بنجاح",
        "data" => $executors
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل جلب قائمة المنفذين",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}