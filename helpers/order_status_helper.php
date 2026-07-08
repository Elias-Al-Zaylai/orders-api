<?php

/**
 * ملف مساعد لتوحيد حساب حالة الطلب.
 *
 * الفكرة:
 * حالة الطلب لا تُكتب يدويًا من كل ملف،
 * بل يتم حسابها تلقائيًا من حالات المطاليب التابعة له.
 */

/**
 * حالات الطلب المعتمدة في قاعدة البيانات.
 */
function allowedOrderStatuses(): array
{
    return [
        'submitted',
        'under_direction',
        'directed',
        'in_execution',
        'waiting_receipt',
        'waiting_approval',
        'completed',
        'cancelled'
    ];
}

/**
 * حالات المطلوب المعتمدة في قاعدة البيانات.
 */
function allowedRequirementStatuses(): array
{
    return [
        'new',
        'directed',
        'received_by_executor',
        'action_done',
        'received_by_requester',
        'returned_to_executor',
        'closed',
        'cancelled'
    ];
}

/**
 * تحديث حالة الطلب تلقائيًا حسب حالات المطاليب.
 *
 * يرجع اسم حالة الطلب الجديدة حتى تستطيع إرجاعها في استجابة API.
 */
function updateOrderStatus(PDO $pdo, int $orderId): string
{
    $statement = $pdo->prepare("
        SELECT
            COUNT(*) AS total,

            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN status = 'directed' THEN 1 ELSE 0 END) AS directed_count,
            SUM(CASE WHEN status = 'received_by_executor' THEN 1 ELSE 0 END) AS received_by_executor_count,
            SUM(CASE WHEN status = 'action_done' THEN 1 ELSE 0 END) AS action_done_count,
            SUM(CASE WHEN status = 'received_by_requester' THEN 1 ELSE 0 END) AS received_by_requester_count,
            SUM(CASE WHEN status = 'returned_to_executor' THEN 1 ELSE 0 END) AS returned_to_executor_count,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count

        FROM requirements

        WHERE order_id = ?
    ");

    $statement->execute([$orderId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    $total = (int) ($row['total'] ?? 0);

    if ($total === 0) {
        $newStatus = 'submitted';
    } else {
        $newCount = (int) ($row['new_count'] ?? 0);
        $directedCount = (int) ($row['directed_count'] ?? 0);
        $receivedByExecutorCount = (int) ($row['received_by_executor_count'] ?? 0);
        $actionDoneCount = (int) ($row['action_done_count'] ?? 0);
        $receivedByRequesterCount = (int) ($row['received_by_requester_count'] ?? 0);
        $returnedToExecutorCount = (int) ($row['returned_to_executor_count'] ?? 0);
        $closedCount = (int) ($row['closed_count'] ?? 0);
        $cancelledCount = (int) ($row['cancelled_count'] ?? 0);

        /*
         * أولوية الحالات من النهاية إلى البداية.
         */
        if ($cancelledCount === $total) {
            $newStatus = 'cancelled';

        } elseif (($closedCount + $cancelledCount) === $total) {
            $newStatus = 'completed';

        } elseif ($receivedByRequesterCount > 0) {
            $newStatus = 'waiting_approval';

        } elseif ($actionDoneCount > 0) {
            $newStatus = 'waiting_receipt';

        } elseif (
            $receivedByExecutorCount > 0 ||
            $returnedToExecutorCount > 0
        ) {
            $newStatus = 'in_execution';

        } elseif (
            $directedCount > 0 &&
            ($directedCount + $closedCount + $cancelledCount) === $total
        ) {
            $newStatus = 'directed';

        } elseif (
            $newCount > 0 &&
            ($directedCount + $closedCount) > 0
        ) {
            $newStatus = 'under_direction';

        } else {
            $newStatus = 'submitted';
        }
    }

    $update = $pdo->prepare("
        UPDATE orders

        SET
            status = ?,
            updated_at = NOW()

        WHERE id = ?
    ");

    $update->execute([
        $newStatus,
        $orderId
    ]);

    return $newStatus;
}
