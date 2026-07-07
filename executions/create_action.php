<?php

/*
|--------------------------------------------------------------------------
| تسجيل إجراء تنفيذ المطلوب
|--------------------------------------------------------------------------
|
| يسمح للمنفّذ بتسجيل الإجراء بعد استلام المطلوب.
|
| الحالة قبل العملية:
| received_by_executor
|
| الحالة بعد العملية:
| action_done
|
*/

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول
require_once __DIR__ . '/../middleware/auth.php';

// التحقق من الصلاحيات
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية تنفيذ المطلوب
requirePermission('execute_requirement');


/*
|--------------------------------------------------------------------------
| استقبال البيانات
|--------------------------------------------------------------------------
*/

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
$requirementId =
    (int) ($data['requirement_id'] ?? 0);

// الإجراء الذي تم تنفيذه
$actionTaken =
    trim($data['action_taken'] ?? '');

// التوصيات اختيارية
$recommendation =
    trim($data['recommendation'] ?? '');

// الملاحظات اختيارية
$notes =
    trim($data['notes'] ?? '');

// بداية التنفيذ
$actionStartDate =
    trim($data['action_start_date'] ?? '');

// نهاية التنفيذ
$actionEndDate =
    trim($data['action_end_date'] ?? '');


/*
|--------------------------------------------------------------------------
| التحقق من الحقول المطلوبة
|--------------------------------------------------------------------------
*/

if ($requirementId <= 0) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if ($actionTaken === '') {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "الإجراء الذي تم تنفيذه مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if ($actionStartDate === '') {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ بداية التنفيذ مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if ($actionEndDate === '') {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ نهاية التنفيذ مطلوب"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| التحقق من التواريخ
|--------------------------------------------------------------------------
*/

function isValidActionDate(string $date): bool
{
    $dateObject = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $date
    );

    if (
        $dateObject !== false &&
        $dateObject->format('Y-m-d H:i:s') === $date
    ) {
        return true;
    }

    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return $dateObject !== false &&
        $dateObject->format('Y-m-d') === $date;
}


if (!isValidActionDate($actionStartDate)) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ بداية التنفيذ غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if (!isValidActionDate($actionEndDate)) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ نهاية التنفيذ غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if (
    strtotime($actionEndDate) <
    strtotime($actionStartDate)
) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ نهاية التنفيذ يجب أن يكون بعد تاريخ البداية"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


try {

    /*
    |--------------------------------------------------------------------------
    | بدء المعاملة
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    $executorId = (int) $authUser['id'];


    /*
    |--------------------------------------------------------------------------
    | جلب المطلوب وآخر توجيه
    |--------------------------------------------------------------------------
    */

    $requirementStatement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.requirement AS requirement_title,
            r.status,

            rd.id AS direction_id,
            rd.executor_id,
            rd.allowed_start,
            rd.allowed_end

        FROM requirements r

        INNER JOIN requirement_directions rd
            ON rd.id = (
                SELECT rd2.id

                FROM requirement_directions rd2

                WHERE rd2.requirement_id = r.id

                ORDER BY rd2.id DESC

                LIMIT 1
            )

        WHERE r.id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $requirementStatement->execute([
        $requirementId
    ]);

    $requirement = $requirementStatement->fetch();


    // التحقق أن المطلوب موجود
    if (!$requirement) {

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المطلوب غير موجود أو لم يتم توجيهه"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    // التحقق أن المطلوب موجّه إلى المنفّذ الحالي
    if (
        (int) $requirement['executor_id'] !==
        $executorId
    ) {

        $pdo->rollBack();

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "هذا المطلوب غير موجّه إليك"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
     * يسمح بتسجيل الإجراء فقط بعد استلام المطلوب.
     */
    if (
        $requirement['status'] !==
        'received_by_executor'
    ) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن تسجيل الإجراء في حالة المطلوب الحالية",
            "current_status" => $requirement['status']
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | التحقق من عدم وجود إجراء مسجل سابقًا
    |--------------------------------------------------------------------------
    */

    $existingStatement = $pdo->prepare("
        SELECT id

        FROM requirement_actions

        WHERE requirement_id = ?

        LIMIT 1
    ");

    $existingStatement->execute([
        $requirementId
    ]);

    if ($existingStatement->fetch()) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "يوجد إجراء مسجل لهذا المطلوب، استخدم تعديل الإجراء"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | إضافة الإجراء
    |--------------------------------------------------------------------------
    */

  /*
|--------------------------------------------------------------------------
| إضافة الإجراء
|--------------------------------------------------------------------------
|
| يجب حفظ direction_id لأن جدول requirement_actions
| مرتبط بجدول requirement_directions بمفتاح أجنبي.
|
*/

$insertStatement = $pdo->prepare("
    INSERT INTO requirement_actions (
        requirement_id,
        direction_id,
        executor_id,
        action_taken,
        recommendation,
        notes,
        action_start_date,
        action_end_date,
        created_at,
        updated_at
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
        NOW(),
        NOW()
    )
    RETURNING id
");

$insertStatement->execute([
    $requirementId,

    // رقم آخر توجيه للمطلوب
    (int) $requirement['direction_id'],

    $executorId,

    $actionTaken,

    $recommendation !== ''
        ? $recommendation
        : null,

    $notes !== ''
        ? $notes
        : null,

    $actionStartDate,

    $actionEndDate
]);

    $actionId = (int) $insertStatement->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | تحديث حالة المطلوب
    |--------------------------------------------------------------------------
    */

    $updateRequirementStatement = $pdo->prepare("
        UPDATE requirements

        SET
            status = 'action_done',
            updated_at = NOW()

        WHERE id = ?
          AND status = 'received_by_executor'
    ");

    $updateRequirementStatement->execute([
        $requirementId
    ]);


    if (
        $updateRequirementStatement->rowCount() !== 1
    ) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "تعذر تحديث حالة المطلوب"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | تسجيل النشاط
    |--------------------------------------------------------------------------
    */

    $oldData = json_encode([
        "status" => "received_by_executor"
    ], JSON_UNESCAPED_UNICODE);

    $newData = json_encode([
        "status" => "action_done",
        "action_id" => $actionId
    ], JSON_UNESCAPED_UNICODE);

    $ipAddress =
        substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 50);

    $deviceInfo =
        substr(
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            0,
            255
        );


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
        'create_requirement_action',
        'requirement_actions',
        $actionId,
        $oldData,
        $newData,
        $ipAddress !== ''
            ? $ipAddress
            : null,
        $deviceInfo !== ''
            ? $deviceInfo
            : null
    ]);


    /*
    |--------------------------------------------------------------------------
    | اعتماد العملية
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    echo json_encode([
        "status" => true,
        "message" => "تم تسجيل الإجراء بنجاح",

        "data" => [
            "action_id" => $actionId,
            "requirement_id" => $requirementId,
            "order_id" =>
                (int) $requirement['order_id'],
            "requirement_title" =>
                $requirement['requirement_title'],
            "status" => "action_done",
            "status_name" => "تم التنفيذ"
        ]
    ], JSON_UNESCAPED_UNICODE);


} catch (PDOException $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'create_action.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تسجيل الإجراء",

        // مؤقت أثناء الاختبار
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);


} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'create_action.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ غير متوقع أثناء تسجيل الإجراء",

        // مؤقت أثناء الاختبار
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}