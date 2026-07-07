<?php

function updateOrderStatus($pdo, $orderId, $status)
{
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $status,
        $orderId
    ]);
}

function completeOrderIfAllRequirementsClosed($pdo, $orderId)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM requirements
        WHERE order_id = ?
        AND status NOT IN ('closed', 'cancelled')
    ");

    $stmt->execute([$orderId]);
    $result = $stmt->fetch();

    if ($result['total'] == 0) {
        updateOrderStatus($pdo, $orderId, 'completed');
    }
}

function cancelOrderIfAllRequirementsCancelled($pdo, $orderId)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM requirements
        WHERE order_id = ?
        AND status != 'cancelled'
    ");

    $stmt->execute([$orderId]);
    $result = $stmt->fetch();

    if ($result['total'] == 0) {
        updateOrderStatus($pdo, $orderId, 'cancelled');
    }
}