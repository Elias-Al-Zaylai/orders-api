<?php

function logActivity(
    $pdo,
    $userId,
    $action,
    $tableName,
    $recordId = null,
    $oldData = null,
    $newData = null
) {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $pdo->prepare("
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $userId,
        $action,
        $tableName,
        $recordId,
        $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
        $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
        $ipAddress,
        $deviceInfo
    ]);
}