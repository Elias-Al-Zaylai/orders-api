<?php

function sendNotification($pdo, $userId, $title, $message)
{
    $stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            title,
            message,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, FALSE, NOW())
    ");

    $stmt->execute([
        $userId,
        $title,
        $message
    ]);
}