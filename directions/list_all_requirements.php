<?php

// تحديد نوع الاستجابة بصيغة JSON مع دعم اللغة العربية
header("Content-Type: application/json; charset=UTF-8");

// ملفات تسجيل الدخول والصلاحيات
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permission.php';

// السماح فقط لمن يمتلك صلاحية عرض الطلبات
requirePermission('view_orders');

/*
 * استقبال خيارات البحث والفلترة.
 *
 * search:
 * البحث برقم الطلب أو نص المطلوب أو اسم المنفذ.
 *
 * status:
 * فلترة المطاليب حسب الحالة.
 *
 * page:
 * رقم الصفحة الحالية.
 *
 * per_page:
 * عدد العناصر في كل صفحة.
 */
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$page = (int) ($_GET['page'] ?? 1);
$perPage = (int) ($_GET['per_page'] ?? 20);

// حماية رقم الصفحة
if ($page < 1) {
    $page = 1;
}

// حماية عدد العناصر
if ($perPage < 1) {
    $perPage = 20;
}

// منع جلب عدد كبير جدًا في طلب واحد
if ($perPage > 100) {
    $perPage = 100;
}

/*
 * جميع حالات المطاليب المسموح بها.
 */
$allowedStatuses = [
    'new',
    'directed',
    'received_by_executor',
    'action_done',
    'received_by_requester',
    'returned_to_executor',
    'closed',
    'cancelled'
];

// التحقق من صحة الحالة المرسلة
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

try {

    /*
     * التأكد هل المستخدم مدير نظام.
     *
     * مدير النظام يستطيع مشاهدة جميع المطاليب.
     * الموجّه العادي يشاهد المطاليب المرسلة إلى شركته وإدارته.
     */
    $adminStatement = $pdo->prepare("
        SELECT 1

        FROM user_roles ur

        INNER JOIN roles r
            ON r.id = ur.role_id

        WHERE ur.user_id = ?
          AND r.name = 'admin'
          AND r.is_active = TRUE

        LIMIT 1
    ");

    $adminStatement->execute([
        $authUser['id']
    ]);

    $isAdmin = (bool) $adminStatement->fetchColumn();

    // شروط الاستعلام
    $conditions = [];

    // القيم المرتبطة بالاستعلام
    $parameters = [];

    /*
     * إذا لم يكن المستخدم مدير النظام،
     * يتم عرض المطاليب التابعة لشركته وإدارته فقط.
     */
    if (!$isAdmin) {

        // إذا لم يكن للمستخدم شركة فلا توجد بيانات لعرضها
        if (empty($authUser['company_id'])) {
            echo json_encode([
                "status" => true,
                "message" => "لا توجد مطاليب",
                "data" => [],
                "pagination" => [
                    "current_page" => $page,
                    "per_page" => $perPage,
                    "total" => 0,
                    "last_page" => 1,
                    "has_more" => false
                ]
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // عرض الطلبات المرسلة إلى شركة الموجّه
        $conditions[] = "o.to_company_id = :company_id";

        $parameters[':company_id'] =
            (int) $authUser['company_id'];

        /*
         * إذا كان للموجّه إدارة محددة،
         * نعرض المطاليب المرسلة إلى إدارته فقط.
         */
        if (!empty($authUser['department_id'])) {
            $conditions[] =
                "o.to_department_id = :department_id";

            $parameters[':department_id'] =
                (int) $authUser['department_id'];
        }
    }

    // فلترة حسب حالة المطلوب
    if ($status !== '') {
        $conditions[] = "r.status = :status";
        $parameters[':status'] = $status;
    }

    /*
     * البحث في:
     *
     * رقم الطلب.
     * نص المطلوب.
     * المشكلة.
     * اسم مقدم الطلب.
     * اسم المنفذ.
     * نوع الطلب.
     */
    if ($search !== '') {
        $conditions[] = "(
            o.order_number ILIKE :search_1
            OR o.document_number ILIKE :search_2
            OR o.statement ILIKE :search_3
            OR r.problem ILIKE :search_4
            OR requester.name ILIKE :search_5
            OR executor.name ILIKE :search_6
            OR request_type.name ILIKE :search_7
        )";
        $searchValue = '%' . $search . '%';

        for ($searchIndex = 1; $searchIndex <= 7; $searchIndex++) {
            $parameters[':search_' . $searchIndex] = $searchValue;
        }
    }

    // تكوين جملة WHERE
    $whereSql = '';

    if (!empty($conditions)) {
        $whereSql =
            'WHERE ' . implode(' AND ', $conditions);
    }

    /*
     * حساب إجمالي عدد المطاليب المطابقة للبحث والفلترة.
     */
    $countSql = "
        SELECT COUNT(DISTINCT r.id)

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        INNER JOIN users requester
            ON requester.id = o.requester_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        LEFT JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        LEFT JOIN users executor
            ON executor.id = rd.executor_id

        $whereSql
    ";

    $countStatement = $pdo->prepare($countSql);

    foreach ($parameters as $key => $value) {
        $countStatement->bindValue(
            $key,
            $value
        );
    }

    $countStatement->execute();

    $total = (int) $countStatement->fetchColumn();

    // حساب بداية الصفحة
    $offset = ($page - 1) * $perPage;

    // حساب عدد الصفحات
    $lastPage = max(
        1,
        (int) ceil($total / $perPage)
    );

    /*
     * جلب المطاليب مع بيانات الطلب والمنفذ والمدة.
     *
     * نستخدم LEFT JOIN لأن المطلوب الجديد
     * لا يحتوي على سجل توجيه بعد.
     */
    $requirementsSql = "
        SELECT
            r.id AS requirement_id,
            r.order_id,
            r.problem AS requirement_title,
            r.problem,
            r.status AS requirement_status,
            r.created_at AS requirement_created_at,
            r.updated_at AS requirement_updated_at,

            o.order_number,
            o.document_number,
            o.statement AS order_statement,
            o.status AS order_status,
            o.created_at AS order_created_at,

            requester.id AS requester_id,
            requester.name AS requester_name,

            request_type.id AS request_type_id,
            request_type.name AS request_type_name,

            from_company.name AS from_company_name,
            from_department.name AS from_department_name,

            to_company.name AS to_company_name,
            to_department.name AS to_department_name,

            rd.id AS direction_id,
            rd.directed_by_user_id,
            rd.executor_id,
            rd.notes_to_executor,
            rd.allowed_start,
            rd.allowed_end,
            rd.created_at AS direction_created_at,

            director.name AS directed_by_name,

            executor.name AS executor_name,
            executor.username AS executor_username,
            executor.phone AS executor_phone,
            executor.email AS executor_email,

            executor_company.name AS executor_company_name,
            executor_department.name AS executor_department_name,
            executor_section.name AS executor_section_name,

            CASE
                WHEN r.status IN (
                    'directed',
                    'received_by_executor',
                    'returned_to_executor'
                )
                AND rd.allowed_end IS NOT NULL
                AND rd.allowed_end < NOW()
                THEN 1
                ELSE 0
            END AS is_delayed

        FROM requirements r

        INNER JOIN orders o
            ON o.id = r.order_id

        INNER JOIN users requester
            ON requester.id = o.requester_id

        INNER JOIN request_types request_type
            ON request_type.id = o.request_type_id

        LEFT JOIN companies from_company
            ON from_company.id = o.from_company_id

        LEFT JOIN departments from_department
            ON from_department.id = o.from_department_id

        INNER JOIN companies to_company
            ON to_company.id = o.to_company_id

        INNER JOIN departments to_department
            ON to_department.id = o.to_department_id

        LEFT JOIN requirement_directions rd
            ON rd.requirement_id = r.id

        LEFT JOIN users director
            ON director.id = rd.directed_by_user_id

        LEFT JOIN users executor
            ON executor.id = rd.executor_id

        LEFT JOIN companies executor_company
            ON executor_company.id = executor.company_id

        LEFT JOIN departments executor_department
            ON executor_department.id =
               executor.department_id

        LEFT JOIN sections executor_section
            ON executor_section.id =
               executor.section_id

        $whereSql

        ORDER BY
            CASE
                WHEN r.status = 'new' THEN 1
                WHEN r.status = 'received_by_requester' THEN 2
                WHEN r.status = 'returned_to_executor' THEN 3
                WHEN r.status = 'directed' THEN 4
                WHEN r.status = 'received_by_executor' THEN 5
                WHEN r.status = 'action_done' THEN 6
                WHEN r.status = 'closed' THEN 7
                WHEN r.status = 'cancelled' THEN 8
                ELSE 9
            END,
            r.created_at DESC

        LIMIT :limit
        OFFSET :offset
    ";

    $requirementsStatement =
        $pdo->prepare($requirementsSql);

    foreach ($parameters as $key => $value) {
        $requirementsStatement->bindValue(
            $key,
            $value
        );
    }

    $requirementsStatement->bindValue(
        ':limit',
        $perPage,
        PDO::PARAM_INT
    );

    $requirementsStatement->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $requirementsStatement->execute();

    $requirements =
        $requirementsStatement->fetchAll(
            PDO::FETCH_ASSOC
        );

    /*
     * تحويل القيم الرقمية القادمة من MySQL
     * إلى أرقام صحيحة.
     */
    foreach ($requirements as &$requirement) {

        $requirement['requirement_id'] =
            (int) $requirement['requirement_id'];

        $requirement['order_id'] =
            (int) $requirement['order_id'];

        $requirement['requester_id'] =
            (int) $requirement['requester_id'];

        $requirement['request_type_id'] =
            (int) $requirement['request_type_id'];

        $requirement['is_delayed'] =
            (bool) $requirement['is_delayed'];

        /*
         * المطلوب الجديد لا يحتوي على:
         *
         * direction_id
         * executor_id
         * directed_by_user_id
         */
        $requirement['direction_id'] =
            $requirement['direction_id'] !== null
                ? (int) $requirement['direction_id']
                : null;

        $requirement['executor_id'] =
            $requirement['executor_id'] !== null
                ? (int) $requirement['executor_id']
                : null;

        $requirement['directed_by_user_id'] =
            $requirement['directed_by_user_id'] !== null
                ? (int) $requirement['directed_by_user_id']
                : null;

        /*
         * تحديد العمليات التي يسمح بها وضع المطلوب.
         *
         * تستخدمها شاشة Flutter لإظهار الأزرار المناسبة.
         */
        $requirement['actions'] = [
            // توجيه المطلوب الجديد
            "can_direct" =>
                $requirement['requirement_status'] === 'new',

            // إعادة التوجيه قبل استلام المنفذ
            "can_redirect" =>
                $requirement['requirement_status'] === 'directed',

            // الاعتماد والإغلاق بعد استلام مقدم الطلب
            "can_approve" =>
                $requirement['requirement_status'] ===
                'received_by_requester',

            // إعادة المطلوب لنفس المنفذ
            "can_return_to_executor" =>
                $requirement['requirement_status'] ===
                'received_by_requester'
        ];
    }

    unset($requirement);

    // إرجاع النتيجة
    echo json_encode([
        "status" => true,
        "message" => "تم جلب المطاليب بنجاح",
        "data" => $requirements,
        "pagination" => [
            "current_page" => $page,
            "per_page" => $perPage,
            "total" => $total,
            "last_page" => $lastPage,
            "has_more" => $page < $lastPage
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "message" => "فشل جلب المطاليب",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}