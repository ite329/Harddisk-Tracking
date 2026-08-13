<?php

require_once __DIR__ . '/../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'ค้นหาและพิมพ์ที่อยู่สาขา';

require_once __DIR__ . '/../../config/database.php';

if (!function_exists('blE')) {
    function blE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('blClean')) {
    function blClean($value): string
    {
        return trim((string)($value ?? ''));
    }
}

if (!function_exists('blQuoteColumn')) {
    function blQuoteColumn(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}

if (!function_exists('blTableExists')) {
    function blTableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
            $stmt->execute([':table_name' => $tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('blGetColumns')) {
    function blGetColumns(PDO $pdo, string $tableName): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION ASC');
        $stmt->execute([':table_name' => $tableName]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $column = (string)$column;
            if ($column !== '') {
                $columns[strtolower($column)] = $column;
            }
        }
        return $columns;
    }
}

if (!function_exists('blResolveColumn')) {
    function blResolveColumn(array $availableColumns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower((string)$candidate);
            if (isset($availableColumns[$key])) {
                return $availableColumns[$key];
            }
        }
        return null;
    }
}

if (!function_exists('blColumnMap')) {
    function blColumnMap(array $columns): array
    {
        return [
            'id' => blResolveColumn($columns, ['id', 'branch_id']),
            'main_branch_code' => blResolveColumn($columns, ['main_branch_code', 'main_branch', 'main_code', 'branch_main_code']),
            'branch_code' => blResolveColumn($columns, ['branch_code', 'code', 'cost_center', 'costcenter']),
            'branch_name' => blResolveColumn($columns, ['branch_name', 'name', 'branch_name_th']),
            'branch_name_2' => blResolveColumn($columns, ['branch_name_2', 'branch_name2', 'sub_branch_name', 'name_2']),
            'full_address' => blResolveColumn($columns, ['full_address', 'address_full', 'fulladdr']),
            'address_line' => blResolveColumn($columns, ['address_line', 'address', 'address1', 'addr', 'full_address']),
            'subdistrict' => blResolveColumn($columns, ['subdistrict', 'tambon', 'sub_district']),
            'district' => blResolveColumn($columns, ['district', 'amphur', 'amphoe']),
            'province' => blResolveColumn($columns, ['province', 'changwat']),
            'postal_code' => blResolveColumn($columns, ['postal_code', 'postcode', 'zip_code', 'zipcode']),
            'phone' => blResolveColumn($columns, ['phone', 'tel', 'telephone', 'mobile']),
            'landmark' => blResolveColumn($columns, ['landmark', 'nearby', 'remark']),
            'area_code' => blResolveColumn($columns, ['area_code', 'region_id']),
            'branch_type' => blResolveColumn($columns, ['branch_type', 'type', 'branch_category', 'office_type']),
            'is_active' => blResolveColumn($columns, ['is_active', 'active', 'status']),
        ];
    }
}

if (!function_exists('blRaw')) {
    function blRaw(array $row, array $map, string $field): string
    {
        $column = $map[$field] ?? null;
        if ($column !== null && array_key_exists($column, $row)) {
            return blClean($row[$column] ?? '');
        }
        return '';
    }
}

if (!function_exists('blDisplay')) {
    function blDisplay(array $row, array $map, string $field): string
    {
        $value = blRaw($row, $map, $field);
        return $value !== '' ? $value : '-';
    }
}

if (!function_exists('blBranchAddress')) {
    function blBranchAddress(array $row, array $map): string
    {
        $full = blRaw($row, $map, 'full_address');
        if ($full !== '') {
            return $full;
        }

        $parts = [];
        foreach (['address_line', 'subdistrict', 'district', 'province', 'postal_code'] as $field) {
            $value = blRaw($row, $map, $field);
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        return !empty($parts) ? implode(' ', $parts) : '-';
    }
}

$branchLabelView = blClean($_GET['view'] ?? 'group');
if (!in_array($branchLabelView, ['group', 'direct'], true)) {
    $branchLabelView = 'group';
}

$query = blClean($_GET['q'] ?? '');
$searchField = blClean($_GET['search_field'] ?? 'all');
$allowedSearchFields = ['all', 'main_branch_code', 'branch_code', 'branch_name', 'province', 'postal_code', 'phone'];
if (!in_array($searchField, $allowedSearchFields, true)) {
    $searchField = 'all';
}
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$totalRows = 0;
$totalPages = 1;
$offset = 0;
$pageError = '';
$branchRows = [];
$branchColumns = [];
$branchMap = [];

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('ไม่พบการเชื่อมต่อฐานข้อมูล harddisk_db');
    }

    if (!blTableExists($pdo, 'branch_directory')) {
        throw new RuntimeException('ไม่พบตาราง branch_directory ในฐานข้อมูล harddisk_db');
    }

    $branchColumns = blGetColumns($pdo, 'branch_directory');
    $branchMap = blColumnMap($branchColumns);

    $where = [];
    $params = [];

    if (!empty($branchMap['is_active'])) {
        // รองรับทั้งค่า 1 / active / เปิดใช้งาน ถ้าคอลัมน์เป็น status
        $activeCol = blQuoteColumn($branchMap['is_active']);
        $where[] = '(' . $activeCol . ' IS NULL OR ' . $activeCol . " = '' OR " . $activeCol . " = '1' OR LOWER(CAST(" . $activeCol . " AS CHAR)) IN ('active','yes','y','true','ใช้งาน','เปิดใช้งาน'))";
    }

    if ($query !== '') {
        if ($searchField === 'all') {
            $searchColumns = array_filter(array_unique([
                $branchMap['main_branch_code'] ?? null,
                $branchMap['branch_code'] ?? null,
                $branchMap['branch_name'] ?? null,
                $branchMap['branch_name_2'] ?? null,
                $branchMap['full_address'] ?? null,
                $branchMap['address_line'] ?? null,
                $branchMap['subdistrict'] ?? null,
                $branchMap['district'] ?? null,
                $branchMap['province'] ?? null,
                $branchMap['postal_code'] ?? null,
                $branchMap['phone'] ?? null,
            ]));
        } else {
            $searchColumns = [];
            if (!empty($branchMap[$searchField])) {
                $searchColumns[] = $branchMap[$searchField];
            }

            // ชื่อสาขาค้นหาทั้งชื่อหลักและชื่อรอง
            if ($searchField === 'branch_name' && !empty($branchMap['branch_name_2'])) {
                $searchColumns[] = $branchMap['branch_name_2'];
            }

            $searchColumns = array_filter(array_unique($searchColumns));
        }

        $searchParts = [];
        foreach (array_values($searchColumns) as $index => $column) {
            $param = ':q_' . $index;
            $searchParts[] = 'CAST(' . blQuoteColumn($column) . ' AS CHAR) LIKE ' . $param;
            $params[$param] = '%' . $query . '%';
        }
        if (!empty($searchParts)) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `branch_directory`' . $whereSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;

    $orderParts = [];
    foreach (['branch_code', 'main_branch_code', 'branch_name'] as $field) {
        if (!empty($branchMap[$field])) {
            $orderParts[] = blQuoteColumn($branchMap[$field]) . ' ASC';
        }
    }
    $orderSql = !empty($orderParts) ? ' ORDER BY ' . implode(', ', $orderParts) : '';

    $sql = 'SELECT * FROM `branch_directory`' . $whereSql . $orderSql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $branchRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pageError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
}


$groupMainCode = blClean($_GET['group_main_code'] ?? '');
$groupBranchRows = [];
$groupMainBranch = null;
$groupLookupError = '';
$groupLookupWarning = '';

if ($groupMainCode !== '') {
    if (!preg_match('/^\d{3}$/', $groupMainCode)) {
        $groupLookupError = 'กรุณากรอกรหัสสาขาใหญ่เป็นตัวเลขให้ครบ 3 หลัก';
    } else {
        try {
            $schemaName = 'harddisk_db';
            $tableName = 'branch_directory';
            $schemaTableCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name');
            $schemaTableCheck->execute([':schema_name' => $schemaName, ':table_name' => $tableName]);
            if ((int)$schemaTableCheck->fetchColumn() === 0) {
                throw new RuntimeException('ไม่พบตาราง harddisk_db.branch_directory');
            }

            $schemaColumnsStmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION ASC');
            $schemaColumnsStmt->execute([':schema_name' => $schemaName, ':table_name' => $tableName]);
            $groupColumns = [];
            foreach ($schemaColumnsStmt->fetchAll(PDO::FETCH_COLUMN) as $columnName) {
                $groupColumns[strtolower((string)$columnName)] = (string)$columnName;
            }
            $groupMap = blColumnMap($groupColumns);
            if (empty($groupMap['main_branch_code'])) {
                throw new RuntimeException('ตาราง branch_directory ไม่มีคอลัมน์ main_branch_code');
            }
            if (empty($groupMap['branch_type'])) {
                throw new RuntimeException('ตาราง branch_directory ไม่มีคอลัมน์ branch_type สำหรับระบุสาขาใหญ่');
            }

            $mainCodeColumn = blQuoteColumn($groupMap['main_branch_code']);
            $groupWhere = "LPAD(TRIM(CAST({$mainCodeColumn} AS CHAR)), 3, '0') = LPAD(TRIM(CAST(:group_main_code AS CHAR)), 3, '0')";
            if (!empty($groupMap['is_active'])) {
                $activeColumn = blQuoteColumn($groupMap['is_active']);
                $groupWhere .= " AND ({$activeColumn} IS NULL OR {$activeColumn} = '' OR {$activeColumn} = '1' OR LOWER(CAST({$activeColumn} AS CHAR)) IN ('active','yes','y','true','ใช้งาน','เปิดใช้งาน'))";
            }

            $groupOrder = [];
            if (!empty($groupMap['branch_type'])) {
                $typeColumn = blQuoteColumn($groupMap['branch_type']);
                $groupOrder[] = "CASE WHEN LOWER(TRIM(CAST({$typeColumn} AS CHAR))) IN ('สาขาใหญ่','main','main branch','head branch','large branch') OR LOWER(TRIM(CAST({$typeColumn} AS CHAR))) LIKE '%ใหญ่%' THEN 0 ELSE 1 END ASC";
            }
            if (!empty($groupMap['branch_code'])) $groupOrder[] = blQuoteColumn($groupMap['branch_code']) . ' ASC';
            if (!empty($groupMap['branch_name'])) $groupOrder[] = blQuoteColumn($groupMap['branch_name']) . ' ASC';
            $groupOrderSql = $groupOrder ? ' ORDER BY ' . implode(', ', $groupOrder) : '';

            $groupStmt = $pdo->prepare('SELECT * FROM `harddisk_db`.`branch_directory` WHERE ' . $groupWhere . $groupOrderSql);
            $groupStmt->execute([':group_main_code' => $groupMainCode]);
            $groupBranchRows = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($groupBranchRows as $groupRow) {
                $branchTypeValue = mb_strtolower(blRaw($groupRow, $groupMap, 'branch_type'), 'UTF-8');
                if ($branchTypeValue === 'สาขาใหญ่' || in_array($branchTypeValue, ['main', 'main branch', 'head branch', 'large branch'], true) || mb_strpos($branchTypeValue, 'ใหญ่') !== false) {
                    $groupMainBranch = $groupRow;
                    break;
                }
            }

            if (empty($groupBranchRows)) {
                $groupLookupError = 'ไม่พบข้อมูลสาขาในสังกัดรหัส ' . $groupMainCode;
            } elseif (!$groupMainBranch) {
                $groupLookupError = 'พบสาขาในสังกัด แต่ไม่พบรายการที่ branch_type ระบุว่าเป็นสาขาใหญ่ จึงยังไม่สามารถกำหนดที่อยู่จัดส่งได้';
            }
        } catch (Throwable $e) {
            $groupLookupError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}

$buildPageUrl = static function (int $page) use ($query, $searchField, $branchLabelView): string {
    $params = ['page' => $page, 'view' => $branchLabelView];
    if ($query !== '') {
        $params['q'] = $query;
        $params['search_field'] = $searchField;
    }
    return 'index.php?' . http_build_query($params);
};

$branchLabelBaseUrl = defined('BASE_URL') ? BASE_URL : '/harddisk_delivery_web';
$pageTitle = $branchLabelView === 'group' ? 'พิมพ์สาขาในสังกัด' : 'ค้นหาและพิมพ์ที่อยู่สาขา';

require_once __DIR__ . '/../../includes/header.php';

require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('branch_label.manage');
} else {
    require_permission('branch_label.view');
}

?>

<style>
    .branch-label-hero {
        background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 68%, #1d4ed8 100%);
        border-radius: 22px;
        padding: 22px;
        color: #fff;
        margin-bottom: 16px;
        box-shadow: 0 14px 34px rgba(37, 99, 235, .22);
    }
    .branch-label-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
    }
    .branch-label-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 900;
        color: #0f172a;
    }
    .branch-label-section-title::before {
        content: '';
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: #2563eb;
        box-shadow: 0 0 0 5px rgba(37, 99, 235, .12);
    }
    .branch-label-search .form-control,
    .branch-label-search .form-select {
        min-height: 44px;
        border-radius: 14px;
    }
    .branch-label-table {
        table-layout: fixed;
        width: 100%;
        min-width: 0;
    }
    .branch-label-table th,
    .branch-label-table td {
        font-size: .74rem;
        vertical-align: middle;
        line-height: 1.28;
        padding: .4rem .42rem;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .branch-label-table th {
        color: #0f172a;
        background: #f8fafc;
        font-weight: 900;
    }
    .branch-label-table th:nth-child(1), .branch-label-table td:nth-child(1) { width: 4%; text-align: center; }
    .branch-label-table th:nth-child(2), .branch-label-table td:nth-child(2) { width: 10%; }
    .branch-label-table th:nth-child(3), .branch-label-table td:nth-child(3) { width: 11%; }
    .branch-label-table th:nth-child(4), .branch-label-table td:nth-child(4) { width: 18%; }
    .branch-label-table th:nth-child(5), .branch-label-table td:nth-child(5) { width: 31%; }
    .branch-label-table th:nth-child(6), .branch-label-table td:nth-child(6) { width: 10%; }
    .branch-label-table th:nth-child(7), .branch-label-table td:nth-child(7) { width: 16%; }
    .parcel-label {
        position: relative;
        border: 2px solid #0f172a;
        border-radius: 10px;
        padding: 16px 20px;
        background: #fff;
        color: #0f172a;
        width: 100%;
        max-width: 760px;
        min-height: 365px;
        margin: 0 auto;
        font-family: Tahoma, Arial, sans-serif;
    }
    .parcel-label .label-title {
        font-size: 1rem;
        font-weight: 900;
        border-bottom: 1px solid #0f172a;
        padding-bottom: 8px;
        margin-bottom: 10px;
    }
    .parcel-label .label-row {
        margin-bottom: 8px;
        font-size: .92rem;
        line-height: 1.45;
    }
    .parcel-label .label-branch-name {
        font-size: 1.18rem;
        font-weight: 900;
    }
    .parcel-label .label-code {
        display: inline-block;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 4px 8px;
        font-weight: 900;
        margin-right: 6px;
        background: #f8fafc;
    }
    .parcel-label .label-asset-block {
        position: absolute;
        top: 38px;
        right: 14px;
        width: 206px;
        min-height: 124px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        gap: 6px;
        border: 0;
        padding: 0;
        margin: 0;
        text-align: center;
        background: #fff;
    }
    .parcel-label .label-asset-block > div {
        width: 100%;
        font-size: .78rem;
        line-height: 1.2;
        font-weight: 800;
    }
    .parcel-label .selected-asset-image {
        width: 198px;
        height: 112px;
        object-fit: contain;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 4px;
        background: #fff;
    }
    .parcel-label .label-shipping-item {
        position: absolute;
        left: 20px;
        bottom: 36px;
        width: 280px;
        min-height: 30px;
        margin: 0;
        font-size: .9rem;
        line-height: 1.35;
        font-weight: 800;
    }
    .parcel-label .label-courier-block {
        position: absolute;
        right: 18px;
        bottom: 22px;
        width: 170px;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff;
    }
    .parcel-label .label-courier-image {
        max-width: 160px;
        max-height: 52px;
        object-fit: contain;
    }
    .parcel-label .label-fragile-block {
        position: absolute;
        left: 300px;
        bottom: 18px;
        width: 160px;
        height: 68px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #fff;
    }
    .parcel-label .label-fragile-image {
        max-width: 152px;
        max-height: 62px;
        object-fit: contain;
    }
    .asset-print-preview {
        min-height: 124px;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #f8fafc;
        padding: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .asset-print-preview img {
        max-width: 180px;
        max-height: 110px;
        object-fit: contain;
    }
    .print-only-label {
        display: none;
    }
    #branchPrintRoot {
        display: none;
    }
    @media (max-width: 1366px) {
        .branch-label-table th,
        .branch-label-table td { font-size: .68rem; padding: .32rem .34rem; }
    }
    @media print {
        @page { size: A4 portrait; margin: 10mm; }

        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            width: auto !important;
            height: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
        }

        body.branch-print-mode .branch-print-hidden {
            display: none !important;
        }

        body.branch-print-mode #branchPrintRoot {
            display: block !important;
            visibility: visible !important;
            position: static !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
        }

        #branchPrintRoot .print-sheet {
            width: 190mm !important;
            min-height: 277mm !important;
            margin: 0 auto !important;
            padding-top: 18mm !important;
            display: flex !important;
            justify-content: center !important;
            align-items: flex-start !important;
            background: #fff !important;
        }

        #branchPrintRoot .parcel-label {
            position: relative !important;
            display: block !important;
            visibility: visible !important;
            width: 170mm !important;
            height: 105mm !important;
            max-width: none !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 5mm 6mm !important;
            border: 2px solid #000 !important;
            border-radius: 3mm !important;
            background: #fff !important;
            color: #000 !important;
            box-shadow: none !important;
            overflow: hidden !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        #branchPrintRoot .label-title {
            font-size: 12pt !important;
            font-weight: 900 !important;
            border-bottom: 1.5px solid #000 !important;
            padding-bottom: 3mm !important;
            margin-bottom: 3mm !important;
        }

        #branchPrintRoot .label-row {
            margin-bottom: 2mm !important;
            font-size: 9.5pt !important;
            line-height: 1.34 !important;
        }

        #branchPrintRoot .label-branch-name {
            font-size: 12pt !important;
            font-weight: 900 !important;
        }

        #branchPrintRoot .label-code {
            display: inline-block !important;
            border: 1px solid #334155 !important;
            border-radius: 2mm !important;
            padding: 1.2mm 2mm !important;
            margin-right: 2mm !important;
            background: #f8fafc !important;
            font-weight: 900 !important;
        }

        #branchPrintRoot .label-asset-block {
            position: absolute !important;
            top: 7mm !important;
            right: 5mm !important;
            width: 54mm !important;
            min-height: 36mm !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: flex-start !important;
            gap: 1mm !important;
            background: #fff !important;
            text-align: center !important;
        }

        #branchPrintRoot .label-asset-block > div {
            width: 100% !important;
            font-size: 7.5pt !important;
            line-height: 1.15 !important;
            text-align: center !important;
            font-weight: 800 !important;
        }

        #branchPrintRoot .selected-asset-image {
            display: block !important;
            width: 52mm !important;
            height: 31mm !important;
            object-fit: contain !important;
            border: 1px solid #bbb !important;
            border-radius: 2mm !important;
            padding: 1mm !important;
            background: #fff !important;
        }

        #branchPrintRoot .label-courier-block {
            position: absolute !important;
            right: 7mm !important;
            bottom: 7mm !important;
            width: 46mm !important;
            height: 17mm !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #fff !important;
        }

        #branchPrintRoot .label-courier-image {
            max-width: 44mm !important;
            max-height: 15mm !important;
            object-fit: contain !important;
        }

        #branchPrintRoot .label-fragile-block {
            position: absolute !important;
            left: 76mm !important;
            bottom: 8mm !important;
            width: 42mm !important;
            height: 18mm !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #fff !important;
        }

        #branchPrintRoot .label-fragile-image {
            max-width: 40mm !important;
            max-height: 16mm !important;
            object-fit: contain !important;
        }
    }


    /* Unified header style: match IT System Registry */
    .branch-label-page { --branch-blue:#0f4c81; --branch-border:#dbe5ee; }
    .branch-label-hero {
        background: linear-gradient(135deg,#0b3c68,#1769aa);
        border-radius: 18px;
        padding: 22px;
        color: #fff;
        margin-bottom: 24px;
        box-shadow: 0 12px 30px rgba(15,76,129,.18);
    }
    .branch-label-hero h1 {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0 0 5px;
    }
    .branch-label-hero p {
        margin: 0;
        opacity: .86;
        font-size: .9rem;
    }
    .branch-label-total {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        background: rgba(255,255,255,.16);
        border: 1px solid rgba(255,255,255,.25);
        padding: .42rem .72rem;
        border-radius: 999px;
        font-size: .8rem;
        white-space: nowrap;
    }
    .branch-label-search-card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 5px 18px rgba(20,46,70,.07);
    }
    .branch-label-search {
        display: grid;
        grid-template-columns: minmax(190px,.8fr) minmax(320px,1.8fr) auto;
        gap: 10px;
        align-items: end;
    }
    .branch-label-search > div { width: auto; max-width: none; }
    .branch-label-search .form-control,
    .branch-label-search .form-select {
        min-height: 40px;
        border-radius: 10px;
        font-size: .86rem;
    }
    .branch-label-search-actions {
        display: flex;
        gap: 8px;
        min-width: 180px;
    }
    .branch-label-search-actions .btn {
        min-height: 40px;
        border-radius: 10px;
        font-weight: 700;
        white-space: nowrap;
    }
    .branch-preview-modal .modal-dialog { max-width: 860px; }
    .branch-preview-modal .modal-content {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 22px 60px rgba(15,23,42,.24);
        overflow: hidden;
    }
    .branch-preview-modal .modal-header {
        background: linear-gradient(135deg,#eff6ff,#fff);
        border-bottom: 1px solid #e2e8f0;
        padding: 14px 18px;
    }
    .branch-preview-modal .modal-title { font-weight: 800; color:#17324d; }
    .branch-preview-modal .modal-body { background:#f8fafc; padding:16px; }
    .branch-preview-modal .parcel-label { max-width: 760px; }
    @media (max-width:1366px) {
        .branch-label-page { margin-left:-4px; margin-right:-4px; }
        .branch-label-hero { padding:18px; margin-bottom:18px; }
        .branch-label-search-card { padding:14px !important; }
        .branch-label-search { grid-template-columns:minmax(170px,.8fr) minmax(260px,1.7fr) auto; gap:8px; }
        .branch-label-search .form-control,
        .branch-label-search .form-select,
        .branch-label-search-actions .btn { min-height:36px; font-size:.8rem; }
        .branch-label-search-actions { min-width:160px; }
        .branch-preview-modal .modal-dialog { max-width:760px; }
    }
    @media (max-width:1100px) {
        .branch-label-hero h1 { font-size:1.12rem; }
        .branch-label-hero p { font-size:.78rem; }
        .branch-label-search { grid-template-columns:1fr 1.5fr; }
        .branch-label-search-actions { grid-column:1/-1; min-width:0; }
    }
    @media (max-width:767.98px) {
        .branch-label-search { grid-template-columns:1fr; }
        .branch-label-search-actions { grid-column:auto; }
        .branch-label-search-actions .btn { flex:1; }
        .branch-label-total { width:100%; justify-content:center; }
        .branch-preview-modal .modal-dialog { margin:.5rem; }
        .branch-preview-modal .modal-body { padding:10px; overflow-x:auto; }
        .branch-preview-modal .parcel-label { min-width:680px; }
    }

    .branch-group-card{border:1px solid #bae6fd;background:linear-gradient(135deg,#f8fdff,#eef9ff);border-radius:18px;box-shadow:0 8px 24px rgba(14,165,233,.08);overflow:hidden}
    .branch-group-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:16px 18px;border-bottom:1px solid #dbeafe;background:#fff}
    .branch-group-head h2{font-size:1rem;font-weight:900;color:#0f4c81;margin:0 0 3px}.branch-group-head p{font-size:.78rem;color:#64748b;margin:0}
    .branch-group-search{display:grid;grid-template-columns:minmax(230px,360px) auto;gap:10px;align-items:start;padding:16px 18px}
    .branch-group-search .form-control,.branch-group-search .btn{height:42px;border-radius:10px;font-weight:700}
    .branch-group-search-actions{display:flex;gap:8px;align-items:center;padding-top:31px}
    .branch-group-search-actions .btn{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
    .branch-group-summary{margin:0 18px 16px;padding:14px;border:1px solid #bfdbfe;border-radius:14px;background:#fff}
    .branch-group-summary-grid{display:grid;grid-template-columns:1fr 1fr 1.6fr;gap:12px}.branch-group-summary-label{font-size:.68rem;font-weight:800;color:#64748b;text-transform:uppercase}.branch-group-summary-value{font-size:.82rem;font-weight:900;color:#0f172a;margin-top:3px}
    .branch-group-table-wrap{padding:0 18px 18px}.branch-group-table{table-layout:fixed;width:100%;margin:0}.branch-group-table th{background:#eff6ff;color:#334155;font-size:.72rem;font-weight:900}.branch-group-table td{font-size:.74rem;vertical-align:middle}.branch-group-table th:nth-child(1){width:5%}.branch-group-table th:nth-child(2){width:12%}.branch-group-table th:nth-child(3){width:15%}.branch-group-table th:nth-child(4){width:18%}.branch-group-table th:nth-child(5){width:36%}.branch-group-table th:nth-child(6){width:14%}
    .branch-type-badge{display:inline-flex;align-items:center;padding:.22rem .5rem;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:.68rem;font-weight:900}.branch-main-address-note{font-size:.68rem;color:#64748b;margin-top:4px}.branch-group-action .btn{font-size:.7rem;font-weight:800;border-radius:8px;white-space:nowrap}
    .branch-group-dropdown-wrap{padding:0 18px 18px}.branch-group-dropdown-card{border:1px solid #dbeafe;border-radius:14px;background:#fff;padding:14px}.branch-group-dropdown-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:start}.branch-group-select{height:42px;min-height:42px;border-radius:10px;font-size:.82rem}.branch-group-dropdown-actions{display:flex;gap:8px;align-items:center;padding-top:25px}.branch-group-dropdown-actions .btn{height:42px;min-height:42px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;font-size:.76rem;font-weight:800;white-space:nowrap}.branch-group-selected-detail{display:flex;align-items:flex-start;gap:12px;margin-top:12px;padding:12px;border:1px solid #bfdbfe;border-radius:12px;background:linear-gradient(135deg,#eff6ff,#f8fbff)}.branch-group-selected-icon{width:38px;height:38px;flex:0 0 38px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:#dbeafe;color:#1d4ed8;font-size:1.05rem}.branch-group-selected-content{min-width:0}.branch-group-selected-content strong{font-size:.86rem;color:#0f172a}
    @media(max-width:900px){.branch-group-summary-grid{grid-template-columns:1fr}.branch-group-search{grid-template-columns:1fr}.branch-group-search-actions{padding-top:0}.branch-group-search-actions .btn{flex:1}.branch-group-dropdown-grid{grid-template-columns:1fr}.branch-group-dropdown-actions{padding-top:0}.branch-group-dropdown-actions .btn{flex:1}}
    @media(max-width:575.98px){.branch-group-dropdown-actions{flex-direction:column}.branch-group-dropdown-wrap{padding-left:12px;padding-right:12px}.branch-group-selected-detail{align-items:flex-start}}


    /* Branch Label module navigation: same card layout as HDD request module */
    .branch-label-module-menu {
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:9px;
        margin:0 0 14px;
    }
    .branch-label-module-menu-item {
        position:relative;
        min-width:0;
        min-height:78px;
        display:flex;
        align-items:center;
        gap:10px;
        padding:11px 12px;
        border:1px solid #dbe5ee;
        border-radius:14px;
        background:#fff;
        color:#334155;
        text-decoration:none;
        box-shadow:0 5px 16px rgba(15,23,42,.055);
        transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;
        overflow:hidden;
    }
    .branch-label-module-menu-item:hover {
        color:#0f4c81;
        text-decoration:none;
        border-color:#93c5fd;
        box-shadow:0 9px 22px rgba(37,99,235,.12);
        transform:translateY(-1px);
    }
    .branch-label-module-menu-item.active {
        color:#fff;
        border-color:#00acc1;
        background:linear-gradient(135deg,#0097a7 0%,#00bcd4 58%,#26c6da 100%);
        box-shadow:0 10px 24px rgba(0,188,212,.28);
    }
    .branch-label-module-menu-icon {
        width:38px;
        height:38px;
        flex:0 0 38px;
        display:flex;
        align-items:center;
        justify-content:center;
        border-radius:11px;
        background:#e0f7fa;
        color:#00acc1;
        font-size:1.1rem;
    }
    .branch-label-module-menu-item.active .branch-label-module-menu-icon {
        background:rgba(255,255,255,.18);
        color:#fff;
    }
    .branch-label-module-menu-content { min-width:0; }
    .branch-label-module-menu-title {
        display:block;
        font-size:.78rem;
        line-height:1.25;
        font-weight:900;
        white-space:normal;
    }
    .branch-label-module-menu-note {
        display:block;
        margin-top:3px;
        font-size:.65rem;
        line-height:1.2;
        color:#64748b;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .branch-label-module-menu-item.active .branch-label-module-menu-note { color:rgba(255,255,255,.82); }
    .branch-label-module-menu-item.active.branch-label-active-menu-blink {
        /* animation:branchLabelActiveMenuBlink 1.4s ease-out infinite; */
        transform-origin:center;
        will-change:transform,box-shadow,filter;
    }
    @keyframes branchLabelActiveMenuBlink {
        0%,100% { transform:scale(1); box-shadow:0 10px 24px rgba(0,188,212,.28); filter:brightness(1); }
        50% { transform:scale(1.025); box-shadow:0 0 0 4px rgba(0,188,212,.22),0 14px 30px rgba(0,151,167,.38); filter:brightness(1.16); }
    }
    .branch-label-anchor-offset { scroll-margin-top:90px; }
    @media(max-width:1366px){
        .branch-label-module-menu{gap:7px}
        .branch-label-module-menu-item{min-height:70px;padding:9px 10px;gap:8px}
        .branch-label-module-menu-icon{width:32px;height:32px;flex-basis:32px;border-radius:9px;font-size:.95rem}
        .branch-label-module-menu-title{font-size:.7rem}
        .branch-label-module-menu-note{font-size:.59rem}
    }
    @media(max-width:800px){.branch-label-module-menu{grid-template-columns:1fr}}
    @media(prefers-reduced-motion:reduce){.branch-label-module-menu-item.active.branch-label-active-menu-blink{animation:none}}

</style>


<div class="branch-label-page pb-4">
<nav class="branch-label-module-menu" aria-label="เมนูระบบค้นหาและพิมพ์ที่อยู่สาขา">
    <a class="branch-label-module-menu-item<?php echo $branchLabelView === 'group' ? ' active branch-label-active-menu-blink' : ''; ?>" href="index.php?view=group"<?php echo $branchLabelView === 'group' ? ' aria-current="page"' : ''; ?>>
        <span class="branch-label-module-menu-icon"><i class="bi bi-diagram-3"></i></span>
        <span class="branch-label-module-menu-content"><span class="branch-label-module-menu-title">พิมพ์ที่อยู่สาขาใหญ่</span><span class="branch-label-module-menu-note">เลือกสาขาย่อยและใช้ที่อยู่สาขาใหญ่</span></span>
    </a>
    <a class="branch-label-module-menu-item<?php echo $branchLabelView === 'direct' ? ' active branch-label-active-menu-blink' : ''; ?>" href="index.php?view=direct"<?php echo $branchLabelView === 'direct' ? ' aria-current="page"' : ''; ?>>
        <span class="branch-label-module-menu-icon"><i class="bi bi-geo-alt"></i></span>
        <span class="branch-label-module-menu-content"><span class="branch-label-module-menu-title">พิมพ์ที่อยู่สาขาย่อย/ศูนย์ฯ/ค้นหา Cost Center</span><span class="branch-label-module-menu-note">ค้นหาและพิมพ์ตามข้อมูลสาขาเดิม</span></span>
    </a>
    <a class="branch-label-module-menu-item" href="print_history.php">
        <span class="branch-label-module-menu-icon"><i class="bi bi-clock-history"></i></span>
        <span class="branch-label-module-menu-content"><span class="branch-label-module-menu-title">ประวัติการพิมพ์ที่อยู่</span><span class="branch-label-module-menu-note">ตรวจสอบผู้พิมพ์ สาขา และรายการย้อนหลัง</span></span>
    </a>
</nav>
<div class="branch-label-hero d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
    <div>
        <h1><?php echo $branchLabelView === 'group' ? 'พิมพ์ที่อยู่สาขาใหญ่' : 'พิมพ์ที่อยู่สาขาย่อย/ศูนย์ฯ/ค้นหา Cost Center'; ?></h1>
        <!-- <p>ค้นหาข้อมูลสาขา ตรวจสอบที่อยู่ และจัดทำใบปะหน้าพัสดุ</p> -->
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="branch-label-total">ข้อมูลทั้งหมด <strong><?php echo number_format($totalRows); ?></strong> สาขา</div>
    </div>
</div>

<?php if ($pageError !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm"><?php echo blE($pageError); ?></div>
<?php endif; ?>


<?php if ($branchLabelView === 'group'): ?>
<div class="branch-group-card branch-label-anchor-offset mb-3" id="branch-group-system">
    <!-- <div class="branch-group-head">
        <div>
            <h2><i class="bi bi-diagram-3 me-1"></i>พิมพ์ที่อยู่สาขาในสังกัด โดยใช้ที่อยู่สาขาใหญ่</h2>
            <p>กรอกรหัสสาขาใหญ่เพื่อดูสาขาย่อยและศูนย์บริการทั้งหมด จากนั้นเลือกปลายทางที่ต้องการพิมพ์ โดยระบบใช้ที่อยู่ของรายการที่ branch_type เป็น “สาขาใหญ่”</p>
        </div>
        <span class="badge text-bg-info">ระบบใหม่</span>
    </div> -->
    <form method="get" class="branch-group-search" autocomplete="off">
        <input type="hidden" name="view" value="group">
        <div>
            <label class="form-label fw-bold">รหัสสาขาใหญ่</label>
            <input type="text" name="group_main_code" id="groupMainCodeInput" class="form-control" value="<?php echo blE($groupMainCode); ?>" inputmode="numeric" pattern="[0-9]{3}" minlength="3" maxlength="3" autocomplete="off" placeholder="ตัวอย่าง 002" title="กรุณากรอกตัวเลขให้ครบ 3 หลัก" required>
            <div class="form-text">กรอกเฉพาะตัวเลข 3 หลัก เช่น 002</div>
        </div>
        <div class="branch-group-search-actions">
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-search me-1"></i>ค้นหาสาขาในสังกัด</button>
            <a href="index.php?view=group" class="btn btn-outline-secondary px-3">ล้างค่า</a>
        </div>
    </form>

    <?php if ($groupLookupError !== ''): ?>
        <div class="alert alert-danger mx-3 mb-3 py-2"><?php echo blE($groupLookupError); ?></div>
    <?php endif; ?>

    <?php if ($groupMainBranch && !empty($groupBranchRows)): ?>
        <?php
            $mainBranchName = blDisplay($groupMainBranch, $groupMap, 'branch_name');
            $mainBranchName2 = blRaw($groupMainBranch, $groupMap, 'branch_name_2');
            $mainBranchAddress = blBranchAddress($groupMainBranch, $groupMap);
            $mainBranchPhone = blDisplay($groupMainBranch, $groupMap, 'phone');
            $mainBranchLandmark = blRaw($groupMainBranch, $groupMap, 'landmark');
        ?>
        <div class="branch-group-summary">
            <div class="branch-group-summary-grid">
                <div><div class="branch-group-summary-label">รหัสสาขาใหญ่</div><div class="branch-group-summary-value"><?php echo blE($groupMainCode); ?></div></div>
                <div><div class="branch-group-summary-label">สาขาใหญ่ที่ใช้เป็นที่อยู่จัดส่ง</div><div class="branch-group-summary-value"><?php echo blE($mainBranchName); ?><?php if ($mainBranchName2 !== ''): ?> <span class="text-muted">(<?php echo blE($mainBranchName2); ?>)</span><?php endif; ?></div></div>
                <div><div class="branch-group-summary-label">ที่อยู่จัดส่งกลาง</div><div class="branch-group-summary-value"><?php echo blE($mainBranchAddress); ?></div></div>
            </div>
        </div>
        <div class="branch-group-dropdown-wrap">
            <div class="branch-group-dropdown-card">
                <div class="branch-group-dropdown-grid">
                    <div>
                        <label for="groupBranchSelect" class="form-label fw-bold mb-1">เลือกสาขาในสังกัด</label>
                        <select id="groupBranchSelect" class="form-select branch-group-select">
                            <option value="">-- เลือกสาขาปลายทางที่ต้องการพิมพ์ --</option>
                            <?php foreach ($groupBranchRows as $groupIndex => $groupRow): ?>
                                <?php
                                    $childMainCode = blDisplay($groupRow, $groupMap, 'main_branch_code');
                                    $childBranchCode = blDisplay($groupRow, $groupMap, 'branch_code');
                                    $childBranchName = blDisplay($groupRow, $groupMap, 'branch_name');
                                    $childBranchName2 = blRaw($groupRow, $groupMap, 'branch_name_2');
                                    $childBranchType = blDisplay($groupRow, $groupMap, 'branch_type');
                                ?>
                                <option value="<?php echo (int)$groupIndex; ?>"
                                    data-main-code="<?php echo blE($childMainCode); ?>"
                                    data-branch-code="<?php echo blE($childBranchCode); ?>"
                                    data-branch-name="<?php echo blE($childBranchName); ?>"
                                    data-branch-name-2="<?php echo blE($childBranchName2); ?>"
                                    data-branch-type="<?php echo blE($childBranchType); ?>">
                                    <?php echo blE($childBranchCode . ' - ' . $childBranchName . ($childBranchType !== '-' ? ' (' . $childBranchType . ')' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">พบ <?php echo number_format(count($groupBranchRows)); ?> สาขาในสังกัด</div>
                    </div>
                    <div class="branch-group-dropdown-actions">
                        <button id="groupPreviewButton" class="btn btn-outline-primary" type="button" disabled onclick="printBranchLabel('groupSelectedParcelLabel', '', '', false)"><i class="bi bi-eye me-1"></i>ดูใบปะหน้า</button>
                        <button id="groupPrintButton" class="btn btn-success" type="button" disabled onclick="openAssetPrintModal('groupSelectedParcelLabel')"><i class="bi bi-printer me-1"></i>พิมพ์ใบปะหน้า</button>
                    </div>
                </div>

                <div id="groupBranchSelectedDetail" class="branch-group-selected-detail d-none">
                    <div class="branch-group-selected-icon"><i class="bi bi-building-check"></i></div>
                    <div class="branch-group-selected-content">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <strong id="groupSelectedBranchName">-</strong>
                            <span id="groupSelectedBranchType" class="branch-type-badge">-</span>
                        </div>
                        <div class="small text-muted"><span class="fw-bold">รหัสสาขา:</span> <span id="groupSelectedMainCode">-</span> <span class="mx-1">|</span> <span class="fw-bold">Cost Center:</span> <span id="groupSelectedBranchCode">-</span></div>
                        <div class="branch-main-address-note mt-2"><i class="bi bi-geo-alt me-1"></i>ที่อยู่จัดส่งยังคงใช้ที่อยู่สาขาใหญ่: <?php echo blE($mainBranchAddress); ?></div>
                    </div>
                </div>
            </div>

            <div id="groupSelectedParcelLabel" class="parcel-label d-none" data-print-source="main_branch_group" data-main-branch-name="<?php echo blE($mainBranchName); ?>">
                <div class="label-title">ใบปะหน้าพัสดุ / ที่อยู่สาขา</div>
                <div class="label-row"><strong>ถึง:</strong> <span class="label-branch-name">-</span></div>
                <div class="label-row"><span class="label-code">รหัสสาขา: -</span><span class="label-code">Cost Center: -</span></div>
                <div class="label-row"><strong>ที่อยู่:</strong> <?php echo blE($mainBranchAddress); ?></div>
                <?php if ($mainBranchPhone !== '-'): ?><div class="label-row"><strong>โทร:</strong> <?php echo blE($mainBranchPhone); ?></div><?php endif; ?>
                <?php if ($mainBranchLandmark !== ''): ?><div class="label-row"><strong>จุดสังเกต:</strong> <?php echo blE($mainBranchLandmark); ?></div><?php endif; ?>
                <div class="label-row label-asset-block"><div class="label-asset-title">รายการจัดส่ง : <span class="selected-asset-text">-</span></div><img class="selected-asset-image" src="" alt="รูปภาพทรัพย์สิน" style="display:none;"></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php if ($branchLabelView === 'direct'): ?>

<div class="branch-label-card branch-label-search-card p-3 mb-3">
    <!-- <div class="branch-label-section-title mb-3">ค้นหาสาขา</div> -->
    <form method="get" class="branch-label-search" autocomplete="off">
        <input type="hidden" name="view" value="direct">
        <div>
            <select name="search_field" class="form-select" aria-label="เลือกประเภทข้อมูลที่ต้องการค้นหา">
                <option value="all" <?php echo $searchField === 'all' ? 'selected' : ''; ?>>ค้นหาทุกข้อมูล</option>
                <option value="main_branch_code" <?php echo $searchField === 'main_branch_code' ? 'selected' : ''; ?>>รหัสสาขา</option>
                <option value="branch_code" <?php echo $searchField === 'branch_code' ? 'selected' : ''; ?>>Cost Center</option>
                <option value="branch_name" <?php echo $searchField === 'branch_name' ? 'selected' : ''; ?>>ชื่อสาขา</option>
                <option value="province" <?php echo $searchField === 'province' ? 'selected' : ''; ?>>จังหวัด</option>
                <option value="postal_code" <?php echo $searchField === 'postal_code' ? 'selected' : ''; ?>>รหัสไปรษณีย์</option>
                <option value="phone" <?php echo $searchField === 'phone' ? 'selected' : ''; ?>>เบอร์โทร</option>
            </select>
        </div>
        <div>
            <input type="text" name="q" class="form-control" value="<?php echo blE($query); ?>" placeholder="กรอกคำค้นหา">
        </div>
        <div class="branch-label-search-actions">
            <button class="btn btn-primary flex-fill" type="submit">ค้นหา</button>
            <a href="index.php?view=direct" class="btn btn-outline-secondary">ล้างค่า</a>
        </div>
    </form>
</div>

<div class="branch-label-card p-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="branch-label-section-title">รายการสาขา</div>
            <div class="small text-muted mt-1">
                <?php $startItem = $totalRows > 0 ? $offset + 1 : 0; $endItem = min($offset + $perPage, $totalRows); ?>
                แสดง <?php echo number_format($startItem); ?>-<?php echo number_format($endItem); ?> จาก <?php echo number_format($totalRows); ?> รายการ | หน้า <?php echo number_format($currentPage); ?> / <?php echo number_format($totalPages); ?>
            </div>
        </div>
        <div class="small text-muted">กด “พิมพ์ใบปะหน้า” เพื่อพิมพ์ที่อยู่เฉพาะสาขานั้น</div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle branch-label-table mb-0">
            <thead>
                <tr>
                    <th>ลำดับ</th>
                    <th>รหัสสาขา</th>
                    <th>Cost Center</th>
                    <th>ชื่อสาขา</th>
                    <th>ที่อยู่</th>
                    <th>เบอร์โทร</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($branchRows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูลสาขา</td></tr>
                <?php else: ?>
                    <?php foreach ($branchRows as $index => $row): ?>
                        <?php
                            $mainCode = blDisplay($row, $branchMap, 'main_branch_code');
                            $branchCode = blDisplay($row, $branchMap, 'branch_code');
                            $branchName = blDisplay($row, $branchMap, 'branch_name');
                            $branchName2 = blRaw($row, $branchMap, 'branch_name_2');
                            $address = blBranchAddress($row, $branchMap);
                            $phone = blDisplay($row, $branchMap, 'phone');
                            $landmark = blRaw($row, $branchMap, 'landmark');
                            $labelId = 'branchParcelLabel' . $index;
                        ?>
                        <tr>
                            <td><?php echo number_format($offset + $index + 1); ?></td>
                            <td class="fw-bold text-primary"><?php echo blE($mainCode); ?></td>
                            <td class="fw-bold"><?php echo blE($branchCode); ?></td>
                            <td>
                                <div class="fw-bold"><?php echo blE($branchName); ?></div>
                                <?php if ($branchName2 !== ''): ?><div class="text-muted small"><?php echo blE($branchName2); ?></div><?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo blE($address); ?></div>
                                <?php if ($landmark !== ''): ?><div class="text-muted small">ใกล้เคียง: <?php echo blE($landmark); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo blE($phone); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary w-100 mb-1" type="button"
                                    onclick="printBranchLabel('<?php echo blE($labelId); ?>', '', '', false)">ดูใบปะหน้า</button>
                                <button class="btn btn-sm btn-success w-100" type="button" onclick="openAssetPrintModal('<?php echo blE($labelId); ?>')">พิมพ์ใบปะหน้า</button>
                            </td>
                        </tr>
                        <tr class="d-none" id="preview<?php echo blE($labelId); ?>">
                            <td colspan="7">
                                <div id="<?php echo blE($labelId); ?>" class="parcel-label" data-print-source="direct_branch">
                                    <div class="label-title">ใบปะหน้าพัสดุ / ที่อยู่สาขา</div>

                                    <div class="label-row">
                                        <strong>ผู้ส่ง</strong><br>
                                        บริษัทเมืองไทยแคปปิตอล จำกัด(มหาชน)<br>
                                        332/1 ถนนจรัญสนิทวงศ์ แขวงบางพลัด<br>
                                        เขตบางพลัด กรุงเทพมหานคร 10700<br>
                                        โทร 02-483-8888,061-271-3113
                                    </div>

                                    <hr>

                                    <div class="label-row"><strong>ถึง:</strong> <span class="label-branch-name"><?php echo blE($branchName); ?></span></div>
                                    <div class="label-row">
                                        <span class="label-code">รหัสสาขา: <?php echo blE($mainCode); ?></span>
                                        <span class="label-code">Cost Center: <?php echo blE($branchCode); ?></span>
                                    </div>
                                    <div class="label-row"><strong>ที่อยู่:</strong> <?php echo blE($address); ?></div>
                                    <?php if ($phone !== '-'): ?><div class="label-row"><strong>โทร:</strong> <?php echo blE($phone); ?></div><?php endif; ?>
                                    <?php if ($landmark !== ''): ?><div class="label-row"><strong>จุดสังเกต:</strong> <?php echo blE($landmark); ?></div><?php endif; ?>
                                    <div class="label-row label-asset-block">
                                        <div class="label-asset-title">รายการจัดส่ง : <span class="selected-asset-text">-</span></div>
                                        <img class="selected-asset-image" src="" alt="รูปภาพทรัพย์สิน" style="display:none;">
                                    </div>
                                    
                                    <div class="label-fragile-block">
                                        <img class="label-fragile-image" src="<?php echo blE($branchLabelBaseUrl); ?>/images/FRAGILE.jpg" alt="Fragile ระวังแตก">
                                    </div>
                                    <div class="label-courier-block">
                                        <img class="label-courier-image" src="<?php echo blE($branchLabelBaseUrl); ?>/images/Kerry-Express-Logo.png" alt="Kerry Express">
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
            <div class="small text-muted">แสดงผลทีละ <?php echo number_format($perPage); ?> รายการ</div>
            <nav aria-label="Branch pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo blE($buildPageUrl(max(1, $currentPage - 1))); ?>">ก่อนหน้า</a></li>
                    <?php $startPage = max(1, $currentPage - 2); $endPage = min($totalPages, $currentPage + 2); ?>
                    <?php if ($startPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo blE($buildPageUrl(1)); ?>">1</a></li>
                        <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                        <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>"><a class="page-link" href="<?php echo blE($buildPageUrl($page)); ?>"><?php echo number_format($page); ?></a></li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?php echo blE($buildPageUrl($totalPages)); ?>"><?php echo number_format($totalPages); ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo blE($buildPageUrl(min($totalPages, $currentPage + 1))); ?>">ถัดไป</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<div class="modal fade branch-preview-modal" id="branchPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">ตัวอย่างใบปะหน้าพัสดุ</h5>
                    <div class="small text-muted mt-1" id="branchPreviewSubtitle">-</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div id="branchPreviewContent"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>



<div class="modal fade" id="assetPrintModal" tabindex="-1" aria-labelledby="assetPrintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="assetPrintModalLabel">เลือกทรัพย์สินที่ต้องการจัดส่ง</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="selectedLabelId" value="">
                <label class="form-label fw-bold">รายการทรัพย์สิน <span class="text-danger">*</span></label>
                <select id="selectedAssetName" class="form-select" required>
                    <option value="">-- เลือกทรัพย์สิน --</option>
                    <option value="เครื่องปริ้นเตอร์ HP">เครื่องปริ้นเตอร์ HP</option>
                    <option value="เครื่องปริ้นเตอร์ Brother">เครื่องปริ้นเตอร์ Brother</option>
                    <option value="คอมพิวเตอร์">คอมพิวเตอร์</option>
                    <option value="จอคอมพิวเตอร์">จอคอมพิวเตอร์</option>
                    <option value="กล้องวงจรปิด CCTV">กล้องวงจรปิด CCTV</option>
                    <option value="เครื่องบันทึกกล้องวงจรปิด">เครื่องบันทึกกล้องวงจรปิด</option>
                    <option value="Projector">Projector</option>
                    <option value="HDD กล้อง">HDD กล้อง</option>
                    <option value="RAM">RAM</option>
                    <option value="Adapter CCTV">Adapter CCTV</option>
                    <option value="Adapter Notebook">Adapter Notebook</option>
                    <option value="ตลับหมึก Brother 5915">ตลับหมึก Brother 5915</option>
                    <option value="Drum 3455">Drum 3455</option>
                    <option value="Drum 3608">Drum 3608</option>
                </select>
                <div class="small text-muted mt-2">เมื่อกดพิมพ์ ระบบจะใส่รายการทรัพย์สินและรูปภาพลงในใบปะหน้า โดยสามารถเลือกรูปแบบกระดาษได้ที่หน้าใบปะหน้าพัสดุ / ที่อยู่สาขา</div>
                <div class="asset-print-preview mt-3" id="assetImagePreview">
                    <span class="text-muted small">เลือกรายการทรัพย์สินเพื่อแสดงตัวอย่างรูปภาพ</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" onclick="confirmAssetAndPrint()">พิมพ์ใบปะหน้า</button>
            </div>
        </div>
    </div>
</div>


<script>
    var branchLabelAssetImages = {
        'เครื่องปริ้นเตอร์ HP': '<?php echo blE($branchLabelBaseUrl); ?>/images/HP430.png',
        'เครื่องปริ้นเตอร์ Brother': '<?php echo blE($branchLabelBaseUrl); ?>/images/DCPL5600DN_1.jpg',
        'คอมพิวเตอร์': '<?php echo blE($branchLabelBaseUrl); ?>/images/Acer.webp',
        'จอคอมพิวเตอร์': '<?php echo blE($branchLabelBaseUrl); ?>/images/Monitor.png',
        'กล้องวงจรปิด CCTV': '<?php echo blE($branchLabelBaseUrl); ?>/images/Cam%20Watashi.jpg',
        'เครื่องบันทึกกล้องวงจรปิด': '<?php echo blE($branchLabelBaseUrl); ?>/images/WSR0.webp',
        'Projector': '<?php echo blE($branchLabelBaseUrl); ?>/images/EPSON.jpg',
        'HDD กล้อง': '<?php echo blE($branchLabelBaseUrl); ?>/images/HDDCCTV2.png',
        'RAM': '<?php echo blE($branchLabelBaseUrl); ?>/images/Ram.jpg',
        'Adapter CCTV': '<?php echo blE($branchLabelBaseUrl); ?>/images/adapter.jfif',
        'Adapter Notebook': '<?php echo blE($branchLabelBaseUrl); ?>/images/Acer1.jpg',
        'ตลับหมึก Brother 5915': '<?php echo blE($branchLabelBaseUrl); ?>/images/TN3668P.jpg',
        'Drum 3455': '<?php echo blE($branchLabelBaseUrl); ?>/images/3455.jpg',
        'Drum 3608': '<?php echo blE($branchLabelBaseUrl); ?>/images/3608.jpg'
    };

    function getBranchLabelAssetImage(assetName) {
        return branchLabelAssetImages[assetName] || '';
    }

    function updateAssetImagePreview() {
        var select = document.getElementById('selectedAssetName');
        var preview = document.getElementById('assetImagePreview');
        if (!select || !preview) {
            return;
        }

        var assetName = select.value;
        var imageUrl = getBranchLabelAssetImage(assetName);
        if (!assetName) {
            preview.innerHTML = '<span class="text-muted small">เลือกรายการทรัพย์สินเพื่อแสดงตัวอย่างรูปภาพ</span>';
            return;
        }
        if (!imageUrl) {
            preview.innerHTML = '<span class="text-danger small">ยังไม่ได้กำหนดรูปภาพสำหรับ ' + assetName + '</span>';
            return;
        }

        preview.innerHTML = '';
        var img = document.createElement('img');
        img.src = imageUrl;
        img.alt = assetName;
        img.onerror = function () {
            preview.innerHTML = '';
            var span = document.createElement('span');
            span.className = 'text-danger small';
            span.textContent = 'ไม่พบไฟล์รูปภาพของ ' + assetName;
            preview.appendChild(span);
        };
        preview.appendChild(img);
    }

    function openAssetPrintModal(labelId) {
        var source = document.getElementById(labelId);
        if (!source) {
            alert('ไม่พบข้อมูลใบปะหน้าสำหรับพิมพ์');
            return;
        }

        document.getElementById('selectedLabelId').value = labelId;
        document.getElementById('selectedAssetName').value = '';
        updateAssetImagePreview();

        if (window.bootstrap && bootstrap.Modal) {
            var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('assetPrintModal'));
            modal.show();
        } else {
            var assetName = prompt('กรุณาระบุทรัพย์สินที่ต้องการจัดส่ง');
            if (assetName) {
                printBranchLabel(labelId, assetName, getBranchLabelAssetImage(assetName));
            }
        }
    }

    function confirmAssetAndPrint() {
        var labelId = document.getElementById('selectedLabelId').value;
        var assetName = document.getElementById('selectedAssetName').value;
        if (!assetName) {
            alert('กรุณาเลือกทรัพย์สินที่ต้องการจัดส่ง');
            document.getElementById('selectedAssetName').focus();
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            var modal = bootstrap.Modal.getInstance(document.getElementById('assetPrintModal'));
            if (modal) {
                modal.hide();
            }
        }

        printBranchLabel(labelId, assetName, getBranchLabelAssetImage(assetName), true, 'portrait');
    }

    function printBranchLabel(labelId, assetName, assetImageUrl, autoPrint, printOrientation) {
        var source = document.getElementById(labelId);
        if (!source) {
            alert('ไม่พบข้อมูลใบปะหน้าสำหรับเปิดหน้าใหม่');
            return;
        }

        var form = document.createElement('form');
        form.method = 'post';
        form.action = 'print.php';
        form.target = '_blank';
        form.style.display = 'none';

        function addField(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value || '';
            form.appendChild(input);
        }

        var branchNameEl = source.querySelector('.label-branch-name');
        var codeEls = source.querySelectorAll('.label-code');
        var rows = source.querySelectorAll('.label-row');
        var address = '';
        var phone = '';
        var landmark = '';

        rows.forEach(function (row) {
            var text = (row.textContent || '').trim();
            if (text.indexOf('ที่อยู่:') === 0) address = text.replace(/^ที่อยู่:\s*/, '');
            if (text.indexOf('โทร:') === 0) phone = text.replace(/^โทร:\s*/, '');
            if (text.indexOf('จุดสังเกต:') === 0) landmark = text.replace(/^จุดสังเกต:\s*/, '');
        });

        var mainCode = codeEls[0] ? codeEls[0].textContent.replace(/^รหัสสาขา:\s*/, '').trim() : '';
        var branchCode = codeEls[1] ? codeEls[1].textContent.replace(/^Cost Center:\s*/, '').trim() : '';

        addField('branch_name', branchNameEl ? branchNameEl.textContent.trim() : '');
        addField('main_code', mainCode);
        addField('main_branch_name', source.dataset.mainBranchName || '');
        addField('branch_code', branchCode);
        addField('address', address);
        addField('phone', phone);
        addField('landmark', landmark);
        addField('asset_name', assetName || '');
        addField('asset_image', assetImageUrl || '');
        addField('auto_print', autoPrint === false ? '0' : '1');
        addField('print_orientation', printOrientation === 'landscape' ? 'landscape' : 'portrait');
        addField('print_source', source.dataset.printSource === 'main_branch_group' ? 'main_branch_group' : 'direct_branch');

        document.body.appendChild(form);
        form.submit();
        setTimeout(function () { form.remove(); }, 1000);
    }

document.addEventListener('DOMContentLoaded', function () {
        var groupMainCodeInput = document.getElementById('groupMainCodeInput');
        if (groupMainCodeInput) {
            groupMainCodeInput.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 3);
                this.setCustomValidity(this.value.length === 3 ? '' : 'กรุณากรอกตัวเลขให้ครบ 3 หลัก');
            });
            groupMainCodeInput.addEventListener('invalid', function () {
                this.setCustomValidity('กรุณากรอกรหัสสาขาใหญ่เป็นตัวเลขให้ครบ 3 หลัก');
            });
            groupMainCodeInput.addEventListener('blur', function () {
                this.setCustomValidity(this.value.length === 3 ? '' : 'กรุณากรอกตัวเลขให้ครบ 3 หลัก');
            });
        }

        var select = document.getElementById('selectedAssetName');
        if (select) {
            select.addEventListener('change', updateAssetImagePreview);
        }

        var groupBranchSelect = document.getElementById('groupBranchSelect');
        if (groupBranchSelect) {
            groupBranchSelect.addEventListener('change', function () {
                var selectedOption = groupBranchSelect.options[groupBranchSelect.selectedIndex];
                var hasSelection = selectedOption && selectedOption.value !== '';
                var previewButton = document.getElementById('groupPreviewButton');
                var printButton = document.getElementById('groupPrintButton');
                var detail = document.getElementById('groupBranchSelectedDetail');
                var label = document.getElementById('groupSelectedParcelLabel');

                if (previewButton) previewButton.disabled = !hasSelection;
                if (printButton) printButton.disabled = !hasSelection;
                if (!detail || !label) return;

                detail.classList.toggle('d-none', !hasSelection);
                if (!hasSelection) return;

                var mainCode = selectedOption.dataset.mainCode || '-';
                var branchCode = selectedOption.dataset.branchCode || '-';
                var branchName = selectedOption.dataset.branchName || '-';
                var branchName2 = selectedOption.dataset.branchName2 || '';
                var branchType = selectedOption.dataset.branchType || '-';
                var displayName = branchName2 ? branchName + ' (' + branchName2 + ')' : branchName;

                document.getElementById('groupSelectedBranchName').textContent = displayName;
                document.getElementById('groupSelectedBranchType').textContent = branchType;
                document.getElementById('groupSelectedMainCode').textContent = mainCode;
                document.getElementById('groupSelectedBranchCode').textContent = branchCode;

                var labelName = label.querySelector('.label-branch-name');
                var labelCodes = label.querySelectorAll('.label-code');
                if (labelName) labelName.textContent = branchName;
                if (labelCodes[0]) labelCodes[0].textContent = 'รหัสสาขา: ' + mainCode;
                if (labelCodes[1]) labelCodes[1].textContent = 'Cost Center: ' + branchCode;
            });
        }
    });
</script>

</div>

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