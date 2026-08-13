<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (function_exists('require_login')) {
    require_login();
}

if (!function_exists('drumExportE')) {
    function drumExportE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('drumExportNormalizeMainCode')) {
    function drumExportNormalizeMainCode($value): string
    {
        $value = preg_replace('/\D+/', '', trim((string)($value ?? '')));
        if ($value === '') {
            return '';
        }
        return str_pad(substr($value, 0, 3), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('drumExportCurrentUserName')) {
    function drumExportCurrentUserName(): string
    {
        $name = trim((string)($_SESSION['full_name'] ?? ''));
        if ($name === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $name = trim((string)($_SESSION['user']['full_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string)($_SESSION['display_name'] ?? $_SESSION['username'] ?? ''));
        }
        return $name;
    }
}

if (!function_exists('drumExportCurrentUserPosition')) {
    function drumExportCurrentUserPosition(PDO $pdo): string
    {
        $sessionPosition = trim((string)(
            $_SESSION['position']
            ?? $_SESSION['position_name']
            ?? $_SESSION['job_title']
            ?? $_SESSION['employee_position']
            ?? $_SESSION['title']
            ?? ''
        ));

        if ($sessionPosition === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach (['position', 'position_name', 'job_title', 'employee_position', 'title'] as $field) {
                $value = trim((string)($_SESSION['user'][$field] ?? ''));
                if ($value !== '') {
                    $sessionPosition = $value;
                    break;
                }
            }
        }

        $employeeCode = trim((string)(
            $_SESSION['employee_code']
            ?? $_SESSION['emp_code']
            ?? $_SESSION['employee_id']
            ?? ''
        ));

        if ($employeeCode === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $employeeCode = trim((string)(
                $_SESSION['user']['employee_code']
                ?? $_SESSION['user']['emp_code']
                ?? $_SESSION['user']['employee_id']
                ?? ''
            ));
        }

        if ($employeeCode === '') {
            return $sessionPosition;
        }

        try {
            $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'");
            $columnsStmt->execute();

            $columns = [];
            foreach ($columnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
                $columns[strtolower((string)$column)] = (string)$column;
            }

            if (!isset($columns['employee_code'])) {
                return $sessionPosition;
            }

            $positionColumn = '';
            foreach (['position', 'position_name', 'job_title', 'employee_position', 'title'] as $candidate) {
                if (isset($columns[$candidate])) {
                    $positionColumn = $columns[$candidate];
                    break;
                }
            }

            if ($positionColumn === '') {
                return $sessionPosition;
            }

            $employeeCodeColumn = '`' . str_replace('`', '``', $columns['employee_code']) . '`';
            $positionColumnSql = '`' . str_replace('`', '``', $positionColumn) . '`';

            $stmt = $pdo->prepare("SELECT {$positionColumnSql} AS resolved_position
                FROM `users`
                WHERE {$employeeCodeColumn} = :employee_code
                LIMIT 1");
            $stmt->execute([':employee_code' => $employeeCode]);

            $position = trim((string)($stmt->fetchColumn() ?: ''));
            return $position !== '' ? $position : $sessionPosition;
        } catch (Throwable $e) {
            error_log('[drum_withdrawals/export_pdf] Cannot resolve current user position: ' . $e->getMessage());
            return $sessionPosition;
        }
    }
}

if (!function_exists('drumExportFetchRows')) {
    function drumExportFetchRows(PDO $pdo, string $keyword, string $drumFilter, string $dateFrom, string $dateTo, string $deliveryStatus = ''): array
    {
        $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
        $tableCheck->execute();
        if ((int)$tableCheck->fetchColumn() === 0) {
            throw new RuntimeException('ไม่พบตาราง drum_withdrawals ในฐานข้อมูลที่ระบบกำลังเชื่อมต่อ');
        }

        $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
        $columnsStmt->execute();
        $drumColumns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));

        $requiredColumns = ['request_no', 'main_branch_code', 'branch_name', 'drum_code', 'recorded_by', 'created_at'];
        $missingColumns = array_values(array_diff($requiredColumns, $drumColumns));
        if ($missingColumns) {
            throw new RuntimeException('ตาราง drum_withdrawals ขาดคอลัมน์: ' . implode(', ', $missingColumns));
        }

        $hasBranchCodeColumn = in_array('branch_code', $drumColumns, true);
        $hasQuantityColumn = in_array('quantity', $drumColumns, true);
        if (!$hasQuantityColumn) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ quantity กรุณารันไฟล์ database/add_drum_quantity.sql');
        $hasDeletedAtColumn = in_array('deleted_at', $drumColumns, true);
        $hasDeliveryStatusColumn = in_array('delivery_status', $drumColumns, true);

        $where = [];
        $params = [];
        if ($hasDeletedAtColumn) {
            $where[] = 'dw.deleted_at IS NULL';
        }
        if ($deliveryStatus !== '') {
            if (!$hasDeliveryStatusColumn) throw new RuntimeException('กรุณารันไฟล์ database/add_drum_delivery_status.sql ก่อน Export');
            $where[] = 'dw.delivery_status = :delivery_status';
            $params[':delivery_status'] = $deliveryStatus;
        }

        if ($keyword !== '') {
            $keywordConditions = [
                'dw.request_no LIKE :keyword_request_no',
                'dw.main_branch_code LIKE :keyword_main_branch_code',
                'dw.branch_name LIKE :keyword_branch_name',
                'dw.recorded_by LIKE :keyword_recorded_by',
                'dw.drum_code LIKE :keyword_drum_code',
            ];
            $keywordLike = '%' . $keyword . '%';
            $params[':keyword_request_no'] = $keywordLike;
            $params[':keyword_main_branch_code'] = $keywordLike;
            $params[':keyword_branch_name'] = $keywordLike;
            $params[':keyword_recorded_by'] = $keywordLike;
            $params[':keyword_drum_code'] = $keywordLike;

            if ($hasBranchCodeColumn) {
                $keywordConditions[] = 'dw.branch_code LIKE :keyword_branch_code';
                $params[':keyword_branch_code'] = $keywordLike;
            } else {
                $keywordConditions[] = "EXISTS (
                    SELECT 1
                    FROM harddisk_db.branch_directory bd_kw
                    WHERE LPAD(TRIM(CAST(bd_kw.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
                      AND (TRIM(bd_kw.branch_name) = TRIM(dw.branch_name) OR TRIM(COALESCE(bd_kw.branch_name_2, '')) = TRIM(dw.branch_name))
                      AND TRIM(CAST(bd_kw.branch_code AS CHAR)) LIKE :keyword_branch_code_lookup
                )";
                $params[':keyword_branch_code_lookup'] = $keywordLike;
            }
            $where[] = '(' . implode(' OR ', $keywordConditions) . ')';
        }

        if ($drumFilter !== '') {
            $where[] = 'dw.drum_code = :drum_code';
            $params[':drum_code'] = $drumFilter;
        }
        if ($dateFrom !== '') {
            $where[] = 'DATE(dw.created_at) >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'DATE(dw.created_at) <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        if ($hasBranchCodeColumn) {
            $sql = "SELECT dw.request_no, dw.main_branch_code, dw.branch_code, dw.branch_name,
                           GROUP_CONCAT(dw.drum_code ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                           SUM(CASE WHEN dw.drum_code='Drum-DR-3455' THEN COALESCE(dw.quantity,1) ELSE 0 END) AS drum_3455_qty,
                           SUM(CASE WHEN dw.drum_code='Drum-DR-3608' THEN COALESCE(dw.quantity,1) ELSE 0 END) AS drum_3608_qty,
                           dw.recorded_by, dw.created_at
                    FROM harddisk_db.drum_withdrawals dw
                    {$whereSql}
                    GROUP BY dw.request_no, dw.main_branch_code, dw.branch_code, dw.branch_name, dw.recorded_by, dw.created_at
                    ORDER BY dw.created_at ASC
                    LIMIT 500";
        } else {
            $sql = "SELECT dw.request_no, dw.main_branch_code,
                           COALESCE((
                               SELECT bd.branch_code
                               FROM harddisk_db.branch_directory bd
                               WHERE LPAD(TRIM(CAST(bd.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
                                 AND (TRIM(bd.branch_name) = TRIM(dw.branch_name) OR TRIM(COALESCE(bd.branch_name_2, '')) = TRIM(dw.branch_name))
                               ORDER BY bd.branch_code ASC
                               LIMIT 1
                           ), '') AS branch_code,
                           dw.branch_name,
                           GROUP_CONCAT(dw.drum_code ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                           SUM(CASE WHEN dw.drum_code='Drum-DR-3455' THEN COALESCE(dw.quantity,1) ELSE 0 END) AS drum_3455_qty,
                           SUM(CASE WHEN dw.drum_code='Drum-DR-3608' THEN COALESCE(dw.quantity,1) ELSE 0 END) AS drum_3608_qty,
                           dw.recorded_by, dw.created_at
                    FROM harddisk_db.drum_withdrawals dw
                    {$whereSql}
                    GROUP BY dw.request_no, dw.main_branch_code, dw.branch_name, dw.recorded_by, dw.created_at
                    ORDER BY dw.created_at ASC
                    LIMIT 500";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('drumExportResolveMainBranchNames')) {
    function drumExportResolveMainBranchNames(PDO $pdo, array $rows): array
    {
        $mainCodes = [];
        foreach ($rows as $row) {
            $mainCode = drumExportNormalizeMainCode($row['main_branch_code'] ?? '');
            if ($mainCode !== '') {
                $mainCodes[$mainCode] = true;
            }
        }

        $mainCodes = array_keys($mainCodes);
        if (!$mainCodes) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($mainCodes as $index => $mainCode) {
            $placeholder = ':main_code_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $mainCode;
        }

        $sql = "SELECT main_branch_code, branch_code, branch_name, branch_name_2, branch_type
                FROM harddisk_db.branch_directory
                WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') IN (" . implode(', ', $placeholders) . ")
                ORDER BY main_branch_code ASC, branch_code ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $directoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        $scores = [];
        foreach ($directoryRows as $directoryRow) {
            $mainCode = drumExportNormalizeMainCode($directoryRow['main_branch_code'] ?? '');
            if ($mainCode === '') {
                continue;
            }

            $branchType = trim((string)($directoryRow['branch_type'] ?? ''));
            if ($branchType !== 'สาขาใหญ่') {
                continue;
            }

            $branchName = trim((string)($directoryRow['branch_name'] ?? ''));
            $branchName2 = trim((string)($directoryRow['branch_name_2'] ?? ''));
            $candidateName = $branchName !== '' ? $branchName : $branchName2;
            if ($candidateName === '') {
                continue;
            }

            $branchCode = trim((string)($directoryRow['branch_code'] ?? ''));
            $score = 1;
            if ($branchCode === $mainCode || ltrim($branchCode, '0') === ltrim($mainCode, '0')) {
                $score += 100;
            }
            if (preg_match('/(?:สาขา\s*ใหญ่|สำนักงานใหญ่)/u', $candidateName)) {
                $score += 80;
            }
            if ($branchName2 === '') {
                $score += 10;
            }
            if ($branchCode !== '' && preg_match('/000$/', $branchCode)) {
                $score += 5;
            }

            if (!isset($scores[$mainCode]) || $score > $scores[$mainCode]) {
                $scores[$mainCode] = $score;
                $result[$mainCode] = $candidateName;
            }
        }

        return $result;
    }
}


if (!function_exists('drumExportResolveSelectedBranchTypes')) {
    function drumExportResolveSelectedBranchTypes(PDO $pdo, array $rows): array
    {
        $mainCodes = [];
        foreach ($rows as $row) {
            $mainCode = drumExportNormalizeMainCode($row['main_branch_code'] ?? '');
            if ($mainCode !== '') {
                $mainCodes[$mainCode] = true;
            }
        }

        $mainCodes = array_keys($mainCodes);
        if (!$mainCodes) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($mainCodes as $index => $mainCode) {
            $placeholder = ':selected_main_code_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $mainCode;
        }

        $sql = "SELECT main_branch_code, branch_code, branch_name, branch_name_2, branch_type
                FROM harddisk_db.branch_directory
                WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') IN (" . implode(', ', $placeholders) . ")
                ORDER BY main_branch_code ASC, branch_code ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $directoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $requestNo = trim((string)($row['request_no'] ?? ''));
            if ($requestNo === '') {
                continue;
            }

            $mainCode = drumExportNormalizeMainCode($row['main_branch_code'] ?? '');
            $selectedBranchCode = trim((string)($row['branch_code'] ?? ''));
            $selectedBranchName = trim((string)($row['branch_name'] ?? ''));
            $matchedType = '';

            foreach ($directoryRows as $directoryRow) {
                if (drumExportNormalizeMainCode($directoryRow['main_branch_code'] ?? '') !== $mainCode) {
                    continue;
                }

                $directoryBranchCode = trim((string)($directoryRow['branch_code'] ?? ''));
                $directoryBranchName = trim((string)($directoryRow['branch_name'] ?? ''));
                $directoryBranchName2 = trim((string)($directoryRow['branch_name_2'] ?? ''));

                $codeMatched = $selectedBranchCode !== '' && $directoryBranchCode !== ''
                    && strcasecmp($selectedBranchCode, $directoryBranchCode) === 0;
                $nameMatched = $selectedBranchName !== ''
                    && (strcasecmp($selectedBranchName, $directoryBranchName) === 0
                        || strcasecmp($selectedBranchName, $directoryBranchName2) === 0);

                if ($codeMatched || $nameMatched) {
                    $matchedType = trim((string)($directoryRow['branch_type'] ?? ''));
                    break;
                }
            }

            $result[$requestNo] = $matchedType;
        }

        return $result;
    }
}

$keyword = trim((string)($_GET['keyword'] ?? ''));
$drumFilter = trim((string)($_GET['drum_code'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$deliveryStatus = trim((string)($_GET['delivery_status'] ?? ''));
if (!in_array($deliveryStatus, ['pending', 'shipped'], true)) $deliveryStatus = '';
$allowedDrumFilters = ['Drum-DR-3455', 'Drum-DR-3608'];
if (!in_array($drumFilter, $allowedDrumFilters, true)) {
    $drumFilter = '';
}

$exportRows = [];
$exportError = '';

try {
    $recent = drumExportFetchRows($pdo, $keyword, $drumFilter, $dateFrom, $dateTo, $deliveryStatus);
    $mainBranchNames = drumExportResolveMainBranchNames($pdo, $recent);
    $selectedBranchTypes = drumExportResolveSelectedBranchTypes($pdo, $recent);

    foreach ($recent as $row) {
        $mainCode = drumExportNormalizeMainCode($row['main_branch_code'] ?? '');
        $selectedBranchName = trim((string)($row['branch_name'] ?? ''));
        $mainBranchName = trim((string)($mainBranchNames[$mainCode] ?? ''));

        if ($mainBranchName === '') {
            $mainBranchName = $selectedBranchName;
        }

        $requestNo = trim((string)($row['request_no'] ?? ''));
        $selectedBranchType = trim((string)($selectedBranchTypes[$requestNo] ?? ''));

        $subBranchName = '';
        if ($selectedBranchType === 'สาขาใหญ่') {
            // หากผู้ใช้เลือกสาขาใหญ่ ให้แสดงชื่อสาขาใหญ่ในคอลัมน์สาขาย่อย/ศูนย์บริการด้วย
            $subBranchName = $selectedBranchName !== '' ? $selectedBranchName : $mainBranchName;
        } elseif ($selectedBranchName !== '' && $mainBranchName !== '' && strcasecmp($selectedBranchName, $mainBranchName) !== 0) {
            // สาขาย่อยและศูนย์บริการยังคงแสดงชื่อสาขาที่ผู้ใช้เลือกตาม Logic เดิม
            $subBranchName = $selectedBranchName;
        }

        $drum3455Qty = (int)($row['drum_3455_qty'] ?? 0);
        $drum3608Qty = (int)($row['drum_3608_qty'] ?? 0);

        $exportRows[] = [
            'branchCode' => $mainCode !== '' ? $mainCode : trim((string)($row['main_branch_code'] ?? '')),
            'mainBranch' => $mainBranchName,
            'subBranch' => $subBranchName,
            'costCenter' => trim((string)($row['branch_code'] ?? '')),
            'drum3455' => $drum3455Qty,
            'drum3608' => $drum3608Qty,
            'notifiedBy' => trim((string)($row['recorded_by'] ?? '')),
        ];
    }
} catch (Throwable $e) {
    error_log('[drum_withdrawals/export_pdf] ' . $e->getMessage());
    $exportError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'ไม่สามารถสร้างข้อมูลสำหรับ Export PDF ได้';
}

$requesterName = drumExportCurrentUserName();
$requesterPosition = drumExportCurrentUserPosition($pdo);
$bangkokTimeZone = new DateTimeZone('Asia/Bangkok');
$printedAt = new DateTimeImmutable('now', $bangkokTimeZone);
$exportConfig = [
    'rows' => $exportRows,
    'requester' => $requesterName,
    'department' => $requesterPosition,
    'date' => $printedAt->format('d/m/Y'),
    'printedAt' => $printedAt->format('d/m/Y H:i:s') . ' น.',
    'filename' => 'drum_withdrawals_' . $printedAt->format('Y-m-d_His') . '.pdf',
    // 0 = ให้ JavaScript คำนวณจำนวนแถวที่พอดีกับพื้นที่ A4 โดยอัตโนมัติ
    'rowsPerPage' => 0,
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export PDF - ใบเบิกทรัพย์สินสำนักงานใหญ่</title>
    <style>
        :root {
            color-scheme: light;
            --pdf-blue: #0f4c81;
            --pdf-red: #b91c1c;
            --pdf-border: #dbe5ee;
        }
        @font-face {
            font-family: "Sarabun";
            src: url("fonts/Sarabun-Regular.ttf") format("truetype");
            font-style: normal;
            font-weight: 400;
            font-display: block;
        }
        @font-face {
            font-family: "Sarabun";
            src: url("fonts/Sarabun-Medium.ttf") format("truetype");
            font-style: normal;
            font-weight: 500;
            font-display: block;
        }
        @font-face {
            font-family: "Sarabun";
            src: url("fonts/Sarabun-SemiBold.ttf") format("truetype");
            font-style: normal;
            font-weight: 600;
            font-display: block;
        }
        @font-face {
            font-family: "Sarabun";
            src: url("fonts/Sarabun-Bold.ttf") format("truetype");
            font-style: normal;
            font-weight: 700;
            font-display: block;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Sarabun", Tahoma, Arial, sans-serif;
            color: #0f172a;
            background: #eef3f8;
        }
        .export-shell {
            width: min(920px, calc(100% - 28px));
            margin: 28px auto;
        }
        .export-card {
            background: #fff;
            border: 1px solid var(--pdf-border);
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .12);
            overflow: hidden;
        }
        .export-header {
            padding: 18px 20px;
            color: #fff;
            background: linear-gradient(135deg, #0f4c81, #1976d2);
        }
        .export-header h1 {
            margin: 0 0 4px;
            font-size: 1.05rem;
            font-weight: 800;
        }
        .export-header p {
            margin: 0;
            opacity: .82;
            font-size: .82rem;
        }
        .export-body { padding: 18px 20px 20px; }
        .export-status {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            background: #eff6ff;
            color: #1e3a8a;
            font-size: .9rem;
            font-weight: 700;
        }
        .export-status.error {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }
        .spinner {
            width: 20px;
            height: 20px;
            flex: 0 0 20px;
            border: 3px solid rgba(30, 64, 175, .2);
            border-top-color: #1d4ed8;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }
        .export-status.done .spinner { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .export-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 14px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 8px 14px;
            border: 1px solid transparent;
            border-radius: 10px;
            font: inherit;
            font-size: .86rem;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-pdf { color: #fff; background: var(--pdf-red); }
        .btn-pdf:disabled { opacity: .55; cursor: not-allowed; }
        .btn-back { color: #334155; background: #fff; border-color: #cbd5e1; }
        .preview-wrap {
            margin-top: 18px;
            padding: 12px;
            border: 1px solid #dbe5ee;
            border-radius: 12px;
            background: #f8fafc;
            overflow: auto;
        }
        .preview-label {
            margin-bottom: 8px;
            color: #64748b;
            font-size: .78rem;
            font-weight: 700;
        }
        #pdfPreviewCanvas {
            display: block;
            width: 100%;
            height: auto;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .15);
        }
        .export-error {
            padding: 14px;
            border: 1px solid #fecaca;
            border-radius: 12px;
            background: #fef2f2;
            color: #991b1b;
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="export-shell">
    <div class="export-card">
        <div class="export-header">
            <h1>Export ใบเบิกทรัพย์สินสำนักงานใหญ่เป็น PDF</h1>
            <p>รูปแบบเอกสารอ้างอิงตามแบบฟอร์มใบเบิก Drum</p>
        </div>
        <div class="export-body">
            <?php if ($exportError !== ''): ?>
                <div class="export-error"><?php echo drumExportE($exportError); ?></div>
                <div class="export-actions">
                    <a class="btn btn-back" href="index.php">กลับหน้ารายการ</a>
                </div>
            <?php else: ?>
                <div class="export-status" id="exportStatus">
                    <span class="spinner" aria-hidden="true"></span>
                    <span id="exportStatusText">กำลังจัดหน้าและสร้างตัวอย่าง PDF...</span>
                </div>
                <div class="export-actions">
                    <button type="button" class="btn btn-pdf" id="downloadPdfBtn" disabled>ดาวน์โหลด PDF</button>
                    <a class="btn btn-back" href="index.php">กลับหน้ารายการ</a>
                </div>
                <div class="preview-wrap" id="previewWrap" hidden>
                    <div class="preview-label">ตัวอย่างหน้าแรก</div>
                    <canvas id="pdfPreviewCanvas" width="1240" height="1754"></canvas>
                </div>
                <script>
                    window.DRUM_EXPORT_CONFIG = <?php echo json_encode($exportConfig, $jsonFlags); ?>;
                </script>
                <script src="export_pdf.js?v=<?php echo (int)@filemtime(__DIR__ . '/export_pdf.js'); ?>"></script>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
