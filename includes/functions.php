<?php

function log_admin_action($conn, $admin_id, $action_type, $description, $entity_id, $entity_type, $entity_name = null, $old_value = null, $new_value = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $created_at = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action_type, description, entity_id, entity_type, entity_name, old_value, new_value, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "sssssssssss",
        $admin_id,
        $action_type,
        $description,
        $entity_id,
        $entity_type,
        $entity_name,
        $old_value,
        $new_value,
        $ip_address,
        $user_agent,
        $created_at
    );
    $stmt->execute();
    $stmt->close();
}
?>