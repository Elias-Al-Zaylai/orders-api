<?php

/*
|--------------------------------------------------------------------------
| تعديل إجراء تنفيذ المطلوب
|--------------------------------------------------------------------------
|
| يسمح بالتعديل عندما تكون الحالة:
|
| action_done
| أو
| returned_to_executor
|
| لا يسمح بالتعديل بعد استلام مقدم الطلب للتنفيذ.
|
*/

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول
require_once __DIR__ . '/../middleware/auth.php';

// التحقق من الصلاحيات
require_once __DIR__ . '/../middleware/permission.php';

// صلاحية تعديل الإجراء
requirePermission('update_action');


/*
|--------------------------------------------------------------------------
| استقبال البيانات
|--------------------------------------------------------------------------
*/

$data = json_decode(
    file_get_contents("php://input"),
    true
);

if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "البيانات المرسلة غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


// رقم الإجراء
$actionId =
    (int) ($data['action_id'] ?? 0);

// نص الإجراء
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
| التحقق من البيانات
|--------------------------------------------------------------------------
*/

if ($actionId <= 0) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم الإجراء مطلوب"
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


if (
    $actionStartDate === '' ||
    $actionEndDate === ''
) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ بداية ونهاية التنفيذ مطلوبان"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if (
    strtotime($actionStartDate) === false ||
    strtotime($actionEndDate) === false
) {

    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ التنفيذ غير صحيح"
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
        "message" => "تاريخ النهاية يجب أن يكون بعد تاريخ البداية"
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
    | جلب الإجراء والمطلوب وآخر توجيه
    |--------------------------------------------------------------------------
    */

    $statement = $pdo->prepare("
        SELECT
            ra.id AS action_id,
            ra.requirement_id,
            ra.executor_id AS action_executor_id,
            ra.action_taken,
            ra.recommendation,
            ra.notes,
            ra.action_start_date,
            ra.action_end_date,

            r.order_id,
            r.requirement AS requirement_title,
            r.status AS requirement_status,

            rd.executor_id AS direction_executor_id

        FROM requirement_actions ra

        INNER JOIN requirements r
            ON r.id = ra.requirement_id

        INNER JOIN requirement_directions rd
            ON rd.id = (
                SELECT rd2.id

                FROM requirement_directions rd2

                WHERE rd2.requirement_id = r.id

                ORDER BY rd2.id DESC

                LIMIT 1
            )

        WHERE ra.id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $statement->execute([
        $actionId
    ]);

    $action = $statement->fetch();


    if (!$action) {

        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "الإجراء غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
     * التحقق أن المطلوب ما زال موجّهًا للمنفّذ الحالي.
     */
    if (
        (int) $action['direction_executor_id'] !==
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
     * التحقق أن الإجراء أنشأه المنفّذ الحالي.
     */
    if (
        (int) $action['action_executor_id'] !==
        $executorId
    ) {

        $pdo->rollBack();

        http_response_code(403);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكنك تعديل إجراء منفّذ آخر"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
     * يسمح بالتعديل قبل استلام مقدم الطلب،
     * أو إذا أعاد مقدم الطلب التنفيذ للتعديل.
     */
    $allowedStatuses = [
        'action_done',
        'returned_to_executor'
    ];

    if (
        !in_array(
            $action['requirement_status'],
            $allowedStatuses,
            true
        )
    ) {

        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "لا يمكن تعديل الإجراء في حالة المطلوب الحالية",
            "current_status" =>
                $action['requirement_status']
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | حفظ البيانات القديمة للسجل
    |--------------------------------------------------------------------------
    */

    $oldData = json_encode([
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

        "status" =>
            $action['requirement_status']
    ], JSON_UNESCAPED_UNICODE);


    /*
    |--------------------------------------------------------------------------
    | تحديث الإجراء
    |--------------------------------------------------------------------------
    */

    $updateActionStatement = $pdo->prepare("
        UPDATE requirement_actions

        SET
            action_taken = ?,
            recommendation = ?,
            notes = ?,
            action_start_date = ?,
            action_end_date = ?,
            updated_at = NOW()

        WHERE id = ?
          AND executor_id = ?
    ");

    $updateActionStatement->execute([
        $actionTaken,
        $recommendation !== ''
            ? $recommendation
            : null,
        $notes !== ''
            ? $notes
            : null,
        $actionStartDate,
        $actionEndDate,
        $actionId,
        $executorId
    ]);


    /*
     * عند تعديل تنفيذ معاد للمنفّذ،
     * نعيد الحالة إلى تم التنفيذ.
     */
    if (
        $action['requirement_status'] ===
        'returned_to_executor'
    ) {

        $updateRequirementStatement =
            $pdo->prepare("
                UPDATE requirements

                SET
                    status = 'action_done',
                    updated_at = NOW()

                WHERE id = ?
                  AND status = 'returned_to_executor'
            ");

        $updateRequirementStatement->execute([
            (int) $action['requirement_id']
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | تسجيل النشاط
    |--------------------------------------------------------------------------
    */

    $newData = json_encode([
        "action_taken" => $actionTaken,

        "recommendation" =>
            $recommendation !== ''
                ? $recommendation
                : null,

        "notes" =>
            $notes !== ''
                ? $notes
                : null,

        "action_start_date" =>
            $actionStartDate,

        "action_end_date" =>
            $actionEndDate,

        "status" => "action_done"
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
        'update_requirement_action',
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
        "message" => "تم تعديل الإجراء بنجاح",

        "data" => [
            "action_id" => $actionId,

            "requirement_id" =>
                (int) $action['requirement_id'],

            "order_id" =>
                (int) $action['order_id'],

            "requirement_title" =>
                $action['requirement_title'],

            "status" =>
                "action_done",

            "status_name" =>
                "تم التنفيذ"
        ]
    ], JSON_UNESCAPED_UNICODE);


} catch (PDOException $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'update_action.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء تعديل الإجراء",

        // مؤقت أثناء الاختبار
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);


} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'update_action.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ غير متوقع أثناء تعديل الإجراء",

        // مؤقت أثناء الاختبار
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}