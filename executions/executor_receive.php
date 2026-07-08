<?php

/*
|--------------------------------------------------------------------------
| استلام المطلوب بواسطة المنفّذ
|--------------------------------------------------------------------------
|
| يحوّل حالة المطلوب من:
| directed
|
| إلى:
| received_by_executor
|
*/

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول
require_once __DIR__ . '/../middleware/auth.php';

// التحقق من الصلاحيات
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/order_status_helper.php';

// يجب أن يمتلك المستخدم صلاحية استلام المطلوب
requirePermission('receive_requirement');


/*
|--------------------------------------------------------------------------
| استقبال البيانات
|--------------------------------------------------------------------------
*/

$data = json_decode(
    file_get_contents("php://input"),
    true
);

// التحقق من أن البيانات JSON صحيحة
if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// رقم المطلوب
$requirementId = (int) ($data['requirement_id'] ?? 0);

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

    /*
    |--------------------------------------------------------------------------
    | بدء المعاملة
    |--------------------------------------------------------------------------
    |
    | تمنع المعاملة استلام المطلوب مرتين في الوقت نفسه.
    |
    */

    $pdo->beginTransaction();

    // رقم المستخدم المسجل حاليًا
    $executorId = (int) $authUser['id'];


    /*
    |--------------------------------------------------------------------------
    | جلب المطلوب مع آخر توجيه
    |--------------------------------------------------------------------------
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
            r.status,

            rd.id AS direction_id,
            rd.executor_id,
            rd.notes_to_executor,
            rd.allowed_start,
            rd.allowed_end,
            rd.created_at AS directed_at

        FROM requirements r

        INNER JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        WHERE r.id = ?

        ORDER BY rd.id DESC

        LIMIT 1

        FOR UPDATE
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

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المطلوب غير موجود أو لم يتم توجيهه"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | التحقق أن آخر توجيه للمنفّذ الحالي
    |--------------------------------------------------------------------------
    */

    if ((int) $requirement['executor_id'] !== $executorId) {

        $pdo->rollBack();

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "هذا المطلوب غير موجّه إليك"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | التحقق من حالة المطلوب
    |--------------------------------------------------------------------------
    |
    | يسمح بالاستلام فقط عندما تكون الحالة directed.
    |
    */

    if ($requirement['status'] !== 'directed') {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن استلام المطلوب في حالته الحالية",
            "current_status" => $requirement['status']
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | تحديث حالة المطلوب
    |--------------------------------------------------------------------------
    */

    $updateStatement = $pdo->prepare("
        UPDATE requirements

        SET
            status = 'received_by_executor',
            updated_at = NOW()

        WHERE id = ?
          AND status = 'directed'
    ");

    $updateStatement->execute([
        $requirementId
    ]);


    /*
    |--------------------------------------------------------------------------
    | التأكد من تنفيذ التحديث
    |--------------------------------------------------------------------------
    */

    if ($updateStatement->rowCount() !== 1) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "تعذر استلام المطلوب، ربما تم تحديث حالته مسبقًا"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    // تحديث حالة الطلب تلقائيًا بعد استلام المنفذ
    $newOrderStatus = updateOrderStatus($pdo, (int) $requirement['order_id']);

    /*
    |--------------------------------------------------------------------------
    | تجهيز بيانات سجل النشاط
    |--------------------------------------------------------------------------
    */

    $oldData = json_encode([
        "status" => "directed"
    ], JSON_UNESCAPED_UNICODE);

    $newData = json_encode([
        "status" => "received_by_executor",
        "executor_id" => $executorId
    ], JSON_UNESCAPED_UNICODE);

    // عنوان الجهاز
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    if ($ipAddress !== null) {
        $ipAddress = substr($ipAddress, 0, 50);
    }

    // معلومات الجهاز والمتصفح
    $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if ($deviceInfo !== null) {
        $deviceInfo = substr($deviceInfo, 0, 255);
    }


    /*
    |--------------------------------------------------------------------------
    | تسجيل العملية في activity_logs
    |--------------------------------------------------------------------------
    */

    $logStatement = $pdo->prepare("
        INSERT INTO activity_logs (
            user_id,
            action,
            table_name,
            record_id,
            old_data,
            new_data,
            ip_address,
            device_info,
            created_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $logStatement->execute([
        $executorId,
        'receive_requirement',
        'requirements',
        $requirementId,
        $oldData,
        $newData,
        $ipAddress,
        $deviceInfo
    ]);


    /*
    |--------------------------------------------------------------------------
    | اعتماد العملية
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | الاستجابة الناجحة
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "status" => true,
        "message" => "تم استلام المطلوب بنجاح",

        "data" => [
            "requirement_id" =>
                (int) $requirement['requirement_id'],

            "order_id" =>
                (int) $requirement['order_id'],

            "direction_id" =>
                (int) $requirement['direction_id'],

            "requirement_title" =>
                $requirement['requirement_title'],

            "old_status" =>
                "directed",

            "status" =>
                "received_by_executor",

            "status_name" =>
                "استلمه المنفّذ",

            "order_status" =>
                $newOrderStatus
        ]
    ], JSON_UNESCAPED_UNICODE);


} catch (PDOException $exception) {

    // التراجع عن العملية عند حدوث خطأ
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // تسجيل الخطأ في سجل PHP
    error_log(
        'executor_receive.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء استلام المطلوب",

        // احذف error بعد التأكد أن الملف يعمل
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);


} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'executor_receive.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ غير متوقع أثناء استلام المطلوب",

        // احذف error بعد التأكد أن الملف يعمل
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}