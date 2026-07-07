<?php

// تحديد نوع الاستجابة بصيغة JSON مع دعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// استخدام ملفات تسجيل الدخول والصلاحيات الموجودة مسبقًا
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// السماح فقط للمستخدم الذي يمتلك صلاحية توجيه المطاليب
requirePermission('direct_requirement');

try {

    // تجهيز استعلام جلب الطلبات الواردة
    $stmt = $pdo->prepare("
        SELECT
            o.id AS order_id,
            o.order_number,
            o.document_number,
            o.statement,
            o.notes,
            o.status AS order_status,
            o.created_at AS order_created_at,

            requester.name AS requester_name,

            rt.name AS request_type_name,

            from_company.name AS from_company_name,
            from_department.name AS from_department_name,

            to_company.name AS to_company_name,
            to_department.name AS to_department_name,

            (
                SELECT COUNT(*)
                FROM requirements r
                WHERE r.order_id = o.id
                  AND r.status = 'new'
            ) AS new_requirements_count

        FROM orders o

        INNER JOIN users requester
            ON requester.id = o.requester_id

        LEFT JOIN request_types rt
            ON rt.id = o.request_type_id

        LEFT JOIN companies from_company
            ON from_company.id = o.from_company_id

        LEFT JOIN departments from_department
            ON from_department.id = o.from_department_id

        LEFT JOIN companies to_company
            ON to_company.id = o.to_company_id

        LEFT JOIN departments to_department
            ON to_department.id = o.to_department_id

        WHERE o.status = 'submitted'

          AND EXISTS (
              SELECT 1
              FROM requirements r
              WHERE r.order_id = o.id
                AND r.status = 'new'
          )

        ORDER BY o.created_at DESC
    ");

    // تنفيذ الاستعلام
    $stmt->execute();

    // جلب جميع الطلبات
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // إرسال البيانات إلى تطبيق Flutter
    echo json_encode([
        "status" => true,
        "message" => "تم جلب الطلبات الواردة بنجاح",
        "count" => count($orders),
        "data" => $orders
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    // تحديد أن الخطأ حدث داخل السيرفر
    http_response_code(500);

    // إرسال رسالة الخطأ
    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب الطلبات الواردة",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}