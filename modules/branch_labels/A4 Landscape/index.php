<?php
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

$buildPageUrl = static function (int $page) use ($query, $searchField): string {
    $params = ['page' => $page];
    if ($query !== '') {
        $params['q'] = $query;
        $params['search_field'] = $searchField;
    }
    return 'index.php?' . http_build_query($params);
};

$branchLabelBaseUrl = defined('BASE_URL') ? BASE_URL : '/harddisk_delivery_web';

require_once __DIR__ . '/../../includes/header.php';
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
        top: 44px;
        right: 20px;
        width: 178px;
        min-height: 112px;
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
        width: 156px;
        height: 92px;
        object-fit: contain;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 4px;
        background: #fff;
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
    @media (max-width: 1366px) {
        .branch-label-table th,
        .branch-label-table td { font-size: .68rem; padding: .32rem .34rem; }
    }
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        html, body {
            width: 277mm !important;
            height: 190mm !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            overflow: hidden !important;
        }
        body * {
            visibility: hidden !important;
        }
        .print-only-label,
        .print-only-label * {
            visibility: visible !important;
        }
        .print-only-label {
            display: flex !important;
            position: fixed !important;
            left: 0 !important;
            top: 0 !important;
            width: 277mm !important;
            height: 190mm !important;
            padding: 18mm 0 0 0 !important;
            margin: 0 !important;
            justify-content: center !important;
            align-items: flex-start !important;
        }
        .print-only-label .parcel-label {
            position: relative !important;
            width: 205mm !important;
            height: 92mm !important;
            max-width: none !important;
            min-height: 0 !important;
            margin: 0 auto !important;
            padding: 5mm 6mm !important;
            border: 2px solid #000 !important;
            border-radius: 3mm !important;
            box-shadow: none !important;
            page-break-before: avoid !important;
            page-break-after: avoid !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .print-only-label .label-asset-block {
            position: absolute !important;
            top: 7mm !important;
            right: 7mm !important;
            width: 48mm !important;
            min-height: 34mm !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: flex-start !important;
            gap: 1.5mm !important;
            border: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            background: #fff !important;
        }
        .print-only-label .label-asset-block > div {
            width: 100% !important;
            font-size: 7.5pt !important;
            line-height: 1.15 !important;
            text-align: center !important;
            font-weight: 800 !important;
        }
        .print-only-label .selected-asset-image {
            width: 42mm !important;
            height: 25mm !important;
            object-fit: contain !important;
            border: 1px solid #bbb !important;
            border-radius: 2mm !important;
            padding: 1mm !important;
            background: #fff !important;
        }
    }
</style>

<div class="branch-label-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="small opacity-75 fw-bold mb-1">Branch Address Label</div>
            <h1 class="h3 fw-bold mb-2">ค้นหาสาขาและพิมพ์ที่อยู่สาขา</h1>
            <div class="opacity-90">เพื่อทำใบปะหน้าติดกล่องพัสดุ</div>
        </div>
        <div class="text-end">
            <div class="small opacity-75">จำนวนรายการที่พบ</div>
            <div class="h2 fw-bold mb-0"><?php echo number_format($totalRows); ?></div>
        </div>
    </div>
</div>

<?php if ($pageError !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm"><?php echo blE($pageError); ?></div>
<?php endif; ?>

<div class="branch-label-card p-3 mb-3">
    <div class="branch-label-section-title mb-3">ค้นหาสาขา</div>
    <form method="get" class="row g-2 branch-label-search" autocomplete="off">
        <div class="col-lg-3 col-md-4">
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
        <div class="col-lg-6 col-md-8">
            <input type="text" name="q" class="form-control" value="<?php echo blE($query); ?>" placeholder="กรอกคำค้นหา">
        </div>
        <div class="col-lg-3 col-md-12 d-flex gap-2">
            <button class="btn btn-primary flex-fill" type="submit">ค้นหา</button>
            <a href="index.php" class="btn btn-outline-secondary">ล้างค่า</a>
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
                                <button class="btn btn-sm btn-outline-primary w-100 mb-1" type="button" data-bs-toggle="collapse" data-bs-target="#preview<?php echo blE($labelId); ?>">ดูใบปะหน้า</button>
                                <button class="btn btn-sm btn-success w-100" type="button" onclick="openAssetPrintModal('<?php echo blE($labelId); ?>')">พิมพ์ใบปะหน้า</button>
                            </td>
                        </tr>
                        <tr class="collapse" id="preview<?php echo blE($labelId); ?>">
                            <td colspan="7">
                                <div id="<?php echo blE($labelId); ?>" class="parcel-label">
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
                                        <div><strong>รายการจัดส่ง:</strong> <span class="selected-asset-text">-</span></div>
                                        <img class="selected-asset-image" src="" alt="รูปภาพทรัพย์สิน" style="display:none;">
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

<div id="printArea" class="print-only-label"></div>

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
                <div class="small text-muted mt-2">เมื่อกดพิมพ์ ระบบจะใส่รายการทรัพย์สินและรูปภาพลงในใบปะหน้าก่อนเปิดหน้าพิมพ์</div>
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

        printBranchLabel(labelId, assetName, getBranchLabelAssetImage(assetName));
    }

    function printBranchLabel(labelId, assetName, assetImageUrl) {
        var source = document.getElementById(labelId);
        if (!source) {
            alert('ไม่พบข้อมูลใบปะหน้าสำหรับพิมพ์');
            return;
        }

        var labelClone = source.cloneNode(true);
        var assetText = labelClone.querySelector('.selected-asset-text');
        if (assetText) {
            assetText.textContent = assetName || '-';
        }

        var assetImage = labelClone.querySelector('.selected-asset-image');
        if (assetImage && assetImageUrl) {
            assetImage.src = assetImageUrl;
            assetImage.alt = assetName || 'รูปภาพทรัพย์สิน';
            assetImage.style.display = 'block';
            assetImage.onerror = function () { this.style.display = 'none'; };
        } else if (assetImage) {
            assetImage.style.display = 'none';
        }

        // ใช้ iframe สำหรับพิมพ์เฉพาะใบปะหน้า 1 ใบ
        // ป้องกัน Chrome นับ layout ของหน้าเว็บเดิมจนกลายเป็นหลายแผ่น
        var oldFrame = document.getElementById('branchLabelPrintFrame');
        if (oldFrame) {
            oldFrame.remove();
        }

        var frame = document.createElement('iframe');
        frame.id = 'branchLabelPrintFrame';
        frame.style.position = 'fixed';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.width = '0';
        frame.style.height = '0';
        frame.style.border = '0';
        frame.style.opacity = '0';
        document.body.appendChild(frame);

        var doc = frame.contentWindow || frame.contentDocument;
        if (doc.document) {
            doc = doc.document;
        }

        doc.open();
        doc.write('<!doctype html><html lang="th"><head><meta charset="utf-8"><title>พิมพ์ใบปะหน้าพัสดุ</title>');
        doc.write('<style>');
        doc.write('@page{size:A4 landscape;margin:10mm;}');
        doc.write('html,body{width:277mm;height:190mm;margin:0;padding:0;background:#fff;color:#000;font-family:Tahoma,Arial,sans-serif;overflow:hidden;}');
        doc.write('*{box-sizing:border-box;}');
        doc.write('.print-sheet{width:277mm;height:190mm;display:flex;justify-content:center;align-items:flex-start;padding-top:18mm;}');
        doc.write('.parcel-label{position:relative;width:205mm;height:92mm;max-width:none;margin:0;border:2px solid #000;border-radius:3mm;padding:5mm 6mm;background:#fff;color:#000;box-shadow:none;page-break-inside:avoid;break-inside:avoid;overflow:hidden;}');
        doc.write('.label-title{font-size:12pt;font-weight:900;border-bottom:1.5px solid #000;padding-bottom:3mm;margin-bottom:3mm;}');
        doc.write('.label-row{margin-bottom:2mm;font-size:9.5pt;line-height:1.34;}');
        doc.write('.label-branch-name{font-size:12pt;font-weight:900;}');
        doc.write('.label-code{display:inline-block;border:1px solid #334155;border-radius:2mm;padding:1.2mm 2mm;font-weight:900;margin-right:2mm;background:#f8fafc;}');
        doc.write('.label-asset-block{position:absolute;top:7mm;right:7mm;width:48mm;min-height:34mm;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:1.5mm;border:0;padding:0;margin:0;background:#fff;text-align:center;}');
        doc.write('.label-asset-block>div{width:100%;font-size:7.5pt;line-height:1.15;text-align:center;font-weight:800;}');
        doc.write('.selected-asset-image{width:42mm;height:25mm;object-fit:contain;border:1px solid #bbb;border-radius:2mm;padding:1mm;background:#fff;}');
        doc.write('hr{border:0;border-top:1.5px solid #000;margin:3mm 0;}');
        doc.write('</style></head><body>');
        doc.write('<div class="print-sheet">' + labelClone.outerHTML + '</div>');
        doc.write('</body></html>');
        doc.close();

        frame.onload = function () {
            setTimeout(function () {
                frame.contentWindow.focus();
                frame.contentWindow.print();
                setTimeout(function () {
                    frame.remove();
                }, 800);
            }, 150);
        };
    }
document.addEventListener('DOMContentLoaded', function () {
        var select = document.getElementById('selectedAssetName');
        if (select) {
            select.addEventListener('change', updateAssetImagePreview);
        }
    });
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

<!-- GLOBAL_MODAL_LAYER_FIX_2026: keep every Bootstrap modal above the shared topbar/sidebar -->
<style>
body > .modal {
    z-index: 2147483000 !important;
}
body > .modal.show {
    display: block;
}
body > .modal-backdrop,
body.modal-open > .modal-backdrop {
    z-index: 2147482990 !important;
}
body.modal-open .hdd-topbar,
body.modal-open .topbar,
body.modal-open header,
body.modal-open .navbar,
body.modal-open .sticky-top,
body.modal-open [class*="page-header"] {
    pointer-events: none;
}
body.modal-open > .modal,
body.modal-open > .modal * {
    pointer-events: auto;
}
</style>
<script>
(function () {
    'use strict';

    function moveModalsToBody() {
        document.querySelectorAll('.modal').forEach(function (modal) {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', moveModalsToBody, { once: true });
    } else {
        moveModalsToBody();
    }

    document.addEventListener('show.bs.modal', function (event) {
        var modal = event.target;
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }, true);
})();
</script>
