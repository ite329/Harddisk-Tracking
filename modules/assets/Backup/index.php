<?php

require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'ค้นหาข้อมูลทรัพย์สิน';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/data_database.php';

if (!function_exists('assetE')) {
    function assetE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('assetQuoteColumn')) {
    function assetQuoteColumn(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}

if (!function_exists('assetTableExists')) {
    function assetTableExists(PDO $pdo, string $dbName, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name");
            $stmt->execute([
                ':db_name' => $dbName,
                ':table_name' => $tableName,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('assetGetColumns')) {
    function assetGetColumns(PDO $pdo, string $dbName, string $tableName): array
    {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :db_name
              AND TABLE_NAME = :table_name
            ORDER BY ORDINAL_POSITION ASC");
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $tableName,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('assetColumnNames')) {
    function assetColumnNames(array $columns): array
    {
        $names = [];
        foreach ($columns as $column) {
            $name = (string)($column['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $names[strtolower($name)] = $name;
            }
        }
        return $names;
    }
}

if (!function_exists('assetValueByCandidates')) {
    function assetValueByCandidates(array $row, array $candidates)
    {
        foreach ($candidates as $candidate) {
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === strtolower((string)$candidate)) {
                    $text = trim((string)($value ?? ''));
                    if ($text !== '') {
                        return $value;
                    }
                }
            }
        }
        return null;
    }
}

if (!function_exists('assetFormatText')) {
    function assetFormatText($value): string
    {
        $text = trim((string)($value ?? ''));
        return $text !== '' ? $text : '-';
    }
}

if (!function_exists('assetDisplayBranchName')) {
    function assetDisplayBranchName($value): string
    {
        $branchName = trim((string)($value ?? ''));

        if ($branchName === '') {
            return '-';
        }

        // ตัดวงเล็บนำหน้าชื่อสาขาเสมอ เช่น (ศ.14), (ย่อย 1)
        $branchName = preg_replace('/^\s*\([^)]*\)\s*/u', '', $branchName);

        // ตัดวงเล็บท้ายเฉพาะรหัสสาขาย่อย เช่น (ย.3) หรือ (ศ.14)
        // วงเล็บที่เป็นส่วนหนึ่งของชื่อสถานที่ เช่น (วัดกระทุ่มราย) จะยังคงแสดง
        $branchName = preg_replace('/\s*\((?:ย|ศ)\.?\s*\d+\)\s*$/u', '', $branchName);

        $branchName = trim((string)$branchName);

        return $branchName !== '' ? $branchName : '-';
    }
}

if (!function_exists('assetFormatMoney')) {
    function assetFormatMoney($value): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '-';
        }

        $normalized = str_replace([',', ' '], '', $text);
        if (is_numeric($normalized)) {
            return number_format((float)$normalized, 2) . ' บาท';
        }

        return $text;
    }
}

if (!function_exists('assetParseDate')) {
    function assetParseDate($value): ?DateTime
    {
        $text = trim((string)($value ?? ''));
        if ($text === '' || $text === '-' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
            return null;
        }

        // Excel serial date support.
        if (is_numeric($text) && (float)$text > 20000 && (float)$text < 100000) {
            try {
                $base = new DateTime('1899-12-30');
                $base->modify('+' . (int)$text . ' days');
                return $base;
            } catch (Throwable $e) {
                return null;
            }
        }

        $text = str_replace(['.', '/'], ['-', '-'], $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'd-m-Y',
        ];

        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $text);
            if ($dt instanceof DateTime) {
                $errors = DateTime::getLastErrors();
                if ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0)) {
                    $year = (int)$dt->format('Y');
                    if ($year > 2400) {
                        $dt->modify('-543 years');
                    }
                    return $dt;
                }
            }
        }

        $time = strtotime($text);
        if ($time !== false) {
            $dt = new DateTime('@' . $time);
            $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
            return $dt;
        }

        return null;
    }
}

if (!function_exists('assetFormatDateThai')) {
    function assetFormatDateThai($value): string
    {
        $dt = assetParseDate($value);
        if (!$dt) {
            return '-';
        }
        return $dt->format('d/m/Y');
    }
}

if (!function_exists('assetCalculateAgeFromDate')) {
    function assetCalculateAgeFromDate($value): string
    {
        $start = assetParseDate($value);
        if (!$start) {
            return '-';
        }

        $today = new DateTime('today', new DateTimeZone('Asia/Bangkok'));
        $start->setTime(0, 0, 0);

        if ($start > $today) {
            return 'วันที่รับทรัพย์สินอยู่หลังวันปัจจุบัน';
        }

        $diff = $start->diff($today);
        $parts = [];
        if ((int)$diff->y > 0) {
            $parts[] = (int)$diff->y . ' ปี';
        }
        if ((int)$diff->m > 0) {
            $parts[] = (int)$diff->m . ' เดือน';
        }
        if ((int)$diff->d > 0 || empty($parts)) {
            $parts[] = (int)$diff->d . ' วัน';
        }

        return implode(' ', $parts);
    }
}


if (!function_exists('assetAgeExceedsYears')) {
    function assetAgeExceedsYears($value, int $years = 5): bool
    {
        $start = assetParseDate($value);
        if (!$start) {
            return false;
        }

        $today = new DateTime('today', new DateTimeZone('Asia/Bangkok'));
        $start->setTime(0, 0, 0);

        if ($start > $today) {
            return false;
        }

        $diff = $start->diff($today);
        if ((int)$diff->y > $years) {
            return true;
        }

        return (int)$diff->y === $years && ((int)$diff->m > 0 || (int)$diff->d > 0);
    }
}

if (!function_exists('assetFindCandidateColumns')) {
    function assetFindCandidateColumns(array $columns): array
    {
        $priorityKeywords = [
            'asset', 'as_', 'ทรัพย์', 'รหัส', 'เลข', 'code', 'no', 'number', 'serial', 'sn', 's_n', 'barcode', 'qr'
        ];
        $typesAllowed = ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'int', 'bigint', 'decimal'];
        $result = [];

        foreach ($columns as $column) {
            $name = (string)($column['COLUMN_NAME'] ?? '');
            $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
            $haystack = strtolower($name . ' ' . (string)($column['COLUMN_COMMENT'] ?? ''));

            if (!in_array($type, $typesAllowed, true)) {
                continue;
            }

            foreach ($priorityKeywords as $keyword) {
                if (strpos($haystack, strtolower($keyword)) !== false) {
                    $result[] = $name;
                    break;
                }
            }
        }

        if (empty($result)) {
            foreach ($columns as $column) {
                $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
                if (in_array($type, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                    $result[] = (string)$column['COLUMN_NAME'];
                }
            }
        }

        return array_values(array_unique($result));
    }
}

if (!function_exists('assetSearchRows')) {
    function assetSearchRows(PDO $pdo, array $columns, string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $searchColumns = assetFindCandidateColumns($columns);
        if (empty($searchColumns)) {
            return [];
        }

        $where = [];
        $orderExact = [];
        $params = [];

        foreach ($searchColumns as $index => $column) {
            $quoted = assetQuoteColumn($column);
            $likeParam = ':keyword_like_' . $index;
            $exactParam = ':keyword_exact_' . $index;

            $where[] = "CAST({$quoted} AS CHAR) LIKE {$likeParam}";
            $orderExact[] = "WHEN CAST({$quoted} AS CHAR) = {$exactParam} THEN 0";
            $params[$likeParam] = '%' . $keyword . '%';
            $params[$exactParam] = $keyword;
        }

        $sql = "SELECT * FROM asset
            WHERE " . implode(' OR ', $where) . "
            ORDER BY CASE " . implode(' ', $orderExact) . " ELSE 1 END ASC
            LIMIT 20";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}


if (!function_exists('assetSearchRowsByBranchName')) {
    function assetSearchRowsByBranchName(PDO $pdo, array $columns, string $branchKeyword): array
    {
        $branchKeyword = trim($branchKeyword);
        if ($branchKeyword === '') {
            return [];
        }

        $availableColumns = [];
        foreach ($columns as $column) {
            $name = (string)($column['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $availableColumns[strtolower($name)] = $name;
            }
        }

        $candidateColumns = [];
        foreach (['as_name', 'branch_name', 'br_name', 'branch_name_th', 'branchname', 'as_branch_name', 'as_br_name', 'location_name', 'office_name'] as $column) {
            if (isset($availableColumns[strtolower($column)])) {
                $candidateColumns[] = $availableColumns[strtolower($column)];
            }
        }

        if (empty($candidateColumns)) {
            return [];
        }

        $where = [];
        $orderExact = [];
        $params = [];
        foreach (array_values(array_unique($candidateColumns)) as $index => $column) {
            $quoted = assetQuoteColumn($column);
            $likeParam = ':branch_keyword_like_' . $index;
            $exactParam = ':branch_keyword_exact_' . $index;
            $where[] = "CAST({$quoted} AS CHAR) LIKE {$likeParam}";
            $orderExact[] = "WHEN TRIM(CAST({$quoted} AS CHAR)) = {$exactParam} THEN 0";
            $params[$likeParam] = '%' . $branchKeyword . '%';
            $params[$exactParam] = $branchKeyword;
        }

        $orderParts = [];
        foreach (['as_name', 'a_id', 'as_list', 'as_code_new'] as $column) {
            if (isset($availableColumns[strtolower($column)])) {
                $orderParts[] = assetQuoteColumn($availableColumns[strtolower($column)]) . ' ASC';
            }
        }
        $orderSql = 'CASE ' . implode(' ', $orderExact) . ' ELSE 1 END ASC';
        if (!empty($orderParts)) {
            $orderSql .= ', ' . implode(', ', $orderParts);
        }

        $sql = "SELECT * FROM asset
            WHERE " . implode(' OR ', $where) . "
            ORDER BY " . $orderSql . "
            LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('assetPickValue')) {
    function assetPickValue(array $asset, string $field)
    {
        // Map column names from Data.asset exactly as used by the asset database.
        // Put exact requested columns first to avoid pulling wrong fallback data.
        $map = [
            'branch_code' => [
                'a_id', 'branch_code', 'br_code', 'b_code', 'cost_center', 'costcenter', 'branch', 'as_branch_code', 'as_branch', 'as_branch_id', 'as_br_code'
            ],
            'branch_name' => [
                'as_name', 'branch_name', 'br_name', 'branch_name_th', 'branchname', 'as_branch_name', 'as_br_name', 'location_name', 'office_name'
            ],
            'asset_new_code' => [
                'as_code_new', 'asset_code', 'asset_no', 'asset_number', 'asset_id', 'new_asset_code', 'new_asset_no', 'new_code', 'code', 'as_code', 'as_id', 'as_number', 'as_new_code', 'as_asset_code'
            ],
            'asset_old_code' => [
                'as_code_old', 'old_asset_code', 'old_asset_no', 'old_asset_number', 'old_asset_id', 'old_code', 'asset_old_code', 'asset_old_no', 'as_old_code', 'as_old_asset', 'old_asset'
            ],
            'received_date' => [
                'as_day', 'received_date', 'receive_date', 'date_received', 'received_at', 'asset_received_date', 'asset_in_date', 'as_date', 'created_at'
            ],
            'remaining_price' => [
                'as_price', 'remaining_price', 'remain_price', 'remain_value', 'net_book_value', 'book_value', 'nbv', 'asset_remaining_price', 'as_remaining_price', 'as_remain_price', 'as_balance', 'balance_price', 'price_balance', 'cost_remaining', 'price'
            ],
            'asset_item' => [
                'as_list', 'asset_name', 'name', 'item_name', 'asset_desc', 'description', 'asset_description', 'as_detail', 'as_item', 'item', 'รายการทรัพย์สิน'
            ],
            'age_base_date' => [
                'as_day'
            ],
        ];

        return assetValueByCandidates($asset, $map[$field] ?? []);
    }
}

if (!function_exists('assetBranchAssetRows')) {
    function assetBranchAssetRows(PDO $pdo, array $columns, string $branchCode, string $branchName = ''): array
    {
        $branchCode = trim($branchCode);
        $branchName = trim($branchName);
        if ($branchCode === '' && $branchName === '') {
            return [];
        }

        $availableColumns = [];
        foreach ($columns as $column) {
            $name = (string)($column['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $availableColumns[strtolower($name)] = $name;
            }
        }

        if ($branchCode !== '' && !isset($availableColumns['a_id'])) {
            return [];
        }
        if ($branchName !== '' && !isset($availableColumns['as_name'])) {
            return [];
        }

        $displayColumns = ['a_id', 'as_name', 'as_code_new', 'as_code_old', 'as_day', 'as_price', 'as_price1', 'as_come', 'as_list'];
        $selectParts = [];
        foreach ($displayColumns as $column) {
            if (isset($availableColumns[strtolower($column)])) {
                $selectParts[] = assetQuoteColumn($availableColumns[strtolower($column)]);
            } else {
                $selectParts[] = 'NULL AS ' . assetQuoteColumn($column);
            }
        }

        $orderParts = [];
        foreach (['as_list', 'as_code_new', 'as_code_old'] as $column) {
            if (isset($availableColumns[strtolower($column)])) {
                $orderParts[] = assetQuoteColumn($availableColumns[strtolower($column)]) . ' ASC';
            }
        }
        $orderSql = !empty($orderParts) ? ' ORDER BY ' . implode(', ', $orderParts) : '';

        $where = [];
        $params = [];

        if ($branchCode !== '' && isset($availableColumns['a_id'])) {
            $where[] = 'CAST(' . assetQuoteColumn($availableColumns['a_id']) . ' AS CHAR) = :branch_code';
            $params[':branch_code'] = $branchCode;
        }

        // When a user clicks the branch name, filter by the exact branch name too.
        // This prevents showing every asset under the same branch code when names differ.
        if ($branchName !== '' && isset($availableColumns['as_name'])) {
            $where[] = 'TRIM(CAST(' . assetQuoteColumn($availableColumns['as_name']) . ' AS CHAR)) = :branch_name';
            $params[':branch_name'] = $branchName;
        }

        if (empty($where)) {
            return [];
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . '
            FROM asset
            WHERE ' . implode(' AND ', $where)
            . $orderSql;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}



if (!function_exists('assetColumnLabel')) {
    function assetColumnLabel(array $column): string
    {
        $comment = trim((string)($column['COLUMN_COMMENT'] ?? ''));
        if ($comment !== '') {
            return $comment;
        }

        $name = (string)($column['COLUMN_NAME'] ?? '');
        $labels = [
            'a_id' => 'รหัสสาขา',
            'as_name' => 'ชื่อสาขา',
            'as_code_new' => 'รหัสทรัพย์สินใหม่',
            'as_code_old' => 'รหัสทรัพย์สินเก่า',
            'as_day' => 'วันที่รับทรัพย์สินเข้า',
            'as_price' => 'ราคาคงเหลือ',
            'as_price1' => 'ราคาต้นทุน',
            'as_come' => 'ตำแหน่ง',
            'as_list' => 'รายการทรัพย์สิน',
        ];

        $key = strtolower($name);
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $name));
    }
}

if (!function_exists('assetFindFullDetailRow')) {
    function assetFindFullDetailRow(PDO $pdo, array $columns, string $assetCode): ?array
    {
        $assetCode = trim($assetCode);
        if ($assetCode === '') {
            return null;
        }

        $availableColumns = [];
        foreach ($columns as $column) {
            $name = (string)($column['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $availableColumns[strtolower($name)] = $name;
            }
        }

        $candidateColumns = [];
        foreach (['as_code_new', 'as_code_old', 'asset_code', 'asset_no', 'asset_number', 'asset_id', 'code', 'serial', 'serial_no', 'sn', 'barcode'] as $column) {
            if (isset($availableColumns[strtolower($column)])) {
                $candidateColumns[] = $availableColumns[strtolower($column)];
            }
        }

        if (empty($candidateColumns)) {
            $candidateColumns = assetFindCandidateColumns($columns);
        }

        if (empty($candidateColumns)) {
            return null;
        }

        $where = [];
        $orderExact = [];
        $params = [];

        foreach (array_values(array_unique($candidateColumns)) as $index => $column) {
            $quoted = assetQuoteColumn($column);
            $exactParam = ':detail_exact_' . $index;
            $likeParam = ':detail_like_' . $index;
            $orderExactParam = ':detail_order_exact_' . $index;

            $where[] = "CAST({$quoted} AS CHAR) = {$exactParam}";
            $where[] = "CAST({$quoted} AS CHAR) LIKE {$likeParam}";
            $orderExact[] = "WHEN CAST({$quoted} AS CHAR) = {$orderExactParam} THEN 0";
            $params[$exactParam] = $assetCode;
            $params[$likeParam] = '%' . $assetCode . '%';
            $params[$orderExactParam] = $assetCode;
        }

        $sql = "SELECT * FROM asset
            WHERE " . implode(' OR ', $where) . "
            ORDER BY CASE " . implode(' ', $orderExact) . " ELSE 1 END ASC
            LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

$assetKeyword = trim((string)($_GET['asset_code'] ?? ''));
$branchSearchKeyword = trim((string)($_GET['branch_search'] ?? ''));
$hasAssetSearch = ($assetKeyword !== '' || $branchSearchKeyword !== '');
$assetValidationError = '';
if ($assetKeyword !== '' && $branchSearchKeyword !== '') {
    $assetValidationError = 'กรุณาค้นหาเพียงอย่างใดอย่างหนึ่ง: รหัสทรัพย์สิน หรือชื่อสาขา';
} elseif ($assetKeyword !== '' && !preg_match('/^\d{9}$/', $assetKeyword)) {
    $assetValidationError = 'กรุณากรอกรหัสทรัพย์สินเป็นตัวเลข 9 หลักเท่านั้น';
}
$selectedBranchCode = trim((string)($_GET['branch_code'] ?? ''));
$selectedBranchName = trim((string)($_GET['branch_name'] ?? ''));
$selectedAssetDetailCode = trim((string)($_GET['asset_detail_code'] ?? ''));
$assetRows = [];
$assetColumns = [];
$branchAssetRows = [];
$assetFullDetailRow = null;
$branchAssetError = '';
$assetError = '';
$assetDbName = $DATA_DB_NAME ?? 'Data';

if ($assetValidationError !== '') {
    $assetError = $assetValidationError;
} elseif ($dataDbError !== '') {
    $assetError = $dataDbError;
} elseif (!$dataPdo instanceof PDO) {
    $assetError = 'ไม่พบการเชื่อมต่อฐานข้อมูล Data';
} else {
    try {
        if (!assetTableExists($dataPdo, $assetDbName, 'asset')) {
            $assetError = 'ไม่พบตาราง asset ในฐานข้อมูล ' . $assetDbName;
        } else {
            $assetColumns = assetGetColumns($dataPdo, $assetDbName, 'asset');
            if ($assetKeyword !== '') {
                $assetRows = assetSearchRows($dataPdo, $assetColumns, $assetKeyword);
            } elseif ($branchSearchKeyword !== '') {
                $assetRows = assetSearchRowsByBranchName($dataPdo, $assetColumns, $branchSearchKeyword);
            }
            if ($selectedBranchCode !== '' && $selectedAssetDetailCode === '') {
                $branchAssetRows = assetBranchAssetRows($dataPdo, $assetColumns, $selectedBranchCode, $selectedBranchName);
            }
            if ($selectedAssetDetailCode !== '') {
                $assetFullDetailRow = assetFindFullDetailRow($dataPdo, $assetColumns, $selectedAssetDetailCode);
            }
        }
    } catch (Throwable $e) {
        $assetError = 'ค้นหาข้อมูลไม่สำเร็จ: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';

require_login();
require_permission('asset.view');

?>

<style>
.asset-search-control > .form-label {
    font-size: 1rem;
    font-weight: 800;
    color: #17324d;
}

.asset-search-control .form-control {
    font-size: 1rem;
    min-height: 42px;
}

@media (max-width: 1366px) {
    .asset-search-control > .form-label {
        font-size: .95rem;
    }

    .asset-search-control .form-control {
        font-size: .95rem;
        min-height: 40px;
    }
}
    .asset-page{--asset-blue:#0f4c81;--asset-border:#dbe5ee}
    .asset-hero {
        background: linear-gradient(135deg,#0b3c68,#1769aa);
        border-radius: 18px;
        padding: 22px;
        color: #fff;
        margin-bottom: 18px;
        box-shadow: 0 12px 30px rgba(15,76,129,.18);
        overflow: hidden;
    }
    .asset-hero h1{font-size:1.35rem;font-weight:700;margin:0 0 5px}
    .asset-hero p{margin:0;opacity:.86;font-size:.9rem}
    .asset-search-box {
        background:#fff;
        border:1px solid #e3ebf2;
        border-radius:16px;
        padding:16px;
        box-shadow:0 5px 18px rgba(20,46,70,.07);
        margin-bottom:18px;
    }
    .asset-search-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .asset-search-icon{width:34px;height:34px;border-radius:10px;background:#eaf3fb;color:#0f4c81;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;flex:0 0 34px}
    .asset-search-title{font-size:.95rem;font-weight:800;color:#17324d;margin:0}
    .asset-search-subtitle{font-size:.76rem;color:#6b7a88;margin:2px 0 0}
    .asset-search-row {
        display:grid;
        grid-template-columns:minmax(220px,1fr) minmax(240px,1.15fr) 108px 88px;
        gap:10px;
        align-items:end;
    }
    .asset-search-control,.asset-search-control .input-group,.asset-search-control .form-control{min-width:0}
    .asset-search-control .input-group-text{width:42px;justify-content:center;flex:0 0 42px;background:#f5f8fb;border-color:#dbe5ee;font-size:.9rem}
    .asset-search-control .form-control{font-size:.86rem;min-height:40px;text-overflow:ellipsis}
    .asset-search-submit,.asset-search-reset{min-width:0}
    .asset-search-submit .btn,.asset-search-reset .btn{min-height:40px;font-size:.82rem;font-weight:700}
    .asset-card {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
    }
    .asset-result-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
    }
    .asset-result-title .dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: #2563eb;
        box-shadow: 0 0 0 5px rgba(37, 99, 235, .12);
    }
    .asset-detail-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }
    .asset-field {
        border: 1px solid #dbeafe;
        border-radius: 16px;
        padding: 12px 14px;
        background: #f8fafc;
        min-height: 86px;
    }
    .asset-field.primary {
        background: #eff6ff;
        border-color: #bfdbfe;
    }
    .asset-field.warning {
        background: #fff7ed;
        border-color: #fed7aa;
    }
    .asset-field .label {
        color: #64748b;
        font-size: .78rem;
        font-weight: 800;
        margin-bottom: 5px;
    }
    .asset-field .value {
        color: #0f172a;
        font-weight: 800;
        font-size: .98rem;
        word-break: break-word;
        line-height: 1.35;
    }
    .asset-field .value.main {
        color: #1d4ed8;
        font-size: 1.14rem;
    }
    .asset-age-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 7px 12px;
        background: #dbeafe;
        color: #1e40af;
        font-weight: 900;
    }
    .asset-age-badge.over-age {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    /* อายุทรัพย์สินเกิน 5 ปี: ใช้กับผลค้นหาและตารางทรัพย์สินของสาขาที่เลือก */
    .asset-branch-search-table .asset-age-badge.over-age,
    .asset-branch-table .asset-age-badge.over-age {
        animation: assetAgeOverFivePulse 1.15s linear infinite;
        transform-origin: center;
        will-change: transform, box-shadow, background-color;
    }

    @keyframes assetAgeOverFivePulse {
        0%, 100% {
            transform: scale(1);
            background-color: #fee2e2;
            box-shadow: 0 0 0 0 rgba(220, 38, 38, .20);
        }
        50% {
            transform: scale(1.06);
            background-color: #fecaca;
            box-shadow: 0 0 0 6px rgba(220, 38, 38, 0);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .asset-branch-search-table .asset-age-badge.over-age,
        .asset-branch-table .asset-age-badge.over-age {
            animation: none;
        }
    }


    .asset-detail-link {
        color: #1d4ed8;
        text-decoration: none;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .asset-detail-link:hover {
        color: #0f766e;
        text-decoration: underline;
    }
    .asset-full-detail-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .asset-full-detail-item {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
        padding: 10px 12px;
        min-height: 74px;
    }
    .asset-full-detail-item .label {
        color: #64748b;
        font-size: .76rem;
        font-weight: 800;
        margin-bottom: 4px;
    }
    .asset-full-detail-item .value {
        color: #0f172a;
        font-size: .9rem;
        font-weight: 700;
        line-height: 1.35;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .asset-full-detail-item.primary {
        background: #eff6ff;
        border-color: #bfdbfe;
    }

    .asset-branch-link {
        color: #1d4ed8;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-weight: 900;
    }
    .asset-branch-link:hover {
        color: #1d4ed8;
        text-decoration: underline;
    }
    .asset-branch-hint {
        font-size: .74rem;
        color: #64748b;
        margin-top: 4px;
    }
    .asset-branch-table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
    }
    .asset-branch-table th,
    .asset-branch-table td {
        vertical-align: middle;
        font-size: .76rem;
        line-height: 1.25;
        padding: .38rem .42rem;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .asset-branch-table th {
        font-weight: 800;
        color: #0f172a;
        background: #f8fafc;
    }
    .asset-branch-table .text-wrap-col {
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .asset-branch-table th:nth-child(1),
    .asset-branch-table td:nth-child(1) { width: 4.5%; text-align: center; }
    .asset-branch-table th:nth-child(2),
    .asset-branch-table td:nth-child(2) { width: 7.5%; }
    .asset-branch-table th:nth-child(3),
    .asset-branch-table td:nth-child(3) { width: 15.5%; }
    .asset-branch-table th:nth-child(4),
    .asset-branch-table td:nth-child(4) { width: 12.5%; }
    .asset-branch-table th:nth-child(5),
    .asset-branch-table td:nth-child(5) { width: 10%; }
    .asset-branch-table th:nth-child(6),
    .asset-branch-table td:nth-child(6) { width: 10%; }
    .asset-branch-table th:nth-child(7),
    .asset-branch-table td:nth-child(7) { width: 9%; }
    .asset-branch-table th:nth-child(8),
    .asset-branch-table td:nth-child(8) { width: 20%; }
    .asset-branch-table th:nth-child(9),
    .asset-branch-table td:nth-child(9) { width: 11%; }
    .asset-branch-table .asset-age-badge {
        padding: 4px 7px;
        border-radius: 12px;
        font-size: .72rem;
        line-height: 1.2;
        white-space: normal;
        text-align: center;
    }


    .asset-branch-search-table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
    }
    .asset-branch-search-table th,
    .asset-branch-search-table td {
        vertical-align: middle;
        font-size: .74rem;
        line-height: 1.22;
        padding: .34rem .38rem;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .asset-branch-search-table th {
        font-weight: 900;
        color: #0f172a;
        background: #f8fafc;
        text-align: center;
    }
    .asset-branch-search-table th:nth-child(1),
    .asset-branch-search-table td:nth-child(1) { width: 9%; text-align: center; }
    .asset-branch-search-table th:nth-child(2),
    .asset-branch-search-table td:nth-child(2) { width: 20%; }
    .asset-branch-search-table th:nth-child(3),
    .asset-branch-search-table td:nth-child(3) { width: 15%; }
    .asset-branch-search-table th:nth-child(4),
    .asset-branch-search-table td:nth-child(4) { width: 22%; }
    .asset-branch-search-table th:nth-child(5),
    .asset-branch-search-table td:nth-child(5) { width: 11%; text-align: center; }
    .asset-branch-search-table th:nth-child(6),
    .asset-branch-search-table td:nth-child(6) { width: 11%; text-align: right; }
    .asset-branch-search-table th:nth-child(7),
    .asset-branch-search-table td:nth-child(7) { width: 12%; text-align: center; }
    .asset-branch-search-table .asset-detail-link {
        font-size: .80rem;
        font-weight: 700;
        line-height: 1.3;
    }
    .asset-branch-search-table .asset-age-badge {
        padding: 4px 6px;
        border-radius: 12px;
        font-size: .68rem;
        line-height: 1.15;
        white-space: normal;
        text-align: center;
    }
    .asset-branch-search-table .money-cell {
        font-weight: 800;
        color: #7c2d12;
        white-space: normal;
    }
    .asset-branch-search-table .branch-cell,
    .asset-branch-search-table .item-cell {
        text-align: left;
        font-size: .80rem;
        font-weight: 600;
    }


    .asset-detail-popup-modal .modal-dialog {
        max-width: 720px;
    }
    .asset-detail-popup-modal .modal-content {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 22px 60px rgba(15, 23, 42, .25);
        overflow: hidden;
    }
    .asset-detail-popup-modal .modal-header {
        background: linear-gradient(135deg, #2563eb, #0ea5e9);
        color: #ffffff;
        border-bottom: 0;
        padding: 10px 14px;
    }
    .asset-detail-popup-modal .modal-title {
        font-size: .92rem;
        font-weight: 900;
    }
    .asset-detail-popup-modal .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
        opacity: .9;
    }
    .asset-detail-popup-table-wrap {
        border: 1px solid #dbe5ee;
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }
    .asset-detail-popup-table {
        width: 100%;
        margin: 0;
        table-layout: fixed;
    }
    .asset-detail-popup-table th,
    .asset-detail-popup-table td {
        padding: .42rem .55rem;
        border-color: #dbe5ee;
        vertical-align: middle;
        font-size: .86rem;
        line-height: 1.35;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .asset-detail-popup-table th {
        width: 30%;
        background: #f1f5f9;
        color: #475569;
        font-weight: 900;
        white-space: nowrap;
    }
    .asset-detail-popup-table td {
        color: #0f172a;
        font-weight: 800;
        background: #fff;
    }
    .asset-detail-popup-table tr:nth-child(even) td {
        background: #f8fafc;
    }
    .asset-highlight-red {
        color: #dc2626 !important;
    }
    .asset-branch-name-blue,
    .asset-branch-name-blue .asset-branch-link {
        color: #1d4ed8 !important;
        font-weight: 800;
    }
    .asset-branch-name-blue .asset-branch-link:hover {
        color: #1e40af !important;
    }
    .asset-search-value-red,
    .asset-search-value-red .asset-branch-link,
    .asset-search-value-red .asset-detail-link,
    .asset-branch-asset-code-red,
    .asset-branch-asset-code-red .asset-detail-link {
        color: #dc2626 !important;
    }
    .asset-search-value-red .asset-branch-link:hover,
    .asset-search-value-red .asset-detail-link:hover,
    .asset-branch-asset-code-red .asset-detail-link:hover {
        color: #b91c1c !important;
    }
    .asset-detail-popup-button {
        border: 0;
        background: transparent;
        cursor: pointer;
    }
    .asset-detail-popup-button:hover {
        text-decoration: underline;
    }
    .asset-detail-popup-value-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        width: 100%;
    }
    .asset-detail-popup-value-text {
        min-width: 0;
        flex: 1 1 auto;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .asset-copy-button {
        flex: 0 0 auto;
        width: 22px;
        height: 22px;
        padding: 0;
        border: 1px solid #93c5fd;
        border-radius: 7px;
        background: #eff6ff;
        color: #1d4ed8;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        line-height: 1;
        transition: .15s ease-in-out;
    }
    .asset-copy-button svg {
        width: 14px;
        height: 14px;
        pointer-events: none;
    }
    .asset-copy-button:hover,
    .asset-copy-button:focus {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }
    .asset-copy-button.copied {
        background: #dcfce7;
        border-color: #86efac;
        color: #166534;
    }
    .asset-inline-copy-wrap {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        max-width: 100%;
    }
    .asset-inline-copy-text {
        min-width: 0;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .asset-inline-copy-button {
        flex: 0 0 auto;
        width: 24px;
        height: 24px;
        padding: 0;
        border: 1px solid #bfdbfe;
        border-radius: 7px;
        background: #eff6ff;
        color: #2563eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        line-height: 1;
        transition: .15s ease-in-out;
    }
    .asset-inline-copy-button:hover,
    .asset-inline-copy-button:focus {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }
    .asset-inline-copy-button.copied {
        background: #dcfce7;
        border-color: #86efac;
        color: #166534;
    }
    .asset-inline-copy-button svg {
        width: 13px;
        height: 13px;
        pointer-events: none;
    }
    @media (max-width: 576px) {
        .asset-detail-popup-table th,
        .asset-detail-popup-table td {
            padding: .55rem .6rem;
            font-size: .84rem;
        }
        .asset-detail-popup-table th {
            width: 38%;
            white-space: normal;
        }
    }

    @media (max-width: 1366px) {
        .asset-page{margin-left:-4px;margin-right:-4px}
        .asset-hero{padding:18px;margin-bottom:14px}
        .asset-search-box{padding:14px;margin-bottom:14px}
        .asset-search-row{grid-template-columns:minmax(180px,.95fr) minmax(210px,1.05fr) 96px 78px;gap:8px}
        .asset-search-control .form-control{font-size:.78rem;min-height:36px}
        .asset-search-control .input-group-text{width:38px;flex-basis:38px;font-size:.8rem}
        .asset-search-submit .btn,.asset-search-reset .btn{min-height:36px;font-size:.74rem;padding-left:.5rem;padding-right:.5rem}
        .asset-branch-table th,
        .asset-branch-table td {
            font-size: .72rem;
            padding: .34rem .36rem;
        }
        .asset-branch-table .asset-age-badge {
            font-size: .68rem;
            padding: 3px 6px;
        }
    }
    @media (max-width: 1400px) {
        .asset-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .asset-full-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 992px) {
        .asset-search-row{grid-template-columns:1fr 1fr}
        .asset-search-submit .btn,.asset-search-reset .btn{width:100%}
    }
    @media (max-width: 768px) {
        .asset-detail-grid { grid-template-columns: 1fr; }
        .asset-full-detail-grid { grid-template-columns: 1fr; }
        .asset-hero{padding:16px}
        .asset-search-row{grid-template-columns:1fr}
    }
</style>

<div class="asset-page pb-4">
<div class="asset-hero">
    <div>
        <h1>ค้นหาข้อมูลทรัพย์สิน</h1>
        <!-- <p>ค้นหาจากรหัสทรัพย์สินหรือชื่อสาขา เพื่อดูข้อมูลหลักและรายละเอียดทรัพย์สิน</p> -->
    </div>
</div>

<form method="get" class="asset-search-box" autocomplete="off">
    <!-- <div class="asset-search-header">
        <span class="asset-search-icon">⌕</span>
        <div>
            <div class="asset-search-title">ค้นหาข้อมูลทรัพย์สิน</div>
            <div class="asset-search-subtitle">กรอกอย่างใดอย่างหนึ่ง: รหัสทรัพย์สิน 9 หลัก หรือชื่อสาขา</div>
        </div>
    </div> -->
    <div class="asset-search-row">
        <div class="asset-search-control">
            <label class="form-label small fw-bold mb-1">รหัสทรัพย์สิน</label>
            <div class="input-group">
                <span class="input-group-text">🔍</span>
                <input type="text" name="asset_code" id="assetCodeInput" class="form-control" value="" placeholder="ตัวเลข 9 หลัก" inputmode="numeric" pattern="[0-9]{9}" minlength="9" maxlength="9" autocomplete="off" autofocus>
            </div>
        </div>
        <div class="asset-search-control">
            <label class="form-label small fw-bold mb-1">ชื่อสาขา</label>
            <div class="input-group">
                <span class="input-group-text">🏢</span>
                <input type="text" name="branch_search" id="branchSearchInput" class="form-control" value="" placeholder="ตัวอย่าง: บางพลัด" autocomplete="off">
            </div>
        </div>
        <div class="asset-search-submit d-grid"><button type="submit" class="btn btn-primary">ค้นหา</button></div>
        <div class="asset-search-reset d-grid"><a href="index.php" class="btn btn-outline-secondary">ล้างค่า</a></div>
    </div>
</form>

<?php if ($assetError !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm"><?php echo assetE($assetError); ?></div>
<?php endif; ?>

<?php if ($hasAssetSearch && $assetError === ''): ?>
    <!-- <div class="asset-card p-3 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">ผลการค้นหา</div>
                <div class="text-muted small">Keyword: <strong><?php echo assetE($assetKeyword !== '' ? $assetKeyword : $branchSearchKeyword); ?></strong></div>
            </div>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">พบ <?php echo number_format(count($assetRows)); ?> รายการ</span>
        </div>
    </div> -->

    <?php if (empty($assetRows)): ?>
        <div class="alert alert-warning">ไม่พบข้อมูลทรัพย์สินตามเงื่อนไขที่ค้นหา</div>
    <?php elseif ($branchSearchKeyword !== '' || $assetKeyword !== ''): ?>
        <div class="asset-card p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <div class="fw-bold"><?php echo $assetKeyword !== '' ? 'ผลการค้นหาจากรหัสทรัพย์สิน' : 'ผลการค้นหาจากชื่อสาขา'; ?></div>
                    <!-- <div class="text-muted small">แสดงผลแบบตาราง เพื่อให้ดูข้อมูลได้กระชับและไม่ต้องเลื่อนซ้าย-ขวา</div> -->
                </div>
                <span class="badge rounded-pill text-bg-primary px-3 py-2">พบ <?php echo number_format(count($assetRows)); ?> รายการ</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover asset-branch-search-table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>รหัสสาขา</th>
                            <th>ชื่อสาขา</th>
                            <th>รหัสทรัพย์สินใหม่</th>
                            <th>รายการทรัพย์สิน</th>
                            <th>วันที่รับทรัพย์สินเข้า</th>
                            <th>ราคาคงเหลือ</th>
                            <th>อายุทรัพย์สิน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assetRows as $asset): ?>
                            <?php
                                $branchCode = assetPickValue($asset, 'branch_code');
                                $branchName = assetPickValue($asset, 'branch_name');
                                $assetNewCode = assetPickValue($asset, 'asset_new_code');
                                $assetItem = assetPickValue($asset, 'asset_item');
                                $receivedDateValue = assetPickValue($asset, 'received_date');
                                $remainingPrice = assetPickValue($asset, 'remaining_price');
                                $ageBaseDate = assetPickValue($asset, 'age_base_date');
                                if ($ageBaseDate === null) {
                                    $ageBaseDate = $receivedDateValue;
                                }
                                $assetAge = assetCalculateAgeFromDate($ageBaseDate);
                                $assetAgeOverLimit = assetAgeExceedsYears($ageBaseDate, 5);
                                $assetCodeText = assetFormatText($assetNewCode);
                                $assetOldCode = assetPickValue($asset, 'asset_old_code');
                                $assetCostPrice = assetValueByCandidates($asset, ['as_price1']);
                                $assetLocation = assetValueByCandidates($asset, ['as_come']);
                                $assetDetailLink = '';
                                if ($assetCodeText !== '-') {
                                    $assetDetailLink = 'index.php?' . http_build_query([
                                        'asset_code' => $assetKeyword,
                                        'branch_search' => $branchSearchKeyword,
                                        'branch_code' => assetFormatText($branchCode) !== '-' ? (string)$branchCode : '',
                                        'branch_name' => assetFormatText($branchName) !== '-' ? assetFormatText($branchName) : '',
                                        'asset_detail_code' => $assetCodeText,
                                    ]) . '#asset-full-detail';
                                }
                            ?>
                            <tr>
                                <td class="fw-bold text-center"><?php echo assetE(assetFormatText($branchCode)); ?></td>
                                <td class="branch-cell asset-branch-name-blue">
                                    <?php
                                        $branchLink = '';
                                        if (assetFormatText($branchCode) !== '-' && assetFormatText($branchName) !== '-') {
                                            $branchLink = 'index.php?' . http_build_query([
                                                'asset_code' => $assetKeyword,
                                                'branch_search' => $branchSearchKeyword,
                                                'branch_code' => (string)$branchCode,
                                                'branch_name' => assetFormatText($branchName),
                                            ]) . '#branch-assets';
                                        }
                                    ?>
                                    <span class="asset-inline-copy-wrap">
                                        <span class="asset-inline-copy-text">
                                            <?php if ($branchLink !== ''): ?>
                                                <a href="<?php echo assetE($branchLink); ?>" class="asset-branch-link" title="คลิกเพื่อดูทรัพย์สินทั้งหมดในสาขานี้">
                                                    <?php echo assetE(assetDisplayBranchName($branchName)); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo assetE(assetDisplayBranchName($branchName)); ?>
                                            <?php endif; ?>
                                        </span>
                                        <button type="button" class="asset-inline-copy-button js-asset-copy" data-copy-text="<?php echo assetE(assetDisplayBranchName($branchName)); ?>" title="คัดลอกชื่อสาขา" aria-label="คัดลอกชื่อสาขา">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        </button>
                                    </span>
                                </td>
                                <td class="fw-bold text-center asset-search-value-red">
                                    <span class="asset-inline-copy-wrap">
                                        <span class="asset-inline-copy-text">
                                            <?php if ($assetCodeText !== '-'): ?>
                                                <button type="button"
                                                        class="asset-detail-link asset-detail-popup-button js-asset-detail-popup p-0"
                                                        title="คลิกเพื่อดูรายละเอียดทรัพย์สินทั้งหมด"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#assetDetailPopupModal"
                                                        data-branch-code="<?php echo assetE(assetFormatText($branchCode)); ?>"
                                                        data-branch-name="<?php echo assetE(assetFormatText($branchName)); ?>"
                                                        data-asset-new-code="<?php echo assetE($assetCodeText); ?>"
                                                        data-asset-old-code="<?php echo assetE(assetFormatText($assetOldCode)); ?>"
                                                        data-asset-item="<?php echo assetE(assetFormatText($assetItem)); ?>"
                                                        data-received-date="<?php echo assetE(assetFormatDateThai($receivedDateValue)); ?>"
                                                        data-cost-price="<?php echo assetE(assetFormatMoney($assetCostPrice)); ?>"
                                                        data-remaining-price="<?php echo assetE(assetFormatMoney($remainingPrice)); ?>"
                                                        data-location="<?php echo assetE(assetFormatText($assetLocation)); ?>"
                                                        data-age="<?php echo assetE($assetAge); ?>"
                                                        data-age-over="<?php echo $assetAgeOverLimit ? '1' : '0'; ?>">
                                                    <?php echo assetE($assetCodeText); ?>
                                                </button>
                                            <?php else: ?>
                                                <?php echo assetE($assetCodeText); ?>
                                            <?php endif; ?>
                                        </span>
                                        <button type="button" class="asset-inline-copy-button js-asset-copy" data-copy-text="<?php echo assetE($assetCodeText); ?>" title="คัดลอกรหัสทรัพย์สินใหม่" aria-label="คัดลอกรหัสทรัพย์สินใหม่">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        </button>
                                    </span>
                                </td>
                                <td class="item-cell"><span class="asset-inline-copy-wrap"><span class="asset-inline-copy-text"><?php echo assetE(assetFormatText($assetItem)); ?></span><button type="button" class="asset-inline-copy-button js-asset-copy" data-copy-text="<?php echo assetE(assetFormatText($assetItem)); ?>" title="คัดลอกรายการทรัพย์สิน" aria-label="คัดลอกรายการทรัพย์สิน"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button></span></td>
                                <td><?php echo assetE(assetFormatDateThai($receivedDateValue)); ?></td>
                                <td class="money-cell"><?php echo assetE(assetFormatMoney($remainingPrice)); ?></td>
                                <td><span class="asset-age-badge<?php echo $assetAgeOverLimit ? ' over-age' : ''; ?>"><?php echo assetE($assetAge); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($selectedBranchCode !== '' && $selectedAssetDetailCode === ''): ?>
            <?php
                $branchSummaryName = $selectedBranchName !== '' ? $selectedBranchName : '-';
                if ($branchSummaryName === '-' && !empty($branchAssetRows)) {
                    $branchSummaryName = assetFormatText($branchAssetRows[0]['as_name'] ?? '');
                }
            ?>
            <div class="asset-card p-3 mb-3" id="branch-assets">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <div class="fw-bold">รายการทรัพย์สินทั้งหมดของสาขาที่เลือก</div>
                        <div class="text-muted small">
                            รหัสสาขา: <strong><?php echo assetE($selectedBranchCode); ?></strong>
                            <?php if ($branchSummaryName !== '-'): ?>
                                | ชื่อสาขา: <strong><?php echo assetE($branchSummaryName); ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge rounded-pill text-bg-primary px-3 py-2">พบ <?php echo number_format(count($branchAssetRows)); ?> รายการ</span>
                </div>

                <?php if (empty($branchAssetRows)): ?>
                    <div class="alert alert-warning mb-0">ไม่พบรายการทรัพย์สินในสาขานี้</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered asset-branch-table mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>รหัสสาขา</th>
                                    <th>ชื่อสาขา</th>
                                    <th>รหัสทรัพย์สินใหม่</th>
                                    <th>รหัสทรัพย์สินเก่า</th>
                                    <th>วันที่รับเข้า</th>
                                    <th>ราคาคงเหลือ</th>
                                    <th>รายการทรัพย์สิน</th>
                                    <th>อายุทรัพย์สิน</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($branchAssetRows as $rowIndex => $branchAsset): ?>
                                    <?php
                                        $rowAgeBaseDate = $branchAsset['as_day'] ?? null;
                                        $rowAssetAge = assetCalculateAgeFromDate($rowAgeBaseDate);
                                        $rowAssetAgeOverLimit = assetAgeExceedsYears($rowAgeBaseDate, 5);
                                        $rowAssetCode = assetFormatText($branchAsset['as_code_new'] ?? null);
                                    ?>
                                    <tr>
                                        <td class="text-center"><?php echo number_format($rowIndex + 1); ?></td>
                                        <td class="fw-bold"><?php echo assetE(assetFormatText($branchAsset['a_id'] ?? null)); ?></td>
                                        <td class="text-wrap-col asset-branch-name-blue"><?php echo assetE(assetDisplayBranchName($branchAsset['as_name'] ?? null)); ?></td>
                                        <td class="fw-bold asset-branch-asset-code-red">
                                            <?php if ($rowAssetCode !== '-'): ?>
                                                <button type="button"
                                                        class="asset-detail-link asset-detail-popup-button js-asset-detail-popup p-0"
                                                        title="คลิกเพื่อดูรายละเอียดทรัพย์สินทั้งหมด"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#assetDetailPopupModal"
                                                        data-branch-code="<?php echo assetE(assetFormatText($branchAsset['a_id'] ?? null)); ?>"
                                                        data-branch-name="<?php echo assetE(assetFormatText($branchAsset['as_name'] ?? null)); ?>"
                                                        data-asset-new-code="<?php echo assetE($rowAssetCode); ?>"
                                                        data-asset-old-code="<?php echo assetE(assetFormatText($branchAsset['as_code_old'] ?? null)); ?>"
                                                        data-asset-item="<?php echo assetE(assetFormatText($branchAsset['as_list'] ?? null)); ?>"
                                                        data-received-date="<?php echo assetE(assetFormatDateThai($branchAsset['as_day'] ?? null)); ?>"
                                                        data-cost-price="<?php echo assetE(assetFormatMoney($branchAsset['as_price1'] ?? null)); ?>"
                                                        data-remaining-price="<?php echo assetE(assetFormatMoney($branchAsset['as_price'] ?? null)); ?>"
                                                        data-location="<?php echo assetE(assetFormatText($branchAsset['as_come'] ?? null)); ?>"
                                                        data-age="<?php echo assetE($rowAssetAge); ?>"
                                                        data-age-over="<?php echo $rowAssetAgeOverLimit ? '1' : '0'; ?>">
                                                    <?php echo assetE($rowAssetCode); ?>
                                                </button>
                                            <?php else: ?>
                                                <?php echo assetE($rowAssetCode); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo assetE(assetFormatText($branchAsset['as_code_old'] ?? null)); ?></td>
                                        <td><?php echo assetE(assetFormatDateThai($branchAsset['as_day'] ?? null)); ?></td>
                                        <td><?php echo assetE(assetFormatMoney($branchAsset['as_price'] ?? null)); ?></td>
                                        <td class="text-wrap-col"><?php echo assetE(assetFormatText($branchAsset['as_list'] ?? null)); ?></td>
                                        <td><span class="asset-age-badge<?php echo $rowAssetAgeOverLimit ? ' over-age' : ''; ?>"><?php echo assetE($rowAssetAge); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($selectedAssetDetailCode !== ''): ?>
            <div class="asset-card p-3 mb-3" id="asset-full-detail">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <div class="fw-bold">รายละเอียดทรัพย์สินทั้งหมด</div>
                        <div class="text-muted small">รหัสทรัพย์สิน: <strong><?php echo assetE($selectedAssetDetailCode); ?></strong></div>
                    </div>
                    <span class="badge rounded-pill text-bg-info px-3 py-2">แสดงข้อมูลหลัก</span>
                </div>

                <?php if (empty($assetFullDetailRow)): ?>
                    <div class="alert alert-warning mb-0">ไม่พบรายละเอียดทรัพย์สินตามรหัสที่เลือก</div>
                <?php else: ?>
                    <?php
                        $orderedDetailFields = [
                            ['label' => 'รหัสสาขา', 'column' => 'a_id', 'type' => 'text'],
                            ['label' => 'ชื่อสาขา', 'column' => 'as_name', 'type' => 'text'],
                            ['label' => 'รหัสทรัพย์สินใหม่', 'column' => 'as_code_new', 'type' => 'text'],
                            ['label' => 'รายการทรัพย์สิน', 'column' => 'as_list', 'type' => 'text'],
                            ['label' => 'รหัสทรัพย์สินเก่า', 'column' => 'as_code_old', 'type' => 'text'],
                            ['label' => 'วันที่รับทรัพย์สินเข้า', 'column' => 'as_day', 'type' => 'date'],
                            ['label' => 'ราคาต้นทุน', 'column' => 'as_price1', 'type' => 'money'],
                            ['label' => 'ราคาคงเหลือ', 'column' => 'as_price', 'type' => 'money'],
                            ['label' => 'ตำแหน่ง', 'column' => 'as_come', 'type' => 'text'],
                            ['label' => 'อายุทรัพย์สิน', 'column' => 'as_day', 'type' => 'age'],
                        ];
                    ?>
                    <div class="asset-full-detail-grid">
                        <?php foreach ($orderedDetailFields as $detailField): ?>
                            <?php
                                $columnName = (string)$detailField['column'];
                                $rawValue = $assetFullDetailRow[$columnName] ?? null;
                                $displayValue = assetFormatText($rawValue);
                                $displayAgeOverLimit = false;

                                if ($detailField['type'] === 'date') {
                                    $displayValue = assetFormatDateThai($rawValue);
                                } elseif ($detailField['type'] === 'money') {
                                    $displayValue = assetFormatMoney($rawValue);
                                } elseif ($detailField['type'] === 'age') {
                                    $displayValue = assetCalculateAgeFromDate($rawValue);
                                    $displayAgeOverLimit = assetAgeExceedsYears($rawValue, 5);
                                }
                            ?>
                            <div class="asset-full-detail-item<?php echo $detailField['type'] === 'age' ? ' primary' : ''; ?>">
                                <div class="label"><?php echo assetE($detailField['label']); ?> <span class="text-muted">(<?php echo assetE($columnName); ?>)</span></div>
                                <div class="value<?php echo $columnName === 'as_name' ? ' asset-branch-name-blue' : (in_array($columnName, ['as_code_new', 'as_list'], true) ? ' asset-highlight-red' : ''); ?>">
                                    <?php if ($detailField['type'] === 'age'): ?>
                                        <span class="asset-age-badge<?php echo !empty($displayAgeOverLimit) ? ' over-age' : ''; ?>"><?php echo assetE($displayValue); ?></span>
                                    <?php else: ?>
                                        <?php echo assetE($displayValue); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php foreach ($assetRows as $index => $asset): ?>
            <?php
                $branchCode = assetPickValue($asset, 'branch_code');
                $branchName = assetPickValue($asset, 'branch_name');
                $branchLink = '';
                if (assetFormatText($branchCode) !== '-') {
                    $branchLink = 'index.php?' . http_build_query([
                        'asset_code' => $assetKeyword,
                        'branch_search' => $branchSearchKeyword,
                        'branch_code' => (string)$branchCode,
                        'branch_name' => assetFormatText($branchName),
                    ]) . '#branch-assets';
                }
                $assetNewCode = assetPickValue($asset, 'asset_new_code');
                $assetDetailLink = '';
                if (assetFormatText($assetNewCode) !== '-') {
                    $assetDetailLink = 'index.php?' . http_build_query([
                        'asset_code' => $assetKeyword,
                        'branch_search' => $branchSearchKeyword,
                        'branch_code' => assetFormatText($branchCode) !== '-' ? (string)$branchCode : '',
                        'branch_name' => assetFormatText($branchName) !== '-' ? assetFormatText($branchName) : '',
                        'asset_detail_code' => assetFormatText($assetNewCode),
                    ]) . '#asset-full-detail';
                }
                $assetOldCode = assetPickValue($asset, 'asset_old_code');
                $receivedDateValue = assetPickValue($asset, 'received_date');
                $remainingPrice = assetPickValue($asset, 'remaining_price');
                $assetItem = assetPickValue($asset, 'asset_item');
                $ageBaseDate = assetPickValue($asset, 'age_base_date');
                if ($ageBaseDate === null) {
                    $ageBaseDate = $receivedDateValue;
                }
                $assetAge = assetCalculateAgeFromDate($ageBaseDate);
                $assetAgeOverLimit = assetAgeExceedsYears($ageBaseDate, 5);
            ?>
            <div class="asset-card p-3 mb-3">
                <div class="asset-result-title">
                    <span class="dot"></span>
                    <div>
                        <div class="fw-bold">รายละเอียดทรัพย์สิน #<?php echo number_format($index + 1); ?></div>
                        <div class="text-muted small">แสดงเฉพาะข้อมูลหลักตามที่กำหนด</div>
                    </div>
                </div>

                <div class="asset-detail-grid">
                    <div class="asset-field">
                        <div class="label">รหัสสาขา</div>
                        <div class="value"><?php echo assetE(assetFormatText($branchCode)); ?></div>
                    </div>
                    <div class="asset-field">
                        <div class="label">ชื่อสาขา</div>
                        <div class="value">
                            <?php if ($branchLink !== '' && assetFormatText($branchName) !== '-'): ?>
                                <a href="<?php echo assetE($branchLink); ?>" class="asset-branch-link" title="คลิกเพื่อดูทรัพย์สินทั้งหมดในสาขานี้">
                                    <?php echo assetE(assetFormatText($branchName)); ?>
                                </a>
                                <div class="asset-branch-hint"></div>
                            <?php else: ?>
                                <?php echo assetE(assetFormatText($branchName)); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="asset-field primary">
                        <div class="label">รหัสทรัพย์สินใหม่</div>
                        <div class="value main">
                            <?php if ($assetDetailLink !== ''): ?>
                                <a href="<?php echo assetE($assetDetailLink); ?>" class="asset-detail-link" title="คลิกเพื่อดูรายละเอียดทรัพย์สินทั้งหมด">
                                    <?php echo assetE(assetFormatText($assetNewCode)); ?>
                                </a>
                                <div class="asset-branch-hint"></div>
                            <?php else: ?>
                                <?php echo assetE(assetFormatText($assetNewCode)); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="asset-field">
                        <div class="label">รหัสทรัพย์สินเก่า</div>
                        <div class="value"><?php echo assetE(assetFormatText($assetOldCode)); ?></div>
                    </div>
                    <div class="asset-field">
                        <div class="label">วันที่รับทรัพย์สินเข้า</div>
                        <div class="value"><?php echo assetE(assetFormatDateThai($receivedDateValue)); ?></div>
                    </div>
                    <div class="asset-field warning">
                        <div class="label">ราคาคงเหลือ</div>
                        <div class="value"><?php echo assetE(assetFormatMoney($remainingPrice)); ?></div>
                    </div>
                    <div class="asset-field">
                        <div class="label">รายการทรัพย์สิน</div>
                        <div class="value"><?php echo assetE(assetFormatText($assetItem)); ?></div>
                    </div>
                    <div class="asset-field primary">
                        <div class="label">อายุทรัพย์สิน</div>
                        <div class="value"><span class="asset-age-badge<?php echo $assetAgeOverLimit ? ' over-age' : ''; ?>"><?php echo assetE($assetAge); ?></span></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($selectedBranchCode !== '' && $selectedAssetDetailCode === ''): ?>
            <?php
                $branchSummaryName = $selectedBranchName !== '' ? $selectedBranchName : '-';
                if ($branchSummaryName === '-' && !empty($branchAssetRows)) {
                    $branchSummaryName = assetFormatText($branchAssetRows[0]['as_name'] ?? '');
                } else {
                    foreach ($assetRows as $assetForBranchName) {
                        $candidateBranchCode = assetPickValue($assetForBranchName, 'branch_code');
                        if ((string)$candidateBranchCode === (string)$selectedBranchCode) {
                            $branchSummaryName = assetFormatText(assetPickValue($assetForBranchName, 'branch_name'));
                            break;
                        }
                    }
                }
            ?>
            <div class="asset-card p-3 mb-3" id="branch-assets">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <div class="fw-bold">รายการทรัพย์สินทั้งหมดของสาขาที่เลือก</div>
                        <div class="text-muted small">
                            รหัสสาขา: <strong><?php echo assetE($selectedBranchCode); ?></strong>
                            <?php if ($branchSummaryName !== '-'): ?>
                                | ชื่อสาขา: <strong><?php echo assetE($branchSummaryName); ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge rounded-pill text-bg-primary px-3 py-2">พบ <?php echo number_format(count($branchAssetRows)); ?> รายการ</span>
                </div>

                <?php if (empty($branchAssetRows)): ?>
                    <div class="alert alert-warning mb-0">ไม่พบรายการทรัพย์สินในสาขานี้</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered asset-branch-table mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>รหัสสาขา</th>
                                    <th>ชื่อสาขา</th>
                                    <th>รหัสทรัพย์สินใหม่</th>
                                    <th>รหัสทรัพย์สินเก่า</th>
                                    <th>วันที่รับเข้า</th>
                                    <th>ราคาคงเหลือ</th>
                                    <th>รายการทรัพย์สิน</th>
                                    <th>อายุทรัพย์สิน</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($branchAssetRows as $rowIndex => $branchAsset): ?>
                                    <?php
                                        $rowAgeBaseDate = $branchAsset['as_day'] ?? null;
                                        $rowAssetAge = assetCalculateAgeFromDate($rowAgeBaseDate);
                                        $rowAssetAgeOverLimit = assetAgeExceedsYears($rowAgeBaseDate, 5);
                                    ?>
                                    <tr>
                                        <td class="text-center"><?php echo number_format($rowIndex + 1); ?></td>
                                        <td class="fw-bold"><?php echo assetE(assetFormatText($branchAsset['a_id'] ?? null)); ?></td>
                                        <td class="text-wrap-col asset-branch-name-blue"><?php echo assetE(assetDisplayBranchName($branchAsset['as_name'] ?? null)); ?></td>
                                        <td class="fw-bold asset-branch-asset-code-red">
                                            <?php
                                                $rowAssetCode = assetFormatText($branchAsset['as_code_new'] ?? null);
                                                $rowDetailLink = '';
                                                if ($rowAssetCode !== '-') {
                                                    $rowDetailLink = 'index.php?' . http_build_query([
                                                        'asset_code' => $assetKeyword,
                                                        'branch_search' => $branchSearchKeyword,
                                                        'branch_code' => $selectedBranchCode,
                                                        'branch_name' => $selectedBranchName,
                                                        'asset_detail_code' => $rowAssetCode,
                                                    ]) . '#asset-full-detail';
                                                }
                                            ?>
                                            <?php if ($rowDetailLink !== ''): ?>
                                                <a href="<?php echo assetE($rowDetailLink); ?>" class="asset-detail-link" title="คลิกเพื่อดูรายละเอียดทรัพย์สินทั้งหมด">
                                                    <?php echo assetE($rowAssetCode); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo assetE($rowAssetCode); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo assetE(assetFormatText($branchAsset['as_code_old'] ?? null)); ?></td>
                                        <td><?php echo assetE(assetFormatDateThai($branchAsset['as_day'] ?? null)); ?></td>
                                        <td><?php echo assetE(assetFormatMoney($branchAsset['as_price'] ?? null)); ?></td>
                                        <td class="text-wrap-col"><?php echo assetE(assetFormatText($branchAsset['as_list'] ?? null)); ?></td>
                                        <td><span class="asset-age-badge<?php echo $rowAssetAgeOverLimit ? ' over-age' : ''; ?>"><?php echo assetE($rowAssetAge); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <?php if ($selectedAssetDetailCode !== ''): ?>
            <div class="asset-card p-3 mb-3" id="asset-full-detail">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <div class="fw-bold">รายละเอียดทรัพย์สินทั้งหมด</div>
                        <div class="text-muted small">รหัสทรัพย์สิน: <strong><?php echo assetE($selectedAssetDetailCode); ?></strong></div>
                    </div>
                    <span class="badge rounded-pill text-bg-info px-3 py-2">แสดงข้อมูลหลัก</span>
                </div>

                <?php if (empty($assetFullDetailRow)): ?>
                    <div class="alert alert-warning mb-0">ไม่พบรายละเอียดทรัพย์สินตามรหัสที่เลือก</div>
                <?php else: ?>
                    <?php
                        $orderedDetailFields = [
                            ['label' => 'รหัสสาขา', 'column' => 'a_id', 'type' => 'text'],
                            ['label' => 'ชื่อสาขา', 'column' => 'as_name', 'type' => 'text'],
                            ['label' => 'รหัสทรัพย์สินใหม่', 'column' => 'as_code_new', 'type' => 'text'],
                            ['label' => 'รายการทรัพย์สิน', 'column' => 'as_list', 'type' => 'text'],
                            ['label' => 'รหัสทรัพย์สินเก่า', 'column' => 'as_code_old', 'type' => 'text'],
                            ['label' => 'วันที่รับทรัพย์สินเข้า', 'column' => 'as_day', 'type' => 'date'],
                            ['label' => 'ราคาต้นทุน', 'column' => 'as_price1', 'type' => 'money'],
                            ['label' => 'ราคาคงเหลือ', 'column' => 'as_price', 'type' => 'money'],
                            ['label' => 'ตำแหน่ง', 'column' => 'as_come', 'type' => 'text'],
                            ['label' => 'อายุทรัพย์สิน', 'column' => 'as_day', 'type' => 'age'],
                        ];
                    ?>
                    <div class="asset-full-detail-grid">
                        <?php foreach ($orderedDetailFields as $detailField): ?>
                            <?php
                                $columnName = (string)$detailField['column'];
                                $rawValue = $assetFullDetailRow[$columnName] ?? null;
                                $displayValue = assetFormatText($rawValue);
                                $displayAgeOverLimit = false;

                                if ($detailField['type'] === 'date') {
                                    $displayValue = assetFormatDateThai($rawValue);
                                } elseif ($detailField['type'] === 'money') {
                                    $displayValue = assetFormatMoney($rawValue);
                                } elseif ($detailField['type'] === 'age') {
                                    $displayValue = assetCalculateAgeFromDate($rawValue);
                                    $displayAgeOverLimit = assetAgeExceedsYears($rawValue, 5);
                                } else {
                                    $displayAgeOverLimit = false;
                                }
                            ?>
                            <div class="asset-full-detail-item<?php echo $detailField['type'] === 'age' ? ' primary' : ''; ?>">
                                <div class="label"><?php echo assetE($detailField['label']); ?> <span class="text-muted">(<?php echo assetE($columnName); ?>)</span></div>
                                <div class="value<?php echo $columnName === 'as_name' ? ' asset-branch-name-blue' : (in_array($columnName, ['as_code_new', 'as_list'], true) ? ' asset-highlight-red' : ''); ?>">
                                    <?php if ($detailField['type'] === 'age'): ?>
                                        <span class="asset-age-badge<?php echo !empty($displayAgeOverLimit) ? ' over-age' : ''; ?>"><?php echo assetE($displayValue); ?></span>
                                    <?php else: ?>
                                        <?php echo assetE($displayValue); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
<?php else: ?>
    <!-- <div class="row g-3">
        <div class="col-lg-4">
            <div class="asset-card p-3 h-100">
                <div class="fw-bold mb-1">1. กรอกรหัสทรัพย์สิน</div>
                <div class="text-muted small">กรอกรหัสทรัพย์สิน 9 หลัก หรือชื่อสาขา เพื่อเริ่มค้นหาข้อมูล</div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="asset-card p-3 h-100">
                <div class="fw-bold mb-1">2. แสดงเฉพาะข้อมูลหลัก</div>
                <div class="text-muted small">ระบบจะแสดงเฉพาะรหัสสาขา, ชื่อสาขา, รหัสทรัพย์สิน, วันที่รับเข้า, ราคาคงเหลือ และรายการทรัพย์สิน</div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="asset-card p-3 h-100">
                <div class="fw-bold mb-1">3. คำนวณอายุทรัพย์สิน</div>
                <div class="text-muted small">คำนวณอายุทรัพย์สินเทียบกับวันที่ปัจจุบันเพื่อแสดงอายุทรัพย์สิน</div>
            </div>
        </div>
    </div> -->
<?php endif; ?>


<div class="modal fade asset-detail-popup-modal" id="assetDetailPopupModal" tabindex="-1" aria-labelledby="assetDetailPopupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="assetDetailPopupModalLabel">รายละเอียดทรัพย์สินทั้งหมด</h5>
                    <div class="small opacity-75">รหัสทรัพย์สิน: <span data-popup-field="asset-new-code">-</span></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-2">
                <div class="table-responsive asset-detail-popup-table-wrap">
                    <table class="table table-bordered table-hover asset-detail-popup-table">
                        <tbody>
                            <tr><th>รหัสสาขา</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text" data-popup-field="branch-code">-</span></div></td></tr>
                            <tr><th>ชื่อสาขา</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text asset-branch-name-blue" data-popup-field="branch-name">-</span></div></td></tr>
                            <tr><th>รหัสทรัพย์สินใหม่</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text asset-highlight-red" data-popup-field="asset-new-code">-</span></div></td></tr>
                            <tr><th>รายการทรัพย์สิน</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text asset-highlight-red" data-popup-field="asset-item">-</span></div></td></tr>
                            <tr><th>รหัสทรัพย์สินเก่า</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text" data-popup-field="asset-old-code">-</span></div></td></tr>
                            <tr><th>วันที่รับทรัพย์สินเข้า</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text" data-popup-field="received-date">-</span></div></td></tr>
                            <tr><th>ราคาต้นทุน</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text" data-popup-field="cost-price">-</span></div></td></tr>
                            <tr><th>ราคาคงเหลือ</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text" data-popup-field="remaining-price">-</span></div></td></tr>
                            <tr><th>ตำแหน่ง</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-detail-popup-value-text" data-popup-field="location">-</span></div></td></tr>
                            <tr><th>อายุทรัพย์สิน</th><td><div class="asset-detail-popup-value-wrap"><span class="asset-age-badge" data-popup-field="age">-</span></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-1 px-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var popupButtons = document.querySelectorAll('.js-asset-detail-popup');
    var popupModal = document.getElementById('assetDetailPopupModal');

    function setPopupField(name, value) {
        if (!popupModal) {
            return;
        }
        popupModal.querySelectorAll('[data-popup-field="' + name + '"]').forEach(function (element) {
            element.textContent = value || '-';
        });
    }

    function fallbackCopyText(text) {
        var textarea = document.createElement('textarea');
        var activeElement = document.activeElement;
        var selection = window.getSelection ? window.getSelection() : null;
        var savedRanges = [];
        var copied = false;

        if (selection && selection.rangeCount > 0) {
            for (var rangeIndex = 0; rangeIndex < selection.rangeCount; rangeIndex++) {
                savedRanges.push(selection.getRangeAt(rangeIndex).cloneRange());
            }
        }

        textarea.value = String(text || '');
        textarea.setAttribute('readonly', 'readonly');
        textarea.setAttribute('aria-hidden', 'true');
        textarea.style.position = 'fixed';
        textarea.style.left = '0';
        textarea.style.top = '0';
        textarea.style.width = '2px';
        textarea.style.height = '2px';
        textarea.style.padding = '0';
        textarea.style.border = '0';
        textarea.style.outline = '0';
        textarea.style.opacity = '0.01';
        textarea.style.zIndex = '-1';
        document.body.appendChild(textarea);

        try {
            textarea.focus();
            textarea.select();
            if (typeof textarea.setSelectionRange === 'function') {
                textarea.setSelectionRange(0, textarea.value.length);
            }
            copied = document.execCommand('copy') === true;
        } catch (error) {
            copied = false;
        }

        if (textarea.parentNode) {
            textarea.parentNode.removeChild(textarea);
        }

        if (selection) {
            try {
                selection.removeAllRanges();
                savedRanges.forEach(function (range) {
                    selection.addRange(range);
                });
            } catch (error) {
                // Ignore selection restore errors.
            }
        }

        if (activeElement && typeof activeElement.focus === 'function') {
            try {
                activeElement.focus();
            } catch (error) {
                // Ignore focus restore errors.
            }
        }

        return copied;
    }

    function copyTextToClipboard(text) {
        text = String(text || '').trim();

        if (text === '' || text === '-') {
            return Promise.resolve(false);
        }

        // Run the legacy copy method synchronously while the browser still
        // considers this code part of the user's click gesture. This is more
        // reliable on production sites served over HTTP or restricted by policy.
        if (fallbackCopyText(text)) {
            return Promise.resolve(true);
        }

        // Use the modern Clipboard API only when the synchronous method fails.
        if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            try {
                return navigator.clipboard.writeText(text)
                    .then(function () {
                        return true;
                    })
                    .catch(function () {
                        return false;
                    });
            } catch (error) {
                return Promise.resolve(false);
            }
        }

        return Promise.resolve(false);
    }

    function showCopyResult(button, success) {
        if (button.classList.contains('asset-inline-copy-button')) {
            var originalTitle = button.getAttribute('title') || 'คัดลอก';
            button.classList.toggle('copied', success);
            button.setAttribute('title', success ? 'คัดลอกแล้ว' : 'คัดลอกไม่สำเร็จ');
            button.setAttribute('aria-label', success ? 'คัดลอกแล้ว' : 'คัดลอกไม่สำเร็จ');
            window.setTimeout(function () {
                button.classList.remove('copied');
                button.setAttribute('title', originalTitle);
                button.setAttribute('aria-label', originalTitle);
            }, 1200);
            return;
        }

        var originalTitle = button.getAttribute('title') || 'คัดลอก';
        button.classList.toggle('copied', success);
        button.setAttribute('title', success ? 'คัดลอกแล้ว' : 'คัดลอกไม่สำเร็จ');
        button.setAttribute('aria-label', success ? 'คัดลอกแล้ว' : 'คัดลอกไม่สำเร็จ');
        window.setTimeout(function () {
            button.classList.remove('copied');
            button.setAttribute('title', originalTitle);
            button.setAttribute('aria-label', originalTitle);
        }, 1200);
    }

    popupButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setPopupField('branch-code', button.getAttribute('data-branch-code'));
            setPopupField('branch-name', button.getAttribute('data-branch-name'));
            setPopupField('asset-new-code', button.getAttribute('data-asset-new-code'));
            setPopupField('asset-old-code', button.getAttribute('data-asset-old-code'));
            setPopupField('asset-item', button.getAttribute('data-asset-item'));
            setPopupField('received-date', button.getAttribute('data-received-date'));
            setPopupField('cost-price', button.getAttribute('data-cost-price'));
            setPopupField('remaining-price', button.getAttribute('data-remaining-price'));
            setPopupField('location', button.getAttribute('data-location'));
            setPopupField('age', button.getAttribute('data-age'));

            var ageBadge = popupModal ? popupModal.querySelector('[data-popup-field="age"]') : null;
            if (ageBadge) {
                ageBadge.classList.toggle('over-age', button.getAttribute('data-age-over') === '1');
            }
        });
    });

    document.addEventListener('click', function (event) {
        var copyButton = event.target.closest('.js-asset-copy');
        if (!copyButton) {
            return;
        }

        var text = (copyButton.getAttribute('data-copy-text') || '').trim();
        if (text === '' || text === '-') {
            showCopyResult(copyButton, false);
            return;
        }

        copyTextToClipboard(text).then(function (success) {
            showCopyResult(copyButton, success);
        });
    });

    var assetInput = document.getElementById('assetCodeInput');
    var branchInput = document.getElementById('branchSearchInput');
    if (!assetInput || !assetInput.form) {
        return;
    }

    function syncSearchInputs() {
        var hasAssetCode = assetInput.value.trim() !== '';
        var hasBranchName = branchInput && branchInput.value.trim() !== '';

        if (branchInput) {
            branchInput.disabled = hasAssetCode;
            if (hasAssetCode) {
                branchInput.value = '';
                branchInput.setCustomValidity('');
            }
        }

        assetInput.disabled = Boolean(hasBranchName);
        if (hasBranchName) {
            assetInput.value = '';
            assetInput.setCustomValidity('');
        }
    }

    assetInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 9);
        this.setCustomValidity('');
        syncSearchInputs();
    });

    if (branchInput) {
        branchInput.addEventListener('input', function () {
            this.setCustomValidity('');
            assetInput.setCustomValidity('');
            syncSearchInputs();
        });
    }

    syncSearchInputs();

    assetInput.form.addEventListener('submit', function (event) {
        var assetCode = assetInput.value.trim();
        var branchName = branchInput ? branchInput.value.trim() : '';

        if (assetCode === '' && branchName === '') {
            assetInput.setCustomValidity('กรุณากรอกรหัสทรัพย์สิน หรือชื่อสาขา');
            assetInput.reportValidity();
            event.preventDefault();
            return false;
        }

        if (assetCode !== '' && branchName !== '') {
            assetInput.setCustomValidity('กรุณาค้นหาเพียงอย่างใดอย่างหนึ่ง: รหัสทรัพย์สิน หรือชื่อสาขา');
            assetInput.reportValidity();
            event.preventDefault();
            return false;
        }

        if (assetCode !== '' && !/^\d{9}$/.test(assetCode)) {
            assetInput.setCustomValidity('กรุณากรอกรหัสทรัพย์สินเป็นตัวเลข 9 หลัก');
            assetInput.reportValidity();
            event.preventDefault();
            return false;
        }

        assetInput.setCustomValidity('');
        if (branchInput) {
            branchInput.setCustomValidity('');
        }
    });
})();
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
