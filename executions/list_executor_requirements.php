<?php

/*
|--------------------------------------------------------------------------
| قائمة مطاليب المنفّذ
|--------------------------------------------------------------------------
|
| يدعم الملف:
| - البحث.
| - الفلترة حسب الحالة.
| - الفلترة حسب تاريخ التوجيه.
| - تقسيم النتائج إلى صفحات.
| - حساب الوقت المتبقي للتنفيذ.
| - تحديد العملية المتاحة حسب حالة المطلوب.
|
*/

// نوع الاستجابة
header("Content-Type: application/json; charset=UTF-8");

// التحقق من تسجيل الدخول
require_once __DIR__ . '/../middleware/auth.php';

// التحقق من الصلاحيات
require_once __DIR__ . '/../middleware/permission.php';

// الشاشة خاصة بمن يملك صلاحية تنفيذ المطلوب
requirePermission('execute_requirement');


/*
|--------------------------------------------------------------------------
| بيانات المستخدم الحالي
|--------------------------------------------------------------------------
*/

// رقم المنفّذ المسجل حاليًا
$executorId = (int) $authUser['id'];


/*
|--------------------------------------------------------------------------
| استقبال معاملات البحث والفلترة
|--------------------------------------------------------------------------
*/

// نص البحث
$search = trim($_GET['search'] ?? '');

// حالة المطلوب
$status = trim($_GET['status'] ?? '');

// تاريخ البداية
$dateFrom = trim($_GET['date_from'] ?? '');

// تاريخ النهاية
$dateTo = trim($_GET['date_to'] ?? '');

// رقم الصفحة
$page = (int) ($_GET['page'] ?? 1);

// عدد العناصر في الصفحة
$limit = (int) ($_GET['limit'] ?? 15);


/*
|--------------------------------------------------------------------------
| ضبط أرقام الصفحات
|--------------------------------------------------------------------------
*/

if ($page < 1) {
    $page = 1;
}

if ($limit < 1) {
    $limit = 15;
}

// منع طلب عدد كبير جدًا من السجلات
if ($limit > 50) {
    $limit = 50;
}

// بداية السجلات في الصفحة
$offset = ($page - 1) * $limit;


/*
|--------------------------------------------------------------------------
| الحالات المسموح بها
|--------------------------------------------------------------------------
*/

$allowedStatuses = [
    'directed',
    'received_by_executor',
    'action_done',
    'received_by_requester',
    'returned_to_executor',
    'closed',
    'cancelled'
];


/*
|--------------------------------------------------------------------------
| التحقق من حالة الفلترة
|--------------------------------------------------------------------------
*/

if (
    $status !== '' &&
    !in_array($status, $allowedStatuses, true)
) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "حالة المطلوب غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| التحقق من تنسيق التاريخ
|--------------------------------------------------------------------------
*/

function isValidDate(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return $dateObject !== false &&
        $dateObject->format('Y-m-d') === $date;
}


if (!isValidDate($dateFrom)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ البداية غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if (!isValidDate($dateTo)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ النهاية غير صحيح"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


if (
    $dateFrom !== '' &&
    $dateTo !== '' &&
    $dateFrom > $dateTo
) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "message" => "تاريخ البداية يجب أن يكون قبل تاريخ النهاية"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


try {

    /*
    |--------------------------------------------------------------------------
    | بناء شروط البحث
    |--------------------------------------------------------------------------
    */

    $conditions = [];

    $parameters = [];

    // عرض المطاليب الموجّهة إلى المنفّذ الحالي فقط
    $conditions[] = "rd.executor_id = ?";

    $parameters[] = $executorId;


    /*
     * البحث في:
     * - رقم الطلب.
     * - نص المطلوب.
     * - المشكلة.
     * - اسم الشركة.
     * - اسم الإدارة.
     */
    if ($search !== '') {

        $conditions[] = "(
            o.order_number ILIKE ?
            OR r.requirement ILIKE ?
            OR r.problem ILIKE ?
            OR c.name ILIKE ?
            OR d.name ILIKE ?
        )";

        $searchValue = "%{$search}%";

        $parameters[] = $searchValue;
        $parameters[] = $searchValue;
        $parameters[] = $searchValue;
        $parameters[] = $searchValue;
        $parameters[] = $searchValue;
    }


    // الفلترة حسب الحالة
    if ($status !== '') {

        $conditions[] = "r.status = ?";

        $parameters[] = $status;
    }


    // الفلترة من تاريخ
    if ($dateFrom !== '') {

        $conditions[] = "DATE(rd.created_at) >= ?";

        $parameters[] = $dateFrom;
    }


    // الفلترة إلى تاريخ
    if ($dateTo !== '') {

        $conditions[] = "DATE(rd.created_at) <= ?";

        $parameters[] = $dateTo;
    }


    // دمج جميع الشروط
    $whereClause = implode(
        " AND ",
        $conditions
    );


    /*
    |--------------------------------------------------------------------------
    | الاستعلام الأساسي المشترك
    |--------------------------------------------------------------------------
    |
    | نربط المطلوب بآخر توجيه فقط.
    |
    */

    $baseFromQuery = "
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

        WHERE {$whereClause}
    ";


    /*
    |--------------------------------------------------------------------------
    | حساب العدد الإجمالي للنتائج
    |--------------------------------------------------------------------------
    */

    $countStatement = $pdo->prepare("
        SELECT COUNT(*) AS total

        {$baseFromQuery}
    ");

    $countStatement->execute($parameters);

    $countResult = $countStatement->fetch();

    $total = (int) ($countResult['total'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | جلب المطاليب
    |--------------------------------------------------------------------------
    */

    $listStatement = $pdo->prepare("
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.requirement AS requirement_title,
            r.problem,
            r.status,

            o.order_number,

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

        {$baseFromQuery}

        ORDER BY

            /*
             * المطاليب التي انتهى وقتها تظهر أولًا.
             */
            CASE
                WHEN rd.allowed_end IS NOT NULL
                     AND rd.allowed_end < NOW()
                     AND r.status NOT IN ('closed', 'cancelled')
                THEN 0

                /*
                 * المطاليب المعادة للتعديل.
                 */
                WHEN r.status = 'returned_to_executor'
                THEN 1

                /*
                 * المطاليب التي بقي عليها 3 أيام أو أقل.
                 */
                WHEN rd.allowed_end IS NOT NULL
                     AND rd.allowed_end >= NOW()
                     AND rd.allowed_end <= (NOW() + INTERVAL '3 days')
                     AND r.status NOT IN ('closed', 'cancelled')
                THEN 2

                /*
                 * المطاليب الجديدة الموجّهة.
                 */
                WHEN r.status = 'directed'
                THEN 3

                /*
                 * المطاليب المستلمة.
                 */
                WHEN r.status = 'received_by_executor'
                THEN 4

                /*
                 * المطاليب المنفذة.
                 */
                WHEN r.status = 'action_done'
                THEN 5

                ELSE 6
            END ASC,

            rd.created_at DESC

        LIMIT {$limit}
        OFFSET {$offset}
    ");

    $listStatement->execute($parameters);

    $rows = $listStatement->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | ترجمة الحالات
    |--------------------------------------------------------------------------
    */

    $statusNames = [
        'directed' => 'موجّه إليك',
        'received_by_executor' => 'تم الاستلام',
        'action_done' => 'تم التنفيذ',
        'received_by_requester' => 'استلمه مقدم الطلب',
        'returned_to_executor' => 'معاد للتعديل',
        'closed' => 'مغلق',
        'cancelled' => 'ملغي'
    ];


    /*
    |--------------------------------------------------------------------------
    | تجهيز النتائج
    |--------------------------------------------------------------------------
    */

    $requirements = [];

    foreach ($rows as $row) {

        $requirementStatus = $row['status'];

        /*
         * حساب الوقت المتبقي.
         */
        $remainingTime = calculateRemainingTime(
            $row['allowed_end'],
            $requirementStatus
        );


        /*
         * تحديد العمليات المتاحة حسب الحالة.
         */
        $availableActions = [
            /*
             * استلام المطلوب عندما تكون الحالة موجه.
             */
            "can_receive" =>
                $requirementStatus === 'directed',

            /*
             * تسجيل الإجراء بعد استلام المطلوب.
             */
            "can_create_action" =>
                $requirementStatus === 'received_by_executor',

            /*
             * تعديل الإجراء:
             * - بعد تسجيله وقبل استلام مقدم الطلب له.
             * - أو عندما يعيده مقدم الطلب للتعديل.
             */
            "can_update_action" =>
                in_array(
                    $requirementStatus,
                    [
                        'action_done',
                        'returned_to_executor'
                    ],
                    true
                ),

            /*
             * يمكن عرض التفاصيل في جميع الحالات.
             */
            "can_view_details" => true
        ];


        /*
         * ملاحظة توضيحية حسب الحالة.
         */
        $statusNote = getStatusNote(
            $requirementStatus
        );


        $requirements[] = [
            "requirement_id" =>
                (int) $row['requirement_id'],

            "order_id" =>
                (int) $row['order_id'],

            "direction_id" =>
                (int) $row['direction_id'],

            "order_number" =>
                $row['order_number'],

            "requirement_title" =>
                $row['requirement_title'],

            "problem" =>
                $row['problem'],

            "company_id" =>
                $row['company_id'] !== null
                    ? (int) $row['company_id']
                    : null,

            "company_name" =>
                $row['company_name'],

            "department_id" =>
                $row['department_id'] !== null
                    ? (int) $row['department_id']
                    : null,

            "department_name" =>
                $row['department_name'],

            "notes_to_executor" =>
                $row['notes_to_executor'],

            "allowed_start" =>
                $row['allowed_start'],

            "allowed_end" =>
                $row['allowed_end'],

            "directed_at" =>
                $row['directed_at'],

            "status" =>
                $requirementStatus,

            "status_name" =>
                $statusNames[$requirementStatus]
                ?? $requirementStatus,

            "status_note" =>
                $statusNote,

            "remaining_time" =>
                $remainingTime,

            "available_actions" =>
                $availableActions
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | بيانات الصفحات
    |--------------------------------------------------------------------------
    */

    $lastPage = $total > 0
        ? (int) ceil($total / $limit)
        : 1;


    /*
    |--------------------------------------------------------------------------
    | الاستجابة الناجحة
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "status" => true,
        "message" => "تم جلب مطاليب المنفّذ بنجاح",

        "data" => [
            "requirements" => $requirements,

            "pagination" => [
                "current_page" => $page,
                "last_page" => $lastPage,
                "per_page" => $limit,
                "total" => $total,
                "has_more" => $page < $lastPage
            ],

            "filters" => [
                "search" => $search,
                "status" => $status,
                "date_from" => $dateFrom,
                "date_to" => $dateTo
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);


} catch (PDOException $exception) {

    error_log(
        'list_executor_requirements.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ أثناء جلب مطاليب المنفّذ",

        // مؤقت أثناء التطوير
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);


} catch (Throwable $exception) {

    error_log(
        'list_executor_requirements.php: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "حدث خطأ غير متوقع أثناء جلب المطاليب",

        // مؤقت أثناء التطوير
        "error" => $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


/*
|--------------------------------------------------------------------------
| حساب الوقت المتبقي
|--------------------------------------------------------------------------
*/

function calculateRemainingTime(
    ?string $allowedEnd,
    string $status
): array {

    /*
     * لا نحسب الوقت للحالات المنتهية.
     */
    if (
        in_array(
            $status,
            ['closed', 'cancelled'],
            true
        )
    ) {
        return [
            "show" => false,
            "is_expired" => false,
            "is_urgent" => false,
            "total_seconds" => null,
            "days" => 0,
            "hours" => 0,
            "minutes" => 0,
            "message" => null
        ];
    }


    /*
     * إذا لم يتم تحديد نهاية التنفيذ.
     */
    if (
        $allowedEnd === null ||
        trim($allowedEnd) === ''
    ) {
        return [
            "show" => false,
            "is_expired" => false,
            "is_urgent" => false,
            "total_seconds" => null,
            "days" => 0,
            "hours" => 0,
            "minutes" => 0,
            "message" => "لم يتم تحديد نهاية مدة التنفيذ"
        ];
    }


    try {

        $now = new DateTime();

        $endDate = new DateTime($allowedEnd);

        $differenceSeconds =
            $endDate->getTimestamp() -
            $now->getTimestamp();


        $isExpired = $differenceSeconds < 0;

        $absoluteSeconds = abs($differenceSeconds);


        $days = intdiv(
            $absoluteSeconds,
            86400
        );

        $remainingAfterDays =
            $absoluteSeconds % 86400;


        $hours = intdiv(
            $remainingAfterDays,
            3600
        );

        $remainingAfterHours =
            $remainingAfterDays % 3600;


        $minutes = intdiv(
            $remainingAfterHours,
            60
        );


        /*
         * المطالَب يعتبر عاجلًا إذا بقي 3 أيام أو أقل.
         */
        $isUrgent =
            !$isExpired &&
            $differenceSeconds <= (3 * 86400);


        /*
         * إنشاء النص العربي.
         */
        if ($isExpired) {

            $message = buildTimeMessage(
                $days,
                $hours,
                $minutes,
                true
            );

        } else {

            $message = buildTimeMessage(
                $days,
                $hours,
                $minutes,
                false
            );
        }


        return [
            "show" => true,
            "is_expired" => $isExpired,
            "is_urgent" => $isUrgent,
            "total_seconds" => $differenceSeconds,
            "days" => $days,
            "hours" => $hours,
            "minutes" => $minutes,
            "message" => $message
        ];


    } catch (Throwable $exception) {

        return [
            "show" => false,
            "is_expired" => false,
            "is_urgent" => false,
            "total_seconds" => null,
            "days" => 0,
            "hours" => 0,
            "minutes" => 0,
            "message" => "تعذر حساب الوقت المتبقي"
        ];
    }
}


/*
|--------------------------------------------------------------------------
| إنشاء نص الوقت المتبقي
|--------------------------------------------------------------------------
*/

function buildTimeMessage(
    int $days,
    int $hours,
    int $minutes,
    bool $isExpired
): string {

    $parts = [];


    if ($days > 0) {
        $parts[] = "{$days} يوم";
    }


    if ($hours > 0) {
        $parts[] = "{$hours} ساعة";
    }


    /*
     * نظهر الدقائق إذا كان المتبقي أقل من يوم،
     * أو إذا لم توجد أيام وساعات.
     */
    if (
        $days === 0 &&
        (
            $minutes > 0 ||
            $hours === 0
        )
    ) {
        $parts[] = "{$minutes} دقيقة";
    }


    $timeText = implode(
        " و",
        $parts
    );


    if ($timeText === '') {
        $timeText = "أقل من دقيقة";
    }


    if ($isExpired) {
        return "انتهت مدة تنفيذ هذا المطلوب منذ {$timeText}";
    }


    return "باقي {$timeText} لتنفيذ هذا المطلوب";
}


/*
|--------------------------------------------------------------------------
| الملاحظة الخاصة بحالة المطلوب
|--------------------------------------------------------------------------
*/

function getStatusNote(string $status): ?string
{
    switch ($status) {

        case 'directed':
            return 'يرجى استلام المطلوب للبدء في تنفيذه';

        case 'received_by_executor':
            return 'تم استلام المطلوب ويمكنك الآن تسجيل الإجراء';

        case 'action_done':
            return 'تم تسجيل الإجراء ويمكن تعديله قبل استلام مقدم الطلب';

        case 'received_by_requester':
            return 'استلم مقدم الطلب التنفيذ ولا يمكن تعديله';

        case 'returned_to_executor':
            return 'أعاد مقدم الطلب التنفيذ للتعديل';

        case 'closed':
            return 'تم اعتماد وإغلاق المطلوب';

        case 'cancelled':
            return 'تم إلغاء المطلوب';

        default:
            return null;
    }
}