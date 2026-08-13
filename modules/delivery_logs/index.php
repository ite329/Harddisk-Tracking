<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/permissions.php';

require_login();
if (function_exists('can') && !can('delivery_log.view') && !can('wcs_quote.view')) {
    require_permission('delivery_log.view');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'บันทึกรายการส่งของ';
date_default_timezone_set('Asia/Bangkok');

function dlE($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dlTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute([':t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function dlColumns(PDO $pdo, string $table): array
{
    if (!dlTableExists($pdo, $table)) return [];
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute([':t' => $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function dlCurrentUser(): string
{
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    if ($name === '') $name = trim((string)(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
    $code = trim((string)($_SESSION['employee_code'] ?? ''));
    if ($name !== '' && $code !== '') return $name . ' (' . $code . ')';
    if ($name !== '') return $name;
    if ($code !== '') return $code;
    return 'IT';
}

function dlDisplayUserName($value): string
{
    $displayName = trim((string)($value ?? ''));
    if ($displayName === '') return '-';
    return trim((string)preg_replace('/\s*\([^()]+\)\s*$/u', '', $displayName));
}

function dlGenerateNo(PDO $pdo, string $date): string
{
    $ymd = date('ymd', strtotime($date));
    $prefix = 'DL' . $ymd;
    $stmt = $pdo->prepare("SELECT delivery_no FROM delivery_headers WHERE delivery_no LIKE :p ORDER BY id DESC LIMIT 1");
    $stmt->execute([':p' => $prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $running = 1;
    if ($last !== '' && preg_match('/(\d{4})$/', $last, $m)) $running = (int)$m[1] + 1;
    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}


function dlSyncBranchLabelHistory(PDO $pdo): array
{
    if (!dlTableExists($pdo, 'branch_label_print_history')) {
        throw new RuntimeException('ไม่พบตารางประวัติการพิมพ์ที่อยู่สาขา branch_label_print_history');
    }

    $historyColumns = dlColumns($pdo, 'branch_label_print_history');
    foreach (['id', 'main_branch_code', 'branch_code', 'branch_name', 'asset_name', 'printed_by_name', 'printed_by_employee_code', 'printed_at'] as $requiredColumn) {
        if (!in_array($requiredColumn, $historyColumns, true)) {
            throw new RuntimeException('ตาราง branch_label_print_history ไม่มีคอลัมน์ ' . $requiredColumn);
        }
    }

    $sourceSelect = in_array('print_source', $historyColumns, true) ? 'h.print_source' : "'direct_branch' AS print_source";
    $orientationSelect = in_array('print_orientation', $historyColumns, true) ? 'h.print_orientation' : "'' AS print_orientation";
    $addressSelect = in_array('shipping_address', $historyColumns, true) ? 'h.shipping_address' : "'' AS shipping_address";
    $directoryColumns = dlColumns($pdo, 'branch_directory');
    $mainBranchNameExpression = in_array('branch_name_2', $directoryColumns, true)
        ? "COALESCE(bd_main.branch_name, bd_main.branch_name_2, '')"
        : "COALESCE(bd_main.branch_name, '')";
    $subBranchNameExpression = in_array('branch_name_2', $directoryColumns, true)
        ? "COALESCE(NULLIF(TRIM(bd_sub.branch_name), ''), NULLIF(TRIM(bd_sub.branch_name_2), ''), '')"
        : "COALESCE(NULLIF(TRIM(bd_sub.branch_name), ''), '')";

    $sql = "SELECT h.id, h.main_branch_code, h.branch_code, h.branch_name, h.asset_name,
                   h.printed_by_name, h.printed_by_employee_code, h.printed_at,
                   {$sourceSelect}, {$orientationSelect}, {$addressSelect},
                   COALESCE((
                       SELECT NULLIF(TRIM({$mainBranchNameExpression}), '')
                       FROM branch_directory bd_main
                       WHERE LPAD(TRIM(CAST(bd_main.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(h.main_branch_code AS CHAR)), 3, '0')
                         AND (TRIM(COALESCE(bd_main.branch_type, '')) = 'สาขาใหญ่'
                              OR LOWER(TRIM(COALESCE(bd_main.branch_type, ''))) IN ('main', 'main branch', 'head branch', 'large branch')
                              OR TRIM(COALESCE(bd_main.branch_type, '')) LIKE '%ใหญ่%')
                       ORDER BY CASE WHEN TRIM(CAST(bd_main.branch_code AS CHAR)) = TRIM(CAST(h.main_branch_code AS CHAR)) THEN 0 ELSE 1 END,
                                bd_main.branch_code ASC
                       LIMIT 1
                   ), NULLIF(TRIM(h.branch_name), ''), LPAD(TRIM(CAST(h.main_branch_code AS CHAR)), 3, '0')) AS resolved_main_branch_name,
                   COALESCE((
                       SELECT NULLIF(TRIM({$subBranchNameExpression}), '')
                       FROM branch_directory bd_sub
                       WHERE TRIM(CAST(bd_sub.branch_code AS CHAR)) = TRIM(CAST(h.branch_code AS CHAR))
                         AND LPAD(TRIM(CAST(bd_sub.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(h.main_branch_code AS CHAR)), 3, '0')
                       ORDER BY bd_sub.branch_code ASC
                       LIMIT 1
                   ), NULLIF(TRIM(h.branch_name), '')) AS resolved_sub_branch_name
            FROM branch_label_print_history h
            WHERE NOT EXISTS (
                SELECT 1
                FROM delivery_headers dh
                WHERE dh.reference_no = CONCAT('BRANCH_LABEL_HISTORY:', h.id)
            )
            ORDER BY h.printed_at ASC, h.id ASC
            LIMIT 2000";
    $historyRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$historyRows) {
        return ['saved' => 0, 'skipped' => 0];
    }

    $headerInsert = $pdo->prepare("INSERT INTO delivery_headers
        (delivery_no, delivery_date, branch_code, main_branch_name, sub_branch_name, carrier, tracking_no, reference_no, remark, shipping_cost, created_by, updated_by)
        VALUES (:delivery_no, :delivery_date, :branch_code, :main_branch_name, :sub_branch_name, '', '', :reference_no, :remark, 0, :created_by, :updated_by)");
    $itemInsert = $pdo->prepare("INSERT INTO delivery_items (delivery_id, item_type, quantity, item_detail)
        VALUES (:delivery_id, :item_type, 1, :item_detail)");

    $saved = 0;
    $skipped = 0;
    $pdo->beginTransaction();
    try {
        foreach ($historyRows as $historyRow) {
            $historyId = (int)($historyRow['id'] ?? 0);
            $printedAt = trim((string)($historyRow['printed_at'] ?? ''));
            $deliveryDate = $printedAt !== '' ? date('Y-m-d', strtotime($printedAt)) : date('Y-m-d');
            $branchCode = trim((string)($historyRow['branch_code'] ?? ''));
            $mainBranchName = trim((string)($historyRow['resolved_main_branch_name'] ?? ''));
            $branchName = trim((string)($historyRow['resolved_sub_branch_name'] ?? $historyRow['branch_name'] ?? ''));
            $assetName = trim((string)($historyRow['asset_name'] ?? ''));

            if ($historyId <= 0 || $branchCode === '' || $mainBranchName === '') {
                $skipped++;
                continue;
            }

            $subBranchName = $branchName;
            if ($subBranchName !== '' && strcasecmp($subBranchName, $mainBranchName) === 0) {
                $subBranchName = '';
            }

            $printedByName = trim((string)($historyRow['printed_by_name'] ?? ''));
            $printedByCode = trim((string)($historyRow['printed_by_employee_code'] ?? ''));
            $createdBy = $printedByName;
            if ($createdBy !== '' && $printedByCode !== '') {
                $createdBy .= ' (' . $printedByCode . ')';
            } elseif ($createdBy === '') {
                $createdBy = $printedByCode !== '' ? $printedByCode : 'ซิงค์จากประวัติการพิมพ์ที่อยู่สาขา';
            }

            $referenceNo = 'BRANCH_LABEL_HISTORY:' . $historyId;
            $remarkParts = ['ซิงค์จากประวัติการพิมพ์ที่อยู่สาขา'];
            $shippingAddress = trim((string)($historyRow['shipping_address'] ?? ''));
            if ($shippingAddress !== '') {
                $remarkParts[] = 'ที่อยู่: ' . $shippingAddress;
            }
            $remark = implode(' | ', $remarkParts);

            $deliveryNo = dlGenerateNo($pdo, $deliveryDate);
            $headerInsert->execute([
                ':delivery_no' => $deliveryNo,
                ':delivery_date' => $deliveryDate,
                ':branch_code' => $branchCode,
                ':main_branch_name' => $mainBranchName,
                ':sub_branch_name' => $subBranchName,
                ':reference_no' => $referenceNo,
                ':remark' => $remark,
                ':created_by' => $createdBy,
                ':updated_by' => $createdBy,
            ]);
            $deliveryId = (int)$pdo->lastInsertId();

            $itemInsert->execute([
                ':delivery_id' => $deliveryId,
                ':item_type' => $assetName !== '' ? $assetName : '-',
                ':item_detail' => null,
            ]);
            $saved++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['saved' => $saved, 'skipped' => $skipped];
}

function dlCountPendingBranchLabelSync(PDO $pdo): int
{
    if (!dlTableExists($pdo, 'branch_label_print_history') || !dlTableExists($pdo, 'delivery_headers')) {
        return 0;
    }

    $stmt = $pdo->query("SELECT COUNT(*)
        FROM branch_label_print_history h
        WHERE NOT EXISTS (
            SELECT 1
            FROM delivery_headers dh
            WHERE dh.reference_no = CONCAT('BRANCH_LABEL_HISTORY:', h.id)
        )");

    return (int)$stmt->fetchColumn();
}

function dlRunBranchLabelHistorySync(PDO $pdo): array
{
    $lockName = 'delivery_logs_branch_label_history_sync';
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 3)');
    $lockStmt->execute([':lock_name' => $lockName]);
    $locked = (int)$lockStmt->fetchColumn() === 1;

    if (!$locked) {
        return ['saved' => 0, 'skipped' => 0, 'locked' => true];
    }

    try {
        $result = dlSyncBranchLabelHistory($pdo);
        $result['locked'] = false;
        return $result;
    } finally {
        try {
            $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseStmt->execute([':lock_name' => $lockName]);
        } catch (Throwable $releaseError) {
            error_log('[delivery_logs/index] Cannot release auto-sync lock: ' . $releaseError->getMessage());
        }
    }
}

function dlBackfillSyncedSubBranchNames(PDO $pdo): int
{
    if (!dlTableExists($pdo, 'branch_label_print_history') || !dlTableExists($pdo, 'branch_directory')) {
        return 0;
    }

    $directoryColumns = dlColumns($pdo, 'branch_directory');
    $subBranchNameExpression = in_array('branch_name_2', $directoryColumns, true)
        ? "COALESCE(NULLIF(TRIM(bd.branch_name), ''), NULLIF(TRIM(bd.branch_name_2), ''), '')"
        : "COALESCE(NULLIF(TRIM(bd.branch_name), ''), '')";

    $sql = "SELECT dh.id,
                   COALESCE((
                       SELECT NULLIF(TRIM({$subBranchNameExpression}), '')
                       FROM branch_directory bd
                       WHERE TRIM(CAST(bd.branch_code AS CHAR)) = TRIM(CAST(h.branch_code AS CHAR))
                         AND LPAD(TRIM(CAST(bd.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(h.main_branch_code AS CHAR)), 3, '0')
                       ORDER BY bd.branch_code ASC
                       LIMIT 1
                   ), NULLIF(TRIM(h.branch_name), '')) AS resolved_sub_branch_name
            FROM delivery_headers dh
            INNER JOIN branch_label_print_history h
                ON dh.reference_no = CONCAT('BRANCH_LABEL_HISTORY:', h.id)
            WHERE dh.deleted_at IS NULL
              AND (dh.sub_branch_name IS NULL OR TRIM(dh.sub_branch_name) = '')";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return 0;
    }

    $update = $pdo->prepare("UPDATE delivery_headers
        SET sub_branch_name = :sub_branch_name, updated_at = NOW()
        WHERE id = :id AND deleted_at IS NULL");
    $updated = 0;
    foreach ($rows as $row) {
        $subBranchName = trim((string)($row['resolved_sub_branch_name'] ?? ''));
        if ($subBranchName === '') {
            continue;
        }
        $update->execute([
            ':sub_branch_name' => $subBranchName,
            ':id' => (int)$row['id'],
        ]);
        $updated += $update->rowCount();
    }

    return $updated;
}


if (($_GET['action'] ?? '') === 'bulk_branch_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $mainBranchCode = trim((string)($_GET['main_branch_code'] ?? ''));
        if (!preg_match('/^\d{1,3}$/', $mainBranchCode)) {
            throw new RuntimeException('รหัสสาขาใหญ่ไม่ถูกต้อง');
        }
        $mainBranchCode = str_pad($mainBranchCode, 3, '0', STR_PAD_LEFT);

        if (!dlTableExists($pdo, 'branch_directory')) {
            throw new RuntimeException('ไม่พบตาราง branch_directory');
        }

        $branchColumns = dlColumns($pdo, 'branch_directory');
        foreach (['main_branch_code', 'branch_code', 'branch_name', 'branch_type'] as $requiredColumn) {
            if (!in_array($requiredColumn, $branchColumns, true)) {
                throw new RuntimeException('ตาราง branch_directory ไม่มีคอลัมน์ ' . $requiredColumn);
            }
        }

        $branchName2Select = in_array('branch_name_2', $branchColumns, true)
            ? 'branch_name_2'
            : "'' AS branch_name_2";

        $sql = "SELECT main_branch_code, branch_code, branch_name, {$branchName2Select}, branch_type
                FROM branch_directory
                WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') = :main_branch_code";
        if (in_array('is_active', $branchColumns, true)) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY branch_code ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':main_branch_code' => $mainBranchCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $rows,
            'total' => count($rows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$tablesReady = dlTableExists($pdo, 'delivery_headers') && dlTableExists($pdo, 'delivery_items');
$branchLabelHistoryReady = dlTableExists($pdo, 'branch_label_print_history');
$pendingBranchLabelSync = 0;
if ($tablesReady && $branchLabelHistoryReady) {
    try {
        dlBackfillSyncedSubBranchNames($pdo);
        $pendingBranchLabelSync = dlCountPendingBranchLabelSync($pdo);
    } catch (Throwable $pendingSyncError) {
        error_log('[delivery_logs/index] Cannot load pending branch label history: ' . $pendingSyncError->getMessage());
    }
}
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesReady) {
    try {
        if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('CSRF Token ไม่ถูกต้อง');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'sync_branch_label_history') {
            if (function_exists('can') && !can('delivery_log.create') && !can('wcs_quote.view')) require_permission('delivery_log.create');
            $syncResult = dlRunBranchLabelHistorySync($pdo);
            header('Location: index.php?sync_saved=' . (int)$syncResult['saved'] . '&sync_skipped=' . (int)$syncResult['skipped']);
            exit;
        }
        if ($action === 'save') {
            if (function_exists('can') && !can('delivery_log.create') && !can('wcs_quote.view')) require_permission('delivery_log.create');
            $id = max(0, (int)($_POST['id'] ?? 0));
            $date = trim((string)($_POST['delivery_date'] ?? ''));
            $branchCode = trim((string)($_POST['branch_code'] ?? ''));
            $mainBranch = trim((string)($_POST['main_branch_name'] ?? ''));
            $subBranch = trim((string)($_POST['sub_branch_name'] ?? ''));
            $carrier = trim((string)($_POST['carrier'] ?? ''));
            $tracking = trim((string)($_POST['tracking_no'] ?? ''));
            $reference = trim((string)($_POST['reference_no'] ?? ''));
            $remark = trim((string)($_POST['remark'] ?? ''));
            $shippingCost = max(0, (float)($_POST['shipping_cost'] ?? 0));
            $types = $_POST['item_type'] ?? [];
            $qtys = $_POST['quantity'] ?? [];
            $details = $_POST['item_detail'] ?? [];
            if ($date === '' || $branchCode === '' || $mainBranch === '') throw new RuntimeException('กรุณากรอกวันที่และข้อมูลสาขาให้ครบ');
            $items = [];
            foreach ($types as $i => $type) {
                $type = trim((string)$type);
                $qty = max(0, (int)($qtys[$i] ?? 0));
                if ($type !== '' && $qty > 0) $items[] = ['type'=>$type,'qty'=>$qty,'detail'=>trim((string)($details[$i] ?? ''))];
            }
            if (!$items) throw new RuntimeException('กรุณาเพิ่มรายการอุปกรณ์อย่างน้อย 1 รายการ');
            $pdo->beginTransaction();
            if ($id > 0) {
                if (function_exists('can') && !can('delivery_log.edit') && !can('wcs_quote.view')) require_permission('delivery_log.edit');
                $stmt = $pdo->prepare("UPDATE delivery_headers SET delivery_date=:d,branch_code=:b,main_branch_name=:m,sub_branch_name=:s,carrier=:c,tracking_no=:t,reference_no=:r,remark=:rm,shipping_cost=:sc,updated_by=:u,updated_at=NOW() WHERE id=:id AND deleted_at IS NULL");
                $stmt->execute([':d'=>$date,':b'=>$branchCode,':m'=>$mainBranch,':s'=>$subBranch,':c'=>$carrier,':t'=>$tracking,':r'=>$reference,':rm'=>$remark,':sc'=>$shippingCost,':updated_by'=>dlCurrentUser(),':id'=>$id]);
                $pdo->prepare('DELETE FROM delivery_items WHERE delivery_id=:id')->execute([':id'=>$id]);
                $deliveryId = $id;
            } else {
                $deliveryNo = dlGenerateNo($pdo, $date);
                $stmt = $pdo->prepare("INSERT INTO delivery_headers (delivery_no,delivery_date,branch_code,main_branch_name,sub_branch_name,carrier,tracking_no,reference_no,remark,shipping_cost,created_by,updated_by) VALUES (:n,:d,:b,:m,:s,:c,:t,:r,:rm,:sc,:created_by,:updated_by)");
                $stmt->execute([':n'=>$deliveryNo,':d'=>$date,':b'=>$branchCode,':m'=>$mainBranch,':s'=>$subBranch,':c'=>$carrier,':t'=>$tracking,':r'=>$reference,':rm'=>$remark,':sc'=>$shippingCost,':created_by'=>dlCurrentUser(),':updated_by'=>dlCurrentUser()]);
                $deliveryId = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO delivery_items (delivery_id,item_type,quantity,item_detail) VALUES (:d,:t,:q,:x)');
            foreach ($items as $item) $ins->execute([':d'=>$deliveryId,':t'=>$item['type'],':q'=>$item['qty'],':x'=>$item['detail']]);
            $pdo->commit();
            header('Location: index.php?saved=1');
            exit;
        }
        if ($action === 'save_bulk') {
            if (function_exists('can') && !can('delivery_log.create') && !can('wcs_quote.view')) require_permission('delivery_log.create');

            $date = trim((string)($_POST['bulk_delivery_date'] ?? ''));
            $carrier = trim((string)($_POST['bulk_carrier'] ?? ''));
            $tracking = '';
            $reference = '';
            $remark = '';
            $shippingCostTotal = max(0, (float)($_POST['bulk_shipping_cost_total'] ?? 0));
            $rowsJson = trim((string)($_POST['bulk_rows_json'] ?? ''));
            $bulkRows = json_decode($rowsJson, true);

            if ($date === '') throw new RuntimeException('กรุณาระบุวันที่ส่ง');
            if (!is_array($bulkRows) || !$bulkRows) throw new RuntimeException('ไม่พบรายการที่พักไว้สำหรับบันทึก');
            if (count($bulkRows) > 500) throw new RuntimeException('บันทึกได้สูงสุดครั้งละ 500 รายการ');

            $headerInsert = $pdo->prepare("INSERT INTO delivery_headers (delivery_no,delivery_date,branch_code,main_branch_name,sub_branch_name,carrier,tracking_no,reference_no,remark,shipping_cost,created_by,updated_by) VALUES (:n,:d,:b,:m,:s,:c,:t,:r,:rm,:sc,:created_by,:updated_by)");
            $itemInsert = $pdo->prepare("INSERT INTO delivery_items (delivery_id,item_type,quantity,item_detail) VALUES (:d,:t,:q,:x)");
            $pdo->beginTransaction();
            $savedCount = 0;
            foreach ($bulkRows as $rowIndex => $row) {
                $branchCode = trim((string)($row['branch_code'] ?? ''));
                $mainBranchName = trim((string)($row['main_branch_name'] ?? ''));
                $subBranchName = trim((string)($row['sub_branch_name'] ?? ''));
                $itemType = trim((string)($row['item_type'] ?? ''));
                $quantity = max(1, (int)($row['quantity'] ?? 1));
                $itemDetail = '';
                if ($branchCode === '' || $mainBranchName === '' || $itemType === '') continue;

                $deliveryNo = dlGenerateNo($pdo, $date);
                $rowShippingCost = $savedCount === 0 ? $shippingCostTotal : 0;
                $headerInsert->execute([
                    ':n'=>$deliveryNo, ':d'=>$date, ':b'=>$branchCode,
                    ':m'=>$mainBranchName, ':s'=>$subBranchName,
                    ':c'=>$carrier, ':t'=>$tracking, ':r'=>$reference, ':rm'=>$remark, ':sc'=>$rowShippingCost,
                    ':created_by'=>dlCurrentUser(), ':updated_by'=>dlCurrentUser(),
                ]);
                $itemInsert->execute([
                    ':d'=>(int)$pdo->lastInsertId(), ':t'=>$itemType, ':q'=>$quantity, ':x'=>$itemDetail,
                ]);
                $savedCount++;
            }
            if ($savedCount === 0) throw new RuntimeException('ไม่มีรายการที่ผ่านการตรวจสอบ');
            $pdo->commit();
            header('Location: index.php?bulk_saved=' . $savedCount);
            exit;
        }

        if ($action === 'delete') {
            if (function_exists('can') && !can('delivery_log.delete') && !can('wcs_quote.view')) require_permission('delivery_log.delete');
            $id = max(0, (int)($_POST['id'] ?? 0));
            $stmt = $pdo->prepare('UPDATE delivery_headers SET deleted_at=NOW(),updated_by=:u WHERE id=:id');
            $stmt->execute([':u'=>dlCurrentUser(),':id'=>$id]);
            header('Location: index.php?deleted=1');
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$keyword = trim((string)($_GET['keyword'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$itemTypeFilter = trim((string)($_GET['item_type'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$itemTypes = [];
$rows = [];
$totalRows = 0;
$totalPages = 1;
$todayCount = $monthCount = $todayQty = 0;
$overviewDate = trim((string)($_GET['overview_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $overviewDate)) {
    $overviewDate = date('Y-m-d');
}
$overviewTimestamp = strtotime($overviewDate);
if ($overviewTimestamp === false || date('Y-m-d', $overviewTimestamp) !== $overviewDate) {
    $overviewDate = date('Y-m-d');
    $overviewTimestamp = strtotime($overviewDate);
}
$overviewDisplayDate = date('d/m/', $overviewTimestamp) . ((int)date('Y', $overviewTimestamp) + 543);
$overviewShipmentCount = 0;
$overviewBranchCount = 0;
$overviewTotalQty = 0;
$overviewShippingCost = 0.0;
$overviewItems = [];

if ($tablesReady) {
    if (dlTableExists($pdo, 'delivery_item_types')) {
        $itemTypes = $pdo->query("SELECT item_name FROM delivery_item_types WHERE is_active=1 ORDER BY sort_order, item_name")->fetchAll(PDO::FETCH_COLUMN);

        $itemTypePriority = [
            'คอมพิวเตอร์',
            'จอคอมพิวเตอร์',
            'เครื่องปริ้น',
            'Harddisk',
            'SSD',
            'Notebook',
            'Projector',
            'RAM',
            'กล้องวงจรปิด(ตัวกล้อง)',
            'NVR/DVR',
            'Drum',
        ];

        $normalizeItemType = static function ($value): string {
            $value = trim((string)$value);
            $value = preg_replace('/\s+/u', '', $value);
            return mb_strtolower($value, 'UTF-8');
        };

        $priorityMap = [];
        foreach ($itemTypePriority as $priorityIndex => $priorityName) {
            $priorityMap[$normalizeItemType($priorityName)] = $priorityIndex;
        }

        $originalOrder = array_flip(array_map('strval', $itemTypes));
        usort($itemTypes, static function ($left, $right) use ($priorityMap, $normalizeItemType, $originalOrder): int {
            $leftKey = $normalizeItemType($left);
            $rightKey = $normalizeItemType($right);
            $leftPriority = $priorityMap[$leftKey] ?? 9999;
            $rightPriority = $priorityMap[$rightKey] ?? 9999;

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return ($originalOrder[(string)$left] ?? 9999) <=> ($originalOrder[(string)$right] ?? 9999);
        });
    }
    $where = ['h.deleted_at IS NULL'];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(h.delivery_no LIKE :kw_delivery_no
            OR h.branch_code LIKE :kw_branch_code
            OR h.main_branch_name LIKE :kw_main_branch
            OR h.sub_branch_name LIKE :kw_sub_branch
            OR h.tracking_no LIKE :kw_tracking
            OR h.reference_no LIKE :kw_reference
            OR h.created_by LIKE :kw_created_by
            OR EXISTS (
                SELECT 1
                FROM branch_directory bd_kw
                WHERE bd_kw.branch_code = h.branch_code
                  AND bd_kw.main_branch_code LIKE :kw_main_branch_code
            ))';
        $keywordLike = '%' . $keyword . '%';
        $params[':kw_delivery_no'] = $keywordLike;
        $params[':kw_branch_code'] = $keywordLike;
        $params[':kw_main_branch'] = $keywordLike;
        $params[':kw_sub_branch'] = $keywordLike;
        $params[':kw_tracking'] = $keywordLike;
        $params[':kw_reference'] = $keywordLike;
        $params[':kw_created_by'] = $keywordLike;
        $params[':kw_main_branch_code'] = $keywordLike;
    }
    if ($dateFrom !== '') { $where[] = 'h.delivery_date>=:df'; $params[':df']=$dateFrom; }
    if ($dateTo !== '') { $where[] = 'h.delivery_date<=:dt'; $params[':dt']=$dateTo; }
    if ($itemTypeFilter !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM delivery_items fi WHERE fi.delivery_id=h.id AND fi.item_type=:it)';
        $params[':it'] = $itemTypeFilter;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM delivery_headers h {$whereSql}");
    $count->execute($params);
    $totalRows = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows/$limit));
    if ($page>$totalPages) { $page=$totalPages; $offset=($page-1)*$limit; }
    $sql = "SELECT h.*, MAX(bd.main_branch_code) AS display_main_branch_code, GROUP_CONCAT(
        CASE
            WHEN h.reference_no LIKE 'BRANCH_LABEL_HISTORY:%' THEN CASE WHEN i.item_type IS NULL OR TRIM(i.item_type) = '' OR i.item_type = 'อุปกรณ์ IT' THEN '-' ELSE i.item_type END
            ELSE CONCAT(i.item_type,' × ',i.quantity,IF(i.item_detail IS NULL OR i.item_detail='','',CONCAT(' (',i.item_detail,')')))
        END
        ORDER BY i.id SEPARATOR '||'
    ) AS item_summary, SUM(i.quantity) AS total_qty FROM delivery_headers h LEFT JOIN delivery_items i ON i.delivery_id=h.id LEFT JOIN branch_directory bd ON bd.branch_code=h.branch_code {$whereSql} GROUP BY h.id ORDER BY h.delivery_date DESC,h.id DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $stmt->bindValue($k,$v,PDO::PARAM_STR);
    $stmt->bindValue(':lim',$limit,PDO::PARAM_INT);
    $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ตรวจรายการส่งซ้ำจากวันที่ส่ง สาขาปลายทาง รายการ และจำนวน โดยยังคงแสดงทุกแถวตามเดิม */
    $duplicateSql = "SELECT duplicate_group.delivery_date,
                            duplicate_group.branch_code,
                            duplicate_group.main_branch_name,
                            duplicate_group.sub_branch_name,
                            duplicate_group.item_summary,
                            duplicate_group.total_qty,
                            COUNT(*) AS duplicate_count
                     FROM (
                         SELECT dh.id,
                                dh.delivery_date,
                                dh.branch_code,
                                COALESCE(dh.main_branch_name, '') AS main_branch_name,
                                COALESCE(dh.sub_branch_name, '') AS sub_branch_name,
                                GROUP_CONCAT(
                                    CASE
                                        WHEN dh.reference_no LIKE 'BRANCH_LABEL_HISTORY:%' THEN
                                            CASE
                                                WHEN di.item_type IS NULL OR TRIM(di.item_type) = '' OR di.item_type = 'อุปกรณ์ IT' THEN '-'
                                                ELSE di.item_type
                                            END
                                        ELSE CONCAT(di.item_type, ' × ', di.quantity, IF(di.item_detail IS NULL OR di.item_detail = '', '', CONCAT(' (', di.item_detail, ')')))
                                    END
                                    ORDER BY di.id SEPARATOR '||'
                                ) AS item_summary,
                                COALESCE(SUM(di.quantity), 0) AS total_qty
                         FROM delivery_headers dh
                         LEFT JOIN delivery_items di ON di.delivery_id = dh.id
                         WHERE dh.deleted_at IS NULL
                         GROUP BY dh.id
                     ) duplicate_group
                     GROUP BY duplicate_group.delivery_date,
                              duplicate_group.branch_code,
                              duplicate_group.main_branch_name,
                              duplicate_group.sub_branch_name,
                              duplicate_group.item_summary,
                              duplicate_group.total_qty
                     HAVING COUNT(*) > 1";
    $duplicateGroups = $pdo->query($duplicateSql)->fetchAll(PDO::FETCH_ASSOC);
    $duplicateMap = [];
    foreach ($duplicateGroups as $duplicateGroup) {
        $duplicateKey = implode('|', [
            (string)($duplicateGroup['delivery_date'] ?? ''),
            (string)($duplicateGroup['branch_code'] ?? ''),
            (string)($duplicateGroup['main_branch_name'] ?? ''),
            (string)($duplicateGroup['sub_branch_name'] ?? ''),
            (string)($duplicateGroup['item_summary'] ?? ''),
            (string)($duplicateGroup['total_qty'] ?? '0'),
        ]);
        $duplicateMap[$duplicateKey] = (int)($duplicateGroup['duplicate_count'] ?? 0);
    }
    foreach ($rows as &$deliveryRow) {
        $duplicateKey = implode('|', [
            (string)($deliveryRow['delivery_date'] ?? ''),
            (string)($deliveryRow['branch_code'] ?? ''),
            (string)($deliveryRow['main_branch_name'] ?? ''),
            (string)($deliveryRow['sub_branch_name'] ?? ''),
            (string)($deliveryRow['item_summary'] ?? ''),
            (string)($deliveryRow['total_qty'] ?? '0'),
        ]);
        $deliveryRow['duplicate_count'] = $duplicateMap[$duplicateKey] ?? 1;
    }
    unset($deliveryRow);

    $todayCount = (int)$pdo->query("SELECT COUNT(*) FROM delivery_headers WHERE deleted_at IS NULL AND delivery_date=CURDATE()")->fetchColumn();
    $monthCount = (int)$pdo->query("SELECT COUNT(*) FROM delivery_headers WHERE deleted_at IS NULL AND YEAR(delivery_date)=YEAR(CURDATE()) AND MONTH(delivery_date)=MONTH(CURDATE())")->fetchColumn();
    $todayQty = (int)$pdo->query("SELECT COALESCE(SUM(i.quantity),0) FROM delivery_items i INNER JOIN delivery_headers h ON h.id=i.delivery_id WHERE h.deleted_at IS NULL AND h.delivery_date=CURDATE()")->fetchColumn();

    $overviewStmt = $pdo->prepare("SELECT COUNT(DISTINCT h.id) AS shipment_count,
                                         COUNT(DISTINCT h.branch_code) AS branch_count,
                                         COALESCE(SUM(i.quantity),0) AS total_qty
                                  FROM delivery_headers h
                                  LEFT JOIN delivery_items i ON i.delivery_id=h.id
                                  WHERE h.deleted_at IS NULL AND h.delivery_date=:overview_date");
    $overviewStmt->execute([':overview_date'=>$overviewDate]);
    $overviewTotals = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $overviewShipmentCount = (int)($overviewTotals['shipment_count'] ?? 0);
    $overviewBranchCount = (int)($overviewTotals['branch_count'] ?? 0);
    $overviewTotalQty = (int)($overviewTotals['total_qty'] ?? 0);

    $overviewCostStmt = $pdo->prepare("SELECT COALESCE(SUM(shipping_cost),0) FROM delivery_headers WHERE deleted_at IS NULL AND delivery_date=:overview_cost_date");
    $overviewCostStmt->execute([':overview_cost_date'=>$overviewDate]);
    $overviewShippingCost = (float)$overviewCostStmt->fetchColumn();

    $overviewItemStmt = $pdo->prepare("SELECT i.item_type, COALESCE(SUM(i.quantity),0) AS total_qty
                                      FROM delivery_items i
                                      INNER JOIN delivery_headers h ON h.id=i.delivery_id
                                      WHERE h.deleted_at IS NULL AND h.delivery_date=:overview_date
                                      GROUP BY i.item_type
                                      HAVING SUM(i.quantity) > 0
                                      ORDER BY total_qty DESC, i.item_type ASC");
    $overviewItemStmt->execute([':overview_date'=>$overviewDate]);
    $overviewItems = $overviewItemStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (($_GET['export'] ?? '') === 'csv' && $tablesReady) {
    if (function_exists('can') && !can('delivery_log.export') && !can('wcs_quote.view')) require_permission('delivery_log.export');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="delivery_log_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['วันที่ส่ง','เลขที่ส่ง','รหัสสาขา','สาขาใหญ่','สาขาย่อย','รายการ','จำนวนรวม','ค่าส่ง','เลขอ้างอิง','ผู้บันทึก']);
    foreach ($rows as $r) fputcsv($out,[$r['delivery_date'],$r['delivery_no'],$r['display_main_branch_code'] ?: '-',$r['main_branch_name'],$r['sub_branch_name'],str_replace('||','; ',$r['item_summary']),(int)$r['total_qty'],number_format((float)($r['shipping_cost'] ?? 0),2,'.',''),$r['reference_no'],dlDisplayUserName($r['created_by'])]);
    fclose($out); exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.delivery-page{padding:0 10px 24px}.delivery-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);color:#fff;border-radius:18px;padding:17px 20px;margin-bottom:12px;box-shadow:0 12px 30px rgba(15,76,129,.18)}.delivery-hero h1{font-size:1.25rem;font-weight:900;margin:0 0 3px}.delivery-card{border:0;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.07);overflow:hidden}.delivery-card .card-header{background:#fff;border-bottom:1px solid #e2e8f0;font-weight:900}.kpi-delivery{border:0;border-radius:15px;box-shadow:0 5px 18px rgba(15,23,42,.06)}.kpi-delivery .card-body{padding:12px 14px}.kpi-label{font-size:.72rem;color:#64748b;font-weight:800}.kpi-value{font-size:1.6rem;font-weight:900;color:#0f172a}.delivery-filter .form-control,.delivery-filter .form-select,.delivery-filter .btn{min-height:36px;font-size:.74rem;border-radius:10px}
.delivery-table-wrap{max-height:none;overflow-y:visible;overflow-x:hidden}
.delivery-table{width:100%;min-width:0;margin:0;table-layout:fixed}
.delivery-table th{position:sticky;top:0;z-index:2;background:#f1f5f9;white-space:nowrap;font-size:.72rem;padding:.55rem;overflow:hidden;text-overflow:ellipsis}
.delivery-table td{font-size:.73rem;padding:.5rem;vertical-align:middle;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
.delivery-table th:nth-child(1),.delivery-table td:nth-child(1){width:4%;text-align:center}
.delivery-table th:nth-child(2),.delivery-table td:nth-child(2){width:10%;text-align:center}
.delivery-table th:nth-child(3),.delivery-table td:nth-child(3){width:8%;text-align:center}
.delivery-table th:nth-child(4),.delivery-table td:nth-child(4){width:13%}
.delivery-table th:nth-child(5),.delivery-table td:nth-child(5){width:13%}
.delivery-table th:nth-child(6),.delivery-table td:nth-child(6){width:15%}
.delivery-table th:nth-child(7),.delivery-table td:nth-child(7){width:7%;text-align:center}
.delivery-table th:nth-child(8),.delivery-table td:nth-child(8){width:11%}
.delivery-table th:nth-child(9),.delivery-table td:nth-child(9){width:8%;text-align:center}
.delivery-table th:nth-child(10),.delivery-table td:nth-child(10){width:11%;text-align:center}
.delivery-table td:nth-child(2) .delivery-no{white-space:normal;overflow-wrap:anywhere}
.delivery-table td:nth-child(10){white-space:nowrap!important}
.delivery-table td:nth-child(10) .btn{display:inline-flex;align-items:center;justify-content:center;width:auto;min-width:38px;padding:.22rem .45rem;margin:0 2px;font-size:.62rem;font-weight:400;border-radius:5px;background:#fff}
.delivery-table td:nth-child(10) form{display:inline-block!important;width:auto;margin:0}
.delivery-duplicate-row>td{color:#0d6efd!important;font-weight:700}
.delivery-duplicate-row .delivery-no,.delivery-duplicate-row .branch-code,.delivery-duplicate-row .item-chip{color:#0d6efd!important}
.delivery-duplicate-row .btn{font-weight:400}
.delivery-table td:nth-child(10) form .btn{margin:0 2px}.delivery-table td:nth-child(10) .btn-outline-primary{color:#2563eb;border-color:#60a5fa}.delivery-table td:nth-child(10) .btn-outline-primary:hover,.delivery-table td:nth-child(10) .btn-outline-primary:focus{color:#fff;background:#2563eb;border-color:#2563eb}.delivery-table td:nth-child(10) .btn-outline-danger{color:#ef4444;border-color:#f87171}.delivery-table td:nth-child(10) .btn-outline-danger:hover,.delivery-table td:nth-child(10) .btn-outline-danger:focus{color:#fff;background:#ef4444;border-color:#ef4444}@media(max-width:1366px){.delivery-table-wrap{overflow-x:hidden}
.delivery-table th{font-size:.64rem;padding:.42rem .24rem}
.delivery-table td{font-size:.65rem;padding:.4rem .24rem;line-height:1.25}.item-chip{font-size:.6rem;padding:.12rem .3rem;margin:1px}.delivery-no{font-size:inherit}.branch-code{font-size:.66rem}
.delivery-table td:nth-child(10) .btn{font-size:.56rem;padding:.2rem .38rem;min-width:34px}}.item-chip{display:inline-block;background:transparent;color:#000;border:0;border-radius:0;padding:0;margin:2px 6px 2px 0;font-weight:400}.branch-code{font-weight:400;color:#1d4ed8}.delivery-no{font-size:inherit;font-weight:900;color:#0f766e}.modal-content{border:0;border-radius:17px;overflow:hidden}.modal-header{background:linear-gradient(135deg,#eff6ff,#fff)}.item-row{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px;margin-bottom:8px}.branch-result{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:10px}.btn-compact{font-size:.72rem;font-weight:800;border-radius:9px}.detail-items{display:flex;flex-wrap:wrap;gap:4px}@media(max-width:767px){.delivery-hero{padding:14px}}
.delivery-detail-modal .modal-dialog{max-width:820px}.delivery-detail-modal .modal-header{padding:.5rem .75rem}.delivery-detail-modal .modal-title{font-size:1rem}.delivery-detail-modal .modal-body{padding:.4rem;background:#f8fafc}.delivery-detail-modal .modal-footer{padding:.3rem .5rem}.delivery-detail-modal .modal-footer .btn{font-size:.75rem;padding:.25rem .7rem}.delivery-detail-table-wrap{border:1px solid #dbe5ee;border-radius:10px;overflow:hidden;background:#fff}.delivery-detail-table{width:100%;margin:0;table-layout:fixed}.delivery-detail-table th,.delivery-detail-table td{padding:.28rem .4rem;border-color:#dbe5ee;vertical-align:middle;font-size:.74rem;line-height:1.15}.delivery-detail-table th{width:18%;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}.delivery-detail-table td{width:32%;background:#fff;font-weight:700;color:#0f172a;overflow-wrap:anywhere}.delivery-detail-table tr:nth-child(even) td{background:#f8fafc}.delivery-detail-table .detail-items{padding:.05rem 0}.delivery-detail-table .item-chip{font-size:.66rem;padding:.12rem .38rem;margin:1px}.delivery-detail-table .detail-full-label{width:18%}.delivery-detail-table .detail-full-value{width:82%}@media(max-width:767.98px){.delivery-detail-modal .modal-dialog{margin:.5rem}.delivery-detail-table{min-width:680px}}

.delivery-overview{border:0;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.07);overflow:hidden;background:#fff}.delivery-overview-head{padding:12px 14px;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#eff6ff,#fff)}.delivery-overview-title{font-size:.92rem;font-weight:900;color:#0f172a}.delivery-overview-date{font-size:.72rem;color:#64748b}.overview-date-form .form-control,.overview-date-form .btn{min-height:34px;font-size:.72rem;border-radius:9px}.overview-total-card{height:100%;border-radius:14px;padding:14px;background:linear-gradient(135deg,#0f4c81,#1769aa);color:#fff}.overview-total-label{font-size:.7rem;font-weight:800;opacity:.8}.overview-total-value{font-size:2rem;line-height:1.05;font-weight:900}.overview-total-unit{font-size:.75rem;font-weight:800;opacity:.85}.overview-stat{height:100%;border:1px solid #dbeafe;border-radius:12px;padding:10px 12px;background:#f8fbff}.overview-stat-label{font-size:.68rem;color:#64748b;font-weight:800}.overview-stat-value{font-size:1.15rem;color:#0f172a;font-weight:900}.overview-item-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.overview-item{border:1px solid #e2e8f0;border-radius:12px;padding:9px 10px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:8px}.overview-item-name{font-size:.7rem;font-weight:800;color:#334155;overflow-wrap:anywhere}.overview-item-qty{min-width:36px;text-align:center;font-size:.86rem;font-weight:900;color:#1d4ed8;background:#eff6ff;border-radius:9px;padding:4px 7px}.overview-empty{border:1px dashed #cbd5e1;border-radius:12px;padding:18px;text-align:center;color:#64748b;font-size:.76rem;background:#f8fafc}@media(max-width:991px){.overview-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:575px){.overview-item-grid{grid-template-columns:1fr}.overview-total-value{font-size:1.6rem}}

.bulk-entry-help{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:10px;font-size:.76rem;color:#1e3a8a}
.bulk-preview-wrap{max-height:360px;overflow:auto;border:1px solid #dbe3ec;border-radius:12px;background:#fff}
.bulk-preview-table{margin:0;min-width:780px}.bulk-preview-table th{position:sticky;top:0;background:#0f4c81;color:#fff;z-index:2;font-size:.72rem;white-space:nowrap}.bulk-preview-table td{font-size:.73rem;vertical-align:middle}.bulk-status{font-weight:800}.bulk-status.ok{color:#15803d}.bulk-status.error{color:#b91c1c}.bulk-count{font-size:.75rem;font-weight:800;color:#475569}

.bulk-stage-header{background:linear-gradient(135deg,#0f4c81,#1769aa);color:#fff;border-radius:14px;padding:12px 14px}.bulk-stage-step{display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:800}.bulk-stage-step span{display:inline-flex;width:24px;height:24px;border-radius:50%;align-items:center;justify-content:center;background:rgba(255,255,255,.2)}.bulk-stage-card{border:1px solid #dbe3ec;border-radius:14px;background:#fff;overflow:hidden}.bulk-stage-card .card-header{background:#f8fafc;font-weight:900}.bulk-staged-wrap{max-height:270px;overflow:auto;border:1px solid #dbe3ec;border-radius:12px}.bulk-staged-table{min-width:920px;margin:0}.bulk-staged-table th{position:sticky;top:0;background:#0f4c81;color:#fff;z-index:2;font-size:.7rem}.bulk-staged-table td{font-size:.72rem;vertical-align:middle}.bulk-summary-table{width:100%;margin:0;border-collapse:separate;border-spacing:0}.bulk-summary-table th{background:#f1f5f9;color:#475569;font-size:.68rem;font-weight:900;padding:9px;border:1px solid #dbe3ec}.bulk-summary-table td{background:#fff;font-size:.9rem;font-weight:900;color:#0f172a;padding:10px;border:1px solid #dbe3ec}.bulk-summary-table .shipping-cell{background:#f0fdf4}.bulk-summary-table .shipping-input{min-width:140px;font-weight:900;text-align:right}.bulk-summary-table .shipping-live{font-size:.72rem;color:#15803d;font-weight:800;margin-top:4px}.bulk-type-summary{display:flex;flex-wrap:wrap;gap:6px}.bulk-type-chip{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:.7rem;font-weight:800}.bulk-type-inline-wrap{overflow:auto;border:1px solid #dbe3ec;border-radius:12px}.bulk-type-inline-table{min-width:620px;margin:0}.bulk-type-inline-table th{font-size:.72rem;background:#f1f5f9;white-space:nowrap}.bulk-type-inline-table td{font-size:.76rem;vertical-align:middle}.bulk-required-note{font-size:.7rem;color:#b91c1c;font-weight:800}.bulk-round-note{font-size:.7rem;color:#64748b}.bulk-line-errors{display:none;margin-top:6px;padding:8px 10px;border:1px solid #fecaca;border-radius:9px;background:#fef2f2;color:#b91c1c;font-size:.7rem;font-weight:700;white-space:pre-line}.bulk-line-errors.show{display:block}.bulk-field-error{border-color:#dc3545!important;box-shadow:0 0 0 .2rem rgba(220,53,69,.08)!important}.bulk-highlight-wrap{position:relative;background:#fff;border-radius:.375rem}.bulk-highlight-wrap textarea{position:relative;z-index:2;background:transparent}.bulk-highlight-wrap.active textarea{color:transparent!important;caret-color:#212529}.bulk-highlight-layer{display:none;position:absolute;z-index:1;inset:0;margin:0;padding:.375rem .75rem;border:1px solid transparent;border-radius:.375rem;overflow:auto;pointer-events:none;white-space:pre-wrap;overflow-wrap:break-word;word-break:break-word;font:inherit;line-height:1.5;color:#6c757d;background:#fff;scrollbar-width:none}.bulk-highlight-layer::-webkit-scrollbar{display:none}.bulk-highlight-wrap.active .bulk-highlight-layer{display:block}.bulk-highlight-line-error{color:#dc3545;font-weight:800;background:#fff1f2}.bulk-highlight-line-normal{color:#6c757d}@media(max-width:767px){.bulk-summary-table{min-width:720px}.bulk-summary-scroll{overflow-x:auto}}
.delivery-add-btn{color:#dc2626!important;background:#fff!important;border:2px solid #dc2626!important;font-weight:900!important;font-size:.9rem;padding:.45rem .95rem;box-shadow:0 0 0 .15rem rgba(220,38,38,.12)}.delivery-add-btn:hover,.delivery-add-btn:focus{background:#dc2626!important;color:#fff!important;border-color:#b91c1c!important;box-shadow:0 .35rem .8rem rgba(220,38,38,.25)}
.delivery-form-modal .modal-header{padding:.55rem .8rem}.delivery-form-modal .modal-title{font-size:1rem}.delivery-form-modal .modal-header .small{font-size:.7rem;margin-top:1px!important}.delivery-form-modal .modal-body{padding:.45rem;background:#f8fafc}.delivery-form-modal .modal-footer{padding:.35rem .55rem}.delivery-form-modal .modal-footer .btn{font-size:.76rem;padding:.28rem .72rem}.delivery-form-table-wrap{border:1px solid #dbe5ee;border-radius:10px;overflow:hidden;background:#fff;margin-bottom:.45rem}.delivery-form-table{width:100%;margin:0;table-layout:fixed}.delivery-form-table th,.delivery-form-table td{padding:.3rem .42rem;border-color:#dbe5ee;vertical-align:middle;font-size:.76rem;line-height:1.15}.delivery-form-table th{width:22%;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}.delivery-form-table td{background:#fff}.delivery-form-table tr:nth-child(even) td{background:#f8fafc}.delivery-form-table .form-control,.delivery-form-table .form-select{min-height:30px;height:30px;font-size:.76rem;padding:.2rem .42rem;border-radius:6px}.delivery-form-table .btn{font-size:.72rem;padding:.24rem .55rem}.delivery-selected-row td{background:#ecfdf5!important}.delivery-selected-info{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.delivery-selected-info small{display:block;font-size:.62rem;color:#64748b}.delivery-selected-info strong{font-size:.72rem}.delivery-item-section-head{display:flex;align-items:center;justify-content:space-between;padding:.34rem .48rem;background:#f1f5f9;border-bottom:1px solid #dbe5ee;font-size:.76rem;font-weight:800}.delivery-item-table{width:100%;margin:0;table-layout:fixed}.delivery-item-table th,.delivery-item-table td{padding:.28rem .35rem;border-color:#dbe5ee;vertical-align:middle;font-size:.72rem}.delivery-item-table th{background:#f8fafc;color:#475569;font-weight:800}.delivery-item-table .form-control,.delivery-item-table .form-select{min-height:29px;height:29px;font-size:.72rem;padding:.18rem .38rem}.delivery-item-table .remove-item{font-size:.68rem;padding:.2rem .4rem}.delivery-item-table th:nth-child(1){width:30%}.delivery-item-table th:nth-child(2){width:12%}.delivery-item-table th:nth-child(3){width:48%}.delivery-item-table th:nth-child(4){width:10%}@media(max-width:767.98px){.delivery-form-table th{width:34%}.delivery-selected-info{grid-template-columns:1fr}.delivery-item-table{min-width:650px}}
</style>

<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="container-fluid delivery-page">
<div class="delivery-hero d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1>บันทึกรายการส่งของ</h1>
    <!-- <div class="small opacity-75">บันทึกและติดตามการจัดส่งอุปกรณ์ IT ไปยังสาขา</div> -->
</div><div class="d-flex gap-2 flex-wrap">
    <!-- <button class="btn btn-info btn-sm fw-bold text-white" data-bs-toggle="modal" data-bs-target="#deliveryOverviewModal" id="deliveryOverviewBtn"><i class="bi bi-bar-chart-line me-1"></i>ภาพรวมการส่ง</button> -->
    <?php if($tablesReady && $branchLabelHistoryReady): ?><form method="post" class="d-inline" id="manualBranchLabelSyncForm" onsubmit="return confirm('ซิงค์ประวัติการพิมพ์ที่อยู่สาขาที่ยังไม่เคยนำเข้า จำนวน <?php echo number_format($pendingBranchLabelSync); ?> รายการ ใช่หรือไม่?');"><input type="hidden" name="csrf_token" value="<?php echo dlE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="sync_branch_label_history"><button class="btn btn-outline-light fw-bold" id="manualBranchLabelSyncButton" type="submit" <?php echo $pendingBranchLabelSync <= 0 ? 'disabled' : ''; ?>><i class="bi bi-arrow-repeat me-1"></i><span id="manualBranchLabelSyncText">ซิงค์ประวัติพิมพ์<?php if($pendingBranchLabelSync > 0): ?> (<?php echo number_format($pendingBranchLabelSync); ?>)<?php endif; ?></span></button></form><?php endif; ?>
    <button class="btn btn-light hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#bulkDeliveryModal" id="bulkDeliveryBtn">+ บันทึกหลายรายการ</button><button class="btn btn-light hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#deliveryModal" id="addDeliveryBtn">+ เพิ่มรายการส่งของ</button></div></div>
<?php if(!$tablesReady):?><div class="alert alert-warning">ยังไม่ได้ติดตั้งตารางฐานข้อมูล กรุณารันไฟล์ <strong>database/030_delivery_logs.sql</strong> ก่อนใช้งาน</div><?php endif;?>
<?php if($error!==''):?><div class="alert alert-danger py-2"><?php echo dlE($error);?></div><?php endif;?>
<?php if(isset($_GET['saved'])):?><div class="alert alert-success py-2">บันทึกรายการส่งของเรียบร้อยแล้ว</div><?php endif;?><?php if(isset($_GET['bulk_saved'])):?><div class="alert alert-success py-2">บันทึกรายการส่งของแบบหลายรายการเรียบร้อยแล้ว <?php echo number_format((int)$_GET['bulk_saved']);?> รายการ</div><?php endif;?>
<?php if(isset($_GET['deleted'])):?><div class="alert alert-success py-2">ลบรายการส่งของเรียบร้อยแล้ว</div><?php endif;?>
<?php if(isset($_GET['sync_saved'])):?><div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>ซิงค์ข้อมูลจากประวัติการพิมพ์ที่อยู่สาขาเรียบร้อยแล้ว <?php echo number_format((int)$_GET['sync_saved']);?> รายการ<?php if((int)($_GET['sync_skipped'] ?? 0) > 0): ?> และข้าม <?php echo number_format((int)$_GET['sync_skipped']);?> รายการที่ข้อมูลไม่ครบ<?php endif; ?></div><?php endif;?>
<!-- <div class="row g-2 mb-2"><div class="col-md-3"><div class="card kpi-delivery"><div class="card-body"><div class="kpi-label">รายการวันนี้</div><div class="kpi-value"><?php echo number_format($todayCount);?></div></div></div></div><div class="col-md-3"><div class="card kpi-delivery"><div class="card-body"><div class="kpi-label">จำนวนชิ้นวันนี้</div><div class="kpi-value"><?php echo number_format($todayQty);?></div></div></div></div><div class="col-md-3"><div class="card kpi-delivery"><div class="card-body"><div class="kpi-label">รายการเดือนนี้</div><div class="kpi-value"><?php echo number_format($monthCount);?></div></div></div></div><div class="col-md-3"><div class="card kpi-delivery"><div class="card-body"><div class="kpi-label">ตามตัวกรอง</div><div class="kpi-value"><?php echo number_format($totalRows);?></div></div></div></div></div> -->
<div class="card delivery-card mb-2"><div class="card-body"><form method="get" class="row g-2 delivery-filter align-items-end"><div class="col-lg-4"><label class="form-label small fw-bold">ค้นหา</label><input name="keyword" class="form-control" value="<?php echo dlE($keyword);?>" placeholder="เลขที่ส่ง, รหัสสาขา, ชื่อสาขา, Tracking, ผู้บันทึก"></div><div class="col-lg-2"><label class="form-label small fw-bold">ประเภทอุปกรณ์</label><select name="item_type" class="form-select"><option value="">ทุกประเภท</option><?php foreach($itemTypes as $type):?><option value="<?php echo dlE($type);?>" <?php echo $itemTypeFilter===$type?'selected':'';?>><?php echo dlE($type);?></option><?php endforeach;?></select></div><div class="col-lg-3"><label class="form-label small fw-bold">ช่วงวันที่</label><div class="input-group"><input type="date" name="date_from" class="form-control" value="<?php echo dlE($dateFrom);?>"><input type="date" name="date_to" class="form-control" value="<?php echo dlE($dateTo);?>"></div></div><div class="col-lg-3 d-flex gap-2"><button class="btn btn-dark flex-fill">ค้นหา</button><a href="index.php" class="btn btn-outline-secondary flex-fill">ล้างค่า</a><a href="?<?php echo dlE(http_build_query(array_merge($_GET,['export'=>'csv'])));?>" class="btn btn-outline-success">Excel</a></div></form></div></div>
<div class="card delivery-card"><div class="card-header d-flex justify-content-between"><span>รายการส่งของทั้งหมด</span><span class="small text-muted"><?php echo number_format($totalRows);?> รายการ · หน้า <?php echo $page;?>/<?php echo $totalPages;?></span></div><div class="card-body p-0"><div class="table-responsive delivery-table-wrap"><table class="table table-hover table-bordered delivery-table"><thead><tr><th>ลำดับ</th><th>เลขที่ส่ง</th><th>รหัสสาขา</th><th>สาขาใหญ่</th><th>สาขาย่อย</th><th>รายการ</th><th>จำนวนรวม</th><th>ผู้บันทึก</th><th>วันที่ส่ง</th><th>จัดการ</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr><?php else:foreach($rows as $i=>$row):$items=explode('||',(string)$row['item_summary']);?><tr class="<?php echo (int)($row['duplicate_count'] ?? 1) > 1 ? 'delivery-duplicate-row' : ''; ?>"<?php echo (int)($row['duplicate_count'] ?? 1) > 1 ? ' title="พบรายการส่งของที่มีวันที่ สาขา รายการ และจำนวนตรงกันมากกว่า 1 ครั้ง"' : ''; ?>><td class="text-center"><?php echo number_format($offset+$i+1);?></td><td><button type="button" class="btn btn-link p-0 delivery-no js-detail" data-row='<?php echo dlE(json_encode($row,JSON_UNESCAPED_UNICODE));?>'><?php echo dlE($row['delivery_no']);?></button></td><td class="branch-code"><?php echo dlE($row['display_main_branch_code'] ?: '-');?></td><td><?php echo dlE($row['main_branch_name']);?></td><td><?php echo dlE($row['sub_branch_name'] ?: '-');?></td><td><?php foreach($items as $it):?><span class="item-chip"><?php echo dlE(trim($it) !== '' ? $it : '-');?></span><?php endforeach;?></td><td class="text-center"><?php echo number_format((int)$row['total_qty']);?></td><td><?php echo dlE(dlDisplayUserName($row['created_by']));?></td><td class="text-center"><?php echo dlE(date('d/m/Y',strtotime($row['delivery_date'])));?></td><td class="text-nowrap"><button class="btn btn-sm btn-outline-primary btn-compact js-edit" data-row='<?php echo dlE(json_encode($row,JSON_UNESCAPED_UNICODE));?>'><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="แก้ไข"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10A.5.5 0 0 1 5.5 14H2a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .146-.354zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zM12.793 5.5 10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zM3.5 10.207 2.5 11.207V13h1.793l1-1H5.5v-.5H5a.5.5 0 0 1-.5-.5v-.5H4a.5.5 0 0 1-.5-.5z"/></svg></button> <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบรายการนี้หรือไม่?')"><input type="hidden" name="csrf_token" value="<?php echo dlE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$row['id'];?>"><button class="btn btn-sm btn-outline-danger btn-compact" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></form></td></tr><?php endforeach;endif;?></tbody></table></div><?php if($totalPages>1):?><div class="p-2 border-top"><ul class="pagination pagination-sm justify-content-center mb-0"><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):$qp=$_GET;$qp['page']=$p;unset($qp['export']);?><li class="page-item <?php echo $p===$page?'active':'';?>"><a class="page-link" href="?<?php echo dlE(http_build_query($qp));?>"><?php echo $p;?></a></li><?php endfor;?></ul></div><?php endif;?></div></div>
</div>

<div class="modal fade" id="deliveryOverviewModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title fw-bold"><i class="bi bi-bar-chart-line me-1 text-primary"></i>ภาพรวมการส่ง</h5><div class="small text-muted">สรุปจากรายการส่งจริงตามวันที่ที่เลือก</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
<div class="delivery-overview">
<div class="delivery-overview-head d-flex justify-content-between align-items-center flex-wrap gap-2">
<div><div class="delivery-overview-title"><i class="bi bi-box-seam me-1"></i>ข้อมูลประจำวันที่ <?php echo dlE($overviewDisplayDate);?></div><div class="delivery-overview-date">คำนวณจากรายการที่ยังไม่ถูกลบในระบบ</div></div>
<form method="get" class="overview-date-form d-flex gap-2 align-items-center">
<input type="hidden" name="open_overview" value="1">
<?php if($keyword!==''):?><input type="hidden" name="keyword" value="<?php echo dlE($keyword);?>"><?php endif;?>
<?php if($dateFrom!==''):?><input type="hidden" name="date_from" value="<?php echo dlE($dateFrom);?>"><?php endif;?>
<?php if($dateTo!==''):?><input type="hidden" name="date_to" value="<?php echo dlE($dateTo);?>"><?php endif;?>
<?php if($itemTypeFilter!==''):?><input type="hidden" name="item_type" value="<?php echo dlE($itemTypeFilter);?>"><?php endif;?>
<input type="date" name="overview_date" class="form-control" value="<?php echo dlE($overviewDate);?>">
<button class="btn btn-primary fw-bold"><i class="bi bi-search me-1"></i>ดูภาพรวม</button>
</form>
</div>
<div class="p-3">
<div class="row g-2">
<div class="col-lg-3"><div class="overview-total-card"><div class="overview-total-label">รวมทั้งหมดที่จัดส่ง</div><div class="d-flex align-items-end gap-2 mt-2"><div class="overview-total-value"><?php echo number_format($overviewTotalQty);?></div><div class="overview-total-unit">ชิ้น</div></div></div></div>
<div class="col-lg-9">
<div class="row g-2 mb-2"><div class="col-md-4"><div class="overview-stat"><div class="overview-stat-label">จำนวนรายการส่ง</div><div class="overview-stat-value"><?php echo number_format($overviewShipmentCount);?> รายการ</div></div></div><div class="col-md-4"><div class="overview-stat"><div class="overview-stat-label">จำนวนสาขาปลายทาง</div><div class="overview-stat-value"><?php echo number_format($overviewBranchCount);?> สาขา</div></div></div><div class="col-md-4"><div class="overview-stat"><div class="overview-stat-label">ค่าส่งรวม</div><div class="overview-stat-value text-success"><?php echo number_format($overviewShippingCost,2);?> บาท</div></div></div></div>
<?php if($overviewItems):?><div class="overview-item-grid"><?php foreach($overviewItems as $overviewItem):?><div class="overview-item"><div class="overview-item-name"><?php echo dlE($overviewItem['item_type']);?></div><div class="overview-item-qty"><?php echo number_format((int)$overviewItem['total_qty']);?></div></div><?php endforeach;?></div><?php else:?><div class="overview-empty"><i class="bi bi-inbox me-1"></i>ไม่พบรายการจัดส่งในวันที่เลือก</div><?php endif;?>
</div>
</div>
</div>
</div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div></div>
<div class="modal fade delivery-form-modal" id="deliveryModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content" id="deliveryForm"><div class="modal-header"><div><h5 class="modal-title fw-bold">เพิ่มรายการส่งของ</h5><div class="small text-muted mt-1">เลือกสาขาจากฐานข้อมูลและเพิ่มอุปกรณ์ได้หลายรายการ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo dlE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="delivery_id">
<input type="hidden" name="branch_code" id="branch_code" required>
<input type="hidden" name="main_branch_name" id="main_branch_name" required>
<input type="hidden" name="sub_branch_name" id="sub_branch_name">
<div class="table-responsive delivery-form-table-wrap"><table class="table table-bordered delivery-form-table"><tbody>
<tr><th>วันที่ส่ง <span class="text-danger">*</span></th><td><input type="date" name="delivery_date" id="delivery_date" class="form-control" value="<?php echo date('Y-m-d');?>" required></td><th>รหัสสาขาใหญ่ <span class="text-danger">*</span></th><td><div class="input-group input-group-sm"><input type="text" id="search_branch_code" class="form-control" placeholder="เช่น 017" maxlength="3" inputmode="numeric"><button type="button" class="btn btn-primary" id="btnSearchBranch">ค้นหา</button></div></td></tr>
<tr><th>สาขาที่ต้องการจัดส่ง <span class="text-danger">*</span></th><td colspan="3"><select id="branch_select" class="form-select" disabled><option value="">-- กรุณาค้นหาสาขาก่อน --</option></select><div id="branchSearchResult" class="d-none mt-1"></div></td></tr>
<tr id="selectedDeliveryBranch" class="delivery-selected-row d-none"><th>สาขาที่เลือก</th><td colspan="3"><div class="delivery-selected-info"><div><small>รหัสสาขาใหญ่</small><strong id="show_main_branch_code">-</strong></div><div><small>Cost Center</small><strong class="text-primary" id="show_branch_code">-</strong></div><div><small>ชื่อสาขา</small><strong id="show_branch_name">-</strong></div></div></td></tr>
<tr><th>บริษัทขนส่ง</th><td><select name="carrier" id="carrier" class="form-select"><option value="">-- เลือก --</option><option>ไปรษณีย์ไทย</option><option>Flash Express</option><option>Kerry Express</option><option>DHL</option><option>รถบริษัท</option><option>Messenger</option><option>รับเอง</option><option>อื่น ๆ</option></select></td><th>ค่าส่ง (บาท)</th><td><input type="number" min="0" step="0.01" name="shipping_cost" id="shipping_cost" class="form-control" value="0.00"></td></tr>
<tr><th>Tracking</th><td><input name="tracking_no" id="tracking_no" class="form-control"></td><th>เลขอ้างอิง</th><td><input name="reference_no" id="reference_no" class="form-control"></td></tr>
<tr><th>หมายเหตุ</th><td colspan="3"><input name="remark" id="remark" class="form-control"></td></tr>
</tbody></table></div>
<div class="delivery-form-table-wrap"><div class="delivery-item-section-head"><span>รายการอุปกรณ์</span><button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">+ เพิ่มรายการ</button></div><div class="table-responsive"><table class="table table-bordered delivery-item-table"><thead><tr><th>ประเภท</th><th>จำนวน</th><th>รายละเอียดเพิ่มเติม</th><th>จัดการ</th></tr></thead><tbody id="itemsContainer"></tbody></table></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary px-3">บันทึก</button></div></form></div></div>
<div class="modal fade" id="bulkDeliveryModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content" id="bulkDeliveryForm"><div class="modal-header"><div><h5 class="modal-title fw-bold">บันทึกรายการส่งของหลายสาขา</h5><div class="small text-muted">เพิ่มรายการเป็นรอบ พักไว้ตรวจสอบ แล้วบันทึกทั้งหมดครั้งเดียว</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
<input type="hidden" name="csrf_token" value="<?php echo dlE($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="save_bulk"><input type="hidden" name="bulk_rows_json" id="bulk_rows_json">
<div class="bulk-stage-header mb-3"><div class="row g-2"><div class="col-md-4"><div class="bulk-stage-step"><span>1</span>กรอกรายการอุปกรณ์รอบปัจจุบัน</div></div><div class="col-md-4"><div class="bulk-stage-step"><span>2</span>ตรวจสอบและพักรายการ</div></div><div class="col-md-4"><div class="bulk-stage-step"><span>3</span>กรอกค่าส่งรวมและยืนยันทั้งหมด</div></div></div></div>
<div class="bulk-stage-card mb-3"><div class="card-header">ข้อมูลส่วนกลางของรอบจัดส่ง</div><div class="card-body"><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">วันที่ส่ง <span class="text-danger">*</span></label><input type="date" name="bulk_delivery_date" id="bulk_delivery_date" class="form-control" value="<?php echo date('Y-m-d');?>" required></div><div class="col-md-6"><label class="form-label fw-bold">บริษัทขนส่ง</label><select name="bulk_carrier" class="form-select"><option value="">-- เลือก --</option><option>ไปรษณีย์ไทย</option><option>Flash Express</option><option>Kerry Express</option><option>DHL</option><option>รถบริษัท</option><option>Messenger</option><option>รับเอง</option><option>อื่น ๆ</option></select></div></div></div></div>
<div class="bulk-stage-card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span>เพิ่มรายการรอบปัจจุบัน</span><span class="bulk-round-note">ตัวอย่าง: รอบคอมพิวเตอร์ 10 สาขา แล้วพักรายการ ก่อนเริ่มรอบ HDD</span></div><div class="card-body"><div class="row g-3 mb-3"><div class="col-md-8"><label class="form-label fw-bold">ประเภทอุปกรณ์ <span class="text-danger">*</span></label><select id="bulk_item_type" class="form-select"><option value="">-- เลือกประเภท --</option><?php foreach($itemTypes as $type):?><option value="<?php echo dlE($type);?>"><?php echo dlE($type);?></option><?php endforeach;?></select></div><div class="col-md-4"><label class="form-label fw-bold">จำนวนต่อสาขา</label><input type="number" min="1" id="bulk_quantity" class="form-control" value="1"></div></div><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">วางรหัสสาขาใหญ่ <span class="text-danger">*</span></label><div class="bulk-highlight-wrap" id="bulkBranchCodeWrap"><pre id="bulkBranchCodeHighlight" class="bulk-highlight-layer" aria-hidden="true"></pre><textarea id="bulk_branch_codes" class="form-control" rows="7" inputmode="numeric" spellcheck="false" autocomplete="off" placeholder="356&#10;369&#10;336"></textarea></div><div id="bulkBranchCodeErrors" class="bulk-line-errors"></div></div><div class="col-md-6"><label class="form-label fw-bold">วางชื่อสาขา <span class="text-danger">*</span></label><div class="bulk-highlight-wrap" id="bulkBranchNameWrap"><pre id="bulkBranchNameHighlight" class="bulk-highlight-layer" aria-hidden="true"></pre><textarea id="bulk_branch_names" class="form-control" rows="7" placeholder="โปร่งไผ่&#10;เกตเวย์&#10;ศูนย์ฯ ชากกอไผ่"></textarea></div><div id="bulkBranchNameErrors" class="bulk-line-errors"></div></div></div><div class="bulk-entry-help mt-2">ลำดับรหัสและชื่อสาขาต้องตรงกัน จากนั้นกด <strong>ตรวจสอบและพักรายการรอบนี้</strong> รายการจะยังไม่ถูกบันทึกลงฐานข้อมูล</div><div class="d-flex justify-content-between align-items-center mt-3 gap-2"><span class="bulk-count" id="bulkCountText">ยังไม่มีรายการพักไว้</span><button type="button" class="btn btn-primary" id="bulkValidateBtn"><i class="bi bi-plus-circle me-1"></i>ตรวจสอบและพักรายการรอบนี้</button></div></div></div>
<div class="bulk-stage-card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span>สรุปประเภทอุปกรณ์และค่าจัดส่งรวม</span><button type="button" class="btn btn-sm btn-outline-danger" id="bulkClearAllBtn" disabled>ล้างรายการทั้งหมด</button></div><div class="card-body"><div class="row g-3 align-items-stretch mb-3"><div class="col-lg-8"><div class="bulk-type-inline-wrap h-100"><table class="table table-bordered table-hover bulk-type-inline-table"><thead><tr><th style="width:60px" class="text-center">#</th><th>ประเภทอุปกรณ์</th><th style="width:150px" class="text-center">จำนวนรายการส่ง</th><th style="width:150px" class="text-center">จำนวนอุปกรณ์</th></tr></thead><tbody id="bulkTypeInlineBody"><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีรายการ</td></tr></tbody><tfoot><tr class="table-primary fw-bold"><td colspan="2" class="text-end">รวมทั้งหมด</td><td class="text-center" id="bulkTypeInlineTotalRows">0 รายการ</td><td class="text-center" id="bulkTypeInlineTotalQty">0 ชิ้น</td></tr></tfoot></table></div></div><div class="col-lg-4"><div class="shipping-cell border rounded-3 p-3 h-100 bg-success-subtle"><label class="form-label fw-bold">ค่าจัดส่งรวมทั้งหมด (บาท) <span class="text-danger">*</span></label><input type="number" min="0.01" step="0.01" name="bulk_shipping_cost_total" id="bulk_shipping_cost_total" class="form-control shipping-input" placeholder="กรอกค่าจัดส่งรวม" required><div class="shipping-live">ยอดปัจจุบัน: <span id="bulkShippingLive">0.00</span> บาท</div><div class="bulk-required-note mt-2">กรุณากรอกค่าจัดส่งมากกว่า 0 บาทก่อนยืนยันบันทึก</div></div></div></div><div class="bulk-summary-scroll mb-2"><table class="bulk-summary-table"><thead><tr><th>จำนวนรายการส่ง</th><th>อุปกรณ์รวม</th><th>สาขาปลายทาง</th><th>ประเภทอุปกรณ์</th></tr></thead><tbody><tr><td id="bulkSummaryRows">0 รายการ</td><td id="bulkSummaryQty">0 ชิ้น</td><td id="bulkSummaryBranches">0 สาขา</td><td id="bulkSummaryTypes">0 ประเภท</td></tr></tbody></table></div><div class="bulk-staged-wrap"><table class="table table-bordered table-hover bulk-staged-table"><thead><tr><th>#</th><th>ประเภท</th><th>จำนวน</th><th>รหัสสาขาใหญ่</th><th>Cost Center</th><th>สาขาใหญ่</th><th>สาขาปลายทาง</th><th>จัดการ</th></tr></thead><tbody id="bulkPreviewBody"><tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีรายการพักไว้</td></tr></tbody></table></div><div class="alert alert-info mt-2 mb-0 py-2"><strong>ก่อนยืนยัน:</strong> ตารางด้านบนอัปเดตจำนวนรายการแบบ Real-time และแสดงค่าจัดส่งตามยอดที่กรอกทันที</div></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-success px-4" id="bulkSaveBtn" disabled><i class="bi bi-check2-circle me-1"></i>ยืนยันบันทึกรายการทั้งหมด</button></div></form></div></div>
<div class="modal fade delivery-detail-modal" id="detailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold">รายละเอียดรายการส่งของ</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="table-responsive delivery-detail-table-wrap"><table class="table table-bordered delivery-detail-table"><tbody id="detailGrid"></tbody></table></div></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div></div>
<script>
(function(){
const types=<?php echo json_encode(array_values($itemTypes),JSON_UNESCAPED_UNICODE);?>;
const container=document.getElementById('itemsContainer');
const modalEl=document.getElementById('deliveryModal');
function esc(v){return String(v??'').replace(/[&<>"]/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]));}
function itemRow(item={}){const tr=document.createElement('tr');tr.innerHTML='<td><select name="item_type[]" class="form-select" required><option value="">-- เลือกประเภท --</option>'+types.map(t=>'<option '+(t===item.type?'selected':'')+'>'+esc(t)+'</option>').join('')+'</select></td><td><input type="number" min="1" name="quantity[]" class="form-control text-center" value="'+esc(item.qty||1)+'" required></td><td><input name="item_detail[]" class="form-control" value="'+esc(item.detail||'')+'" placeholder="เช่น Serial, รุ่น"></td><td class="text-center"><button type="button" class="btn btn-outline-danger remove-item" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></td>';container.appendChild(tr);tr.querySelector('.remove-item').onclick=()=>{if(container.children.length>1)tr.remove();};}
document.getElementById('addItemBtn').onclick=()=>itemRow();
function resetForm(){document.getElementById('deliveryForm').reset();document.getElementById('delivery_id').value='';document.getElementById('delivery_date').value=new Date().toISOString().slice(0,10);document.getElementById('branch_code').value='';document.getElementById('main_branch_name').value='';document.getElementById('sub_branch_name').value='';document.getElementById('search_branch_code').value='';document.getElementById('branch_select').innerHTML='<option value="">-- กรุณาค้นหาสาขาก่อน --</option>';document.getElementById('branch_select').disabled=true;document.getElementById('branchSearchResult').className='d-none';document.getElementById('selectedDeliveryBranch').classList.add('d-none');container.innerHTML='';itemRow();document.querySelector('#deliveryModal .modal-title').textContent='เพิ่มรายการส่งของ';}
document.getElementById('addDeliveryBtn').onclick=resetForm;
const searchBranchCode=document.getElementById('search_branch_code');
const btnSearchBranch=document.getElementById('btnSearchBranch');
const branchSelect=document.getElementById('branch_select');
const branchSearchResult=document.getElementById('branchSearchResult');
const selectedDeliveryBranch=document.getElementById('selectedDeliveryBranch');
let deliveryBranchRows=[];
function formatMainBranchCode(value){value=String(value||'').trim();return /^\d+$/.test(value)?value.padStart(3,'0'):value;}
function showBranchMessage(type,message){branchSearchResult.className='alert alert-'+type+' py-2 mb-0';branchSearchResult.textContent=message;branchSearchResult.classList.remove('d-none');}
function clearDeliveryBranch(){deliveryBranchRows=[];branchSelect.innerHTML='<option value="">-- กรุณาค้นหาสาขาก่อน --</option>';branchSelect.disabled=true;document.getElementById('branch_code').value='';document.getElementById('main_branch_name').value='';document.getElementById('sub_branch_name').value='';selectedDeliveryBranch.classList.add('d-none');}
function searchDeliveryBranch(){const raw=String(searchBranchCode.value||'').replace(/[^0-9]/g,'').slice(0,3);searchBranchCode.value=raw;clearDeliveryBranch();if(!/^\d{3}$/.test(raw)){showBranchMessage('warning','กรุณากรอกรหัสสาขาใหญ่เป็นตัวเลข 3 หลัก เช่น 017');return;}const mainCode=formatMainBranchCode(raw);branchSelect.innerHTML='<option value="">กำลังค้นหาข้อมูล...</option>';const params=new URLSearchParams({main_branch_code:mainCode,branch_code:mainCode});fetch('/harddisk_delivery_web/api/get_branches.php?'+params.toString()).then(r=>r.json()).then(result=>{if(!result.success)throw new Error(result.message||'ไม่สามารถค้นหาข้อมูลสาขาได้');const rows=Array.isArray(result.data)?result.data:[];if(!rows.length){showBranchMessage('warning','ไม่พบข้อมูลสาขาภายใต้รหัสสาขาใหญ่ '+mainCode);clearDeliveryBranch();return;}deliveryBranchRows=rows;branchSelect.innerHTML='<option value="">-- เลือกสาขา --</option>';rows.forEach((branch,index)=>{const option=document.createElement('option');option.value=String(index);option.textContent=(branch.branch_code||'-')+' - '+(branch.branch_name||'-');branchSelect.appendChild(option);});branchSelect.disabled=false;showBranchMessage('success','พบทั้งหมด '+rows.length+' สาขา กรุณาเลือกสาขาที่ต้องการจัดส่ง');}).catch(error=>{clearDeliveryBranch();showBranchMessage('danger',error.message||'เกิดข้อผิดพลาดในการเชื่อมต่อ API ค้นหาสาขา');});}
btnSearchBranch.onclick=searchDeliveryBranch;
searchBranchCode.addEventListener('input',function(){this.value=this.value.replace(/[^0-9]/g,'').slice(0,3);clearDeliveryBranch();branchSearchResult.className='d-none';});
searchBranchCode.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();searchDeliveryBranch();}});
branchSelect.addEventListener('change',function(){const index=Number(this.value);const branch=Number.isInteger(index)?deliveryBranchRows[index]:null;if(!branch){document.getElementById('branch_code').value='';document.getElementById('main_branch_name').value='';document.getElementById('sub_branch_name').value='';selectedDeliveryBranch.classList.add('d-none');return;}const mainCode=formatMainBranchCode(branch.main_branch_code||searchBranchCode.value);const branchCode=String(branch.branch_code||'').trim();const branchName=String(branch.branch_name||'').trim();const mainName=String(branch.main_branch_name||branch.main_branch||branchName).trim();document.getElementById('branch_code').value=branchCode;document.getElementById('main_branch_name').value=mainName;document.getElementById('sub_branch_name').value=branchName;document.getElementById('show_main_branch_code').textContent=mainCode||'-';document.getElementById('show_branch_code').textContent=branchCode||'-';document.getElementById('show_branch_name').textContent=branchName||'-';selectedDeliveryBranch.classList.remove('d-none');});
document.querySelectorAll('.js-detail').forEach(btn=>btn.onclick=()=>{const r=JSON.parse(btn.dataset.row);const pairs=[['เลขที่ส่ง',r.delivery_no],['วันที่',r.delivery_date],['รหัสสาขา',r.branch_code],['สาขาใหญ่',r.main_branch_name],['สาขาย่อย',r.sub_branch_name||'-'],['บริษัทขนส่ง',r.carrier||'-'],['ค่าส่ง',Number(r.shipping_cost||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})+' บาท'],['Tracking',r.tracking_no||'-'],['เลขอ้างอิง',r.reference_no||'-'],['ผู้บันทึก',String(r.created_by||'-').replace(/\s*\([^()]+\)\s*$/u,'').trim()||'-'],['หมายเหตุ',r.remark||'-']];let rows='';for(let i=0;i<pairs.length;i+=2){const left=pairs[i];const right=pairs[i+1];if(right){rows+='<tr><th>'+esc(left[0])+'</th><td>'+esc(left[1])+'</td><th>'+esc(right[0])+'</th><td>'+esc(right[1])+'</td></tr>';}else{rows+='<tr><th class="detail-full-label">'+esc(left[0])+'</th><td colspan="3" class="detail-full-value">'+esc(left[1])+'</td></tr>';}}rows+='<tr><th class="detail-full-label">รายการอุปกรณ์</th><td colspan="3" class="detail-full-value"><div class="detail-items">'+String(r.item_summary||'').split('||').map(x=>'<span class="item-chip">'+esc(x)+'</span>').join('')+'</div></td></tr>';document.getElementById('detailGrid').innerHTML=rows;bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal')).show();});
document.querySelectorAll('.js-edit').forEach(btn=>btn.onclick=()=>{resetForm();const r=JSON.parse(btn.dataset.row);document.querySelector('#deliveryModal .modal-title').textContent='แก้ไขรายการส่งของ';document.getElementById('delivery_id').value=r.id;document.getElementById('delivery_date').value=r.delivery_date;document.getElementById('branch_code').value=r.branch_code;document.getElementById('main_branch_name').value=r.main_branch_name||'';document.getElementById('sub_branch_name').value=r.sub_branch_name||'';document.getElementById('show_main_branch_code').textContent='-';document.getElementById('show_branch_code').textContent=r.branch_code||'-';document.getElementById('show_branch_name').textContent=r.sub_branch_name||r.main_branch_name||'-';document.getElementById('selectedDeliveryBranch').classList.remove('d-none');document.getElementById('carrier').value=r.carrier||'';document.getElementById('tracking_no').value=r.tracking_no||'';document.getElementById('reference_no').value=r.reference_no||'';document.getElementById('remark').value=r.remark||'';document.getElementById('shipping_cost').value=Number(r.shipping_cost||0).toFixed(2);container.innerHTML='';String(r.item_summary||'').split('||').forEach(text=>{const m=text.match(/^(.*?) × (\d+)(?: \((.*)\))?$/);itemRow(m?{type:m[1],qty:m[2],detail:m[3]||''}:{type:text,qty:1});});bootstrap.Modal.getOrCreateInstance(modalEl).show();});

const bulkForm=document.getElementById('bulkDeliveryForm');
const bulkCodes=document.getElementById('bulk_branch_codes');
const bulkNames=document.getElementById('bulk_branch_names');
const bulkValidateBtn=document.getElementById('bulkValidateBtn');
const bulkSaveBtn=document.getElementById('bulkSaveBtn');
const bulkPreviewBody=document.getElementById('bulkPreviewBody');
const bulkCountText=document.getElementById('bulkCountText');
const bulkRowsJson=document.getElementById('bulk_rows_json');
const bulkClearAllBtn=document.getElementById('bulkClearAllBtn');
const bulkShippingCostInput=document.getElementById('bulk_shipping_cost_total');
const bulkShippingLive=document.getElementById('bulkShippingLive');
const bulkTypeInlineBody=document.getElementById('bulkTypeInlineBody');
const bulkBranchCodeErrors=document.getElementById('bulkBranchCodeErrors');
const bulkBranchNameErrors=document.getElementById('bulkBranchNameErrors');
const bulkBranchCodeWrap=document.getElementById('bulkBranchCodeWrap');
const bulkBranchNameWrap=document.getElementById('bulkBranchNameWrap');
const bulkBranchCodeHighlight=document.getElementById('bulkBranchCodeHighlight');
const bulkBranchNameHighlight=document.getElementById('bulkBranchNameHighlight');
const bulkErrorLines={code:new Set(),name:new Set()};
function renderBulkHighlight(field){const textarea=field==='code'?bulkCodes:bulkNames;const wrap=field==='code'?bulkBranchCodeWrap:bulkBranchNameWrap;const layer=field==='code'?bulkBranchCodeHighlight:bulkBranchNameHighlight;if(!textarea||!wrap||!layer)return;const lines=String(textarea.value||'').split(/\r?\n/);layer.innerHTML=lines.map((line,index)=>'<span class="'+(bulkErrorLines[field].has(index+1)?'bulk-highlight-line-error':'bulk-highlight-line-normal')+'">'+esc(line===''?' ':line)+'</span>').join('\n');layer.scrollTop=textarea.scrollTop;layer.scrollLeft=textarea.scrollLeft;wrap.classList.toggle('active',bulkErrorLines[field].size>0);}
function clearBulkLineErrors(){[bulkCodes,bulkNames].forEach(el=>el&&el.classList.remove('bulk-field-error'));[bulkBranchCodeErrors,bulkBranchNameErrors].forEach(el=>{if(el){el.classList.remove('show');el.textContent='';}});bulkErrorLines.code.clear();bulkErrorLines.name.clear();renderBulkHighlight('code');renderBulkHighlight('name');}
function showBulkLineErrors(errors){clearBulkLineErrors();const codeMessages=[];const nameMessages=[];errors.forEach(error=>{const message='บรรทัด '+error.line+': '+error.message;if(error.field==='code'){codeMessages.push(message);bulkErrorLines.code.add(Number(error.line));}if(error.field==='name'){nameMessages.push(message);bulkErrorLines.name.add(Number(error.line));}});if(codeMessages.length&&bulkBranchCodeErrors){bulkCodes.classList.add('bulk-field-error');bulkBranchCodeErrors.textContent=codeMessages.join('\n');bulkBranchCodeErrors.classList.add('show');}if(nameMessages.length&&bulkBranchNameErrors){bulkNames.classList.add('bulk-field-error');bulkBranchNameErrors.textContent=nameMessages.join('\n');bulkBranchNameErrors.classList.add('show');}renderBulkHighlight('code');renderBulkHighlight('name');}
function sanitizeBulkBranchCodes(){if(!bulkCodes)return;const original=String(bulkCodes.value||'');const sanitized=original.split(/\r?\n/).map(line=>line.replace(/[^0-9]/g,'').slice(0,3)).join('\n');if(original!==sanitized)bulkCodes.value=sanitized;}
function formatBulkBranchCodes(){if(!bulkCodes)return;sanitizeBulkBranchCodes();const formatted=String(bulkCodes.value||'').split(/\r?\n/).map(line=>{const code=String(line||'').trim();return code===''?'':code.padStart(3,'0');}).join('\n');if(bulkCodes.value!==formatted)bulkCodes.value=formatted;}
if(bulkCodes){bulkCodes.addEventListener('focus',()=>{if(bulkBranchCodeWrap)bulkBranchCodeWrap.classList.remove('active');});bulkCodes.addEventListener('input',()=>{sanitizeBulkBranchCodes();clearBulkLineErrors();});bulkCodes.addEventListener('paste',()=>setTimeout(()=>{formatBulkBranchCodes();clearBulkLineErrors();},0));bulkCodes.addEventListener('blur',()=>{formatBulkBranchCodes();renderBulkHighlight('code');});bulkCodes.addEventListener('scroll',()=>{if(bulkBranchCodeHighlight){bulkBranchCodeHighlight.scrollTop=bulkCodes.scrollTop;bulkBranchCodeHighlight.scrollLeft=bulkCodes.scrollLeft;}});}if(bulkNames){bulkNames.addEventListener('focus',()=>{if(bulkBranchNameWrap)bulkBranchNameWrap.classList.remove('active');});bulkNames.addEventListener('input',clearBulkLineErrors);bulkNames.addEventListener('blur',()=>renderBulkHighlight('name'));bulkNames.addEventListener('scroll',()=>{if(bulkBranchNameHighlight){bulkBranchNameHighlight.scrollTop=bulkNames.scrollTop;bulkBranchNameHighlight.scrollLeft=bulkNames.scrollLeft;}});}
let stagedBulkRows=[];
function normalizeBulkName(value){return String(value||'').trim().toLowerCase().replace(/\s+/g,' ');}
function normalizeBulkBranchName(value){return normalizeBulkName(value).replace(/[()\[\]{}]/g,' ').replace(/^(?:สาขา)?ย่อย\s*\d*\s*/,' ').replace(/^ศูนย์(?:บริการ)?ฯ?\s*/,' ').replace(/^สาขาใหญ่\s*/,' ').replace(/ถ\.\s*/g,'ถนน ').replace(/[.,/_\-]+/g,' ').replace(/\s+/g,' ').trim();}
function parseBulkLines(){formatBulkBranchCodes();const codeLines=String(bulkCodes.value||'').split(/\r?\n/);const nameLines=String(bulkNames.value||'').split(/\r?\n/);const max=Math.max(codeLines.length,nameLines.length);const items=[];const lineErrors=[];for(let index=0;index<max;index++){const code=String(codeLines[index]||'').trim();const name=String(nameLines[index]||'').trim();if(code===''&&name==='')continue;if(code==='')lineErrors.push({line:index+1,field:'code',message:'ไม่มีรหัสสาขาใหญ่'});if(name==='')lineErrors.push({line:index+1,field:'name',message:'ไม่มีชื่อสาขา'});if(code!==''&&!/^\d{3}$/.test(code))lineErrors.push({line:index+1,field:'code',message:'รหัสสาขาต้องเป็นตัวเลข 3 หลัก'});if(code===''||name===''||!/^\d{3}$/.test(code))continue;items.push({line:index+1,main_code:code,input_name:name});}return {items,lineErrors};}
async function fetchBranchesForCode(code){const params=new URLSearchParams({action:'bulk_branch_lookup',main_branch_code:code});const response=await fetch('index.php?'+params.toString());const result=await response.json();if(!result.success)throw new Error(result.message||'ค้นหาสาขาไม่สำเร็จ');return Array.isArray(result.data)?result.data:[];}
function getBulkBranchNames(row){return [row?.branch_name,row?.branch_name_2].map(value=>String(value||'').trim()).filter(Boolean);}
function findBestBranch(rows,inputName){const target=normalizeBulkName(inputName);let exactMatches=rows.filter(row=>getBulkBranchNames(row).some(name=>normalizeBulkName(name)===target));if(exactMatches.length===1)return exactMatches[0];const cleanTarget=normalizeBulkBranchName(inputName);if(cleanTarget!==''){const cleanMatches=rows.filter(row=>getBulkBranchNames(row).some(name=>normalizeBulkBranchName(name)===cleanTarget));if(cleanMatches.length===1)return cleanMatches[0];const containsMatches=rows.filter(row=>getBulkBranchNames(row).some(name=>{const candidate=normalizeBulkBranchName(name);return candidate!==''&&(candidate.includes(cleanTarget)||cleanTarget.includes(candidate));}));if(containsMatches.length===1)return containsMatches[0];}const containsOriginal=rows.filter(row=>getBulkBranchNames(row).some(name=>{const candidate=normalizeBulkName(name);return candidate.includes(target)||target.includes(candidate);}));return containsOriginal.length===1?containsOriginal[0]:null;}
function normalizeBranchType(value){return normalizeBulkName(value).replace(/[._-]+/g,' ');}
function findMainBranchByType(rows){const mainTypes=['สาขาใหญ่','main branch','mainbranch','main'];return rows.find(r=>mainTypes.includes(normalizeBranchType(r.branch_type)))||null;}
function updateBulkShippingLive(){const value=Math.max(0,Number(bulkShippingCostInput?.value||0));if(bulkShippingLive)bulkShippingLive.textContent=value.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function renderStagedBulkRows(){
bulkRowsJson.value=JSON.stringify(stagedBulkRows);
bulkSaveBtn.disabled=stagedBulkRows.length===0;
bulkClearAllBtn.disabled=stagedBulkRows.length===0;
const totalQty=stagedBulkRows.reduce((sum,row)=>sum+Number(row.quantity||0),0);
const branches=new Set(stagedBulkRows.map(row=>row.branch_code));
const typeTotals={};
stagedBulkRows.forEach(row=>{if(!typeTotals[row.item_type])typeTotals[row.item_type]={rows:0,qty:0};typeTotals[row.item_type].rows++;typeTotals[row.item_type].qty+=Number(row.quantity||0);});
const typeEntries=Object.entries(typeTotals);
document.getElementById('bulkSummaryRows').textContent=stagedBulkRows.length.toLocaleString('th-TH')+' รายการ';
document.getElementById('bulkSummaryQty').textContent=totalQty.toLocaleString('th-TH')+' ชิ้น';
document.getElementById('bulkSummaryBranches').textContent=branches.size.toLocaleString('th-TH')+' สาขา';
document.getElementById('bulkSummaryTypes').textContent=typeEntries.length.toLocaleString('th-TH')+' ประเภท';
if(bulkTypeInlineBody){bulkTypeInlineBody.innerHTML=typeEntries.length?typeEntries.map(([type,data],index)=>'<tr><td class="text-center">'+(index+1)+'</td><td class="fw-bold">'+esc(type)+'</td><td class="text-center">'+Number(data.rows).toLocaleString('th-TH')+' รายการ</td><td class="text-center fw-bold text-primary">'+Number(data.qty).toLocaleString('th-TH')+' ชิ้น</td></tr>').join(''):'<tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีรายการ</td></tr>';}
const totalRowsEl=document.getElementById('bulkTypeInlineTotalRows');if(totalRowsEl)totalRowsEl.textContent=stagedBulkRows.length.toLocaleString('th-TH')+' รายการ';
const totalQtyEl=document.getElementById('bulkTypeInlineTotalQty');if(totalQtyEl)totalQtyEl.textContent=totalQty.toLocaleString('th-TH')+' ชิ้น';
if(!stagedBulkRows.length){bulkPreviewBody.innerHTML='<tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีรายการพักไว้</td></tr>';bulkCountText.textContent='ยังไม่มีรายการพักไว้';return;}
bulkPreviewBody.innerHTML=stagedBulkRows.map((row,index)=>'<tr><td>'+(index+1)+'</td><td>'+esc(row.item_type)+'</td><td class="text-center">'+Number(row.quantity).toLocaleString('th-TH')+'</td><td>'+esc(row.main_code||'-')+'</td><td>'+esc(row.branch_code||'-')+'</td><td>'+esc(row.main_branch_name||'-')+'</td><td>'+esc(row.sub_branch_name||'-')+'</td><td><button type="button" class="btn btn-sm btn-outline-danger js-remove-staged" data-index="'+index+'" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></td></tr>').join('');
bulkPreviewBody.querySelectorAll('.js-remove-staged').forEach(btn=>btn.addEventListener('click',()=>{stagedBulkRows.splice(Number(btn.dataset.index),1);renderStagedBulkRows();}));
bulkCountText.textContent='พักไว้แล้ว '+stagedBulkRows.length.toLocaleString('th-TH')+' รายการ · พร้อมเพิ่มรอบถัดไป';
}

async function validateAndStageRound(){const itemType=String(document.getElementById('bulk_item_type').value||'').trim();const quantity=Math.max(1,Number(document.getElementById('bulk_quantity').value||1));const itemDetail='';const parsedResult=parseBulkLines();const parsed=parsedResult.items;if(!itemType){alert('กรุณาเลือกประเภทอุปกรณ์');return;}if(parsedResult.lineErrors.length){showBulkLineErrors(parsedResult.lineErrors);return;}clearBulkLineErrors();if(!parsed.length){alert('กรุณากรอกรหัสและชื่อสาขา');return;}bulkValidateBtn.disabled=true;bulkValidateBtn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>กำลังตรวจสอบ...';const cache={};const roundRows=[];const errors=[];for(const item of parsed){try{if(!cache[item.main_code])cache[item.main_code]=await fetchBranchesForCode(item.main_code);const branchRows=cache[item.main_code];const branch=findBestBranch(branchRows,item.input_name);const mainBranch=findMainBranchByType(branchRows);if(!branch){errors.push({line:item.line,field:'name',message:'ไม่พบชื่อสาขา '+item.input_name});continue;}if(!mainBranch){errors.push({line:item.line,field:'code',message:'ไม่พบสาขาใหญ่ของรหัส '+item.main_code});continue;}roundRows.push({item_type:itemType,quantity:quantity,item_detail:itemDetail,main_code:item.main_code,branch_code:String(branch.branch_code||'').trim(),main_branch_name:String(mainBranch.branch_name||'').trim(),sub_branch_name:String(branch.branch_name||'').trim()});}catch(error){errors.push({line:item.line,field:'code',message:(error.message||'เกิดข้อผิดพลาด')});}}bulkValidateBtn.disabled=false;bulkValidateBtn.innerHTML='<i class="bi bi-plus-circle me-1"></i>ตรวจสอบและพักรายการรอบนี้';if(errors.length){const lookupLineErrors=errors.map(error=>({line:error.line,field:error.field,message:error.message}));showBulkLineErrors(lookupLineErrors);return;}clearBulkLineErrors();stagedBulkRows.push(...roundRows);document.getElementById('bulk_item_type').value='';document.getElementById('bulk_quantity').value='1';bulkCodes.value='';bulkNames.value='';renderStagedBulkRows();}
if(bulkValidateBtn)bulkValidateBtn.addEventListener('click',validateAndStageRound);
if(bulkClearAllBtn)bulkClearAllBtn.addEventListener('click',()=>{if(confirm('ยืนยันล้างรายการที่พักไว้ทั้งหมดหรือไม่?')){stagedBulkRows=[];renderStagedBulkRows();}});
if(bulkShippingCostInput)bulkShippingCostInput.addEventListener('input',()=>{bulkShippingCostInput.classList.remove('is-invalid');updateBulkShippingLive();});
if(bulkForm)bulkForm.addEventListener('submit',function(event){if(!stagedBulkRows.length){event.preventDefault();alert('กรุณาพักรายการอย่างน้อย 1 รอบก่อนบันทึก');return;}const shippingInput=document.getElementById('bulk_shipping_cost_total');const shipping=Number(shippingInput.value||0);if(!Number.isFinite(shipping)||shipping<=0){event.preventDefault();shippingInput.classList.add('is-invalid');shippingInput.focus();alert('กรุณากรอกค่าจัดส่งรวมทั้งหมดมากกว่า 0 บาท');return;}shippingInput.classList.remove('is-invalid');bulkRowsJson.value=JSON.stringify(stagedBulkRows);const totalQty=stagedBulkRows.reduce((sum,row)=>sum+Number(row.quantity||0),0);if(!confirm('ยืนยันบันทึกทั้งหมด '+stagedBulkRows.length+' รายการ รวม '+totalQty+' ชิ้น ค่าจัดส่ง '+shipping.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})+' บาท หรือไม่?'))event.preventDefault();});
renderStagedBulkRows();
updateBulkShippingLive();

<?php if(($_GET['open_overview'] ?? '') === '1'):?>
bootstrap.Modal.getOrCreateInstance(document.getElementById('deliveryOverviewModal')).show();
<?php endif;?>
if(!container.children.length)itemRow();
})();
</script>

<!-- HDD_GLOBAL_MODAL_LAYER_FIX_V2 -->
<style>
html body > .modal { position: fixed !important; z-index: 2147483000 !important; }
html body > .modal.show { display: block !important; }
html body > .modal-backdrop { position: fixed !important; z-index: 2147482990 !important; }
html body.modal-open { overflow: hidden !important; }
</style>
<script>
(function () {
    'use strict';
    function moveModalToBody(modal) {
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }
    function normalizeAllModals() { document.querySelectorAll('.modal').forEach(moveModalToBody); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', normalizeAllModals);
    } else {
        normalizeAllModals();
    }
    document.addEventListener('show.bs.modal', function (event) { moveModalToBody(event.target); }, true);
    document.addEventListener('shown.bs.modal', function (event) {
        moveModalToBody(event.target);
        if (event.target) event.target.style.zIndex = '2147483000';
        document.querySelectorAll('body > .modal-backdrop').forEach(function (backdrop) {
            backdrop.style.zIndex = '2147482990';
        });
    }, true);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
