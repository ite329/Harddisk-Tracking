<?php

require_once __DIR__ . '/../../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'อัปโหลดข้อมูลทรัพย์สิน';

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/data_database.php';

if (!function_exists('aiE')) {
    function aiE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('aiClean')) {
    function aiClean($value): string
    {
        return trim((string)($value ?? ''));
    }
}

if (!function_exists('aiQuoteColumn')) {
    function aiQuoteColumn(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}

if (!function_exists('aiNormalizeEmployeeCode')) {
    function aiNormalizeEmployeeCode(string $value): string
    {
        $value = aiClean($value);
        if ($value === '' || $value === '-') {
            return '';
        }
        if (preg_match('/^[0-9]{1,4}$/', $value)) {
            return str_pad($value, 5, '0', STR_PAD_LEFT);
        }
        return $value;
    }
}

if (!function_exists('aiCurrentEmployeeCode')) {
    function aiCurrentEmployeeCode(): string
    {
        foreach (['employee_code', 'emp_code', 'employee_id', 'username', 'id'] as $key) {
            $value = aiClean($_SESSION[$key] ?? '');
            if ($value !== '') {
                return aiNormalizeEmployeeCode($value);
            }
        }

        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach (['employee_code', 'emp_code', 'employee_id', 'username', 'id', 'code'] as $key) {
                $value = aiClean($_SESSION['user'][$key] ?? '');
                if ($value !== '') {
                    return aiNormalizeEmployeeCode($value);
                }
            }
        }

        return '';
    }
}

if (!function_exists('aiCurrentUser')) {
    function aiCurrentUser(): string
    {
        $fullName = aiClean($_SESSION['full_name'] ?? '');
        $employeeCode = aiCurrentEmployeeCode();
        if ($fullName !== '' && $employeeCode !== '') {
            return $fullName . ' (' . $employeeCode . ')';
        }
        if ($fullName !== '') {
            return $fullName;
        }
        if ($employeeCode !== '') {
            return $employeeCode;
        }
        return 'system';
    }
}

if (!function_exists('aiIsSuperAdmin')) {
    function aiIsSuperAdmin(): bool
    {
        return function_exists('can') && can('admin.asset_import');
    }
}

if (!function_exists('aiColumnExists')) {
    function aiColumnExists(PDO $pdo, string $dbName, string $tableName, string $columnName): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('aiGetColumns')) {
    function aiGetColumns(PDO $pdo, string $dbName, string $tableName): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION ASC');
        $stmt->execute([':db_name' => $dbName, ':table_name' => $tableName]);
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

if (!function_exists('aiPrimaryKeyColumn')) {
    function aiPrimaryKeyColumn(PDO $pdo, string $dbName, string $tableName): string
    {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION ASC LIMIT 1");
        $stmt->execute([':db_name' => $dbName, ':table_name' => $tableName]);
        $column = $stmt->fetchColumn();
        return $column !== false ? (string)$column : 'id';
    }
}

if (!function_exists('aiResolveColumn')) {
    function aiResolveColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower((string)$candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }
        return null;
    }
}

if (!function_exists('aiEnsureTable')) {
    function aiEnsureTable(PDO $pdo, string $dbName): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `delete_computer` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name_com_new` VARCHAR(150) NOT NULL DEFAULT '',
            `name_com_del` VARCHAR(150) NULL,
            `asset` VARCHAR(180) NULL,
            `de_name_l_new` VARCHAR(50) NULL,
            `de_name_l_del` VARCHAR(50) NULL,
            `de_poin` VARCHAR(10) NOT NULL DEFAULT '2',
            `created_by` VARCHAR(180) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_name_com_new` (`name_com_new`),
            KEY `idx_name_com_del` (`name_com_del`),
            KEY `idx_asset` (`asset`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $requiredColumns = [
            'name_com_new' => "VARCHAR(150) NOT NULL DEFAULT ''",
            'name_com_del' => 'VARCHAR(150) NULL',
            'asset' => 'VARCHAR(180) NULL',
            'asset_updated_by' => 'VARCHAR(50) NULL',
            'asset_updated_at' => 'DATETIME NULL DEFAULT NULL',
            'de_poin' => "VARCHAR(10) NOT NULL DEFAULT '2'",
        ];

        foreach ($requiredColumns as $column => $definition) {
            if (!aiColumnExists($pdo, $dbName, 'delete_computer', $column)) {
                $pdo->exec('ALTER TABLE `delete_computer` ADD COLUMN ' . aiQuoteColumn($column) . ' ' . $definition);
            }
        }
    }
}

if (!function_exists('aiConvertEncoding')) {
    function aiConvertEncoding(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'TIS-620,Windows-874,ISO-8859-1');
            if ($converted !== false) {
                return $converted;
            }
        }
        return $value;
    }
}

if (!function_exists('aiNormalizeHeader')) {
    function aiNormalizeHeader(string $value): string
    {
        $value = aiConvertEncoding($value);
        $value = strtolower(trim($value));
        $value = str_replace(["\xEF\xBB\xBF", ' ', '-', '/', '.', '(', ')'], ['', '_', '_', '_', '_', '', ''], $value);
        return $value;
    }
}

if (!function_exists('aiPickCsvValue')) {
    function aiPickCsvValue(array $row, array $headers, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidateKey = aiNormalizeHeader($candidate);
            if (isset($headers[$candidateKey])) {
                $index = $headers[$candidateKey];
                return aiClean(aiConvertEncoding($row[$index] ?? ''));
            }
        }
        return '';
    }
}

if (!function_exists('aiReadCsvFile')) {
    function aiReadCsvFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์ CSV ได้');
        }

        $headerRow = fgetcsv($handle);
        if (!is_array($headerRow) || empty($headerRow)) {
            fclose($handle);
            throw new RuntimeException('ไฟล์ CSV ไม่มี Header');
        }

        $headers = [];
        foreach ($headerRow as $index => $header) {
            $key = aiNormalizeHeader((string)$header);
            if ($key !== '') {
                $headers[$key] = $index;
            }
        }

        $rows = [];
        $line = 1;
        while (($csvRow = fgetcsv($handle)) !== false) {
            $line++;
            $computerName = aiPickCsvValue($csvRow, $headers, [
                'name_com_new', 'ชื่อเครื่องคอมใหม่', 'computer_name', 'computer', 'hostname', 'ชื่อเครื่อง',
                'name_com_del', 'ชื่อเครื่องเก่า'
            ]);
            $asset = aiPickCsvValue($csvRow, $headers, [
                'asset', 'asset_code', 'รหัสทรัพย์สิน', 'รหัสทรัพย์สินใหม่', 'as_code_new', 'ทรัพย์สิน'
            ]);

            if ($computerName === '' && $asset === '') {
                continue;
            }

            $rows[] = [
                'line' => $line,
                'computer_name' => $computerName,
                'asset' => $asset,
            ];
        }
        fclose($handle);

        return $rows;
    }
}

if (!function_exists('aiBuildPreviewRows')) {
    function aiBuildPreviewRows(PDO $pdo, array $columns, string $primaryKey, array $csvRows): array
    {
        $newNameColumn = aiResolveColumn($columns, ['name_com_new', 'new_computer_name', 'computer_name_new']);
        $oldNameColumn = aiResolveColumn($columns, ['name_com_del', 'old_computer_name', 'computer_name_old']);
        $assetColumn = aiResolveColumn($columns, ['asset']);

        if ($newNameColumn === null && $oldNameColumn === null) {
            throw new RuntimeException('ไม่พบคอลัมน์ชื่อเครื่องใน data.delete_computer');
        }
        if ($assetColumn === null) {
            throw new RuntimeException('ไม่พบคอลัมน์ asset ใน data.delete_computer');
        }

        $previewRows = [];
        foreach ($csvRows as $row) {
            $computerName = aiClean($row['computer_name'] ?? '');
            $asset = aiClean($row['asset'] ?? '');
            $matches = [];

            if ($computerName !== '' && $asset !== '') {
                $whereParts = [];
                $params = [':computer_name' => $computerName];
                if ($newNameColumn !== null) {
                    $whereParts[] = aiQuoteColumn($newNameColumn) . ' = :computer_name';
                }
                if ($oldNameColumn !== null) {
                    $whereParts[] = aiQuoteColumn($oldNameColumn) . ' = :computer_name';
                }

                $sql = 'SELECT * FROM `delete_computer` WHERE (' . implode(' OR ', $whereParts) . ') ORDER BY ' . aiQuoteColumn($primaryKey) . ' DESC LIMIT 20';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $matchIds = [];
            $currentAssets = [];
            foreach ($matches as $match) {
                $id = (string)($match[$primaryKey] ?? '');
                if ($id !== '') {
                    $matchIds[] = $id;
                }
                $currentAssets[] = aiClean($match[$assetColumn] ?? '');
            }

            $status = 'พร้อมอัปเดต';
            if ($computerName === '') {
                $status = 'ไม่พบชื่อเครื่องใน CSV';
            } elseif ($asset === '') {
                $status = 'ไม่พบข้อมูลทรัพย์สินใน CSV';
            } elseif (empty($matchIds)) {
                $status = 'ไม่พบชื่อเครื่องในฐานข้อมูล';
            }

            $previewRows[] = [
                'line' => (int)($row['line'] ?? 0),
                'computer_name' => $computerName,
                'asset' => $asset,
                'match_ids' => $matchIds,
                'match_count' => count($matchIds),
                'current_asset' => implode(', ', array_unique(array_filter($currentAssets, static function ($value) { return $value !== ''; }))),
                'status' => $status,
            ];
        }

        return $previewRows;
    }
}

if (!function_exists('aiCsvDownloadTemplate')) {
    function aiCsvDownloadTemplate(): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="asset_import_template.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['name_com_new', 'asset']);
        fputcsv($out, ['B00PC000K00D0', 'ASSET000001']);
        fclose($out);
        exit;
    }
}

if (($_GET['action'] ?? '') === 'template') {
    aiCsvDownloadTemplate();
}

$dataDbName = $DATA_DB_NAME ?? 'data';
$pageError = '';
$pageSuccess = '';
$previewRows = [];
$latestRows = [];
$summary = ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'updated' => 0];
$isSuperAdmin = aiIsSuperAdmin();
$columns = [];
$primaryKey = 'id';

if ($dataDbError !== '') {
    $pageError = $dataDbError;
} elseif (!$dataPdo instanceof PDO) {
    $pageError = 'ไม่พบการเชื่อมต่อฐานข้อมูล data';
} elseif (!$isSuperAdmin) {
    $pageError = 'เมนูนี้แสดงเฉพาะ User สิทธิ์ Super Admin';
} else {
    try {
        aiEnsureTable($dataPdo, $dataDbName);
        $columns = aiGetColumns($dataPdo, $dataDbName, 'delete_computer');
        $primaryKey = aiPrimaryKeyColumn($dataPdo, $dataDbName, 'delete_computer');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = aiClean($_POST['action'] ?? '');

            if ($action === 'preview') {
                if (empty($_FILES['asset_csv']['tmp_name']) || !is_uploaded_file($_FILES['asset_csv']['tmp_name'])) {
                    $pageError = 'กรุณาเลือกไฟล์ CSV';
                } else {
                    $fileName = (string)($_FILES['asset_csv']['name'] ?? '');
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    if ($ext !== 'csv') {
                        $pageError = 'รองรับเฉพาะไฟล์ .CSV เท่านั้น';
                    } else {
                        $csvRows = aiReadCsvFile($_FILES['asset_csv']['tmp_name']);
                        $previewRows = aiBuildPreviewRows($dataPdo, $columns, $primaryKey, $csvRows);
                        $_SESSION['asset_import_preview'] = $previewRows;
                        $_SESSION['asset_import_preview_at'] = date('Y-m-d H:i:s');

                        $summary['total'] = count($previewRows);
                        foreach ($previewRows as $row) {
                            if (!empty($row['match_ids'])) {
                                $summary['matched']++;
                            } else {
                                $summary['unmatched']++;
                            }
                        }
                    }
                }
            } elseif ($action === 'confirm') {
                $previewRows = $_SESSION['asset_import_preview'] ?? [];
                if (empty($previewRows) || !is_array($previewRows)) {
                    $pageError = 'ไม่พบข้อมูล Preview กรุณาอัปโหลด CSV ใหม่อีกครั้ง';
                } else {
                    $assetColumn = aiResolveColumn($columns, ['asset']);
                    if ($assetColumn === null) {
                        throw new RuntimeException('ไม่พบคอลัมน์ asset ใน data.delete_computer');
                    }

                    $setParts = [aiQuoteColumn($assetColumn) . ' = :asset'];
                    if (isset($columns['asset_updated_by'])) {
                        $setParts[] = aiQuoteColumn($columns['asset_updated_by']) . ' = :asset_updated_by';
                    }
                    if (isset($columns['asset_updated_at'])) {
                        $setParts[] = aiQuoteColumn($columns['asset_updated_at']) . ' = NOW()';
                    }
                    if (isset($columns['updated_at'])) {
                        $setParts[] = aiQuoteColumn($columns['updated_at']) . ' = NOW()';
                    }

                    $sql = 'UPDATE `delete_computer` SET ' . implode(', ', $setParts) . ' WHERE ' . aiQuoteColumn($primaryKey) . ' = :id';
                    $stmt = $dataPdo->prepare($sql);
                    $updated = 0;

                    foreach ($previewRows as $row) {
                        $asset = aiClean($row['asset'] ?? '');
                        $matchIds = $row['match_ids'] ?? [];
                        if ($asset === '' || empty($matchIds) || !is_array($matchIds)) {
                            continue;
                        }
                        foreach ($matchIds as $id) {
                            $params = [':asset' => $asset, ':id' => $id];
                            if (isset($columns['asset_updated_by'])) {
                                $params[':asset_updated_by'] = aiCurrentEmployeeCode();
                            }
                            $stmt->execute($params);
                            $updated += $stmt->rowCount();
                        }
                    }

                    unset($_SESSION['asset_import_preview'], $_SESSION['asset_import_preview_at']);
                    $previewRows = [];
                    $summary['updated'] = $updated;
                    $pageSuccess = 'อัปเดตข้อมูลทรัพย์สินเรียบร้อยแล้ว จำนวน ' . number_format($updated) . ' รายการ';
                }
            }
        } elseif (!empty($_SESSION['asset_import_preview']) && is_array($_SESSION['asset_import_preview'])) {
            $previewRows = $_SESSION['asset_import_preview'];
            $summary['total'] = count($previewRows);
            foreach ($previewRows as $row) {
                if (!empty($row['match_ids'])) {
                    $summary['matched']++;
                } else {
                    $summary['unmatched']++;
                }
            }
        }

        $nameNewColumn = aiResolveColumn($columns, ['name_com_new', 'new_computer_name']);
        $nameOldColumn = aiResolveColumn($columns, ['name_com_del', 'old_computer_name']);
        $assetColumn = aiResolveColumn($columns, ['asset']);
        $dateColumn = aiResolveColumn($columns, ['asset_updated_at', 'updated_at', 'created_at']);
        if ($assetColumn !== null) {
            $selectParts = [aiQuoteColumn($primaryKey) . ' AS row_id'];
            $selectParts[] = $nameNewColumn !== null ? aiQuoteColumn($nameNewColumn) . ' AS name_new' : "'' AS name_new";
            $selectParts[] = $nameOldColumn !== null ? aiQuoteColumn($nameOldColumn) . ' AS name_old' : "'' AS name_old";
            $selectParts[] = aiQuoteColumn($assetColumn) . ' AS asset_value';
            $selectParts[] = $dateColumn !== null ? aiQuoteColumn($dateColumn) . ' AS updated_date' : "'' AS updated_date";

            $orderColumn = $dateColumn !== null ? $dateColumn : $primaryKey;
            $stmt = $dataPdo->query('SELECT ' . implode(', ', $selectParts) . ' FROM `delete_computer` WHERE ' . aiQuoteColumn($assetColumn) . " IS NOT NULL AND " . aiQuoteColumn($assetColumn) . " <> '' ORDER BY " . aiQuoteColumn($orderColumn) . ' DESC LIMIT 20');
            $latestRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $pageError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../../../includes/header.php';

require_login();
require_permission('admin.asset_import');

?>

<style>
    .asset-import-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
    }
    .asset-import-title {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 900;
        color: #0f172a;
    }
    .asset-import-title::before {
        content: '';
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: #2563eb;
        box-shadow: 0 0 0 5px rgba(37, 99, 235, .12);
    }
    .asset-import-kpi {
        border-radius: 16px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 12px;
    }
    .asset-import-table th,
    .asset-import-table td {
        font-size: .82rem;
        vertical-align: middle;
        white-space: normal;
        overflow-wrap: anywhere;
    }
</style>

<div class="asset-import-card p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="asset-import-title">อัปโหลดข้อมูลทรัพย์สิน</div>
            <div class="small text-muted mt-1">อัปโหลดไฟล์ CSV เพื่ออัปเดตข้อมูลลงคอลัมน์ <strong>data.delete_computer.asset</strong></div>
        </div>
        <a href="index.php?action=template" class="btn btn-sm btn-outline-primary">ดาวน์โหลด Template CSV</a>
    </div>

    <?php if ($pageSuccess !== ''): ?>
        <div class="alert alert-success border-0 shadow-sm"><?php echo aiE($pageSuccess); ?></div>
    <?php endif; ?>
    <?php if ($pageError !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm"><?php echo aiE($pageError); ?></div>
    <?php endif; ?>

    <?php if ($isSuperAdmin): ?>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="preview">
            <div class="col-lg-7 col-md-8">
                <label class="form-label fw-bold">เลือกไฟล์ CSV <span class="text-danger">*</span></label>
                <input type="file" name="asset_csv" class="form-control" accept=".csv,text/csv" required>
                <div class="form-text">Header ที่รองรับ: <code>name_com_new</code>, <code>asset</code> หรือชื่อภาษาไทยที่ใกล้เคียง</div>
            </div>
            <div class="col-lg-5 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">Preview ข้อมูล</button>
                <a href="index.php" class="btn btn-outline-secondary">ล้างค่า</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($isSuperAdmin): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="asset-import-kpi"><div class="small text-muted">ทั้งหมดใน Preview</div><div class="h4 fw-bold mb-0"><?php echo number_format($summary['total']); ?></div></div></div>
        <div class="col-md-3"><div class="asset-import-kpi"><div class="small text-muted">พร้อมอัปเดต</div><div class="h4 fw-bold text-success mb-0"><?php echo number_format($summary['matched']); ?></div></div></div>
        <div class="col-md-3"><div class="asset-import-kpi"><div class="small text-muted">ไม่พบในระบบ</div><div class="h4 fw-bold text-danger mb-0"><?php echo number_format($summary['unmatched']); ?></div></div></div>
        <div class="col-md-3"><div class="asset-import-kpi"><div class="small text-muted">อัปเดตล่าสุด</div><div class="h4 fw-bold text-primary mb-0"><?php echo number_format($summary['updated']); ?></div></div></div>
    </div>

    <?php if (!empty($previewRows)): ?>
        <div class="asset-import-card p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <div class="asset-import-title">Preview ก่อนอัปเดต</div>
                    <div class="small text-muted mt-1">ตรวจสอบรายการก่อนกดยืนยัน ระบบจะอัปเดตเฉพาะรายการที่ Match ชื่อเครื่องได้</div>
                </div>
                <form method="post" onsubmit="return confirm('ยืนยันอัปเดตข้อมูลทรัพย์สินตาม Preview นี้?');">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-success">ยืนยันอัปเดตข้อมูล</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered asset-import-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">บรรทัด</th>
                            <th>ชื่อเครื่องจาก CSV</th>
                            <th>ข้อมูลทรัพย์สินใหม่</th>
                            <th>ข้อมูลทรัพย์สินเดิม</th>
                            <th style="width:110px;">จำนวนที่พบ</th>
                            <th style="width:170px;">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <?php $ok = !empty($row['match_ids']); ?>
                            <tr>
                                <td class="text-center"><?php echo number_format((int)($row['line'] ?? 0)); ?></td>
                                <td class="fw-bold"><?php echo aiE($row['computer_name'] ?? ''); ?></td>
                                <td><?php echo aiE($row['asset'] ?? ''); ?></td>
                                <td><?php echo aiE(($row['current_asset'] ?? '') !== '' ? $row['current_asset'] : '-'); ?></td>
                                <td class="text-center"><?php echo number_format((int)($row['match_count'] ?? 0)); ?></td>
                                <td><span class="badge <?php echo $ok ? 'bg-success' : 'bg-danger'; ?>"><?php echo aiE($row['status'] ?? '-'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="asset-import-card p-3">
        <div class="asset-import-title mb-3">ข้อมูลทรัพย์สินที่อัปเดตล่าสุด</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered asset-import-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:70px;">ลำดับ</th>
                        <th>ชื่อเครื่องคอมใหม่</th>
                        <th>ชื่อเครื่องเก่า</th>
                        <th>ข้อมูลทรัพย์สิน</th>
                        <th style="width:160px;">วันที่อัปเดต</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($latestRows)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีข้อมูลทรัพย์สินที่อัปเดต</td></tr>
                    <?php else: ?>
                        <?php foreach ($latestRows as $index => $row): ?>
                            <tr>
                                <td class="text-center"><?php echo number_format($index + 1); ?></td>
                                <td class="fw-bold text-primary"><?php echo aiE($row['name_new'] ?? '-'); ?></td>
                                <td><?php echo aiE($row['name_old'] ?? '-'); ?></td>
                                <td><?php echo aiE($row['asset_value'] ?? '-'); ?></td>
                                <td><?php echo aiE($row['updated_date'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
