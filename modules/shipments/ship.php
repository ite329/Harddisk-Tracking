<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

function getCurrentLoginNamesForShip(): array
{
    $names = [];
    $add = static function ($value) use (&$names): void {
        $value = trim((string)($value ?? ''));
        if ($value !== '' && !in_array($value, $names, true)) {
            $names[] = $value;
        }
    };

    $fullName = trim((string)($_SESSION['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
    }
    $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));

    $add($fullName);
    $add($employeeCode);
    $add($_SESSION['name'] ?? '');
    $add($_SESSION['username'] ?? '');
    if ($fullName !== '' && $employeeCode !== '') {
        $add($fullName . ' (' . $employeeCode . ')');
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $user = $_SESSION['user'];
        $userFullName = trim((string)($user['full_name'] ?? ''));
        if ($userFullName === '') {
            $userFullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }
        $userEmployeeCode = trim((string)($user['employee_code'] ?? ''));
        $add($userFullName);
        $add($userEmployeeCode);
        $add($user['name'] ?? '');
        $add($user['username'] ?? '');
        if ($userFullName !== '' && $userEmployeeCode !== '') {
            $add($userFullName . ' (' . $userEmployeeCode . ')');
        }
    }

    return $names;
}

function getCurrentDisplayNameForShip(array $names): string
{
    foreach ($names as $name) {
        if (preg_match('/[ก-๙]/u', $name)) {
            return $name;
        }
    }
    return $names[0] ?? '';
}

function currentEmployeeCodeForShip(): string
{
    return trim((string)($_SESSION['employee_code'] ?? ($_SESSION['user']['employee_code'] ?? '')));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ../requests/matched.php');
    exit;
}

verify_csrf();

$currentLoginNames = getCurrentLoginNamesForShip();
$currentDisplayName = getCurrentDisplayNameForShip($currentLoginNames);
$isCrossUserShipmentApprover = currentEmployeeCodeForShip() === '14329';

$requestIds = [];
if (!empty($_POST['request_ids']) && is_array($_POST['request_ids'])) {
    foreach ($_POST['request_ids'] as $requestId) {
        $requestId = (int)$requestId;
        if ($requestId > 0 && !in_array($requestId, $requestIds, true)) {
            $requestIds[] = $requestId;
        }
    }
} else {
    $requestId = (int)($_POST['request_id'] ?? 0);
    if ($requestId > 0) {
        $requestIds[] = $requestId;
    }
}

$isBulk = !empty($_POST['bulk_action']) || count($requestIds) > 1;
if (!$requestIds || !$currentLoginNames || $currentDisplayName === '') {
    header('Location: ../requests/matched.php?error=invalid');
    exit;
}

$ownerConditions = [];
$ownerParams = [];
if (!$isCrossUserShipmentApprover) {
    $requested = [];
    $matched = [];
    foreach (array_values($currentLoginNames) as $index => $name) {
        $requestedKey = ':requested_by_' . $index;
        $matchedKey = ':matched_by_' . $index;
        $requested[] = $requestedKey;
        $matched[] = $matchedKey;
        $ownerParams[$requestedKey] = $name;
        $ownerParams[$matchedKey] = $name;
    }
    $ownerConditions[] = '(TRIM(requested_by) IN (' . implode(', ', $requested) . ') OR TRIM(matched_by) IN (' . implode(', ', $matched) . '))';
}

$successCount = 0;
$skippedCount = 0;

try {
    $pdo->beginTransaction();

    foreach ($requestIds as $requestId) {
        $requestSql = "
            SELECT id, request_no, main_branch_code, branch_code, branch_name,
                   request_reason, status, requested_by, requested_at, matched_by,
                   matched_at, hdd_inventory_id, hdd_serial
            FROM harddisk_delivery_requests
            WHERE id = :id
              AND deleted_at IS NULL
              AND status = 'matched'";
        if ($ownerConditions) {
            $requestSql .= ' AND ' . implode(' AND ', $ownerConditions);
        }
        $requestSql .= ' LIMIT 1 FOR UPDATE';

        $requestStmt = $pdo->prepare($requestSql);
        $requestStmt->bindValue(':id', $requestId, PDO::PARAM_INT);
        foreach ($ownerParams as $key => $value) {
            $requestStmt->bindValue($key, $value);
        }
        $requestStmt->execute();
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $skippedCount++;
            continue;
        }

        $hddSerial = trim((string)($request['hdd_serial'] ?? ''));
        if ($hddSerial === '') {
            $itemStmt = $pdo->prepare("SELECT hdd_serial FROM harddisk_request_items WHERE request_id = :request_id AND scan_status = 'matched' ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $itemStmt->execute([':request_id' => $requestId]);
            $hddSerial = trim((string)($itemStmt->fetchColumn() ?: ''));
        }
        if ($hddSerial === '') {
            $skippedCount++;
            continue;
        }

        $checkStmt = $pdo->prepare('SELECT id FROM harddisk_shipments WHERE deleted_at IS NULL AND request_id = :request_id LIMIT 1 FOR UPDATE');
        $checkStmt->execute([':request_id' => $requestId]);
        if ($checkStmt->fetchColumn()) {
            $skippedCount++;
            continue;
        }

        $insertStmt = $pdo->prepare("INSERT INTO harddisk_shipments (request_id, delivery_request_no, main_branch_code, branch_code, branch_name, hdd_serial, status, created_by, created_at, shipped_at) VALUES (:request_id, :delivery_request_no, :main_branch_code, :branch_code, :branch_name, :hdd_serial, 'shipped', :created_by, NOW(), NOW())");
        $insertStmt->execute([
            ':request_id' => $request['id'],
            ':delivery_request_no' => $request['request_no'],
            ':main_branch_code' => $request['main_branch_code'],
            ':branch_code' => $request['branch_code'],
            ':branch_name' => $request['branch_name'],
            ':hdd_serial' => $hddSerial,
            ':created_by' => $currentDisplayName,
        ]);

        $updateRequestStmt = $pdo->prepare("UPDATE harddisk_delivery_requests SET status = 'shipped', shipped_by = :shipped_by, shipped_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'matched'");
        $updateRequestStmt->execute([':shipped_by' => $currentDisplayName, ':id' => $requestId]);
        if ($updateRequestStmt->rowCount() !== 1) {
            throw new RuntimeException('สถานะรายการเปลี่ยนระหว่างดำเนินการ');
        }

        if (!empty($request['hdd_inventory_id'])) {
            $inventoryStmt = $pdo->prepare("UPDATE harddisk_inventory SET status = 'shipped', updated_at = NOW() WHERE id = :hdd_inventory_id AND deleted_at IS NULL");
            $inventoryStmt->execute([':hdd_inventory_id' => (int)$request['hdd_inventory_id']]);
        } else {
            $inventoryStmt = $pdo->prepare("UPDATE harddisk_inventory SET status = 'shipped', updated_at = NOW() WHERE BINARY hdd_serial = :hdd_serial AND deleted_at IS NULL");
            $inventoryStmt->execute([':hdd_serial' => $hddSerial]);
        }

        $successCount++;
    }

    $pdo->commit();

    if ($isBulk) {
        header('Location: ../requests/matched.php?bulk_success=' . $successCount . '&bulk_skipped=' . $skippedCount);
    } elseif ($successCount === 1) {
        header('Location: ../requests/matched.php?success=1');
    } else {
        header('Location: ../requests/matched.php?error=not_found');
    }
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[ship.php] ' . $e->getMessage());
    header('Location: ../requests/matched.php?error=ship_failed');
    exit;
}
