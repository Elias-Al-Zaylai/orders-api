<?php

/*
|--------------------------------------------------------------------------
| جلب تفاصيل المطلوب للمنفّذ
|--------------------------------------------------------------------------
|
| يجلب:
| - بيانات الطلب.
| - بيانات المطلوب.
| - نوع الطلب.
| - الشركة والإدارة الطالبة.
| - بيانات آخر توجيه.
| - التنفيذ السابق إن وجد.
| - الأزرار المتاحة حسب حالة المطلوب.
|
*/

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول
require_once __DIR__ . '/../middleware/auth.php';

// التحقق من الصلاحيات
require_once __DIR__ . '/../middleware/permission.php';

// الشاشة خاصة بمن لديه صلاحية تنفيذ المطلوب
requirePermission('execute_requirement');


/*
|--------------------------------------------------------------------------
| استقبال رقم المطلوب
|--------------------------------------------------------------------------
|
| مثال:
| executor_requirement_details.php?id=25
|
*/

$requirementId = (int) ($_GET['id'] ?? 0);

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

    // رقم المنفّذ المسجل حاليًا
    $executorId = (int) $authUser['id'];


    /*
    |--------------------------------------------------------------------------
    | جلب تفاصيل المطلوب وآخر توجيه
    |--------------------------------------------------------------------------
    |
    | اسم عمود نص المطلوب هو requirement.
    |
    | جدول requirement_directions لا يحتوي directed_by،
    | لذلك لم نستخدم هذا العمود.
    |
    */

    $statement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.requirement AS requirement_title,
            r.problem,
            r.status AS requirement_status,
            r.created_at AS requirement_created_at,
            r.updated_at AS requirement_updated_at,

            o.order_number,
            o.document_number,
            o.statement AS order_statement,
            o.notes AS order_notes,
            o.status AS order_status,
            o.created_at AS order_created_at,

            rt.id AS request_type_id,
            rt.name AS request_type_name,

            c.id AS company_id,
            c.name AS company_name,

            d.id AS department_id,
            d.name AS department_name,

            rd.id AS direction_id,
            rd.executor_id,
            rd.notes_to_executor,
            rd.allowed_start,
            rd.allowed_end,
            rd.created_at AS directed_at

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        INNER JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        LEFT JOIN request_types rt
            ON rt.id = o.request_type_id

        LEFT JOIN companies c
            ON c.id = o.from_company_id

        LEFT JOIN departments d
            ON d.id = o.from_department_id

        WHERE r.id = ?

        ORDER BY rd.id DESC

        LIMIT 1
    ");

    $statement->execute([
        $requirementId
    ]);

    $requirement = $statement->fetch();


    /*
    |--------------------------------------------------------------------------
    | التحقق من وجود المطلوب
    |--------------------------------------------------------------------------
    */

    if (!$requirement) {

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المطلوب غير موجود أو لم يتم توجيهه"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | التحقق أن المطلوب موجّه للمنفّذ الحالي
    |--------------------------------------------------------------------------
    */

    if ((int) $requirement['executor_id'] !== $executorId) {

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "هذا المطلوب غير موجّه إليك"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | ترجمة حالات المطلوب
    |--------------------------------------------------------------------------
    */

    $statusNames = [
        "new" =>
            "جديد",

        "directed" =>
            "موجّه",

        "received_by_executor" =>
            "استلمه المنفّذ",

        "action_done" =>
            "تم التنفيذ",

        "received_by_requester" =>
            "استلمه مقدم الطلب",

        "returned_to_executor" =>
            "معاد للمنفّذ",

        "closed" =>
            "مغلق",

        "cancelled" =>
            "ملغي"
    ];

    $requirementStatus =
        $requirement['requirement_status'];

    $requirementStatusName =
        $statusNames[$requirementStatus]
        ?? $requirementStatus;


    /*
    |--------------------------------------------------------------------------
    | تحديد العمليات المتاحة حسب الحالة
    |--------------------------------------------------------------------------
    */

    $availableActions = [
        // يظهر زر استلام المطلوب
        "can_receive" =>
            $requirementStatus === 'directed',

        // يظهر زر إضافة التنفيذ
        "can_create_action" =>
            $requirementStatus === 'received_by_executor',

        // يظهر زر تعديل التنفيذ
        "can_update_action" =>
            $requirementStatus === 'returned_to_executor'
    ];


    /*
    |--------------------------------------------------------------------------
    | جلب التنفيذ السابق إن وجد
    |--------------------------------------------------------------------------
    */

    $actionStatement = $pdo->prepare("
        SELECT
            id,
            requirement_id,
            executor_id,
            action_taken,
            recommendation,
            notes,
            action_start_date,
            action_end_date,
            created_at,
            updated_at

        FROM requirement_actions

        WHERE requirement_id = ?

        ORDER BY id DESC

        LIMIT 1
    ");

    $actionStatement->execute([
        $requirementId
    ]);

    $action = $actionStatement->fetch();


    /*
    |--------------------------------------------------------------------------
    | تجهيز بيانات التنفيذ السابق
    |--------------------------------------------------------------------------
    */

    $previousAction = null;

    if ($action) {

        $previousAction = [
            "action_id" =>
                (int) $action['id'],

            "requirement_id" =>
                (int) $action['requirement_id'],

            "executor_id" =>
                (int) $action['executor_id'],

            "action_taken" =>
                $action['action_taken'],

            "recommendation" =>
                $action['recommendation'],

            "notes" =>
                $action['notes'],

            "action_start_date" =>
                $action['action_start_date'],

            "action_end_date" =>
                $action['action_end_date'],

            "created_at" =>
                $action['created_at'],

            "updated_at" =>
                $action['updated_at']
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | إرسال تفاصيل المطلوب
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "status" => true,
        "message" => "تم جلب تفاصيل المطلوب بنجاح",

        "data" => [

            "requirement" => [

                /*
                 * أرقام الطلب والمطلوب
                 */
                "requirement_id" =>
                    (int) $requirement['requirement_id'],

                "order_id" =>
                    (int) $requirement['order_id'],

                "order_number" =>
                    $requirement['order_number'],

                "document_number" =>
                    $requirement['document_number'],


                /*
                 * نوع الطلب
                 */
                "request_type_id" =>
                    $requirement['request_type_id'] !== null
                        ? (int) $requirement['request_type_id']
                        : null,

                "request_type_name" =>
                    $requirement['request_type_name'],


                /*
                 * بيانات الطلب
                 */
                "order_statement" =>
                    $requirement['order_statement'],

                "order_notes" =>
                    $requirement['order_notes'],

                "order_status" =>
                    $requirement['order_status'],

                "order_created_at" =>
                    $requirement['order_created_at'],


                /*
                 * بيانات المطلوب
                 */
                "requirement_title" =>
                    $requirement['requirement_title'],

                "problem" =>
                    $requirement['problem'],


                /*
                 * الجهة الطالبة
                 */
                "company_id" =>
                    $requirement['company_id'] !== null
                        ? (int) $requirement['company_id']
                        : null,

                "company_name" =>
                    $requirement['company_name'],

                "department_id" =>
                    $requirement['department_id'] !== null
                        ? (int) $requirement['department_id']
                        : null,

                "department_name" =>
                    $requirement['department_name'],


                /*
                 * بيانات آخر توجيه
                 */
                "direction_id" =>
                    (int) $requirement['direction_id'],

                "executor_id" =>
                    (int) $requirement['executor_id'],

                "notes_to_executor" =>
                    $requirement['notes_to_executor'],

                "allowed_start" =>
                    $requirement['allowed_start'],

                "allowed_end" =>
                    $requirement['allowed_end'],

                "directed_at" =>
                    $requirement['directed_at'],


                /*
                 * حالة المطلوب
                 */
                "status" =>
                    $requirementStatus,

                "status_name" =>
                    $requirementStatusName,


                /*
                 * تواريخ المطلوب
                 */
                "created_at" =>
                    $requirement['requirement_created_at'],

                "updated_at" =>
                    $requirement['requirement_updated_at'],


                /*
                 * العمليات المتاحة للمنفّذ
                 */
                "available_actions" =>
                    $availableActions
            ],


            /*
             * التنفيذ السابق
             *
             * إذا لم يوجد تنفيذ ستكون القيمة null.
             */
            "previous_action" =>
                $previousAction
        ]
    ], JSON_UNESCAPED_UNICODE);


} catch (PDOException $exception) {

    error_log(
        'executor_requirement_details.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب تفاصيل المطلوب",

        // احذف error بعد التأكد أن الملف يعمل
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);


} catch (Throwable $exception) {

    error_log(
        'executor_requirement_details.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ غير متوقع أثناء جلب تفاصيل المطلوب",

        // احذف error بعد التأكد أن الملف يعمل
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}