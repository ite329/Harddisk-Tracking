<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'ประวัติการพิมพ์ที่อยู่สาขา';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/print_history_common.php';

require_login();
require_permission('branch_label.view');

function phE($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function phClean($value): string
{
    return trim((string)($value ?? ''));
}

function phBuildQuery(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return http_build_query($query);
}



// ใช้รายการทรัพย์สินชุดเดียวกับหน้า ปริ้นที่อยู่สาขาย่อย > เลือกทรัพย์สินที่ต้องการจัดส่ง
$branchLabelAssetOptions = [
    'เครื่องปริ้นเตอร์ HP',
    'เครื่องปริ้นเตอร์ Brother',
    'คอมพิวเตอร์',
    'จอคอมพิวเตอร์',
    'กล้องวงจรปิด CCTV',
    'เครื่องบันทึกกล้องวงจรปิด',
    'Projector',
    'HDD กล้อง',
    'RAM',
    'Adapter CCTV',
    'Adapter Notebook',
    'ตลับหมึก Brother 5915',
    'Drum 3455',
    'Drum 3608',
];


function phQuoteColumn(string $column): string
{
    return '`' . str_replace('`', '``', $column) . '`';
}

function phBranchDirectoryMap(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'branch_directory' ORDER BY ORDINAL_POSITION");
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[strtolower((string)$column)] = (string)$column;
    }

    $resolve = static function (array $candidates) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }
        return null;
    };

    return [
        'main_branch_code' => $resolve(['main_branch_code', 'main_branch', 'main_code', 'branch_main_code']),
        'branch_code' => $resolve(['branch_code', 'code', 'cost_center', 'costcenter']),
        'branch_name' => $resolve(['branch_name', 'name', 'branch_name_th']),
        'branch_name_2' => $resolve(['branch_name_2', 'branch_name2', 'sub_branch_name', 'name_2']),
        'full_address' => $resolve(['full_address', 'address_full', 'fulladdr']),
        'address_line' => $resolve(['address_line', 'address', 'address1', 'addr']),
        'subdistrict' => $resolve(['subdistrict', 'tambon', 'sub_district']),
        'district' => $resolve(['district', 'amphur', 'amphoe']),
        'province' => $resolve(['province', 'changwat']),
        'postal_code' => $resolve(['postal_code', 'postcode', 'zip_code', 'zipcode']),
        'branch_type' => $resolve(['branch_type', 'type', 'branch_category', 'office_type']),
        'is_active' => $resolve(['is_active', 'active', 'status']),
    ];
}

function phDirectoryValue(array $row, array $map, string $field): string
{
    $column = $map[$field] ?? null;
    return $column !== null ? phClean($row[$column] ?? '') : '';
}

function phDirectoryAddress(array $row, array $map): string
{
    $fullAddress = phDirectoryValue($row, $map, 'full_address');
    if ($fullAddress !== '') {
        return $fullAddress;
    }

    $parts = [];
    foreach (['address_line', 'subdistrict', 'district', 'province', 'postal_code'] as $field) {
        $value = phDirectoryValue($row, $map, $field);
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }
    return implode(' ', $parts);
}

function phFindDirectoryBranch(PDO $pdo, string $mainBranchCode, string $branchCode): array
{
    $map = phBranchDirectoryMap($pdo);
    if (empty($map['main_branch_code']) || empty($map['branch_code']) || empty($map['branch_name'])) {
        throw new RuntimeException('โครงสร้างตาราง harddisk_db.branch_directory ไม่ครบสำหรับค้นหาสาขา');
    }

    $mainColumn = phQuoteColumn($map['main_branch_code']);
    $branchColumn = phQuoteColumn($map['branch_code']);
    $sql = "SELECT * FROM `harddisk_db`.`branch_directory`
        WHERE LPAD(TRIM(CAST({$mainColumn} AS CHAR)), 3, '0') = LPAD(TRIM(CAST(:main_branch_code AS CHAR)), 3, '0')
          AND TRIM(CAST({$branchColumn} AS CHAR)) = TRIM(CAST(:branch_code AS CHAR))
        LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':main_branch_code' => $mainBranchCode, ':branch_code' => $branchCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('ไม่พบสาขาที่เลือกใน harddisk_db.branch_directory');
    }

    $mainAddress = '';
    if (!empty($map['branch_type'])) {
        $typeColumn = phQuoteColumn($map['branch_type']);
        $mainStmt = $pdo->prepare("SELECT * FROM `harddisk_db`.`branch_directory`
            WHERE LPAD(TRIM(CAST({$mainColumn} AS CHAR)), 3, '0') = LPAD(TRIM(CAST(:main_branch_code AS CHAR)), 3, '0')
              AND (LOWER(TRIM(CAST({$typeColumn} AS CHAR))) IN ('สาขาใหญ่','main','main branch','head branch','large branch')
                   OR LOWER(TRIM(CAST({$typeColumn} AS CHAR))) LIKE '%ใหญ่%')
            LIMIT 1");
        $mainStmt->execute([':main_branch_code' => $mainBranchCode]);
        $mainRow = $mainStmt->fetch(PDO::FETCH_ASSOC);
        if ($mainRow) {
            $mainAddress = phDirectoryAddress($mainRow, $map);
        }
    }

    return [
        'main_branch_code' => phDirectoryValue($row, $map, 'main_branch_code'),
        'branch_code' => phDirectoryValue($row, $map, 'branch_code'),
        'branch_name' => phDirectoryValue($row, $map, 'branch_name'),
        'address' => phDirectoryAddress($row, $map),
        'main_address' => $mainAddress,
    ];
}


function phResolveMissingHistoryAddress(PDO $pdo, array $row): string
{
    $currentAddress = phClean($row['shipping_address'] ?? '');
    if ($currentAddress !== '') {
        return $currentAddress;
    }

    $mainBranchCode = phClean($row['main_branch_code'] ?? '');
    $branchCode = phClean($row['branch_code'] ?? '');
    if ($mainBranchCode === '' || $branchCode === '') {
        return '';
    }

    try {
        $directoryBranch = phFindDirectoryBranch($pdo, $mainBranchCode, $branchCode);
        return phClean($directoryBranch['address'] ?? '');
    } catch (Throwable $e) {
        error_log('[branch_labels/print_history/address_fallback] ' . $e->getMessage());
        return '';
    }
}


function phResolveMainBranchName(PDO $pdo, string $mainBranchCode): string
{
    $mainBranchCode = phClean($mainBranchCode);
    if ($mainBranchCode === '') {
        return '';
    }

    try {
        $map = phBranchDirectoryMap($pdo);
        if (empty($map['main_branch_code']) || empty($map['branch_name'])) {
            return '';
        }

        $mainColumn = phQuoteColumn($map['main_branch_code']);
        $nameColumn = phQuoteColumn($map['branch_name']);
        $name2Column = !empty($map['branch_name_2']) ? phQuoteColumn($map['branch_name_2']) : null;
        $typeCondition = '';
        if (!empty($map['branch_type'])) {
            $typeColumn = phQuoteColumn($map['branch_type']);
            $typeCondition = " AND (LOWER(TRIM(CAST({$typeColumn} AS CHAR))) IN ('สาขาใหญ่','main','main branch','head branch','large branch') OR LOWER(TRIM(CAST({$typeColumn} AS CHAR))) LIKE '%ใหญ่%')";
        }

        $selectName = $name2Column !== null
            ? "COALESCE(NULLIF(TRIM(CAST({$nameColumn} AS CHAR)), ''), NULLIF(TRIM(CAST({$name2Column} AS CHAR)), ''))"
            : "NULLIF(TRIM(CAST({$nameColumn} AS CHAR)), '')";

        $stmt = $pdo->prepare("SELECT {$selectName} AS main_branch_name
            FROM `harddisk_db`.`branch_directory`
            WHERE LPAD(TRIM(CAST({$mainColumn} AS CHAR)), 3, '0') = LPAD(TRIM(CAST(:main_branch_code AS CHAR)), 3, '0'){$typeCondition}
            ORDER BY CASE WHEN TRIM(CAST(`branch_code` AS CHAR)) = TRIM(CAST(:main_branch_code_order AS CHAR)) THEN 0 ELSE 1 END, `branch_code` ASC
            LIMIT 1");
        $stmt->execute([
            ':main_branch_code' => $mainBranchCode,
            ':main_branch_code_order' => $mainBranchCode,
        ]);
        return phClean($stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('[branch_labels/print_history/main_branch_name] ' . $e->getMessage());
        return '';
    }
}

if (($_GET['ajax'] ?? '') === 'branch_directory') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!(function_exists('can') && can('branch_label.manage'))) {
            throw new RuntimeException('ไม่มีสิทธิ์เรียกดูข้อมูลสำหรับแก้ไข');
        }
        $mainBranchCode = preg_replace('/[^0-9A-Za-z_-]/', '', phClean($_GET['main_branch_code'] ?? ''));
        if ($mainBranchCode === '') {
            throw new RuntimeException('กรุณาระบุรหัสสาขาใหญ่');
        }

        $map = phBranchDirectoryMap($pdo);
        if (empty($map['main_branch_code']) || empty($map['branch_code']) || empty($map['branch_name'])) {
            throw new RuntimeException('โครงสร้างตาราง harddisk_db.branch_directory ไม่ครบสำหรับค้นหาสาขา');
        }

        $mainColumn = phQuoteColumn($map['main_branch_code']);
        $where = "LPAD(TRIM(CAST({$mainColumn} AS CHAR)), 3, '0') = LPAD(TRIM(CAST(:main_branch_code AS CHAR)), 3, '0')";
        if (!empty($map['is_active'])) {
            $activeColumn = phQuoteColumn($map['is_active']);
            $where .= " AND ({$activeColumn} IS NULL OR {$activeColumn} = '' OR {$activeColumn} = '1' OR LOWER(TRIM(CAST({$activeColumn} AS CHAR))) IN ('active','yes','y','true','ใช้งาน','เปิดใช้งาน'))";
        }

        $order = phQuoteColumn($map['branch_code']) . ' ASC';
        $stmt = $pdo->prepare("SELECT * FROM `harddisk_db`.`branch_directory` WHERE {$where} ORDER BY {$order}");
        $stmt->execute([':main_branch_code' => $mainBranchCode]);
        $data = [];
        $directoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mainAddress = '';
        foreach ($directoryRows as $directoryRow) {
            $branchType = mb_strtolower(phDirectoryValue($directoryRow, $map, 'branch_type'), 'UTF-8');
            if ($branchType === 'สาขาใหญ่' || in_array($branchType, ['main', 'main branch', 'head branch', 'large branch'], true) || mb_strpos($branchType, 'ใหญ่') !== false) {
                $mainAddress = phDirectoryAddress($directoryRow, $map);
                break;
            }
        }
        foreach ($directoryRows as $row) {
            $data[] = [
                'main_branch_code' => phDirectoryValue($row, $map, 'main_branch_code'),
                'branch_code' => phDirectoryValue($row, $map, 'branch_code'),
                'branch_name' => phDirectoryValue($row, $map, 'branch_name'),
                'branch_name_2' => phDirectoryValue($row, $map, 'branch_name_2'),
                'branch_type' => phDirectoryValue($row, $map, 'branch_type'),
                'address' => phDirectoryAddress($row, $map),
                'main_address' => $mainAddress,
            ];
        }
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}



// สิทธิ์จัดการใช้ระบบ Permission ส่วนกลาง โดย Super admin จะได้รับสิทธิ์ผ่าน can()
$canManagePrintHistory = function_exists('can') && can('branch_label.manage');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$manageMessage = '';
$manageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_action'])) {
    try {
        if (!$canManagePrintHistory) {
            throw new RuntimeException('คุณไม่มีสิทธิ์แก้ไขหรือลบประวัติการพิมพ์');
        }
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('CSRF Token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่');
        }

        $manageAction = phClean($_POST['manage_action'] ?? '');
        $historyId = max(0, (int)($_POST['history_id'] ?? 0));
        if ($historyId <= 0) {
            throw new RuntimeException('ไม่พบรหัสรายการประวัติ');
        }

        if ($manageAction === 'edit') {
            $mainBranchCode = phClean($_POST['main_branch_code'] ?? '');
            $branchCode = phClean($_POST['branch_code'] ?? '');
            $directoryBranch = phFindDirectoryBranch($pdo, $mainBranchCode, $branchCode);
            $mainBranchCode = $directoryBranch['main_branch_code'];
            $branchCode = $directoryBranch['branch_code'];
            $branchName = $directoryBranch['branch_name'];
            $source = phClean($_POST['print_source'] ?? 'direct_branch');
            if (!in_array($source, ['direct_branch', 'main_branch_group', 'hdd_request', 'drum_request'], true)) {
                $source = 'direct_branch';
            }
            $shippingAddress = $source === 'main_branch_group'
                ? phClean($directoryBranch['main_address'] ?? '')
                : phClean($directoryBranch['address'] ?? '');
            if ($source === 'main_branch_group' && $shippingAddress === '') {
                throw new RuntimeException('ไม่พบที่อยู่ของสาขาใหญ่ใน harddisk_db.branch_directory');
            }
            $asset = phClean($_POST['asset_name'] ?? '');
            if ($asset !== '' && !in_array($asset, $branchLabelAssetOptions, true)) {
                throw new RuntimeException('รายการจัดส่งไม่ถูกต้อง กรุณาเลือกจากรายการทรัพย์สินที่กำหนด');
            }
            $orientation = phClean($_POST['print_orientation'] ?? 'portrait');

            if ($mainBranchCode === '' || $branchName === '' || $shippingAddress === '') {
                throw new RuntimeException('กรุณากรอกรหัสสาขาใหญ่ ชื่อสาขา และที่อยู่จัดส่งให้ครบ');
            }
            if (!in_array($orientation, ['portrait', 'landscape'], true)) {
                $orientation = 'portrait';
            }
            if (!in_array($source, ['direct_branch', 'main_branch_group', 'hdd_request', 'drum_request'], true)) {
                $source = 'direct_branch';
            }

            $stmt = $pdo->prepare("UPDATE `harddisk_db`.`branch_label_print_history`
                SET main_branch_code = :main_branch_code,
                    branch_code = :branch_code,
                    branch_name = :branch_name,
                    shipping_address = :shipping_address,
                    history.asset_name = :asset_name,
                    print_orientation = :print_orientation,
                    history.print_source = :print_source
                WHERE id = :id");
            $stmt->execute([
                ':main_branch_code' => $mainBranchCode,
                ':branch_code' => $branchCode !== '' ? $branchCode : null,
                ':branch_name' => $branchName,
                ':shipping_address' => $shippingAddress,
                ':asset_name' => $asset !== '' ? $asset : null,
                ':print_orientation' => $orientation,
                ':print_source' => $source,
                ':id' => $historyId,
            ]);
            $manageMessage = 'แก้ไขประวัติการพิมพ์เรียบร้อยแล้ว';
        } elseif ($manageAction === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM `harddisk_db`.`branch_label_print_history` WHERE id = :id');
            $stmt->execute([':id' => $historyId]);
            $manageMessage = 'ลบประวัติการพิมพ์เรียบร้อยแล้ว';
        } else {
            throw new RuntimeException('คำสั่งจัดการไม่ถูกต้อง');
        }
    } catch (Throwable $e) {
        error_log('[branch_labels/print_history/manage] ' . $e->getMessage());
        $manageError = $e->getMessage();
    }
}

$keyword = phClean($_GET['keyword'] ?? '');
$dateFrom = phClean($_GET['date_from'] ?? '');
$dateTo = phClean($_GET['date_to'] ?? '');
$assetName = phClean($_GET['asset_name'] ?? '');
$printSource = phClean($_GET['print_source'] ?? '');
$quickRange = phClean($_GET['range'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

if (!in_array($printSource, ['', 'direct_branch', 'main_branch_group', 'hdd_request', 'drum_request'], true)) {
    $printSource = '';
}

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok'));
if ($quickRange === '7') {
    $dateFrom = $today->modify('-6 days')->format('Y-m-d');
    $dateTo = $today->format('Y-m-d');
} elseif ($quickRange === '30') {
    $dateFrom = $today->modify('-29 days')->format('Y-m-d');
    $dateTo = $today->format('Y-m-d');
}

$rows = [];
$totalRows = 0;
$totalPages = 1;
$summary = ['all' => 0, 'today' => 0, 'week' => 0, 'month' => 0, 'branches' => 0];
$assetOptions = [];
$pageError = '';

try {
    branchLabelEnsurePrintHistoryTable($pdo);

    $summaryStmt = $pdo->query("SELECT
        COUNT(*) AS all_count,
        SUM(DATE(printed_at) = CURDATE()) AS today_count,
        SUM(printed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) AS week_count,
        SUM(printed_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)) AS month_count,
        COUNT(DISTINCT CONCAT(COALESCE(main_branch_code,''), '|', COALESCE(branch_code,''))) AS branch_count
        FROM `harddisk_db`.`branch_label_print_history`");
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary = [
        'all' => (int)($summaryRow['all_count'] ?? 0),
        'today' => (int)($summaryRow['today_count'] ?? 0),
        'week' => (int)($summaryRow['week_count'] ?? 0),
        'month' => (int)($summaryRow['month_count'] ?? 0),
        'branches' => (int)($summaryRow['branch_count'] ?? 0),
    ];

    $assetOptions = $pdo->query("SELECT DISTINCT asset_name FROM `harddisk_db`.`branch_label_print_history` WHERE asset_name IS NOT NULL AND asset_name <> '' ORDER BY asset_name ASC")->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];

    if ($keyword !== '') {
        $keywordLike = '%' . $keyword . '%';
        $keywordFields = [
            'main_branch_code',
            'branch_code',
            'branch_name',
            'printed_by_name',
            'printed_by_employee_code',
            'asset_name',
            'request_no',
            'hdd_serial',
        ];
        $keywordConditions = [];
        foreach ($keywordFields as $index => $field) {
            $paramName = ':keyword_' . $index;
            $keywordConditions[] = 'history.`' . $field . '` LIKE ' . $paramName;
            $params[$paramName] = $keywordLike;
        }
        $where[] = '(' . implode(' OR ', $keywordConditions) . ')';
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(history.printed_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(history.printed_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    if ($assetName !== '') {
        $where[] = 'history.asset_name = :asset_name';
        $params[':asset_name'] = $assetName;
    }
    if ($printSource !== '') {
        $where[] = 'history.print_source = :print_source';
        $params[':print_source'] = $printSource;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    if (($_GET['export'] ?? '') === 'csv') {
        $exportStmt = $pdo->prepare("SELECT printed_at, printed_by_name, printed_by_employee_code, main_branch_code, branch_code, branch_name, asset_name, request_no, hdd_serial, shipping_address, print_orientation, print_source, printed_ip
            FROM `harddisk_db`.`branch_label_print_history`{$whereSql}
            ORDER BY printed_at DESC");
        $exportStmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="branch_label_print_history_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['วันที่พิมพ์', 'ผู้พิมพ์', 'รหัสพนักงาน', 'รหัสสาขาใหญ่', 'สาขาใหญ่', 'Cost Center', 'สาขาปลายทาง', 'รายการจัดส่ง', 'เลขที่คำขอ', 'Serial HDD', 'ที่อยู่จัดส่ง', 'รูปแบบกระดาษ', 'แหล่งการพิมพ์', 'IP Address']);
        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            $resolvedShippingAddress = phResolveMissingHistoryAddress($pdo, $row);
            fputcsv($out, [
                $row['printed_at'], $row['printed_by_name'], $row['printed_by_employee_code'],
                $row['main_branch_code'], phResolveMainBranchName($pdo, (string)$row['main_branch_code']), $row['branch_code'], $row['branch_name'],
                $row['asset_name'], $row['request_no'], $row['hdd_serial'], $resolvedShippingAddress, $row['print_orientation'],
                ($row['print_source'] === 'direct_branch' ? 'ส่งสาขาย่อย,ศูนย์ฯ' : ($row['print_source'] === 'main_branch_group' ? 'ส่งสาขาใหญ่' : $row['print_source'])), $row['printed_ip'],
            ]);
        }
        fclose($out);
        exit;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `harddisk_db`.`branch_label_print_history` history{$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT history.id, history.main_branch_code, history.branch_code, history.branch_name, history.shipping_address, history.asset_name, history.request_no, history.hdd_serial,
        history.print_orientation, history.print_source, history.printed_by_employee_code, history.printed_by_name, history.printed_ip, history.printed_at,
        (SELECT COUNT(*)
         FROM `harddisk_db`.`branch_label_print_history` duplicate_history
         WHERE duplicate_history.main_branch_code <=> history.main_branch_code
           AND duplicate_history.branch_code <=> history.branch_code
           AND duplicate_history.branch_name <=> history.branch_name
           AND duplicate_history.shipping_address <=> history.shipping_address
           AND duplicate_history.asset_name <=> history.asset_name
           AND duplicate_history.request_no <=> history.request_no
           AND duplicate_history.hdd_serial <=> history.hdd_serial
           AND duplicate_history.print_orientation <=> history.print_orientation
           AND duplicate_history.print_source <=> history.print_source) AS duplicate_count
        FROM `harddisk_db`.`branch_label_print_history` history
        {$whereSql}
        ORDER BY history.printed_at DESC, history.id DESC
        LIMIT :limit OFFSET :offset");
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $mainBranchNameCache = [];
    foreach ($rows as &$historyRow) {
        if (phClean($historyRow['shipping_address'] ?? '') === '') {
            $historyRow['shipping_address'] = phResolveMissingHistoryAddress($pdo, $historyRow);
        }
        $mainBranchCodeKey = phClean($historyRow['main_branch_code'] ?? '');
        if (!array_key_exists($mainBranchCodeKey, $mainBranchNameCache)) {
            $mainBranchNameCache[$mainBranchCodeKey] = phResolveMainBranchName($pdo, $mainBranchCodeKey);
        }
        $historyRow['main_branch_name'] = $mainBranchNameCache[$mainBranchCodeKey];
    }
    unset($historyRow);
} catch (Throwable $e) {
    error_log('[branch_labels/print_history] ' . $e->getMessage());
    $pageError = 'ไม่สามารถโหลดประวัติการพิมพ์ได้: ' . $e->getMessage();
}

require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.print-history-page{--ph-blue:#0f4c81;--ph-border:#dbe5ee}.print-history-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);border-radius:18px;padding:18px 20px;color:#fff;box-shadow:0 12px 30px rgba(15,76,129,.18);margin-bottom:14px}.print-history-hero h1{font-size:1.25rem;font-weight:800;margin:0}.print-history-back{border-color:rgba(255,255,255,.65)!important;color:#fff!important}.print-history-back:hover{background:#fff!important;color:#0f4c81!important}.print-history-kpi{border:0;border-radius:14px;box-shadow:0 5px 18px rgba(20,46,70,.07)}.print-history-kpi .card-body{padding:12px 14px}.print-history-kpi-label{font-size:.7rem;color:#64748b;font-weight:800}.print-history-kpi-value{font-size:1.45rem;color:#0f172a;font-weight:900;line-height:1.1}.print-history-filter,.print-history-table-card{border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07)}.print-history-filter .form-control,.print-history-filter .form-select,.print-history-filter .btn{height:36px;font-size:.74rem;border-radius:9px}.print-history-filter-grid{display:grid;grid-template-columns:minmax(220px,1.6fr) repeat(2,minmax(120px,.72fr)) minmax(145px,.85fr) minmax(135px,.78fr) repeat(3,minmax(82px,.48fr));gap:7px;align-items:end}.print-history-table{min-width:980px}.print-history-table th{font-size:.68rem;background:#f8fafc;color:#475569;white-space:nowrap}.print-history-table td{font-size:.71rem;vertical-align:middle}.print-history-address{max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.print-history-source{font-size:.64rem;font-weight:800}.print-history-quick{display:flex;gap:6px;flex-wrap:wrap}.print-history-quick .btn{height:auto!important}.print-history-page .pagination .page-link{font-size:.72rem}.print-history-user{font-weight:800;color:#17324d}.print-history-duplicate-row>td{color:#0d6efd!important;font-weight:700}.print-history-duplicate-row .print-history-address{color:#0d6efd!important}.print-history-duplicate-row .print-history-actions,.print-history-duplicate-row .print-history-source{font-weight:inherit}@media(max-width:1366px){.print-history-filter-grid{grid-template-columns:minmax(180px,1.4fr) repeat(2,minmax(105px,.7fr)) minmax(125px,.8fr) minmax(115px,.72fr) repeat(3,minmax(72px,.45fr));gap:5px}.print-history-filter .form-control,.print-history-filter .form-select,.print-history-filter .btn{font-size:.66rem;padding-left:.4rem;padding-right:.4rem}.print-history-table th,.print-history-table td{font-size:.64rem}}@media(max-width:900px){.print-history-filter-grid{grid-template-columns:1fr 1fr}.print-history-filter-keyword{grid-column:1/-1}.print-history-table-card{overflow:hidden}}


.branch-label-module-menu{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin:0 0 14px}
.branch-label-module-menu-item{position:relative;min-width:0;min-height:78px;display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid #dbe5ee;border-radius:14px;background:#fff;color:#334155;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.055);transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;overflow:hidden}
.branch-label-module-menu-item:hover{color:#0f4c81;text-decoration:none;border-color:#93c5fd;box-shadow:0 9px 22px rgba(37,99,235,.12);transform:translateY(-1px)}
.branch-label-module-menu-item.active{color:#fff;border-color:#00acc1;background:linear-gradient(135deg,#0097a7 0%,#00bcd4 58%,#26c6da 100%);box-shadow:0 10px 24px rgba(0,188,212,.28)}
.branch-label-module-menu-icon{width:38px;height:38px;flex:0 0 38px;display:flex;align-items:center;justify-content:center;border-radius:11px;background:#e0f7fa;color:#00acc1;font-size:1.1rem}
.branch-label-module-menu-item.active .branch-label-module-menu-icon{background:rgba(255,255,255,.18);color:#fff}
.branch-label-module-menu-content{min-width:0}.branch-label-module-menu-title{display:block;font-size:.78rem;line-height:1.25;font-weight:900;white-space:normal}.branch-label-module-menu-note{display:block;margin-top:3px;font-size:.65rem;line-height:1.2;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.branch-label-module-menu-item.active .branch-label-module-menu-note{color:rgba(255,255,255,.82)}
/* .branch-label-module-menu-item.active.branch-label-active-menu-blink{animation:branchLabelActiveMenuBlink 1.4s ease-out infinite; */


transform-origin:center;will-change:transform,box-shadow,filter}@keyframes branchLabelActiveMenuBlink{0%,100%{transform:scale(1);box-shadow:0 10px 24px rgba(0,188,212,.28);filter:brightness(1)}50%{transform:scale(1.025);box-shadow:0 0 0 4px rgba(0,188,212,.22),0 14px 30px rgba(0,151,167,.38);filter:brightness(1.16)}}
@media(max-width:1366px){.branch-label-module-menu{gap:7px}.branch-label-module-menu-item{min-height:70px;padding:9px 10px;gap:8px}.branch-label-module-menu-icon{width:32px;height:32px;flex-basis:32px;border-radius:9px;font-size:.95rem}.branch-label-module-menu-title{font-size:.7rem}.branch-label-module-menu-note{font-size:.59rem}}
@media(max-width:800px){.branch-label-module-menu{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.branch-label-module-menu-item.active.branch-label-active-menu-blink{animation:none}}

.print-history-actions{display:flex;justify-content:center;gap:4px;white-space:nowrap}.print-history-actions .btn{font-size:.62rem;padding:.24rem .38rem}.print-history-manage-modal .modal-content{border:0;border-radius:16px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.24)}.print-history-manage-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);border-bottom:1px solid #dbe5ee;padding:12px 16px}.print-history-manage-modal .modal-body{background:#f8fafc;padding:12px}.print-history-manage-modal .modal-footer{padding:9px 12px;background:#fff}.print-history-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.print-history-edit-grid .full{grid-column:1/-1}.print-history-edit-grid .form-label{font-size:.72rem;font-weight:800;margin-bottom:4px}.print-history-edit-grid .form-control,.print-history-edit-grid .form-select{min-height:36px;font-size:.75rem}.print-history-delete-box{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:12px;padding:12px}.print-history-delete-summary{margin-top:10px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}.print-history-delete-row{display:grid;grid-template-columns:120px 1fr;border-bottom:1px solid #e2e8f0}.print-history-delete-row:last-child{border-bottom:0}.print-history-delete-label{background:#f8fafc;padding:7px 9px;font-size:.7rem;font-weight:800;color:#64748b}.print-history-delete-value{padding:7px 9px;font-size:.74rem;font-weight:800;color:#0f172a;overflow-wrap:anywhere}@media(max-width:767.98px){.print-history-edit-grid{grid-template-columns:1fr}.print-history-edit-grid .full{grid-column:auto}}

</style>
<div class="print-history-page pb-4">
<nav class="branch-label-module-menu" aria-label="เมนูระบบค้นหาและพิมพ์ที่อยู่สาขา">
    <a class="branch-label-module-menu-item" href="index.php?view=group">
        <span class="branch-label-module-menu-icon"><i class="bi bi-diagram-3"></i></span>
        <span class="branch-label-module-menu-content"><span class="branch-label-module-menu-title">พิมพ์ที่อยู่สาขาใหญ่</span><span class="branch-label-module-menu-note">เลือกสาขาย่อยและใช้ที่อยู่สาขาใหญ่</span></span>
    </a>
    <a class="branch-label-module-menu-item" href="index.php?view=direct">
        <span class="branch-label-module-menu-icon"><i class="bi bi-geo-alt"></i></span>
        <span class="branch-label-module-menu-content"><span class="branch-label-module-menu-title">พิมพ์ที่อยู่สาขาย่อย/ศูนย์ฯ/ค้นหา Cost Center</span><span class="branch-label-module-menu-note">ค้นหาและพิมพ์ตามข้อมูลสาขาเดิม</span></span>
    </a>
    <a class="branch-label-module-menu-item active branch-label-active-menu-blink" href="print_history.php" aria-current="page">
        <span class="branch-label-module-menu-icon"><i class="bi bi-clock-history"></i></span>
        <span class="branch-label-module-menu-content"><span class="branch-label-module-menu-title">ประวัติการพิมพ์ที่อยู่</span><span class="branch-label-module-menu-note">ตรวจสอบผู้พิมพ์ สาขา และรายการย้อนหลัง</span></span>
    </a>
</nav>
    <div class="print-history-hero d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div><h1><i class="bi bi-clock-history me-2"></i>ประวัติการพิมพ์ที่อยู่สาขา</h1>
        <div class="small opacity-75 mt-1">ตรวจสอบว่าใครพิมพ์สาขาใด รายการอะไร และพิมพ์เมื่อใด</div>
    </div>
        <!-- <a href="index.php?view=group" class="btn btn-sm print-history-back"><i class="bi bi-arrow-left me-1"></i>กลับหน้าค้นหาสาขา</a> -->
    </div>

    <?php if ($pageError !== ''): ?><div class="alert alert-danger"><?php echo phE($pageError); ?></div><?php endif; ?>
    <?php if ($manageMessage !== ''): ?><div class="alert alert-success py-2"><?php echo phE($manageMessage); ?></div><?php endif; ?>
    <?php if ($manageError !== ''): ?><div class="alert alert-danger py-2"><?php echo phE($manageError); ?></div><?php endif; ?>

    <div class="card print-history-filter mb-3"><div class="card-body p-3">
        <div class="print-history-quick mb-2">
            <a class="btn btn-sm <?php echo $quickRange === '7' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?range=7">7 วันที่ผ่านมา</a>
            <a class="btn btn-sm <?php echo $quickRange === '30' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?range=30">30 วันที่ผ่านมา</a>
            <a class="btn btn-sm btn-outline-secondary" href="print_history.php">ทั้งหมด</a>
        </div>
        <form method="get" class="print-history-filter-grid" autocomplete="off">
            <div class="print-history-filter-keyword"><label class="form-label small fw-bold">ค้นหา</label><input type="text" name="keyword" class="form-control" value="<?php echo phE($keyword); ?>" placeholder="รหัสสาขา, Cost Center, ชื่อสาขา, ผู้พิมพ์, รายการ"></div>
            <div><label class="form-label small fw-bold">วันที่เริ่มต้น</label><input type="date" name="date_from" class="form-control" value="<?php echo phE($dateFrom); ?>"></div>
            <div><label class="form-label small fw-bold">วันที่สิ้นสุด</label><input type="date" name="date_to" class="form-control" value="<?php echo phE($dateTo); ?>"></div>
            <div><label class="form-label small fw-bold">รายการจัดส่ง</label><select name="asset_name" class="form-select"><option value="">ทั้งหมด</option><?php foreach ($assetOptions as $option): ?><option value="<?php echo phE($option); ?>" <?php echo $assetName === $option ? 'selected' : ''; ?>><?php echo phE($option); ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small fw-bold">แหล่งการพิมพ์</label><select name="print_source" class="form-select"><option value="">ทั้งหมด</option><option value="direct_branch" <?php echo $printSource === 'direct_branch' ? 'selected' : ''; ?>>ส่งสาขาย่อย,ศูนย์ฯ</option><option value="main_branch_group" <?php echo $printSource === 'main_branch_group' ? 'selected' : ''; ?>>ส่งสาขาใหญ่</option><option value="hdd_request" <?php echo $printSource === 'hdd_request' ? 'selected' : ''; ?>>คำขอส่ง Harddisk</option><option value="drum_request" <?php echo $printSource === 'drum_request' ? 'selected' : ''; ?>>คำขอเบิก Drum</option></select></div>
            <div><label class="form-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100">ค้นหา</button></div>
            <div><label class="form-label">&nbsp;</label><a href="print_history.php" class="btn btn-outline-secondary w-100">ล้างค่า</a></div>
            <div><label class="form-label">&nbsp;</label><a href="?<?php echo phE(phBuildQuery(['export'=>'csv','page'=>null])); ?>" class="btn btn-outline-success w-100">Excel</a></div>
        </form>
    </div></div>

    <div class="card print-history-table-card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center"><strong>รายการประวัติการพิมพ์</strong><span class="small text-muted"><?php echo number_format($totalRows); ?> รายการ</span></div>
        <div class="table-responsive"><table class="table table-hover table-bordered align-middle mb-0 print-history-table"><thead><tr><th>ลำดับ</th><th>รหัสสาขาใหญ่</th><th>สาขาใหญ่</th><th>สาขาปลายทาง</th><th>รายการจัดส่ง</th><th>ที่อยู่จัดส่ง</th><th>วันที่พิมพ์</th><th>ระบบ</th><?php if ($canManagePrintHistory): ?><th>จัดการ</th><?php endif; ?></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="<?php echo $canManagePrintHistory ? 9 : 8; ?>" class="text-center text-muted py-5">ยังไม่มีประวัติการพิมพ์ตามเงื่อนไขที่เลือก</td></tr><?php else: foreach ($rows as $index => $row): ?>
            <tr class="<?php echo (int)($row['duplicate_count'] ?? 1) > 1 ? 'print-history-duplicate-row' : ''; ?>"<?php echo (int)($row['duplicate_count'] ?? 1) > 1 ? ' title="พบประวัติการพิมพ์รายการเดียวกันมากกว่า 1 ครั้ง"' : ''; ?>><td class="text-center"><?php echo number_format((($page-1)*$perPage)+$index+1); ?></td><td class="fw-bold text-primary text-center"><?php echo phE($row['main_branch_code']); ?></td><td><?php echo phE($row['main_branch_name'] ?: '-'); ?></td><td><?php echo phE($row['branch_name']); ?></td><td><?php echo phE($row['print_source'] === 'hdd_request' ? 'Harddisk' : ($row['print_source'] === 'drum_request' ? 'Drum' : ($row['asset_name'] ?: '-'))); ?></td><td><div class="print-history-address" title="<?php echo phE($row['shipping_address']); ?>"><?php echo phE($row['shipping_address'] ?: '-'); ?></div></td><td class="text-nowrap text-center"><?php echo phE(date('d/m/Y', strtotime((string)$row['printed_at']))); ?></td><td class="text-center"><span class="badge <?php echo in_array($row['print_source'], ['hdd_request','drum_request'], true) ? 'text-bg-success' : ($row['print_source'] === 'main_branch_group' ? 'text-bg-info' : 'text-bg-secondary'); ?> print-history-source"><?php echo $row['print_source'] === 'hdd_request' ? 'คำขอส่ง Harddisk' : ($row['print_source'] === 'drum_request' ? 'คำขอเบิก Drum' : ($row['print_source'] === 'main_branch_group' ? 'ส่งสาขาใหญ่' : 'ส่งสาขาย่อย,ศูนย์ฯ')); ?></span></td><?php if ($canManagePrintHistory): ?><td><div class="print-history-actions"><?php if (!in_array($row['print_source'], ['hdd_request','drum_request'], true)): ?><button type="button" class="btn btn-warning btn-sm js-edit-print-history" data-bs-toggle="modal" data-bs-target="#editPrintHistoryModal" data-id="<?php echo (int)$row['id']; ?>" data-main-branch-code="<?php echo phE($row['main_branch_code']); ?>" data-branch-code="<?php echo phE($row['branch_code']); ?>" data-branch-name="<?php echo phE($row['branch_name']); ?>" data-shipping-address="<?php echo phE($row['shipping_address']); ?>" data-asset-name="<?php echo phE($row['asset_name']); ?>" data-print-orientation="<?php echo phE($row['print_orientation']); ?>" data-print-source="<?php echo phE($row['print_source']); ?>"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="แก้ไข"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10A.5.5 0 0 1 5.5 14H2a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .146-.354zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zM12.793 5.5 10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zM3.5 10.207 2.5 11.207V13h1.793l1-1H5.5v-.5H5a.5.5 0 0 1-.5-.5v-.5H4a.5.5 0 0 1-.5-.5z"/></svg></button><?php endif; ?><button type="button" class="btn btn-outline-danger btn-sm js-delete-print-history" data-bs-toggle="modal" data-bs-target="#deletePrintHistoryModal" data-id="<?php echo (int)$row['id']; ?>" data-branch-name="<?php echo phE($row['branch_name']); ?>" data-printed-at="<?php echo phE(date('d/m/Y', strtotime((string)$row['printed_at']))); ?>"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="ลบ"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></div></td><?php endif; ?></tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
        <?php if ($totalPages > 1): ?><div class="card-footer bg-white d-flex justify-content-between align-items-center"><span class="small text-muted">หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></span><nav><ul class="pagination pagination-sm mb-0"><li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo phE(phBuildQuery(['page'=>max(1,$page-1)])); ?>">ก่อนหน้า</a></li><?php for ($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?><li class="page-item <?php echo $p===$page?'active':''; ?>"><a class="page-link" href="?<?php echo phE(phBuildQuery(['page'=>$p])); ?>"><?php echo $p; ?></a></li><?php endfor; ?><li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo phE(phBuildQuery(['page'=>min($totalPages,$page+1)])); ?>">ถัดไป</a></li></ul></nav></div><?php endif; ?>
    </div>


<?php if ($canManagePrintHistory): ?>
<div class="modal fade print-history-manage-modal" id="editPrintHistoryModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content" autocomplete="off">
            <div class="modal-header"><div><h5 class="modal-title fw-bold">แก้ไขประวัติการพิมพ์</h5><div class="small text-muted">สิทธิ์ส่วนกลาง: branch_label.manage</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="manage_action" value="edit"><input type="hidden" name="history_id" id="edit_history_id"><input type="hidden" name="csrf_token" value="<?php echo phE($_SESSION['csrf_token']); ?>">
                <div class="print-history-edit-grid">
                    <div class="full"><label class="form-label">รหัสสาขาใหญ่</label><div class="input-group"><input type="text" name="main_branch_code" id="edit_main_branch_code" class="form-control" maxlength="10" required><button type="button" class="btn btn-outline-primary" id="btnLoadDirectoryBranches"><i class="bi bi-search me-1"></i>ดึงข้อมูลสาขา</button></div><div class="form-text" id="edit_branch_lookup_status">ข้อมูลสาขาจะอ้างอิงจาก harddisk_db.branch_directory</div></div>
                    <div class="full"><label class="form-label">เลือกสาขาปลายทาง</label><select id="edit_directory_branch_select" class="form-select" disabled required><option value="">-- กรุณาดึงข้อมูลสาขาก่อน --</option></select></div>
                    <div><label class="form-label">Cost Center</label><input type="text" name="branch_code" id="edit_branch_code" class="form-control bg-light" readonly required></div>
                    <div><label class="form-label">ชื่อสาขาปลายทาง</label><input type="text" id="edit_branch_name" class="form-control bg-light" readonly></div>
                    <div class="full"><label class="form-label">ที่อยู่จัดส่ง</label><textarea id="edit_shipping_address" class="form-control bg-light" rows="3" readonly></textarea></div>
                    <div><label class="form-label">รายการจัดส่ง</label><select name="asset_name" id="edit_asset_name" class="form-select"><option value="">-- เลือกทรัพย์สิน --</option><?php foreach ($branchLabelAssetOptions as $branchLabelAssetOption): ?><option value="<?php echo phE($branchLabelAssetOption); ?>"><?php echo phE($branchLabelAssetOption); ?></option><?php endforeach; ?></select></div>
                    <div><label class="form-label">รูปแบบกระดาษ</label><select name="print_orientation" id="edit_print_orientation" class="form-select"><option value="portrait">แนวตั้ง</option><option value="landscape">แนวนอน</option></select></div>
                    <div class="full"><label class="form-label">แหล่งการพิมพ์</label><select name="print_source" id="edit_print_source" class="form-select"><option value="direct_branch">ส่งสาขาย่อย,ศูนย์ฯ</option><option value="main_branch_group">ส่งสาขาใหญ่</option><option value="hdd_request">คำขอส่ง Harddisk</option><option value="drum_request">คำขอเบิก Drum</option></select></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary px-4">บันทึกการแก้ไข</button></div>
        </form>
    </div>
</div>
<div class="modal fade print-history-manage-modal" id="deletePrintHistoryModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold text-danger">ยืนยันลบประวัติการพิมพ์</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="hidden" name="manage_action" value="delete"><input type="hidden" name="history_id" id="delete_history_id"><input type="hidden" name="csrf_token" value="<?php echo phE($_SESSION['csrf_token']); ?>"><div class="print-history-delete-box"><strong>รายการนี้จะถูกลบออกจากประวัติถาวร</strong><div class="small mt-1">กรุณาตรวจสอบข้อมูลก่อนยืนยัน</div></div><div class="print-history-delete-summary"><div class="print-history-delete-row"><div class="print-history-delete-label">สาขา</div><div class="print-history-delete-value" id="delete_branch_name">-</div></div><div class="print-history-delete-row"><div class="print-history-delete-label">วันที่พิมพ์</div><div class="print-history-delete-value" id="delete_printed_at">-</div></div></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-danger px-4">ยืนยันลบ</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var directoryRows = [];
    var pendingBranchCode = '';
    var branchSelect = document.getElementById('edit_directory_branch_select');
    var mainCodeInput = document.getElementById('edit_main_branch_code');
    var branchCodeInput = document.getElementById('edit_branch_code');
    var branchNameInput = document.getElementById('edit_branch_name');
    var addressInput = document.getElementById('edit_shipping_address');
    var lookupStatus = document.getElementById('edit_branch_lookup_status');
    var printSourceSelect = document.getElementById('edit_print_source');

    function setLookupStatus(message, isError) {
        if (!lookupStatus) return;
        lookupStatus.textContent = message;
        lookupStatus.classList.toggle('text-danger', Boolean(isError));
        lookupStatus.classList.toggle('text-muted', !isError);
    }

    function applyDirectoryBranch(row) {
        if (!row) {
            branchCodeInput.value = '';
            branchNameInput.value = '';
            addressInput.value = '';
            return;
        }
        mainCodeInput.value = row.main_branch_code || mainCodeInput.value;
        branchCodeInput.value = row.branch_code || '';
        branchNameInput.value = row.branch_name || '';
        var source = printSourceSelect ? printSourceSelect.value : 'direct_branch';
        addressInput.value = source === 'main_branch_group'
            ? (row.main_address || '')
            : (row.address || '');
    }

    function loadDirectoryBranches(mainBranchCode, selectedBranchCode) {
        mainBranchCode = String(mainBranchCode || '').trim();
        pendingBranchCode = String(selectedBranchCode || '').trim();
        directoryRows = [];
        branchSelect.disabled = true;
        branchSelect.innerHTML = '<option value="">-- กำลังโหลดข้อมูลจาก branch_directory --</option>';
        applyDirectoryBranch(null);

        if (!mainBranchCode) {
            setLookupStatus('กรุณากรอกรหัสสาขาใหญ่', true);
            branchSelect.innerHTML = '<option value="">-- กรุณาระบุรหัสสาขาใหญ่ --</option>';
            return;
        }

        setLookupStatus('กำลังดึงข้อมูลจาก harddisk_db.branch_directory...', false);
        fetch('print_history.php?ajax=branch_directory&main_branch_code=' + encodeURIComponent(mainBranchCode), {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (!result.success) throw new Error(result.message || 'ไม่สามารถโหลดข้อมูลสาขาได้');
            directoryRows = Array.isArray(result.data) ? result.data : [];
            branchSelect.innerHTML = '<option value="">-- เลือกสาขาปลายทาง --</option>';
            var matchedIndex = -1;
            directoryRows.forEach(function (row, index) {
                var option = document.createElement('option');
                option.value = String(index);
                option.textContent = (row.branch_code || '-') + ' - ' + (row.branch_name || '-') + (row.branch_type ? ' (' + row.branch_type + ')' : '');
                branchSelect.appendChild(option);
                if (String(row.branch_code || '').trim() === pendingBranchCode) matchedIndex = index;
            });
            branchSelect.disabled = directoryRows.length === 0;
            if (matchedIndex >= 0) {
                branchSelect.value = String(matchedIndex);
                applyDirectoryBranch(directoryRows[matchedIndex]);
            }
            setLookupStatus(directoryRows.length ? 'พบข้อมูล ' + directoryRows.length + ' สาขา' : 'ไม่พบสาขาในรหัสสาขาใหญ่นี้', directoryRows.length === 0);
        })
        .catch(function (error) {
            branchSelect.innerHTML = '<option value="">-- โหลดข้อมูลไม่สำเร็จ --</option>';
            setLookupStatus(error.message || 'เกิดข้อผิดพลาดในการโหลดข้อมูลสาขา', true);
        });
    }

    document.getElementById('btnLoadDirectoryBranches').addEventListener('click', function () {
        loadDirectoryBranches(mainCodeInput.value, branchCodeInput.value);
    });
    mainCodeInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadDirectoryBranches(mainCodeInput.value, branchCodeInput.value);
        }
    });
    branchSelect.addEventListener('change', function () {
        var index = Number(branchSelect.value);
        applyDirectoryBranch(Number.isInteger(index) ? directoryRows[index] : null);
    });
    if (printSourceSelect) {
        printSourceSelect.addEventListener('change', function () {
            var index = Number(branchSelect.value);
            applyDirectoryBranch(Number.isInteger(index) ? directoryRows[index] : null);
        });
    }

    function setEditAssetValue(assetName) {
        var assetSelect = document.getElementById('edit_asset_name');
        if (!assetSelect) return;
        var value = String(assetName || '').trim();
        assetSelect.value = value;
        if (value !== '' && assetSelect.value !== value) {
            var legacyOption = document.createElement('option');
            legacyOption.value = value;
            legacyOption.textContent = value + ' (ข้อมูลเดิม)';
            legacyOption.dataset.legacy = '1';
            assetSelect.appendChild(legacyOption);
            assetSelect.value = value;
        }
    }

    document.querySelectorAll('.js-edit-print-history').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('edit_history_id').value = button.dataset.id || '';
            mainCodeInput.value = button.dataset.mainBranchCode || '';
            branchCodeInput.value = button.dataset.branchCode || '';
            branchNameInput.value = button.dataset.branchName || '';
            addressInput.value = button.dataset.shippingAddress || '';
            setEditAssetValue(button.dataset.assetName || '');
            document.getElementById('edit_print_orientation').value = button.dataset.printOrientation === 'landscape' ? 'landscape' : 'portrait';
            document.getElementById('edit_print_source').value = button.dataset.printSource === 'main_branch_group' ? 'main_branch_group' : 'direct_branch';
            loadDirectoryBranches(mainCodeInput.value, branchCodeInput.value);
        });
    });
    document.querySelectorAll('.js-delete-print-history').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('delete_history_id').value = button.dataset.id || '';
            document.getElementById('delete_branch_name').textContent = button.dataset.branchName || '-';
            document.getElementById('delete_printed_at').textContent = button.dataset.printedAt || '-';
        });
    });
});
</script>
<?php endif; ?>
</div>


<!-- BRANCH_LABEL_GLOBAL_MODAL_LAYER_FIX_V1 -->
<style>
html body > .modal {
    position: fixed !important;
    z-index: 2147483000 !important;
}
html body > .modal.show {
    display: block !important;
}
html body > .modal-backdrop {
    position: fixed !important;
    z-index: 2147482990 !important;
}
html body.modal-open {
    overflow: hidden !important;
}
.print-history-manage-modal {
    z-index: 2147483000 !important;
}
</style>
<script>
(function () {
    'use strict';

    function moveModalToBody(modal) {
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }

    function normalizePrintHistoryModals() {
        document.querySelectorAll('#editPrintHistoryModal, #deletePrintHistoryModal').forEach(moveModalToBody);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', normalizePrintHistoryModals);
    } else {
        normalizePrintHistoryModals();
    }

    document.addEventListener('show.bs.modal', function (event) {
        if (event.target && (event.target.id === 'editPrintHistoryModal' || event.target.id === 'deletePrintHistoryModal')) {
            moveModalToBody(event.target);
        }
    }, true);

    document.addEventListener('shown.bs.modal', function (event) {
        if (event.target && (event.target.id === 'editPrintHistoryModal' || event.target.id === 'deletePrintHistoryModal')) {
            moveModalToBody(event.target);
            event.target.style.zIndex = '2147483000';
            document.querySelectorAll('body > .modal-backdrop').forEach(function (backdrop) {
                backdrop.style.zIndex = '2147482990';
            });
        }
    }, true);
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
