<?php

// تحديد نوع الاستجابة ودعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// ملفات المصادقة والصلاحيات والإشعارات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';
require_once __DIR__ . '/../helpers/notification_helper.php';

// السماح فقط لمن يمتلك صلاحية توجيه المطاليب
requirePermission('direct_requirement');

// استقبال البيانات المرسلة من تطبيق Flutter
$data = json_decode(
    file_get_contents("php://input"),
    true
);

// التأكد أن البيانات المرسلة JSON صحيحة
if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "بيانات الطلب غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// قراءة بيانات التوجيه
$requirementId = $data['requirement_id'] ?? null;
$executorId = $data['executor_id'] ?? null;

$notesToExecutor = trim(
    $data['notes_to_executor'] ?? ''
);

$allowedStart = $data['allowed_start'] ?? null;
$allowedEnd = $data['allowed_end'] ?? null;

// التحقق من البيانات المطلوبة
if (
    empty($requirementId) ||
    empty($executorId) ||
    empty($allowedStart) ||
    empty($allowedEnd)
) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب والمنفذ وبداية ونهاية التنفيذ مطلوبة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// تحويل الأرقام إلى أعداد صحيحة
$requirementId = (int) $requirementId;
$executorId = (int) $executorId;

// التحقق من صحة الأرقام
if ($requirementId <= 0 || $executorId <= 0) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "رقم المطلوب أو رقم المنفذ غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// تحويل التواريخ
$allowedStartTimestamp = strtotime($allowedStart);
$allowedEndTimestamp = strtotime($allowedEnd);

// التأكد من صحة التواريخ
if (
    $allowedStartTimestamp === false ||
    $allowedEndTimestamp === false
) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "صيغة تاريخ بداية أو نهاية التنفيذ غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// يجب أن تكون النهاية بعد البداية
if ($allowedEndTimestamp <= $allowedStartTimestamp) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ نهاية التنفيذ يجب أن يكون بعد تاريخ البداية"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    // بدء المعاملة
    $pdo->beginTransaction();

    /*
     * جلب المطلوب وقفل السجل أثناء عملية التوجيه.
     *
     * FOR UPDATE يمنع توجيه نفس المطلوب مرتين
     * في نفس اللحظة.
     */
    $requirementStatement = $pdo->prepare("
        SELECT
            r.id,
            r.order_id,
            r.problem,
            r.status

        FROM requirements r

        WHERE r.id = ?

        LIMIT 1

        FOR UPDATE
    ");

    $requirementStatement->execute([
        $requirementId
    ]);

    $requirement = $requirementStatement->fetch(
        PDO::FETCH_ASSOC
    );

    // المطلوب غير موجود
    if (!$requirement) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المطلوب غير موجود"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // لا يمكن توجيه مطلوب غير جديد
    if ($requirement['status'] !== 'new') {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "هذا المطلوب تم توجيهه سابقًا أو حالته لا تسمح بالتوجيه"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // رقم الطلب التابع له المطلوب
    $orderId = (int) $requirement['order_id'];

    /*
     * التأكد أن المنفذ:
     *
     * 1. موجود.
     * 2. نشط.
     * 3. يمتلك صلاحية execute_requirement.
     */
    $executorStatement = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.name

        FROM users u

        INNER JOIN user_roles ur
            ON ur.user_id = u.id

        INNER JOIN role_permissions rp
            ON rp.role_id = ur.role_id

        INNER JOIN permissions p
            ON p.id = rp.permission_id

        WHERE u.id = ?
          AND u.is_active = TRUE
          AND p.permission_key = 'execute_requirement'

        LIMIT 1
    ");

    $executorStatement->execute([
        $executorId
    ]);

    $executor = $executorStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$executor) {
        $pdo->rollBack();

        http_response_code(404);

        echo json_encode([
            "status" => false,
            "message" => "المنفذ غير موجود أو غير فعال أو لا يمتلك صلاحية التنفيذ"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
     * التأكد أن المنفذ غير مشغول بمطلوب آخر.
     *
     * الحالات التي تعني أن المنفذ ما زال مشغولًا:
     *
     * directed
     * تم توجيه المطلوب إليه ولم يستلمه بعد.
     *
     * received_by_executor
     * استلم المطلوب ويقوم بتنفيذه.
     *
     * returned_to_executor
     * أُعيد المطلوب إليه لاستكمال التنفيذ.
     */
    $busyExecutorStatement = $pdo->prepare("
        SELECT
            r.id,
            r.problem,
            r.status

        FROM requirement_directions rd

        INNER JOIN requirements r
            ON r.id = rd.requirement_id

        WHERE rd.executor_id = ?
          AND r.status IN (
              'directed',
              'received_by_executor',
              'returned_to_executor'
          )

        LIMIT 1

        FOR UPDATE
    ");

    $busyExecutorStatement->execute([
        $executorId
    ]);

    $busyRequirement = $busyExecutorStatement->fetch(
        PDO::FETCH_ASSOC
    );

    // المنفذ لديه مطلوب قائم
    if ($busyRequirement) {
        $pdo->rollBack();

        http_response_code(409);

        echo json_encode([
            "status" => false,
            "message" => "هذا المنفذ مشغول حاليًا بمطلوب آخر"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // التأكد أن المطلوب لم يتم توجيهه سابقًا
    $directionStatement = $pdo->prepare("
        SELECT id

        FROM requirement_directions

        WHERE requirement_id = ?

        LIMIT 1
    ");

    $directionStatement->execute([
        $requirementId
    ]);

    if ($directionStatement->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();

        http_response_code(400);

        echo json_encode([
            "status" => false,
            "message" => "تم توجيه هذا المطلوب سابقًا"
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // إضافة سجل التوجيه
    $insertDirection = $pdo->prepare("
        INSERT INTO requirement_directions (
            requirement_id,
            directed_by_user_id,
            executor_id,
            notes_to_executor,
            allowed_start,
            allowed_end,
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
            NOW(),
            NOW()
        )
        RETURNING id
    ");

    $insertDirection->execute([
        $requirementId,
        $authUser['id'],
        $executorId,
        $notesToExecutor,
        date(
            'Y-m-d H:i:s',
            $allowedStartTimestamp
        ),
        date(
            'Y-m-d H:i:s',
            $allowedEndTimestamp
        )
    ]);

    // رقم سجل التوجيه
    $directionId = (int) $insertDirection->fetchColumn();

    // تغيير حالة المطلوب إلى موجّه
    $updateRequirement = $pdo->prepare("
        UPDATE requirements

        SET
            status = 'directed',
            updated_at = NOW()

        WHERE id = ?
          AND status = 'new'
    ");

    $updateRequirement->execute([
        $requirementId
    ]);

    if ($updateRequirement->rowCount() === 0) {
        throw new Exception(
            "تعذر تحديث حالة المطلوب"
        );
    }

    /*
     * حساب عدد المطاليب الفعالة التابعة للطلب.
     *
     * المطاليب الملغية لا تدخل في حساب حالة الطلب.
     */
    $requirementsCountStatement = $pdo->prepare("
        SELECT
            COUNT(*) AS total_requirements,

            SUM(
                CASE
                    WHEN status = 'new'
                    THEN 1
                    ELSE 0
                END
            ) AS new_requirements

        FROM requirements

        WHERE order_id = ?
          AND status <> 'cancelled'
    ");

    $requirementsCountStatement->execute([
        $orderId
    ]);

    $requirementsCount = $requirementsCountStatement->fetch(
        PDO::FETCH_ASSOC
    );

    $totalRequirements = (int) (
        $requirementsCount['total_requirements'] ?? 0
    );

    $newRequirements = (int) (
        $requirementsCount['new_requirements'] ?? 0
    );

    /*
     * تحديد حالة الطلب:
     *
     * إذا بقي مطلوب واحد أو أكثر حالته new:
     * تصبح حالة الطلب under_direction.
     *
     * إذا لم يبق أي مطلوب جديد:
     * تصبح حالة الطلب directed.
     */
    if (
        $totalRequirements > 0 &&
        $newRequirements > 0
    ) {
        $newOrderStatus = 'under_direction';
    } else {
        $newOrderStatus = 'directed';
    }

    // تحديث حالة الطلب
    $updateOrder = $pdo->prepare("
        UPDATE orders

        SET
            status = ?,
            updated_at = NOW()

        WHERE id = ?
          AND status <> 'cancelled'
    ");

    $updateOrder->execute([
        $newOrderStatus,
        $orderId
    ]);

    // إرسال إشعار للمنفذ
    sendNotification(
        $pdo,
        $executorId,
        'مطلوب جديد موجه إليك',
        'تم توجيه مطلوب جديد إليك، يرجى مراجعة تفاصيله.'
    );

    // تثبيت العملية
    $pdo->commit();

    echo json_encode([
        "status" => true,
        "message" => "تم توجيه المطلوب إلى المنفذ بنجاح",
        "direction_id" => $directionId,
        "requirement_id" => $requirementId,
        "order_id" => $orderId,
        "order_status" => $newOrderStatus,
        "remaining_new_requirements" => $newRequirements,
        "executor" => [
            "id" => (int) $executor['id'],
            "name" => $executor['name']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // التراجع عن جميع التغييرات عند حدوث خطأ
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل توجيه المطلوب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}