<?php

// تحديد نوع الاستجابة بصيغة JSON مع دعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// استخدام ملف التحقق من تسجيل الدخول
require_once __DIR__ . '/../middleware/auth.php';

try {

    /*
     * جلب عدد الإشعارات غير المقروءة للمستخدم الحالي.
     *
     * المتغير authUser يتم توفيره من ملف auth.php
     * بعد التأكد من صحة Token المستخدم.
     */
    $userId = $authUser['id'];

    // تجهيز استعلام حساب الإشعارات غير المقروءة
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ?
          AND is_read = FALSE
    ");

    // تنفيذ الاستعلام مع تمرير رقم المستخدم الحالي
    $stmt->execute([$userId]);

    // جلب نتيجة العدد
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // تحويل العدد إلى رقم صحيح
    $unreadCount = (int) ($result['unread_count'] ?? 0);

    // إرسال النتيجة إلى تطبيق Flutter
    echo json_encode([
        "status" => true,
        "message" => "تم جلب عدد الإشعارات غير المقروءة بنجاح",
        "unread_count" => $unreadCount
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    // تحديد أن الخطأ حدث داخل السيرفر
    http_response_code(500);

    // إرسال رسالة الخطأ
    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب عدد الإشعارات غير المقروءة",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}