<?php
// tools/notifications.php - Notifications tool for all logged-in users; create restricted to privilege >= 80

session_start();
require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
require_once '../../../config/config_loader.php';

$db = Database::getInstance();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login_signup.php');
    exit;
}

$userObj = new User($_SESSION['user_id']);
$currentUser = $userObj->getData();
if (!$currentUser) {
    header('Location: ../../../login_signup.php');
    exit;
}

// Prevent customers from accessing the staff notifications page
if (isset($currentUser['user_type']) && strtolower($currentUser['user_type']) === 'customer') {
    header('Location: ../../customer-dashboard.php');
    exit;
}

$privilege_level = $userObj->getPrivilegeLevel();
$can_create = ($privilege_level >= 80);

$user_types = $db->preparedFetchAll(
    "SELECT DISTINCT user_type FROM bakery_users WHERE user_type IS NOT NULL ORDER BY user_type",
    '',
    []
);
$branches = $db->preparedFetchAll(
    "SELECT id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name",
    '',
    []
);
$departments = $db->preparedFetchAll(
    "SELECT id, dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name",
    '',
    []
);
$roles = $db->preparedFetchAll(
    "SELECT id, role_name FROM roles ORDER BY role_name",
    '',
    []
);
$users = $db->preparedFetchAll(
    "SELECT id, fullname, username FROM bakery_users ORDER BY fullname LIMIT 200",
    '',
    []
);

$notification_user_roles = $db->preparedFetchAll(
    "SELECT ur.user_id, ur.department_id, ur.role_id, u.user_type, u.branch_id FROM user_roles ur JOIN bakery_users u ON u.id = ur.user_id WHERE ur.is_active = 1",
    '',
    []
);

$active_department_ids = [];
$active_role_ids = [];
$userRoles = $db->preparedFetchAll(
    "SELECT department_id, role_id FROM user_roles WHERE user_id = ? AND is_active = 1",
    'i',
    [$_SESSION['user_id']]
);
foreach ($userRoles as $roleRow) {
    if (!empty($roleRow['department_id'])) {
        $active_department_ids[] = (int)$roleRow['department_id'];
    }
    if (!empty($roleRow['role_id'])) {
        $active_role_ids[] = (int)$roleRow['role_id'];
    }
}
$active_department_ids = array_unique($active_department_ids);
$active_role_ids = array_unique($active_role_ids);

// Initialize placeholders early for POST handlers
$department_placeholders = count($active_department_ids) ? implode(',', array_fill(0, count($active_department_ids), '?')) : '0';
$role_placeholders = count($active_role_ids) ? implode(',', array_fill(0, count($active_role_ids), '?')) : '0';

$active_department_names = [];
foreach ($departments as $dept) {
    if (in_array((int)$dept['id'], $active_department_ids, true)) {
        $active_department_names[] = $dept['dept_name'];
    }
}
$active_role_names = [];
foreach ($roles as $role) {
    if (in_array((int)$role['id'], $active_role_ids, true)) {
        $active_role_names[] = $role['role_name'];
    }
}
$user_info_segments = [];
if (!empty($active_department_names)) {
    $user_info_segments[] = implode(', ', $active_department_names);
}
if (!empty($active_role_names)) {
    $user_info_segments[] = implode(', ', $active_role_names);
}
if (empty($user_info_segments) && !empty($currentUser['user_type'])) {
    $user_info_segments[] = ucfirst($currentUser['user_type']);
}
$user_info_text = implode(' / ', $user_info_segments);

$edit_mode = false;
$edit_notification = null;
$edit_target = null;
$message = null;
$error = null;

if (isset($_GET['edit_id']) && ctype_digit($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $editResults = $db->preparedFetchAll(
        "SELECT * FROM notifications WHERE id = ? AND from_user_id = ?",
        'ii',
        [$edit_id, $_SESSION['user_id']]
    );
    if (!empty($editResults)) {
        $edit_notification = $editResults[0];
        $edit_target_rows = $db->preparedFetchAll(
            "SELECT * FROM notification_to WHERE notification_id = ?",
            'i',
            [$edit_id]
        );
        $edit_target = [
            'to_user_types' => [],
            'to_branch_ids' => [],
            'to_department_ids' => [],
            'to_role_ids' => [],
            'to_user_ids' => []
        ];
        foreach ($edit_target_rows as $row) {
            if (!empty($row['to_user_type'])) {
                $edit_target['to_user_types'][] = $row['to_user_type'];
            }
            if (!empty($row['to_branch_id'])) {
                $edit_target['to_branch_ids'][] = (int)$row['to_branch_id'];
            }
            if (!empty($row['to_department_id'])) {
                $edit_target['to_department_ids'][] = (int)$row['to_department_id'];
            }
            if (!empty($row['to_role_id'])) {
                $edit_target['to_role_ids'][] = (int)$row['to_role_id'];
            }
            if (!empty($row['to_user_id'])) {
                $edit_target['to_user_ids'][] = (int)$row['to_user_id'];
            }
        }
        $edit_target['to_user_types'] = array_values(array_unique($edit_target['to_user_types']));
        $edit_target['to_branch_ids'] = array_values(array_unique($edit_target['to_branch_ids']));
        $edit_target['to_department_ids'] = array_values(array_unique($edit_target['to_department_ids']));
        $edit_target['to_role_ids'] = array_values(array_unique($edit_target['to_role_ids']));
        $edit_target['to_user_ids'] = array_values(array_unique($edit_target['to_user_ids']));
        $edit_mode = true;
    }
}

$default_tab = $edit_mode ? 'your-notifications' : ($can_create ? 'available-notifications' : 'your-notifications');

function normalizePostValues($value, $dropAllValue = null) {
    if (!is_array($value)) {
        $value = $value === null ? [] : [$value];
    }
    $normalized = [];
    foreach ($value as $item) {
        if ($item === null) {
            continue;
        }
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $normalized[] = $item;
    }
    $normalized = array_values(array_unique($normalized));
    if ($dropAllValue !== null && in_array($dropAllValue, $normalized, true)) {
        return [];
    }
    return $normalized;
}

function normalizePostIntValues($value) {
    if (!is_array($value)) {
        $value = $value === null ? [] : [$value];
    }
    $normalized = [];
    foreach ($value as $item) {
        if (ctype_digit((string)$item)) {
            $normalized[] = (int)$item;
        }
    }
    return array_values(array_unique($normalized));
}

function truncateText($text, $maxLength = 120) {
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if (strlen($clean) <= $maxLength) {
        return $clean;
    }
    return rtrim(substr($clean, 0, $maxLength)) . '...';
}

function buildUserRolesByUser(array $notificationUserRoles): array {
    $map = [];
    foreach ($notificationUserRoles as $row) {
        $userId = (int)$row['user_id'];
        if (!isset($map[$userId])) {
            $map[$userId] = [];
        }
        $map[$userId][] = $row;
    }
    return $map;
}

function userMatchesTargetRow(array $user, array $targetRow, array $userRolesByUser): bool {
    if ($targetRow['to_user_type'] !== null && $targetRow['to_user_type'] !== $user['user_type']) {
        return false;
    }
    if ($targetRow['to_branch_id'] !== null && (int)$targetRow['to_branch_id'] !== (int)$user['branch_id']) {
        return false;
    }
    $roleRows = $userRolesByUser[$user['id']] ?? [];
    if ($targetRow['to_department_id'] !== null) {
        $matches = false;
        foreach ($roleRows as $roleRow) {
            if ((int)$roleRow['department_id'] === (int)$targetRow['to_department_id']) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return false;
        }
    }
    if ($targetRow['to_role_id'] !== null) {
        $matches = false;
        foreach ($roleRows as $roleRow) {
            if ((int)$roleRow['role_id'] === (int)$targetRow['to_role_id']) {
                $matches = true;
                break;
            }
        }
        if (!$matches) {
            return false;
        }
    }
    return true;
}

function resolveNotificationRecipients(array $targetRows, array $users, array $notificationUserRoles): array {
    $userRolesByUser = buildUserRolesByUser($notificationUserRoles);
    $recipientIds = [];
    foreach ($targetRows as $targetRow) {
        if (!empty($targetRow['to_user_id'])) {
            $recipientIds[(int)$targetRow['to_user_id']] = true;
            continue;
        }
        foreach ($users as $user) {
            if (userMatchesTargetRow($user, $targetRow, $userRolesByUser)) {
                $recipientIds[(int)$user['id']] = true;
            }
        }
    }
    return array_keys($recipientIds);
}

function buildNotificationTargetRows($userTypes, $branchIds, $departmentIds, $roleIds) {
    $userTypes = !empty($userTypes) ? $userTypes : [null];
    $branchIds = !empty($branchIds) ? $branchIds : [null];
    $departmentIds = !empty($departmentIds) ? $departmentIds : [null];
    $roleIds = !empty($roleIds) ? $roleIds : [null];

    $rows = [];
    foreach ($userTypes as $userType) {
        foreach ($branchIds as $branchId) {
            foreach ($departmentIds as $departmentId) {
                foreach ($roleIds as $roleId) {
                    $rows[] = [
                        'to_user_type' => $userType,
                        'to_branch_id' => $branchId,
                        'to_department_id' => $departmentId,
                        'to_role_id' => $roleId,
                    ];
                }
            }
        }
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => 'Invalid request.'];
        if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && !empty($_POST['notification_id']) && ctype_digit($_POST['notification_id'])) {
            $notificationId = (int)$_POST['notification_id'];
            error_log("Attempting to mark notification $notificationId as read for user {$_SESSION['user_id']}");
            $eligible = $db->preparedFetchAll(
                "SELECT 1 FROM notifications n
                    JOIN notification_to t ON n.id = t.notification_id
                    WHERE n.id = ?
                      AND (t.to_user_id = ? OR t.to_user_id IS NULL)
                      AND (t.to_branch_id = ? OR t.to_branch_id IS NULL)
                      AND (t.to_user_type = ? OR t.to_user_type IS NULL)
                      AND (t.to_department_id IS NULL OR t.to_department_id IN ($department_placeholders))
                      AND (t.to_role_id IS NULL OR t.to_role_id IN ($role_placeholders))
                    LIMIT 1",
                'iiis' . str_repeat('i', count($active_department_ids) + count($active_role_ids)),
                array_merge([$notificationId, $_SESSION['user_id'], $currentUser['branch_id'] ?? 0, $currentUser['user_type'] ?? ''], $active_department_ids, $active_role_ids)
            );
            if (!empty($eligible)) {
                $stmt = $db->executePrepared(
                    "INSERT INTO notification_read (notification_id, user_id, notification_checked, checked_at)
                        VALUES (?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE notification_checked = 1, checked_at = NOW()",
                    'ii',
                    [$notificationId, $_SESSION['user_id']]
                );
                $saved = false;
                if ($stmt) {
                    $saved = true;
                    $stmt->close();
                }
                $response = ['success' => $saved, 'message' => $saved ? 'Marked as read.' : 'Unable to mark notification as read.'];
            } else {
                $response = ['success' => false, 'message' => 'You do not have access to this notification.'];
            }
        }
        echo json_encode($response);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'mark_notification_read' && !empty($_POST['notification_id']) && ctype_digit($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        $eligible = $db->preparedFetchAll(
            "SELECT 1 FROM notifications n
                JOIN notification_to t ON n.id = t.notification_id
                WHERE n.id = ?
                  AND (t.to_user_id = ? OR t.to_user_id IS NULL)
                  AND (t.to_branch_id = ? OR t.to_branch_id IS NULL)
                  AND (t.to_user_type = ? OR t.to_user_type IS NULL)
                  AND (t.to_department_id IS NULL OR t.to_department_id IN ($department_placeholders))
                  AND (t.to_role_id IS NULL OR t.to_role_id IN ($role_placeholders))
                LIMIT 1",
            'iiis' . str_repeat('i', count($active_department_ids) + count($active_role_ids)),
            array_merge([$notificationId, $_SESSION['user_id'], $currentUser['branch_id'] ?? 0, $currentUser['user_type'] ?? ''], $active_department_ids, $active_role_ids)
        );
        if (!empty($eligible)) {
            $stmt = $db->executePrepared(
                "INSERT INTO notification_read (notification_id, user_id, notification_checked, checked_at)
                    VALUES (?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE notification_checked = 1, checked_at = NOW()",
                'ii',
                [$notificationId, $_SESSION['user_id']]
            );
            $saved = false;
            if ($stmt) {
                $saved = true;
                $stmt->close();
            }
            if (!empty($_POST['ajax']) && $_POST['ajax'] === '1') {
                echo json_encode(['success' => $saved, 'message' => $saved ? 'Marked as read.' : 'Unable to mark notification as read.']);
                exit;
            }
            if ($saved) {
                $redirect = $_POST['page_return'] ?? $_SERVER['REQUEST_URI'];
                header('Location: ' . $redirect);
                exit;
            }
            $error = 'Unable to mark notification as read.';
        } else {
            if (!empty($_POST['ajax']) && $_POST['ajax'] === '1') {
                echo json_encode(['success' => false, 'message' => 'You do not have access to this notification.']);
                exit;
            }
            $error = 'You do not have access to this notification.';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_notification') {
        if (!$can_create || empty($_POST['notification_id']) || !ctype_digit($_POST['notification_id'])) {
            $error = 'Invalid notification delete request.';
        } else {
            $deleteId = (int)$_POST['notification_id'];
            $deleted = $db->preparedExecute("DELETE FROM notification_to WHERE notification_id = ?", 'i', [$deleteId]);
            $deleted = $deleted && $db->preparedExecute("DELETE FROM notification_read WHERE notification_id = ?", 'i', [$deleteId]);
            $deleted = $deleted && $db->preparedExecute("DELETE FROM notifications WHERE id = ? AND from_user_id = ?", 'ii', [$deleteId, $_SESSION['user_id']]);
            if ($deleted) {
                $message = 'Notification deleted successfully.';
            } else {
                $error = 'Unable to delete notification.';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_notification') {
        if (!$can_create) {
            $error = 'Insufficient privileges to update notifications.';
        } elseif (empty($_POST['notification_id']) || !ctype_digit($_POST['notification_id'])) {
            $error = 'Invalid notification update request.';
        } else {
            $updateId = (int)$_POST['notification_id'];
            $type = in_array($_POST['type'] ?? '', ['info', 'warning', 'success', 'security', 'approval', 'department']) ? $_POST['type'] : 'info';
            $text = trim($_POST['message'] ?? '');
            $expires_at = trim($_POST['expires_at'] ?? '');
            $expires_at = $expires_at ? date('Y-m-d H:i:s', strtotime($expires_at)) : null;
            $to_user_types = normalizePostValues($_POST['to_user_types'] ?? [], 'all');
            if (isset($_POST['to_user_type']) && trim($_POST['to_user_type']) !== '') {
                $to_user_types = array_unique(array_merge($to_user_types, normalizePostValues($_POST['to_user_type'], 'all')));
            }
            $to_branch_ids = normalizePostIntValues($_POST['to_branch_ids'] ?? []);
            if (isset($_POST['to_branch_id']) && ctype_digit((string)$_POST['to_branch_id'])) {
                $to_branch_ids = array_unique(array_merge($to_branch_ids, [(int)$_POST['to_branch_id']]));
            }
            $to_department_ids = normalizePostIntValues($_POST['to_department_ids'] ?? []);
            if (isset($_POST['to_department_id']) && ctype_digit((string)$_POST['to_department_id'])) {
                $to_department_ids = array_unique(array_merge($to_department_ids, [(int)$_POST['to_department_id']]));
            }
            $to_role_ids = normalizePostIntValues($_POST['to_role_ids'] ?? []);
            if (isset($_POST['to_role_id']) && ctype_digit((string)$_POST['to_role_id'])) {
                $to_role_ids = array_unique(array_merge($to_role_ids, [(int)$_POST['to_role_id']]));
            }
            $to_user_ids = normalizePostIntValues($_POST['to_user_ids'] ?? []);

            if ($text === '') {
                $error = 'Please enter a notification message.';
            } else {
                $updated = $db->preparedExecute(
                    "UPDATE notifications SET type = ?, message = ?, expires_at = ? WHERE id = ? AND from_user_id = ?",
                    'ssiii',
                    [$type, $text, $expires_at, $updateId, $_SESSION['user_id']]
                );
                if ($updated) {
                    $db->preparedExecute("DELETE FROM notification_to WHERE notification_id = ?", 'i', [$updateId]);
                    $targetRows = [];
                    if (!empty($to_user_ids)) {
                        foreach ($to_user_ids as $userId) {
                            $targetRows[] = ['to_user_id' => $userId, 'to_branch_id' => null, 'to_department_id' => null, 'to_role_id' => null, 'to_user_type' => null];
                        }
                    } else {
                        $generatedRows = buildNotificationTargetRows($to_user_types, $to_branch_ids, $to_department_ids, $to_role_ids);
                        foreach ($generatedRows as $row) {
                            $targetRows[] = ['to_user_id' => null, 'to_branch_id' => $row['to_branch_id'], 'to_department_id' => $row['to_department_id'], 'to_role_id' => $row['to_role_id'], 'to_user_type' => $row['to_user_type']];
                        }
                    }
                    $targetSuccess = true;
                    foreach ($targetRows as $row) {
                        $targetSuccess = $targetSuccess && $db->preparedExecute(
                            "INSERT INTO notification_to (notification_id, to_user_id, to_branch_id, to_department_id, to_role_id, to_user_type) VALUES (?, ?, ?, ?, ?, ?)",
                            'iiiiis',
                            [$updateId, $row['to_user_id'], $row['to_branch_id'], $row['to_department_id'], $row['to_role_id'], $row['to_user_type']]
                        );
                    }
                    $message = $targetSuccess ? 'Notification updated successfully.' : 'Notification updated, but target settings could not be saved.';
                } else {
                    $error = 'Unable to update notification.';
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create_notification') {
        if (!$can_create) {
            $error = 'Insufficient privileges to create notifications.';
        } else {
            $type = in_array($_POST['type'] ?? '', ['info', 'warning', 'success', 'security', 'approval', 'department']) ? $_POST['type'] : 'info';
            $text = trim($_POST['message'] ?? '');
            $expires_at = trim($_POST['expires_at'] ?? '');
            $expires_at = $expires_at ? date('Y-m-d H:i:s', strtotime($expires_at)) : null;
            $to_user_types = normalizePostValues($_POST['to_user_types'] ?? [], 'all');
            if (isset($_POST['to_user_type']) && trim($_POST['to_user_type']) !== '') {
                $to_user_types = array_unique(array_merge($to_user_types, normalizePostValues($_POST['to_user_type'], 'all')));
            }
            $to_branch_ids = normalizePostIntValues($_POST['to_branch_ids'] ?? []);
            if (isset($_POST['to_branch_id']) && ctype_digit((string)$_POST['to_branch_id'])) {
                $to_branch_ids = array_unique(array_merge($to_branch_ids, [(int)$_POST['to_branch_id']]));
            }
            $to_department_ids = normalizePostIntValues($_POST['to_department_ids'] ?? []);
            if (isset($_POST['to_department_id']) && ctype_digit((string)$_POST['to_department_id'])) {
                $to_department_ids = array_unique(array_merge($to_department_ids, [(int)$_POST['to_department_id']]));
            }
            $to_role_ids = normalizePostIntValues($_POST['to_role_ids'] ?? []);
            if (isset($_POST['to_role_id']) && ctype_digit((string)$_POST['to_role_id'])) {
                $to_role_ids = array_unique(array_merge($to_role_ids, [(int)$_POST['to_role_id']]));
            }
            $to_user_ids = normalizePostIntValues($_POST['to_user_ids'] ?? []);

            if ($text === '') {
                $error = 'Please enter a notification message.';
            } else {
                $sql = "INSERT INTO notifications (from_user_id, type, message, expires_at) VALUES (?, ?, ?, ?)";
                $success = $db->preparedExecute($sql, 'isss', [$_SESSION['user_id'], $type, $text, $expires_at]);
                if ($success) {
                    $notification_id = $db->lastInsertId();
                    $targetRows = [];
                    if (!empty($to_user_ids)) {
                        foreach ($to_user_ids as $userId) {
                            $targetRows[] = ['to_user_id' => $userId, 'to_branch_id' => null, 'to_department_id' => null, 'to_role_id' => null, 'to_user_type' => null];
                        }
                    } else {
                        $generatedRows = buildNotificationTargetRows($to_user_types, $to_branch_ids, $to_department_ids, $to_role_ids);
                        foreach ($generatedRows as $row) {
                            $targetRows[] = ['to_user_id' => null, 'to_branch_id' => $row['to_branch_id'], 'to_department_id' => $row['to_department_id'], 'to_role_id' => $row['to_role_id'], 'to_user_type' => $row['to_user_type']];
                        }
                    }
                    $targetSuccess = true;
                    foreach ($targetRows as $row) {
                        $targetSuccess = $targetSuccess && $db->preparedExecute(
                            "INSERT INTO notification_to (notification_id, to_user_id, to_branch_id, to_department_id, to_role_id, to_user_type) VALUES (?, ?, ?, ?, ?, ?)",
                            'iiiiis',
                            [$notification_id, $row['to_user_id'], $row['to_branch_id'], $row['to_department_id'], $row['to_role_id'], $row['to_user_type']]
                        );
                    }
                    if ($targetSuccess) {
                        $message = 'Notification created successfully.';
                    } else {
                        $error = 'Notification created but target configuration failed.';
                    }
                } else {
                    $error = 'Unable to create notification. Please try again.';
                }
            }
        }
    }
}

$your_notifications = $db->preparedFetchAll(
    "SELECT n.*, u.fullname AS sender FROM notifications n LEFT JOIN bakery_users u ON u.id = n.from_user_id WHERE n.from_user_id = ? ORDER BY n.created_at DESC LIMIT 50",
    'i',
    [$_SESSION['user_id']]
);

$all_notifications = $db->preparedFetchAll(
    "SELECT n.*, u.fullname AS sender FROM notifications n LEFT JOIN bakery_users u ON u.id = n.from_user_id ORDER BY n.created_at DESC LIMIT 50",
    '',
    []
);

$your_active_notifications = array_filter(
    $your_notifications,
    function ($note) {
        return empty($note['expires_at']) || strtotime($note['expires_at']) > time();
    }
);

$department_placeholders = count($active_department_ids) ? implode(',', array_fill(0, count($active_department_ids), '?')) : '0';
$role_placeholders = count($active_role_ids) ? implode(',', array_fill(0, count($active_role_ids), '?')) : '0';
$available_types = 'iiis' . str_repeat('i', count($active_department_ids) + count($active_role_ids));
$available_params = array_merge([
    $_SESSION['user_id'],
    $_SESSION['user_id'],
    $currentUser['branch_id'] ?? 0,
    $currentUser['user_type'] ?? ''
], $active_department_ids, $active_role_ids);

$available_notifications = $db->preparedFetchAll(
    "SELECT DISTINCT n.id, n.from_user_id, n.type, n.message, n.expires_at, n.created_at, u.fullname AS sender,
        COALESCE((SELECT rr.notification_checked FROM notification_read rr WHERE rr.notification_id = n.id AND rr.user_id = ? LIMIT 1), 0) AS is_checked
    FROM notifications n
    JOIN notification_to t ON n.id = t.notification_id
    LEFT JOIN bakery_users u ON u.id = n.from_user_id
    WHERE (t.to_user_id = ? OR t.to_user_id IS NULL)
      AND (t.to_branch_id = ? OR t.to_branch_id IS NULL)
      AND (t.to_user_type = ? OR t.to_user_type IS NULL)
      AND (t.to_department_id IS NULL OR t.to_department_id IN ($department_placeholders))
      AND (t.to_role_id IS NULL OR t.to_role_id IN ($role_placeholders))
    ORDER BY n.created_at DESC
    LIMIT 50",
    $available_types,
    $available_params
);

$active_notifications = array_filter($available_notifications, function ($note) {
    return empty($note['expires_at']) || strtotime($note['expires_at']) > time();
});

$notification_read_data = [];
$notification_read_counts = [];
$notification_target_counts = [];
if (!empty($your_active_notifications)) {
    $activeIds = array_map(function ($note) {
        return (int)$note['id'];
    }, $your_active_notifications);
    $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
    $targetRows = $db->preparedFetchAll(
        "SELECT * FROM notification_to WHERE notification_id IN ($placeholders)",
        str_repeat('i', count($activeIds)),
        $activeIds
    );
    $targetRowsByNotification = [];
    foreach ($targetRows as $row) {
        $targetRowsByNotification[$row['notification_id']][] = $row;
    }

    $readRows = $db->preparedFetchAll(
        "SELECT nr.notification_id, nr.user_id, nr.checked_at, u.fullname, u.username FROM notification_read nr LEFT JOIN bakery_users u ON u.id = nr.user_id WHERE nr.notification_id IN ($placeholders)",
        str_repeat('i', count($activeIds)),
        $activeIds
    );
    foreach ($readRows as $row) {
        $noteId = (int)$row['notification_id'];
        $notification_read_data[$noteId][] = [
            'user_id' => (int)$row['user_id'],
            'fullname' => $row['fullname'] ?? '',
            'username' => $row['username'] ?? '',
            'checked_at' => $row['checked_at'] ?? null
        ];
    }
    foreach ($notification_read_data as $noteId => $readList) {
        $notification_read_counts[$noteId] = count($readList);
    }

    foreach ($your_active_notifications as $note) {
        $noteId = (int)$note['id'];
        $recipientIds = resolveNotificationRecipients($targetRowsByNotification[$noteId] ?? [], $users, $notification_user_roles);
        $notification_target_counts[$noteId] = count($recipientIds);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>View Notifications · Fingerchops Ventures</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/notifications.css">
    <link rel="icon" href="../../../logo.jpeg" type="image/jpeg">
</head>
<body class="notifications-page">
    <div class="preloader" id="preloader">
        <div class="preloader-spinner"></div>
    </div>

    <div class="dashboard-container notifications-dashboard">
        <div class="page-header departments-header">
            <div>
                <h1><i class="fas fa-bell"></i> Notifications</h1>
                <p class="page-description">Create alerts and review recent messages.</p>
                <p class="viewer-meta">Viewing as <strong><?php echo htmlspecialchars($currentUser['fullname'] ?? $currentUser['username']); ?></strong><?php if (!empty($user_info_text)): ?> <span class="viewer-meta-details"><?php echo htmlspecialchars($user_info_text); ?></span><?php endif; ?></p>
            </div>
            <div class="header-actions">
                <button type="button" class="btn btn-secondary dashboard-link" onclick="window.history.length > 1 ? window.history.back() : window.location.href='../admin-dashboard.php';"><i class="fas fa-arrow-left"></i> Back to Dashboard</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="tabs-container tabs">
            <?php if ($privilege_level >= 100): ?>
                <button type="button" class="tab-btn tab-button<?php echo $default_tab === 'all-notifications' ? ' active' : ''; ?>" data-tab="all-notifications">All Notifications</button>
            <?php endif; ?>
            <?php if ($can_create): ?>
                <button type="button" class="tab-btn tab-button<?php echo $default_tab === 'available-notifications' ? ' active' : ''; ?>" data-tab="available-notifications">Your Notifications</button>
            <?php endif; ?>
            <button type="button" class="tab-btn tab-button<?php echo $default_tab === 'your-notifications' ? ' active' : ''; ?>" data-tab="your-notifications">Your Notifications</button>
        </div>

        <div class="tab-panels">
            <div class="tab-panel<?php echo $default_tab === 'all-notifications' ? ' active' : ''; ?>" id="all-notifications">
                <section class="card notification-list-card">
                    <div class="card-header">
                        <h2>All Notifications</h2>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($all_notifications)): ?>
                            <?php foreach ($all_notifications as $note): ?>
                                <article class="notification-item" data-notification-id="<?php echo intval($note['id']); ?>" data-note-type="<?php echo htmlspecialchars(ucfirst($note['type'])); ?>" data-note-sender="<?php echo htmlspecialchars($note['sender'] ?? 'System'); ?>" data-note-time="<?php echo htmlspecialchars(date('M j, Y H:i', strtotime($note['created_at']))); ?>" data-note-expires="<?php echo htmlspecialchars(!empty($note['expires_at']) ? date('M j, Y', strtotime($note['expires_at'])) : ''); ?>" data-note-checked="0">
                                    <div class="notification-meta">
                                        <span class="note-type note-<?php echo htmlspecialchars($note['type']); ?>"><?php echo htmlspecialchars(ucfirst($note['type'])); ?></span>
                                        <span class="note-sender"><?php echo htmlspecialchars($note['sender'] ?? 'System'); ?></span>
                                        <span class="note-time"><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($note['created_at']))); ?></span>
                                    </div>
                                    <p class="notification-preview"><?php echo htmlspecialchars(truncateText($note['message'], 120)); ?></p>
                                    <div class="notification-full-message visually-hidden"><?php echo htmlspecialchars($note['message']); ?></div>
                                    <div class="notification-footer">
                                        <span>From: <?php echo htmlspecialchars($note['sender'] ?? 'System'); ?></span>
                                        <div class="notification-actions">
                                            <form method="post" class="inline-form" onsubmit="return confirm('Delete this notification?');">
                                                <input type="hidden" name="action" value="delete_notification">
                                                <input type="hidden" name="notification_id" value="<?php echo intval($note['id']); ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                            </form>
                                        </div>
                                        <?php if (!empty($note['expires_at'])): ?>
                                            <span>Expires: <?php echo htmlspecialchars(date('M j, Y', strtotime($note['expires_at']))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">No notifications have been sent yet.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <?php if ($can_create): ?>
            <div class="tab-panel<?php echo $default_tab === 'available-notifications' ? ' active' : ''; ?>" id="available-notifications">
                <section class="card notification-list-card">
                    <div class="card-header">
                        <h2>Your notifications</h2>
                        <div class="notification-filters">
                            <input type="search" id="notification-search" placeholder="Search notifications..." class="form-input" />
                        </div>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($available_notifications)): ?>                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"                        cd "c:\wamp64\www\bakery proto"
                        mysql -u root -p fingerchops_bakery < "c:\wamp64\www\bakery proto\zentire db schema.sql"
                            <?php foreach ($available_notifications as $note): ?>
                                <article class="notification-item recipient-note<?php echo empty($note['is_checked']) ? ' unread' : ' read'; ?>" data-notification-id="<?php echo intval($note['id']); ?>" data-note-type="<?php echo htmlspecialchars(ucfirst($note['type'])); ?>" data-note-sender="<?php echo htmlspecialchars($note['sender'] ?? 'System'); ?>" data-note-time="<?php echo htmlspecialchars(date('M j, Y H:i', strtotime($note['created_at']))); ?>" data-note-expires="<?php echo htmlspecialchars(!empty($note['expires_at']) ? date('M j, Y', strtotime($note['expires_at'])) : ''); ?>" data-note-checked="<?php echo intval($note['is_checked']); ?>">
                                    <div class="notification-card-row notification-card-main">
                                        <div class="notification-card-tag">
                                            <span class="note-type note-<?php echo htmlspecialchars($note['type']); ?>"><?php echo htmlspecialchars(ucfirst($note['type'])); ?></span>
                                            <span class="note-sender">From: <?php echo htmlspecialchars($note['sender'] ?? 'System'); ?></span>
                                            <strong class="notification-snippet"><?php echo htmlspecialchars(truncateText($note['message'], 24)); ?></strong>
                                        </div>
                                        <div class="notification-card-end">
                                            <?php if (empty($note['is_checked'])): ?>
                                                <button type="button" class="btn btn-secondary btn-sm mark-as-read-btn" data-notification-id="<?php echo intval($note['id']); ?>">Mark as read</button>
                                            <?php endif; ?>
                                            <div class="notification-time-stack">
                                                <span class="notification-time">Sent on: <?php echo htmlspecialchars(date('M j, Y', strtotime($note['created_at']))); ?></span>
                                                <span class="notification-status <?php echo empty($note['is_checked']) ? 'unread' : 'read'; ?>" title="<?php echo empty($note['is_checked']) ? 'Unread' : 'Read'; ?>"></span>
                                                <?php if (!empty($note['expires_at'])): ?>
                                                    <span>Expires: <?php echo htmlspecialchars(date('M j, Y', strtotime($note['expires_at']))); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="notification-full-message visually-hidden"><?php echo htmlspecialchars($note['message']); ?></div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">There are no active notifications for your account right now.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <?php endif; ?>

            <div class="tab-panel<?php echo $default_tab === 'your-notifications' ? ' active' : ''; ?>" id="your-notifications">
                <div class="section-grid">
                    <section class="card notification-list-card">
                        <div class="card-header">
                            <h2>Active Notifications</h2>
                        </div>
                        <div class="notification-list">
                            <?php if (!empty($your_active_notifications)): ?>
                                <?php foreach ($your_active_notifications as $note): ?>
                                    <article class="notification-item live-notification-item sender-note" data-notification-id="<?php echo intval($note['id']); ?>" data-note-type="<?php echo htmlspecialchars(ucfirst($note['type'])); ?>" data-note-sender="<?php echo htmlspecialchars($note['sender'] ?? 'System'); ?>" data-note-time="<?php echo htmlspecialchars(date('M j, Y H:i', strtotime($note['created_at']))); ?>" data-note-expires="<?php echo htmlspecialchars(!empty($note['expires_at']) ? date('M j, Y', strtotime($note['expires_at'])) : ''); ?>" data-note-read-count="<?php echo intval($notification_read_counts[$note['id']] ?? 0); ?>" data-note-total-count="<?php echo intval($notification_target_counts[$note['id']] ?? 0); ?>">
                                        <div class="notification-meta">
                                            <span class="note-type note-<?php echo htmlspecialchars($note['type']); ?>"><?php echo htmlspecialchars(ucfirst($note['type'])); ?></span>
                                            <span class="note-time"><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($note['created_at']))); ?></span>
                                        </div>
                                        <p class="notification-preview"><?php echo htmlspecialchars(truncateText($note['message'], 120)); ?></p>
                                        <div class="notification-full-message visually-hidden"><?php echo htmlspecialchars($note['message']); ?></div>
                                        <div class="notification-footer notification-actions">
                                            <span>ID: <?php echo intval($note['id']); ?></span>
                                            <span class="notification-target-summary">Seen: <?php echo intval($notification_read_counts[$note['id']] ?? 0); ?>/<?php echo intval($notification_target_counts[$note['id']] ?? 0); ?></span>
                                            <div>
                                                <button type="button" class="btn btn-secondary btn-sm view-notification-btn" data-notification-id="<?php echo intval($note['id']); ?>" data-sender="1"><i class="fas fa-eye"></i> View</button>
                                                <a href="?edit_id=<?php echo intval($note['id']); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this notification?');">
                                                    <input type="hidden" name="action" value="delete_notification">
                                                    <input type="hidden" name="notification_id" value="<?php echo intval($note['id']); ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">You have not created any notifications yet.</div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card notification-form-card">
                        <div class="card-header">
                            <h2><?php echo $edit_mode ? 'Edit Notification' : 'Create Notification'; ?></h2>
                        </div>
                        <form method="post" class="notification-form" id="notification-form">
                            <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update_notification' : 'create_notification'; ?>">
                            <?php if ($edit_mode): ?>
                                <input type="hidden" name="notification_id" value="<?php echo intval($edit_notification['id']); ?>">
                            <?php endif; ?>
                            <div class="form-group">
                                <label>Notification Category</label>
                                <select name="type" class="form-input" required>
                                    <option value="info"<?php echo (($edit_mode ? $edit_notification['type'] : ($_POST['type'] ?? 'info')) === 'info') ? ' selected' : ''; ?>>Info</option>
                                    <option value="success"<?php echo (($edit_mode ? $edit_notification['type'] : ($_POST['type'] ?? 'info')) === 'success') ? ' selected' : ''; ?>>Success</option>
                                    <option value="warning"<?php echo (($edit_mode ? $edit_notification['type'] : ($_POST['type'] ?? 'info')) === 'warning') ? ' selected' : ''; ?>>Warning</option>
                                    <option value="security"<?php echo (($edit_mode ? $edit_notification['type'] : ($_POST['type'] ?? 'info')) === 'security') ? ' selected' : ''; ?>>Security</option>
                                    <option value="approval"<?php echo (($edit_mode ? $edit_notification['type'] : ($_POST['type'] ?? 'info')) === 'approval') ? ' selected' : ''; ?>>Approval</option>
                                    <option value="department"<?php echo (($edit_mode ? $edit_notification['type'] : ($_POST['type'] ?? 'info')) === 'department') ? ' selected' : ''; ?>>Department</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Target User Type</label>
                                <?php
                                    $selectedUserTypes = $edit_mode ? ($edit_target['to_user_types'] ?? []) : (isset($_POST['to_user_types']) ? (array)$_POST['to_user_types'] : []);
                                    if (!is_array($selectedUserTypes)) {
                                        $selectedUserTypes = [$selectedUserTypes];
                                    }
                                ?>
                                <div class="multi-select" id="multi-target-user-type" data-name="to_user_types[]" data-all-label="All user types">
                                    <button type="button" class="multi-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                                        <div class="multi-select-chip-list"></div>
                                        <span class="multi-select-placeholder">All user types</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                    <div class="multi-select-panel" role="listbox" aria-label="Target User Type">
                                        <div class="multi-select-panel-header">
                                            <label class="multi-select-toggle-label"><input type="checkbox" class="multi-select-exclude-toggle"> All except</label>
                                            <input type="search" class="multi-select-search" placeholder="Search user types..." aria-label="Search user types">
                                        </div>
                                        <div class="multi-select-options">
                                            <label class="multi-select-option"><input type="checkbox" value="all" data-value="all"> All user types</label>
                                            <?php foreach ($user_types as $user_type): ?>
                                                <label class="multi-select-option"><input type="checkbox" value="<?php echo htmlspecialchars($user_type['user_type']); ?>" data-value="<?php echo htmlspecialchars($user_type['user_type']); ?>"> <?php echo htmlspecialchars(ucfirst($user_type['user_type'])); ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="multi-select-hidden-inputs"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Target Branch</label>
                                <?php
                                    $selectedBranchIds = $edit_mode ? ($edit_target['to_branch_ids'] ?? []) : (isset($_POST['to_branch_ids']) ? array_map('intval', (array)$_POST['to_branch_ids']) : []);
                                    if (!is_array($selectedBranchIds)) {
                                        $selectedBranchIds = [$selectedBranchIds];
                                    }
                                ?>
                                <div class="multi-select" id="multi-target-branch" data-name="to_branch_ids[]" data-all-label="All branches">
                                    <button type="button" class="multi-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                                        <div class="multi-select-chip-list"></div>
                                        <span class="multi-select-placeholder">All branches</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                    <div class="multi-select-panel" role="listbox" aria-label="Target Branch">
                                        <div class="multi-select-panel-header">
                                            <label class="multi-select-toggle-label"><input type="checkbox" class="multi-select-exclude-toggle"> All except</label>
                                            <input type="search" class="multi-select-search" placeholder="Search branches..." aria-label="Search branches">
                                        </div>
                                        <div class="multi-select-options">
                                            <label class="multi-select-option"><input type="checkbox" value="all" data-value="all"> All branches</label>
                                            <?php foreach ($branches as $branch): ?>
                                                <label class="multi-select-option"><input type="checkbox" value="<?php echo htmlspecialchars($branch['id']); ?>" data-value="<?php echo htmlspecialchars($branch['id']); ?>"> <?php echo htmlspecialchars($branch['branch_name']); ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="multi-select-hidden-inputs"></div>
                                </div>
                            </div>
                            <div class="form-group target-dependent" id="department-group" style="display:none;">
                                <label>Target Department</label>
                                <?php
                                    $selectedDepartmentIds = $edit_mode ? ($edit_target['to_department_ids'] ?? []) : (isset($_POST['to_department_ids']) ? array_map('intval', (array)$_POST['to_department_ids']) : []);
                                    if (!is_array($selectedDepartmentIds)) {
                                        $selectedDepartmentIds = [$selectedDepartmentIds];
                                    }
                                ?>
                                <div class="multi-select" id="multi-target-department" data-name="to_department_ids[]" data-all-label="All departments">
                                    <button type="button" class="multi-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                                        <div class="multi-select-chip-list"></div>
                                        <span class="multi-select-placeholder">All departments</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                    <div class="multi-select-panel" role="listbox" aria-label="Target Department">
                                        <div class="multi-select-panel-header">
                                            <label class="multi-select-toggle-label"><input type="checkbox" class="multi-select-exclude-toggle"> All except</label>
                                            <input type="search" class="multi-select-search" placeholder="Search departments..." aria-label="Search departments">
                                        </div>
                                        <div class="multi-select-options">
                                            <label class="multi-select-option"><input type="checkbox" value="all" data-value="all"> All departments</label>
                                            <?php foreach ($departments as $department): ?>
                                                <label class="multi-select-option"><input type="checkbox" value="<?php echo htmlspecialchars($department['id']); ?>" data-value="<?php echo htmlspecialchars($department['id']); ?>"> <?php echo htmlspecialchars($department['dept_name']); ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="multi-select-hidden-inputs"></div>
                                </div>
                            </div>
                            <div class="form-group target-dependent" id="role-group" style="display:none;">
                                <label>Target Role</label>
                                <?php
                                    $selectedRoleIds = $edit_mode ? ($edit_target['to_role_ids'] ?? []) : (isset($_POST['to_role_ids']) ? array_map('intval', (array)$_POST['to_role_ids']) : []);
                                    if (!is_array($selectedRoleIds)) {
                                        $selectedRoleIds = [$selectedRoleIds];
                                    }
                                ?>
                                <div class="multi-select" id="multi-target-role" data-name="to_role_ids[]" data-all-label="All roles">
                                    <button type="button" class="multi-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                                        <div class="multi-select-chip-list"></div>
                                        <span class="multi-select-placeholder">All roles</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                    <div class="multi-select-panel" role="listbox" aria-label="Target Role">
                                        <div class="multi-select-panel-header">
                                            <label class="multi-select-toggle-label"><input type="checkbox" class="multi-select-exclude-toggle"> All except</label>
                                            <input type="search" class="multi-select-search" placeholder="Search roles..." aria-label="Search roles">
                                        </div>
                                        <div class="multi-select-options">
                                            <label class="multi-select-option"><input type="checkbox" value="all" data-value="all"> All roles</label>
                                            <?php foreach ($roles as $role): ?>
                                                <label class="multi-select-option"><input type="checkbox" value="<?php echo htmlspecialchars($role['id']); ?>" data-value="<?php echo htmlspecialchars($role['id']); ?>"> <?php echo htmlspecialchars($role['role_name']); ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="multi-select-hidden-inputs"></div>
                                </div>
                            </div>
                            <div class="form-group target-dependent" id="user-group" style="display:none;">
                                <label>Target Users (optional)</label>
                                <div class="user-search-dropdown">
                                    <input type="search" class="form-input" id="target-user-search" name="target_user_search" autocomplete="off" value="<?php echo htmlspecialchars($edit_mode ? '' : ($_POST['target_user_search'] ?? '')); ?>" placeholder="Search users by name or username">
                                    <div class="user-search-results" id="target-user-results" role="listbox" aria-label="User search results"></div>
                                </div>
                                <div class="selected-users" id="selected-users">
                                    <?php
                                        $selectedUserIds = $edit_mode ? ($edit_target['to_user_ids'] ?? []) : (isset($_POST['to_user_ids']) ? array_map('intval', (array)$_POST['to_user_ids']) : []);
                                        if (!is_array($selectedUserIds)) {
                                            $selectedUserIds = [$selectedUserIds];
                                        }
                                    ?>
                                    <?php foreach ($selectedUserIds as $singleUserId): ?>
                                        <?php
                                            $singleUserId = (int)$singleUserId;
                                            $singleUserLabel = htmlspecialchars($singleUserId);
                                            foreach ($users as $user) {
                                                if ((int)$user['id'] === $singleUserId) {
                                                    $singleUserLabel = htmlspecialchars($user['fullname'] . ' (' . $user['username'] . ')');
                                                    break;
                                                }
                                            }
                                        ?>
                                        <div class="selected-user-chip" data-user-id="<?php echo $singleUserId; ?>">
                                            <span><?php echo $singleUserLabel; ?></span>
                                            <button type="button" aria-label="Remove recipient">&times;</button>
                                            <input type="hidden" name="to_user_ids[]" value="<?php echo $singleUserId; ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="message" rows="5" class="form-input" placeholder="Enter a meaningful notification message..." required><?php echo htmlspecialchars($edit_mode ? $edit_notification['message'] : ($_POST['message'] ?? '')); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label>Expires At</label>
                                <input type="datetime-local" name="expires_at" class="form-input" value="<?php echo htmlspecialchars($edit_mode ? ($edit_notification['expires_at'] ? date('Y-m-d\TH:i', strtotime($edit_notification['expires_at'])) : '') : ($_POST['expires_at'] ?? '')); ?>">
                            </div>
                            <div class="form-actions">
                                <?php if ($can_create): ?>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> <?php echo $edit_mode ? 'Update Notification' : 'Publish Notification'; ?></button>
                                <?php else: ?>
                                    <div class="alert alert-info">You need privilege level 80+ to manage notifications.</div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="notification-modal" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
                <div>
                    <h3 id="modal-title">Notification Details</h3>
                    <p class="modal-meta" id="modal-meta"></p>
                </div>
                <button type="button" class="modal-close" id="modal-close" aria-label="Close notification modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-message" id="modal-message"></div>
                <div class="modal-details" id="modal-details"></div>
                <div class="modal-readers" id="modal-readers"></div>
            </div>
        </div>
    </div>

    <form id="mark-as-read-form" method="post" class="visually-hidden">
        <input type="hidden" name="action" value="mark_notification_read">
        <input type="hidden" name="notification_id" id="mark-as-read-notification-id" value="">
        <input type="hidden" name="page_return" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
    </form>
    <script>
        const notificationUserRoles = <?php echo json_encode($notification_user_roles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const notificationUsers = <?php echo json_encode(array_map(function($user) { return ['id' => (int)$user['id'], 'fullname' => $user['fullname'], 'username' => $user['username']]; }, $users), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const notificationDepartments = <?php echo json_encode($departments, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const notificationRoles = <?php echo json_encode($roles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const notificationBranches = <?php echo json_encode($branches, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const notificationUserTypes = <?php echo json_encode(array_map(function($item){ return $item['user_type']; }, $user_types), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const notificationReadData = <?php echo json_encode($notification_read_data ?? []); ?>;
        const notificationTargetCounts = <?php echo json_encode($notification_target_counts ?? []); ?>;
        const initialTargetValues = {
            userTypes: <?php echo json_encode($selectedUserTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>,
            branchIds: <?php echo json_encode($selectedBranchIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>,
            departmentIds: <?php echo json_encode($selectedDepartmentIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>,
            roleIds: <?php echo json_encode($selectedRoleIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>
        };

        const multiSelectInstances = {};

        class MultiSelectDropdown {
            constructor(root, config) {
                this.root = root;
                this.name = root.dataset.name;
                this.allLabel = root.dataset.allLabel || 'All';
                this.options = Array.isArray(config.options) ? config.options : [];
                this.selected = Array.isArray(config.selected) ? config.selected.map(String) : [];
                this.excludeMode = !!config.excludeMode;
                this.trigger = root.querySelector('.multi-select-trigger');
                this.panel = root.querySelector('.multi-select-panel');
                this.searchInput = root.querySelector('.multi-select-search');
                this.optionsContainer = root.querySelector('.multi-select-options');
                this.hiddenInputs = root.querySelector('.multi-select-hidden-inputs');
                this.excludeToggle = root.querySelector('.multi-select-exclude-toggle');
                this.chipList = root.querySelector('.multi-select-chip-list');
                this.placeholder = root.querySelector('.multi-select-placeholder');

                this.buildOptions();
                this.setSelected(this.selected);
                this.bindEvents();
            }

            buildOptions() {
                const options = [{ value: 'all', label: this.allLabel }, ...this.options];
                this.optionsContainer.innerHTML = '';

                options.forEach((item) => {
                    const label = document.createElement('label');
                    label.className = 'multi-select-option';
                    label.setAttribute('data-value', item.value);

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = item.value;
                    checkbox.dataset.value = item.value;
                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(' ' + item.label));
                    this.optionsContainer.appendChild(label);
                });
            }

            renderOptions(filter = '') {
                const query = filter.trim().toLowerCase();
                this.optionsContainer.querySelectorAll('.multi-select-option').forEach((label) => {
                    const checkbox = label.querySelector('input');
                    const value = checkbox.dataset.value;
                    const labelText = label.textContent.toLowerCase();
                    if (value === 'all' || labelText.includes(query)) {
                        label.style.display = 'flex';
                    } else {
                        label.style.display = 'none';
                    }
                });
            }

            setSelected(values) {
                const normalized = Array.isArray(values) ? values.filter((v) => v !== null && v !== undefined).map(String) : [];
                if (normalized.includes('all')) {
                    this.selected = ['all'];
                } else {
                    this.selected = [...new Set(normalized.filter((v) => v !== 'all'))];
                }

                this.optionsContainer.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
                    checkbox.checked = this.selected.includes(checkbox.value);
                });
                const allCheckbox = this.optionsContainer.querySelector('input[value="all"]');
                if (allCheckbox) {
                    allCheckbox.checked = this.selected.includes('all');
                }

                if (this.excludeToggle) {
                    this.excludeToggle.checked = this.excludeMode;
                }

                this.updateAllCheckboxState();
                this.updateHiddenInputs();
                this.updateChips();
            }

            updateAllCheckboxState() {
                const allCheckbox = this.optionsContainer.querySelector('input[value="all"]');
                if (!allCheckbox) {
                    return;
                }
                if (this.excludeMode) {
                    if (allCheckbox.checked) {
                        this.toggleValue('all', false);
                    }
                    allCheckbox.disabled = true;
                } else {
                    allCheckbox.disabled = false;
                }
            }

            updateHiddenInputs() {
                this.hiddenInputs.innerHTML = '';
                this.hiddenInputs.appendChild(this.createInput(this.name.replace('[]', '__exclude'), this.excludeMode ? '1' : '0'));
                this.selected.forEach((value) => {
                    this.hiddenInputs.appendChild(this.createInput(this.name, value));
                });
            }

            createInput(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                return input;
            }

            updateChips() {
                this.chipList.innerHTML = '';
                const items = this.selected;

                if (items.includes('all')) {
                    this.createChip(this.allLabel, 'all');
                    this.placeholder.textContent = '';
                    return;
                }

                if (items.length === 0) {
                    this.placeholder.textContent = this.allLabel;
                    return;
                }

                items.forEach((value) => {
                    const option = this.options.find((item) => String(item.value) === String(value));
                    const label = option ? option.label : value;
                    this.createChip(label, value);
                });
                this.placeholder.textContent = '';
            }

            createChip(label, value) {
                const chip = document.createElement('span');
                chip.className = 'multi-select-chip';
                chip.textContent = label;

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'multi-select-chip-close';
                removeButton.innerHTML = '&times;';
                removeButton.addEventListener('click', (event) => {
                    event.stopPropagation();
                    this.toggleValue(value, false);
                });

                chip.appendChild(removeButton);
                this.chipList.appendChild(chip);
            }

            toggleValue(value, checked) {
                const current = new Set(this.selected);
                if (value === 'all') {
                    if (checked) {
                        current.clear();
                        current.add('all');
                    } else {
                        current.delete('all');
                    }
                } else {
                    current.delete('all');
                    if (checked) {
                        current.add(value);
                    } else {
                        current.delete(value);
                    }
                }
                this.selected = [...current];
                if (this.selected.length === 0) {
                    this.selected = ['all'];
                }
                this.setSelected(this.selected);
            }

            bindEvents() {
                if (this.trigger) {
                    this.trigger.addEventListener('click', (event) => {
                        event.stopPropagation();
                        const isOpen = this.root.classList.contains('open');
                        if (!isOpen) {
                            Object.values(multiSelectInstances).forEach((instance) => {
                                if (instance !== this) {
                                    instance.togglePanel(false);
                                }
                            });
                        }
                        this.togglePanel(!isOpen);
                    });
                }

                if (this.searchInput) {
                    this.searchInput.addEventListener('input', () => this.renderOptions(this.searchInput.value));
                }

                if (this.excludeToggle) {
                    this.excludeToggle.addEventListener('change', () => {
                        this.excludeMode = this.excludeToggle.checked;
                        this.updateAllCheckboxState();
                        this.updateHiddenInputs();
                    });
                }

                this.optionsContainer.addEventListener('change', (event) => {
                    const input = event.target;
                    if (input.tagName !== 'INPUT' || input.type !== 'checkbox') {
                        return;
                    }
                    this.toggleValue(input.value, input.checked);
                    if (typeof refreshTargetFields === 'function') {
                        refreshTargetFields();
                    }
                });

                document.addEventListener('click', (event) => {
                    if (!this.root.contains(event.target)) {
                        this.togglePanel(false);
                    }
                });
            }

            togglePanel(open) {
                if (open) {
                    this.root.classList.add('open');
                    this.trigger.setAttribute('aria-expanded', 'true');
                } else {
                    this.root.classList.remove('open');
                    this.trigger.setAttribute('aria-expanded', 'false');
                }
            }

            getSelectedValues() {
                return this.selected.includes('all') ? [] : [...this.selected];
            }
        }

        function initializeMultiSelect(id, config) {
            const root = document.getElementById(id);
            if (!root) {
                return null;
            }
            const instance = new MultiSelectDropdown(root, config);
            multiSelectInstances[id] = instance;
            return instance;
        }

        const multiTargetUserType = initializeMultiSelect('multi-target-user-type', {
            options: notificationUserTypes.map((value) => ({ value, label: value ? value.charAt(0).toUpperCase() + value.slice(1) : value })),
            selected: initialTargetValues.userTypes
        });
        const multiTargetBranch = initializeMultiSelect('multi-target-branch', {
            options: notificationBranches.map((branch) => ({ value: String(branch.id), label: branch.branch_name })),
            selected: initialTargetValues.branchIds.map(String)
        });
        const multiTargetDepartment = initializeMultiSelect('multi-target-department', {
            options: notificationDepartments.map((department) => ({ value: String(department.id), label: department.dept_name })),
            selected: initialTargetValues.departmentIds.map(String)
        });
        const multiTargetRole = initializeMultiSelect('multi-target-role', {
            options: notificationRoles.map((role) => ({ value: String(role.id), label: role.role_name })),
            selected: initialTargetValues.roleIds.map(String)
        });

        function optionSetFromFilteredRows(rows, fieldName) {
            const values = new Set();
            rows.forEach((row) => {
                if (row[fieldName] !== null && row[fieldName] !== undefined) {
                    values.add(Number(row[fieldName]));
                }
            });
            return values;
        }

        function matchesBranch(row, branchIds) {
            return branchIds.length === 0 || branchIds.includes(Number(row.branch_id));
        }

        function matchesUserType(row, userTypes) {
            return userTypes.length === 0 || (row.user_type && userTypes.includes(row.user_type));
        }

        function matchesDepartment(row, departmentIds) {
            return departmentIds.length === 0 || departmentIds.includes(Number(row.department_id));
        }

        function populateDepartmentOptions(selectedUserTypes, selectedBranchIds) {
            if (!multiTargetDepartment) {
                return;
            }
            const showAllDepartments = selectedUserTypes.length === 0 && selectedBranchIds.length === 0;
            const allowedDepartmentIds = optionSetFromFilteredRows(
                notificationUserRoles.filter((row) => matchesBranch(row, selectedBranchIds) && matchesUserType(row, selectedUserTypes) && row.department_id),
                'department_id'
            );
            multiTargetDepartment.optionsContainer.querySelectorAll('.multi-select-option').forEach((label) => {
                const input = label.querySelector('input');
                const value = input?.dataset.value;
                if (value === 'all') {
                    label.style.display = 'flex';
                } else {
                    label.style.display = showAllDepartments || allowedDepartmentIds.has(Number(value)) ? 'flex' : 'none';
                }
            });
        }

        function populateRoleOptions(selectedUserTypes, selectedBranchIds, selectedDepartmentIds) {
            if (!multiTargetRole) {
                return;
            }
            const showAllRoles = selectedUserTypes.length === 0 && selectedBranchIds.length === 0 && selectedDepartmentIds.length === 0;
            const allowedRoleIds = optionSetFromFilteredRows(
                notificationUserRoles.filter((row) => matchesBranch(row, selectedBranchIds) && matchesUserType(row, selectedUserTypes) && matchesDepartment(row, selectedDepartmentIds) && row.role_id),
                'role_id'
            );
            multiTargetRole.optionsContainer.querySelectorAll('.multi-select-option').forEach((label) => {
                const input = label.querySelector('input');
                const value = input?.dataset.value;
                if (value === 'all') {
                    label.style.display = 'flex';
                } else {
                    label.style.display = showAllRoles || allowedRoleIds.has(Number(value)) ? 'flex' : 'none';
                }
            });
        }

        function refreshTargetFields() {
            const selectedUserTypes = multiTargetUserType ? multiTargetUserType.getSelectedValues() : [];
            const selectedBranchIds = multiTargetBranch ? multiTargetBranch.getSelectedValues().map((value) => parseInt(value, 10)).filter((value) => !Number.isNaN(value)) : [];
            const selectedDepartmentIds = multiTargetDepartment ? multiTargetDepartment.getSelectedValues().map((value) => parseInt(value, 10)).filter((value) => !Number.isNaN(value)) : [];
            const selectedRoleIds = multiTargetRole ? multiTargetRole.getSelectedValues().map((value) => parseInt(value, 10)).filter((value) => !Number.isNaN(value)) : [];

            populateDepartmentOptions(selectedUserTypes, selectedBranchIds);
            populateRoleOptions(selectedUserTypes, selectedBranchIds, selectedDepartmentIds);
            updateUserSearchOptions(selectedRoleIds, selectedBranchIds, selectedUserTypes);
            updateSectionVisibility(selectedDepartmentIds, selectedRoleIds, selectedBranchIds);
        }

        function updateUserSearchOptions(selectedRoleIds, selectedBranchIds, selectedUserTypes) {
            const query = document.getElementById('target-user-search')?.value || '';
            const users = filterUsersForSearch(query, selectedRoleIds, selectedBranchIds, selectedUserTypes);
            renderUserSearchResults(users);
        }

        function filterUsersForSearch(query, selectedRoleIds, selectedBranchIds, selectedUserTypes) {
            const normalizedQuery = String(query || '').trim().toLowerCase();
            const allowedUserIds = new Set();
            notificationUserRoles.forEach((row) => {
                const userMatches = matchesBranch(row, selectedBranchIds)
                    && matchesUserType(row, selectedUserTypes)
                    && (selectedRoleIds.length === 0 || selectedRoleIds.includes(Number(row.role_id)));
                if (userMatches && row.user_id) {
                    allowedUserIds.add(Number(row.user_id));
                }
            });

            return notificationUsers.filter((user) => {
                const allowed = allowedUserIds.size === 0 || allowedUserIds.has(user.id);
                if (!allowed) {
                    return false;
                }
                if (!normalizedQuery) {
                    return true;
                }
                const label = `${user.fullname} ${user.username}`.toLowerCase();
                return label.includes(normalizedQuery) || String(user.id) === normalizedQuery;
            });
        }

        function renderUserSearchResults(users) {
            const resultBox = document.getElementById('target-user-results');
            if (!resultBox) {
                return;
            }
            resultBox.innerHTML = '';
            if (users.length === 0) {
                resultBox.style.display = 'none';
                return;
            }
            users.slice(0, 10).forEach((user) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'user-search-result';
                item.setAttribute('role', 'option');
                item.dataset.userId = String(user.id);
                item.textContent = `${user.fullname} (${user.username})`;
                item.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    addSelectedUser(user.id, `${user.fullname} (${user.username})`);
                });
                resultBox.appendChild(item);
            });
            resultBox.style.display = 'block';
        }

        function closeUserSearchResults() {
            const resultBox = document.getElementById('target-user-results');
            if (resultBox) {
                resultBox.style.display = 'none';
            }
        }

        function updateSectionVisibility(selectedDepartmentIds, selectedRoleIds, selectedBranchIds) {
            const departmentGroup = document.getElementById('department-group');
            const roleGroup = document.getElementById('role-group');
            const userGroup = document.getElementById('user-group');
            const branchAll = multiTargetBranch ? multiTargetBranch.selected.includes('all') : false;
            const departmentAll = multiTargetDepartment ? multiTargetDepartment.selected.includes('all') : false;
            const roleAll = multiTargetRole ? multiTargetRole.selected.includes('all') : false;
            const branchSelected = selectedBranchIds.length > 0 || branchAll;
            const departmentSelected = selectedDepartmentIds.length > 0 || departmentAll;
            const roleSelected = selectedRoleIds.length > 0 || roleAll;

            if (!branchSelected) {
                departmentGroup.style.display = 'none';
                roleGroup.style.display = 'block';
            } else {
                departmentGroup.style.display = 'block';
                roleGroup.style.display = departmentSelected ? 'block' : 'none';
            }
            userGroup.style.display = roleSelected ? 'block' : 'none';
        }

        const selectedUserIds = new Set();

        function addSelectedUser(userId, label) {
            const normalizedId = String(userId).trim();
            if (!normalizedId || selectedUserIds.has(normalizedId)) {
                return;
            }
            selectedUserIds.add(normalizedId);
            const container = document.getElementById('selected-users');
            const chip = document.createElement('div');
            chip.className = 'selected-user-chip';
            chip.dataset.userId = normalizedId;

            const labelSpan = document.createElement('span');
            labelSpan.textContent = label;
            chip.appendChild(labelSpan);

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.title = 'Remove recipient';
            removeButton.innerHTML = '&times;';
            removeButton.addEventListener('click', function() {
                removeSelectedUser(normalizedId);
            });
            chip.appendChild(removeButton);

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'to_user_ids[]';
            hiddenInput.value = normalizedId;
            hiddenInput.dataset.userId = normalizedId;
            chip.appendChild(hiddenInput);

            container.appendChild(chip);
        }

        function removeSelectedUser(userId) {
            const normalizedId = String(userId).trim();
            selectedUserIds.delete(normalizedId);
            const chip = document.querySelector('.selected-user-chip[data-user-id="' + normalizedId + '"]');
            if (chip) {
                chip.remove();
            }
        }

        function openNotificationModal(notificationId, isSender) {
            const article = document.querySelector('.notification-item[data-notification-id="' + notificationId + '"]');
            const modal = document.getElementById('notification-modal');
            if (!article || !modal) {
                return;
            }

            const title = modal.querySelector('#modal-title');
            const meta = modal.querySelector('#modal-meta');
            const message = modal.querySelector('#modal-message');
            const details = modal.querySelector('#modal-details');
            const readers = modal.querySelector('#modal-readers');

            const sender = article.dataset.noteSender || 'System';
            const type = article.dataset.noteType || 'Notification';
            const createdAt = article.dataset.noteTime || '';
            const expiresAt = article.dataset.noteExpires || '';
            const bodyText = article.querySelector('.notification-full-message')?.textContent || '';
            const readCount = article.dataset.noteReadCount || '0';
            const totalCount = article.dataset.noteTotalCount || '0';
            let checked = article.dataset.noteChecked === '1';

            title.textContent = type + ' Notification';
            meta.innerHTML = '<strong>From:</strong> ' + sender + '<br><strong>Sent:</strong> ' + createdAt + (expiresAt ? ' · <strong>Expires:</strong> ' + expiresAt : '');
            message.innerHTML = bodyText.replace(/\n/g, '<br>');
            details.innerHTML = isSender ? '<p class="modal-stats">Seen by ' + readCount + ' of ' + totalCount + ' recipients.</p>' : '';
            readers.innerHTML = '';

            if (!isSender && !checked) {
                markNotificationRead(notificationId, article).then((wasMarked) => {
                    if (wasMarked) {
                        checked = true;
                        const detailText = modal.querySelector('.modal-stats');
                        if (detailText) {
                            detailText.textContent = 'This notification is now marked read.';
                        }
                    }
                });
            }

            if (isSender) {
                const readerList = notificationReadData[notificationId] || [];
                if (readerList.length > 0) {
                    const listTitle = document.createElement('p');
                    listTitle.className = 'modal-stats';
                    listTitle.textContent = 'Readers:';
                    readers.appendChild(listTitle);
                    const list = document.createElement('ul');
                    list.className = 'readers-list';
                    readerList.forEach((reader) => {
                        const item = document.createElement('li');
                        item.textContent = reader.fullname + ' (' + reader.username + ')' + (reader.checked_at ? ' — ' + reader.checked_at : '');
                        list.appendChild(item);
                    });
                    readers.appendChild(list);
                } else {
                    readers.innerHTML = '<p class="modal-stats">No recipients have opened this notification yet.</p>';
                }
            } else if (!checked) {
                markNotificationRead(notificationId, article, false);
            }

            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (!modal) {
                return;
            }
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            window.location.reload();
        }

        function submitMarkAsReadForm(notificationId) {
            const form = document.getElementById('mark-as-read-form');
            const input = document.getElementById('mark-as-read-notification-id');
            if (form && input) {
                input.value = notificationId;
                form.submit();
            }
        }

        function markNotificationRead(notificationId, article, reloadOnSuccess = false) {
            return fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'mark_notification_read',
                    notification_id: notificationId,
                    ajax: '1'
                })
            })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    article.classList.remove('unread');
                    article.classList.add('read');
                    article.dataset.noteChecked = '1';
                    let status = article.querySelector('.notification-status');
                    if (!status) {
                        status = document.createElement('span');
                        status.className = 'notification-status';
                        status.title = 'Read';
                        const timeStack = article.querySelector('.notification-time-stack');
                        if (timeStack) {
                            timeStack.appendChild(status);
                        } else {
                            article.appendChild(status);
                        }
                    }
                    status.classList.remove('unread');
                    status.classList.add('read');
                    status.title = 'Read';
                    const markButton = article.querySelector('.mark-as-read-btn');
                    if (markButton) {
                        const statusText = document.createElement('span');
                        statusText.className = 'notification-status-text';
                        statusText.textContent = 'Read';
                        markButton.replaceWith(statusText);
                    }
                    const footerStatus = article.querySelector('.notification-status-text');
                    if (footerStatus) {
                        footerStatus.textContent = 'Read';
                    }
                    if (reloadOnSuccess) {
                        window.location.reload();
                        return true;
                    }
                    return true;
                }
                console.warn('Failed to mark notification read via AJAX:', data.message || data);
                submitMarkAsReadForm(notificationId);
                return false;
            })
            .catch((error) => {
                console.warn('AJAX mark-read failed, falling back to form submit.', error);
                submitMarkAsReadForm(notificationId);
                return false;
            });
        }

        function setupNotificationModalEvents() {
            const modal = document.getElementById('notification-modal');
            const closeButton = document.getElementById('modal-close');
            if (modal) {
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) {
                        closeNotificationModal();
                    }
                });
            }
            if (closeButton) {
                closeButton.addEventListener('click', closeNotificationModal);
            }

            document.body.addEventListener('click', (event) => {
                const markReadButton = event.target.closest('.mark-as-read-btn');
                if (markReadButton) {
                    const notificationId = markReadButton.dataset.notificationId;
                    const article = document.querySelector('.notification-item[data-notification-id="' + notificationId + '"]');
                    if (article) {
                        markNotificationRead(notificationId, article, true);
                    }
                    return;
                }

                // prefer explicit view button
                const button = event.target.closest('.view-notification-btn');
                if (button) {
                    const notificationId = button.dataset.notificationId;
                    const isSender = button.dataset.sender === '1';
                    openNotificationModal(notificationId, isSender);
                    return;
                }

                // clicking an article should open the notification (ignore clicks on controls)
                const article = event.target.closest('.notification-item');
                if (article) {
                    if (event.target.closest('button, a, form, input')) return;
                    const notificationId = article.dataset.notificationId;
                    const isSender = article.classList.contains('sender-note');
                    openNotificationModal(notificationId, isSender);
                }
            });
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab-button').forEach((button) => {
                button.classList.toggle('active', button.dataset.tab === tabName);
            });
            document.querySelectorAll('.tab-panel').forEach((panel) => {
                panel.classList.toggle('active', panel.id === tabName);
            });
        }

        window.addEventListener('load', function() {
            const preloader = document.getElementById('preloader');
            if (preloader) {
                const spinner = preloader.querySelector('.preloader-spinner');
                if (spinner) {
                    const spinnerColors = ['#ef4444', '#2563eb', '#22c55e', '#facc15'];
                    let spinnerColorIndex = 0;
                    spinner.style.borderTopColor = spinnerColors[spinnerColorIndex];
                    spinner.addEventListener('animationiteration', function() {
                        spinnerColorIndex = (spinnerColorIndex + 1) % spinnerColors.length;
                        spinner.style.borderTopColor = spinnerColors[spinnerColorIndex];
                    });
                }
                setTimeout(function() {
                    preloader.classList.add('fade-out');
                    setTimeout(function() {
                        if (preloader) {
                            preloader.style.display = 'none';
                        }
                    }, 500);
                }, 400);
            }
            refreshTargetFields();
            const searchInput = document.getElementById('target-user-search');
            const resultBox = document.getElementById('target-user-results');
            const notifSearch = document.getElementById('notification-search');

            function filterNotifications(query) {
                const list = document.querySelectorAll('#available-notifications .notification-list .notification-item');
                const q = (query || '').trim().toLowerCase();
                list.forEach((item) => {
                    if (!q) {
                        item.style.display = '';
                        return;
                    }
                    const preview = (item.querySelector('.notification-preview')?.textContent || '').toLowerCase();
                    const full = (item.querySelector('.notification-full-message')?.textContent || '').toLowerCase();
                    const sender = (item.dataset.noteSender || '').toLowerCase();
                    const type = (item.dataset.noteType || '').toLowerCase();
                    if (preview.includes(q) || full.includes(q) || sender.includes(q) || type.includes(q)) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }

            const updateSearchResults = () => {
                const selectedRoleIds = multiTargetRole ? multiTargetRole.getSelectedValues().map((value) => parseInt(value, 10)).filter((value) => !Number.isNaN(value)) : [];
                const selectedBranchIds = multiTargetBranch ? multiTargetBranch.getSelectedValues().map((value) => parseInt(value, 10)).filter((value) => !Number.isNaN(value)) : [];
                const selectedUserTypes = multiTargetUserType ? multiTargetUserType.getSelectedValues() : [];
                updateUserSearchOptions(selectedRoleIds, selectedBranchIds, selectedUserTypes);
            };

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    updateSearchResults();
                });
                searchInput.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        const firstResult = resultBox?.querySelector('.user-search-result');
                        if (firstResult) {
                            addSelectedUser(firstResult.dataset.userId, firstResult.textContent || '');
                            this.value = '';
                            closeUserSearchResults();
                        }
                    }
                });
                searchInput.addEventListener('blur', function() {
                    setTimeout(closeUserSearchResults, 150);
                });
                searchInput.addEventListener('focus', updateSearchResults);
            }

            if (notifSearch) {
                notifSearch.addEventListener('input', function() {
                    filterNotifications(this.value);
                });
                notifSearch.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        filterNotifications('');
                    }
                });
            }

            document.querySelectorAll('#selected-users .selected-user-chip').forEach((chip) => {
                const userId = chip.dataset.userId;
                if (userId) {
                    selectedUserIds.add(userId);
                    const button = chip.querySelector('button');
                    if (button) {
                        button.addEventListener('click', function() {
                            removeSelectedUser(userId);
                        });
                    }
                }
            });

            // support both department-style and legacy buttons
            document.querySelectorAll('.tab-button, .tab-btn').forEach((button) => {
                button.addEventListener('click', function() {
                    switchTab(this.dataset.tab);
                });
            });
            setupNotificationModalEvents();
            switchTab('<?php echo $default_tab; ?>');
        });
    </script>
</body>
</html>
