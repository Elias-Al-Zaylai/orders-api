<?php

// تحديد نوع الاستجابة بصيغة JSON
header("Content-Type: application/json; charset=UTF-8");

// استدعاء الاتصال بقاعدة البيانات والتحقق من المستخدم
require_once __DIR__ . '/../middleware/auth.php';

// استدعاء ملف التحقق من الصلاحيات
require_once __DIR__ . '/../middleware/permission.php';

// التأكد أن المستخدم يملك صلاحية استلام المطلوب
requirePermission('receive_requirement');

try {

    /*
     * رقم المستخدم المسجل حاليًا.
     * هذا المستخدم هو منفّذ المطاليب.
     */
    $executorId = (int) $authUser['id'];

    /*
     * جلب آخر 3 مطاليب موجّهة إلى المنفّذ الحالي.
     *
     * نستخدم آخر عملية توجيه لكل مطلوب، لأن المطلوب
     * قد تتم إعادة توجيهه أكثر من مرة.
     */
    $requirementsStatement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.requirement AS requirement_title,
            r.problem,
            r.status,

            o.order_number,
            o.created_at AS order_created_at,

            c.name AS company_name,
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
            ON rd.id = (
                SELECT rd2.id

                FROM requirement_directions rd2

                WHERE rd2.requirement_id = r.id

                ORDER BY rd2.id DESC

                LIMIT 1
            )

        LEFT JOIN companies c
            ON c.id = o.from_company_id

        LEFT JOIN departments d
            ON d.id = o.from_department_id

        WHERE rd.executor_id = ?
          AND r.status = 'directed'

        ORDER BY rd.created_at DESC

        LIMIT 3
    ");

    $requirementsStatement->execute([
        $executorId
    ]);

    $requirements = $requirementsStatement->fetchAll();

    /*
     * تجهيز المطاليب بالشكل المطلوب لتطبيق Flutter.
     */
    $newRequirements = [];

    foreach ($requirements as $requirement) {

        $newRequirements[] = [
            "requirement_id" => (int) $requirement['requirement_id'],
            "order_id" => (int) $requirement['order_id'],

            "order_number" => $requirement['order_number'],

            "requirement_title" => $requirement['requirement_title'],
            "problem" => $requirement['problem'],

            "company_name" => $requirement['company_name'],
            "department_name" => $requirement['department_name'],

            "notes_to_executor" =>
                $requirement['notes_to_executor'],

            "allowed_start" =>
                $requirement['allowed_start'],

            "allowed_end" =>
                $requirement['allowed_end'],

            "directed_at" =>
                $requirement['directed_at'],

            "status" => $requirement['status'],

            // اسم الحالة الذي سيظهر في واجهة المنفّذ
            "status_name" => "موجّه إليك"
        ];
    }

    /*
     * جلب آخر 3 إشعارات تخص المستخدم الحالي.
     *
     * إذا كانت أسماء أعمدة جدول notifications عندك مختلفة،
     * عدّل أسماء الأعمدة فقط حسب جدولك.
     */
    $notificationsStatement = $pdo->prepare("
        SELECT
            id,
            title,
            message,
            is_read,
            created_at

        FROM notifications

        WHERE user_id = ?

        ORDER BY created_at DESC

        LIMIT 3
    ");

    $notificationsStatement->execute([
        $executorId
    ]);

    $notificationsRows = $notificationsStatement->fetchAll();

    $notifications = [];

    foreach ($notificationsRows as $notification) {

        $notifications[] = [
            "id" => (int) $notification['id'],
            "title" => $notification['title'],
            "message" => $notification['message'],
            "is_read" => (bool) $notification['is_read'],
            "created_at" => $notification['created_at']
        ];
    }

    /*
     * حساب عدد الإشعارات غير المقروءة.
     */
    $unreadStatement = $pdo->prepare("
        SELECT COUNT(*) AS total

        FROM notifications

        WHERE user_id = ?
          AND is_read = FALSE
    ");

    $unreadStatement->execute([
        $executorId
    ]);

    $unreadResult = $unreadStatement->fetch();

    $unreadNotificationsCount =
        (int) ($unreadResult['total'] ?? 0);

    /*
     * إرسال النتيجة النهائية.
     */
    echo json_encode([
        "status" => true,
        "message" => "تم جلب بيانات الصفحة الرئيسية بنجاح",

        "data" => [
            "new_requirements" => $newRequirements,

            "notifications" => $notifications,

            "unread_notifications_count" =>
                $unreadNotificationsCount
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $exception) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب بيانات الصفحة الرئيسية",

        // مؤقت أثناء التطوير لمعرفة الخطأ الحقيقي
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $exception) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ غير متوقع",

        // مؤقت أثناء التطوير
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}