<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('branchImportE')) {
    function branchImportE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('branchImportCurrentEmployeeCode')) {
    function branchImportCurrentEmployeeCode(): string
    {
        if (function_exists('current_employee_code')) {
            return trim((string)current_employee_code());
        }

        $keys = ['employee_code', 'employee_id', 'emp_code', 'emp_id', 'username', 'user_id', 'id'];
        foreach ($keys as $key) {
            if (!empty($_SESSION[$key])) {
                return trim((string)$_SESSION[$key]);
            }
        }

        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach ($keys as $key) {
                if (!empty($_SESSION['user'][$key])) {
                    return trim((string)$_SESSION['user'][$key]);
                }
            }
        }

        return '';
    }
}

if (!function_exists('branchImportCurrentUserDisplay')) {
    function branchImportCurrentUserDisplay(): string
    {
        $nameKeys = ['full_name', 'name', 'display_name', 'username', 'employee_code', 'employee_id', 'id'];
        foreach ($nameKeys as $key) {
            if (!empty($_SESSION[$key])) {
                return trim((string)$_SESSION[$key]);
            }
        }
        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach ($nameKeys as $key) {
                if (!empty($_SESSION['user'][$key])) {
                    return trim((string)$_SESSION['user'][$key]);
                }
            }
        }
        return branchImportCurrentEmployeeCode() !== '' ? branchImportCurrentEmployeeCode() : 'system';
    }
}

if (!function_exists('branchImportCanAccess')) {
    function branchImportCanAccess(): bool
    {
        if (function_exists('is_super_admin_employee') && is_super_admin_employee()) {
            return true;
        }
        if (function_exists('current_user_role') && current_user_role() === 'super_admin') {
            return true;
        }
        return in_array(branchImportCurrentEmployeeCode(), ['14329', '10057'], true);
    }
}

if (!function_exists('branchImportRequireAccess')) {
    function branchImportRequireAccess(): void
    {
        if (!branchImportCanAccess()) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><div style="font-family:Arial,sans-serif;padding:24px;color:#7f1d1d;background:#fff1f2;border:1px solid #fecdd3;border-radius:12px;margin:24px;">ไม่มีสิทธิ์ใช้งานหน้านี้ เฉพาะผู้ดูแลระบบเท่านั้น</div>';
            exit;
        }
    }
}

if (!function_exists('branchImportTableExists')) {
    function branchImportTableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
            $stmt->execute([':table_name' => $tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('branchImportColumnExists')) {
    function branchImportColumnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
            $stmt->execute([':table_name' => $tableName, ':column_name' => $columnName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('branchImportTableColumns')) {
    function branchImportTableColumns(PDO $pdo, string $tableName): array
    {
        $columns = [];
        try {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
            $stmt->execute([':table_name' => $tableName]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
                $columns[(string)$column] = true;
            }
        } catch (Throwable $e) {
            return [];
        }
        return $columns;
    }
}

if (!function_exists('branchImportCsrfToken')) {
    function branchImportCsrfToken(): string
    {
        if (empty($_SESSION['branch_import_csrf'])) {
            $_SESSION['branch_import_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['branch_import_csrf'];
    }
}

if (!function_exists('branchImportVerifyCsrf')) {
    function branchImportVerifyCsrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? '');
        if (empty($_SESSION['branch_import_csrf']) || !hash_equals($_SESSION['branch_import_csrf'], $token)) {
            throw new RuntimeException('Session หมดอายุหรือ Token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        }
    }
}

if (!function_exists('branchImportBatchNo')) {
    function branchImportBatchNo(): string
    {
        return 'BRI' . date('YmdHis') . random_int(100, 999);
    }
}

if (!function_exists('branchImportToUtf8')) {
    function branchImportToUtf8(string $content): string
    {
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $encodings = ['UTF-8', 'UTF-8-SIG', 'Windows-874', 'TIS-620', 'ISO-8859-11'];
        if (function_exists('mb_convert_encoding')) {
            foreach ($encodings as $encoding) {
                $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }

        foreach (['CP874', 'TIS-620', 'ISO-8859-11'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $content);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $content;
    }
}


if (!function_exists('branchImportLower')) {
    function branchImportLower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('branchImportNormalizeHeader')) {
    function branchImportNormalizeHeader($value): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = branchImportLower($value);
        $value = str_replace([' ', '-', '/', '.', '(', ')', '[', ']'], '', $value);
        return trim($value);
    }
}

if (!function_exists('branchImportColumnAliases')) {
    function branchImportColumnAliases(): array
    {
        return [
            'main_branch_code' => ['main_branch_code', 'mainbranchcode', 'รหัสสาขาใหญ่', 'รหัสสาขาหลัก', 'maincode'],
            'branch_code' => ['branch_code', 'branchcode', 'cost_center', 'costcenter', 'costcentre', 'cc', 'รหัสสาขา', 'costcenter', 'ศูนย์ต้นทุน', 'รหัสศูนย์ต้นทุน'],
            'branch_name' => ['branch_name', 'branchname', 'ชื่อสาขา', 'สาขา', 'ชื่อ'],
            'branch_name_2' => ['branch_name_2', 'branchname2', 'ชื่อสาขา2', 'ชื่อสาขา_2'],
            'branch_type' => ['branch_type', 'branchtype', 'ประเภทสาขา', 'ประเภทของสาขา', 'ชนิดสาขา'],
            'full_address' => ['full_address', 'fulladdress', 'ที่อยู่สาขา', 'ที่อยู่เต็ม', 'ที่อยู่'],
            'phone' => ['phone', 'tel', 'telephone', 'เบอร์โทร', 'เบอร์โทรศัพท์', 'โทรศัพท์'],
            'landmark' => ['landmark', 'สถานที่ใกล้เคียง', 'จุดสังเกต'],
            'area_code' => ['area_code', 'areacode', 'สังกัดเขต', 'เขต'],
            'hierarchy_area' => ['hierarchy_area', 'hierarchyarea', 'hierarchy'],
            'address_line' => ['address_line', 'addressline', 'บ้านเลขที่หมู่ถนนซอย', 'บ้านเลขที่หมู่ถนนซอย'],
            'subdistrict' => ['subdistrict', 'ตำบล', 'แขวง'],
            'district' => ['district', 'อำเภอ', 'เขต'],
            'province' => ['province', 'จังหวัด'],
            'postal_code' => ['postal_code', 'postalcode', 'zipcode', 'รหัสไปรษณีย์'],
            'bot_registered_date' => ['bot_registered_date', 'botregistereddate', 'ธปท', 'วันที่จดทะเบียนธปท'],
            'opening_date' => ['opening_date', 'openingdate', 'วันเปิดสาขา', 'วันที่เปิดสาขา'],
            'dbd_registration_no' => ['dbd_registration_no', 'dbdregistrationno', 'dba_registration_no', 'เลขทะเบียนพาณิชย์', 'ลำดับจดทะเบียนกรมพัฒน์'],
            'latitude' => ['latitude', 'lat', 'ละติจูด'],
            'longitude' => ['longitude', 'lng', 'long', 'ลองจิจูด'],
            'payment_machine_no' => ['payment_machine_no', 'paymentmachineno', 'หมายเลขเครื่องรับชำระเงิน'],
            'ptd20_registered_date' => ['ptd20_registered_date', 'ptd20registereddate', 'วันที่จดทะเบียนภธ20', 'ภธ20'],
            'pp20_registered_date' => ['pp20_registered_date', 'pp20registereddate', 'วันที่จดทะเบียนภพ20', 'ภพ20'],
            'is_active' => ['is_active', 'isactive', 'status', 'สถานะใช้งาน', 'ใช้งาน'],
            'source_file' => ['source_file', 'sourcefile', 'ไฟล์ต้นทาง', 'ชื่อไฟล์'],
        ];
    }
}

if (!function_exists('branchImportBuildHeaderMap')) {
    function branchImportBuildHeaderMap(array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $normalizedHeaders[branchImportNormalizeHeader($header)] = $index;
        }

        $map = [];
        foreach (branchImportColumnAliases() as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = branchImportNormalizeHeader($alias);
                if (array_key_exists($key, $normalizedHeaders)) {
                    $map[$field] = $normalizedHeaders[$key];
                    break;
                }
            }
        }
        return $map;
    }
}

if (!function_exists('branchImportNormalizeDate')) {
    function branchImportNormalizeDate($value)
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || $value === '-' || $value === '0000-00-00' || $value === '00/00/0000') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : null;
    }
}

if (!function_exists('branchImportNormalizeActive')) {
    function branchImportNormalizeActive($value): int
    {
        $value = mb_strtolower(trim((string)($value ?? '1')), 'UTF-8');
        if ($value === '' || $value === '1' || $value === 'active' || $value === 'yes' || $value === 'y' || $value === 'ใช้งาน' || $value === 'เปิด') {
            return 1;
        }
        if ($value === '0' || $value === 'inactive' || $value === 'no' || $value === 'n' || $value === 'ไม่ใช้งาน' || $value === 'ปิด') {
            return 0;
        }
        return 1;
    }
}

if (!function_exists('branchImportNormalizeDecimal')) {
    function branchImportNormalizeDecimal($value)
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || $value === '-') {
            return null;
        }
        $value = str_replace(',', '', $value);
        return is_numeric($value) ? $value : null;
    }
}

if (!function_exists('branchImportNormalizeValue')) {
    function branchImportNormalizeValue(string $field, $value, string $originalFilename)
    {
        $value = trim((string)($value ?? ''));

        if (in_array($field, ['bot_registered_date', 'opening_date', 'ptd20_registered_date', 'pp20_registered_date'], true)) {
            return branchImportNormalizeDate($value);
        }

        if (in_array($field, ['latitude', 'longitude'], true)) {
            return branchImportNormalizeDecimal($value);
        }

        if ($field === 'is_active') {
            return branchImportNormalizeActive($value);
        }

        if ($field === 'branch_type') {
            $normalized = branchImportLower($value);
            $normalized = preg_replace('/\s+/u', '', $normalized);

            $headOfficeTypes = ['h', 'สำนักงานใหญ่', 'headoffice', 'headquarters'];
            $mainTypes = ['b', 'ใหญ่', 'สาขาใหญ่', 'main', 'mainbranch'];
            $subTypes = ['s', 'ย่อย', 'สาขาย่อย', 'sub', 'subbranch'];
            $serviceTypes = ['c', 'ศูนย์', 'ศูนย์ฯ', 'ศูนย์บริการ', 'service', 'servicecenter'];

            if (in_array($normalized, $headOfficeTypes, true)) {
                return 'สำนักงานใหญ่';
            }
            if (in_array($normalized, $mainTypes, true)) {
                return 'สาขาใหญ่';
            }
            if (in_array($normalized, $subTypes, true)) {
                return 'สาขาย่อย';
            }
            if (in_array($normalized, $serviceTypes, true)) {
                return 'ศูนย์บริการ';
            }

            return $value === '' ? null : $value;
        }

        if ($field === 'source_file' && $value === '') {
            return $originalFilename;
        }

        return $value === '' ? null : $value;
    }
}

if (!function_exists('branchImportAllowedColumns')) {
    function branchImportAllowedColumns(): array
    {
        return [
            'main_branch_code',
            'branch_code',
            'branch_name',
            'branch_name_2',
            'branch_type',
            'full_address',
            'phone',
            'landmark',
            'area_code',
            'hierarchy_area',
            'address_line',
            'subdistrict',
            'district',
            'province',
            'postal_code',
            'bot_registered_date',
            'opening_date',
            'dbd_registration_no',
            'latitude',
            'longitude',
            'payment_machine_no',
            'ptd20_registered_date',
            'pp20_registered_date',
            'is_active',
            'source_file',
        ];
    }
}

if (!function_exists('branchImportJson')) {
    function branchImportJson($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }
}

if (!function_exists('branchImportDecodeJson')) {
    function branchImportDecodeJson($json): array
    {
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('branchImportActionBadge')) {
    function branchImportActionBadge(string $action): string
    {
        $labels = [
            'insert' => ['เพิ่มใหม่', 'success'],
            'update' => ['อัปเดต', 'primary'],
            'unchanged' => ['ไม่เปลี่ยนแปลง', 'secondary'],
            'error' => ['Error', 'danger'],
        ];
        $item = $labels[$action] ?? [$action, 'secondary'];
        return '<span class="badge bg-' . $item[1] . '">' . branchImportE($item[0]) . '</span>';
    }
}


if (!function_exists('branchImportStatusBadge')) {
    function branchImportStatusBadge(string $status): string
    {
        $map = [
            'validated' => ['validated', 'secondary'],
            'imported' => ['imported', 'success'],
            'failed' => ['failed', 'danger'],
            'cancelled' => ['cancelled', 'warning text-dark'],
            'uploaded' => ['uploaded', 'info text-dark'],
        ];
        $item = $map[$status] ?? [$status, 'secondary'];
        return '<span class="badge bg-' . $item[1] . '">' . branchImportE($item[0]) . '</span>';
    }
}

if (!function_exists('branchImportReadCsvRows')) {
    function branchImportReadCsvRows(string $storedPath): array
    {
        $content = file_get_contents($storedPath);
        if ($content === false) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์ที่อัปโหลดได้');
        }

        $content = branchImportToUtf8($content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $fp = fopen('php://temp', 'r+');
        if (!$fp) {
            throw new RuntimeException('ไม่สามารถเตรียมพื้นที่อ่านไฟล์ CSV ได้');
        }
        fwrite($fp, $content);
        rewind($fp);

        $headers = fgetcsv($fp);
        if (!is_array($headers) || count($headers) === 0) {
            throw new RuntimeException('ไม่พบหัวคอลัมน์ในไฟล์ CSV');
        }

        $rows = [];
        $rowNo = 1;
        while (($row = fgetcsv($fp)) !== false) {
            $rowNo++;
            $hasData = false;
            foreach ($row as $cell) {
                if (trim((string)$cell) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                continue;
            }
            $rows[] = ['row_no' => $rowNo, 'row' => $row];
        }
        fclose($fp);

        return ['headers' => $headers, 'rows' => $rows];
    }
}

if (!function_exists('branchImportCreatePreviewBatch')) {
    function branchImportCreatePreviewBatch(PDO $pdo, string $storedPath, string $originalFilename, array $options): int
    {
        if (!branchImportTableExists($pdo, 'branch_import_batches') || !branchImportTableExists($pdo, 'branch_import_rows')) {
            throw new RuntimeException('ยังไม่ได้สร้างตาราง Log สำหรับ Import กรุณารันไฟล์ sql/branch_import_log.sql ก่อน');
        }
        if (!branchImportTableExists($pdo, 'branch_directory')) {
            throw new RuntimeException('ไม่พบตาราง branch_directory');
        }

        $parsed = branchImportReadCsvRows($storedPath);
        $headers = $parsed['headers'];
        $rows = $parsed['rows'];
        $headerMap = branchImportBuildHeaderMap($headers);

        if (!array_key_exists('branch_code', $headerMap)) {
            throw new RuntimeException('ไม่พบคอลัมน์ Cost Center / branch_code ในไฟล์');
        }
        if (!array_key_exists('branch_name', $headerMap)) {
            throw new RuntimeException('ไม่พบคอลัมน์ชื่อสาขา / branch_name ในไฟล์');
        }

        $tableColumns = branchImportTableColumns($pdo, 'branch_directory');
        $allowedColumns = array_values(array_filter(branchImportAllowedColumns(), static function ($column) use ($tableColumns) {
            return isset($tableColumns[$column]);
        }));

        $batchNo = branchImportBatchNo();
        $importMonth = (string)$options['import_month'];
        $uploadedBy = branchImportCurrentUserDisplay();
        $allowInsertNew = !empty($options['allow_insert_new']) ? 1 : 0;
        $allowUpdateExisting = !empty($options['allow_update_existing']) ? 1 : 0;
        $allowBlankOverwrite = !empty($options['allow_blank_overwrite']) ? 1 : 0;
        $deactivateMissing = !empty($options['deactivate_missing']) ? 1 : 0;

        $stats = [
            'total_rows' => 0,
            'new_rows' => 0,
            'updated_rows' => 0,
            'unchanged_rows' => 0,
            'error_rows' => 0,
        ];

        $pdo->beginTransaction();
        try {
            $stmtBatch = $pdo->prepare("INSERT INTO branch_import_batches
                (batch_no, import_month, original_filename, stored_filename, status, allow_insert_new, allow_blank_overwrite, deactivate_missing, uploaded_by, uploaded_at, created_at)
                VALUES
                (:batch_no, :import_month, :original_filename, :stored_filename, 'validated', :allow_insert_new, :allow_blank_overwrite, :deactivate_missing, :uploaded_by, NOW(), NOW())");
            $stmtBatch->execute([
                ':batch_no' => $batchNo,
                ':import_month' => $importMonth,
                ':original_filename' => $originalFilename,
                ':stored_filename' => $storedPath,
                ':allow_insert_new' => $allowInsertNew,
                ':allow_blank_overwrite' => $allowBlankOverwrite,
                ':deactivate_missing' => $deactivateMissing,
                ':uploaded_by' => $uploadedBy,
            ]);
            $batchId = (int)$pdo->lastInsertId();

            $selectExisting = $pdo->prepare('SELECT * FROM branch_directory WHERE branch_code = :branch_code LIMIT 1');
            $insertRow = $pdo->prepare("INSERT INTO branch_import_rows
                (batch_id, row_no, main_branch_code, branch_code, branch_name, full_address, phone, landmark, is_active, action_type, error_message, old_data, new_data, created_at)
                VALUES
                (:batch_id, :row_no, :main_branch_code, :branch_code, :branch_name, :full_address, :phone, :landmark, :is_active, :action_type, :error_message, :old_data, :new_data, NOW())");

            foreach ($rows as $rowInfo) {
                $stats['total_rows']++;
                $rowNo = (int)$rowInfo['row_no'];
                $row = $rowInfo['row'];
                $input = [];
                foreach ($allowedColumns as $field) {
                    if (array_key_exists($field, $headerMap)) {
                        $cellIndex = $headerMap[$field];
                        $input[$field] = branchImportNormalizeValue($field, $row[$cellIndex] ?? null, $originalFilename);
                    }
                }

                if (!array_key_exists('source_file', $input) && isset($tableColumns['source_file'])) {
                    $input['source_file'] = $originalFilename;
                }

                $branchCode = trim((string)($input['branch_code'] ?? ''));
                $branchName = trim((string)($input['branch_name'] ?? ''));
                $errorMessage = '';
                $oldData = [];
                $newData = [];
                $actionType = 'unchanged';

                if ($branchCode === '') {
                    $errorMessage = 'Cost Center / branch_code ว่าง';
                    $actionType = 'error';
                } elseif ($branchName === '') {
                    $errorMessage = 'ชื่อสาขาว่าง';
                    $actionType = 'error';
                } else {
                    $selectExisting->execute([':branch_code' => $branchCode]);
                    $existing = $selectExisting->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        $oldData = $existing;
                        foreach ($allowedColumns as $field) {
                            if (array_key_exists($field, $input)) {
                                $newValue = $input[$field];
                                if (!$allowBlankOverwrite && ($newValue === null || $newValue === '') && $field !== 'is_active') {
                                    $newValue = $existing[$field] ?? null;
                                }
                                $newData[$field] = $newValue;
                            } else {
                                $newData[$field] = $existing[$field] ?? null;
                            }
                        }

                        $changed = false;
                        foreach ($newData as $field => $newValue) {
                            $oldValue = $existing[$field] ?? null;
                            if ((string)($oldValue ?? '') !== (string)($newValue ?? '')) {
                                $changed = true;
                                break;
                            }
                        }

                        if (!$changed) {
                            $actionType = 'unchanged';
                        } elseif ($allowUpdateExisting) {
                            $actionType = 'update';
                        } else {
                            $actionType = 'error';
                            $errorMessage = 'พบ Cost Center เดิม แต่ไม่อนุญาตให้อัปเดตข้อมูลเดิม';
                        }
                    } else {
                        foreach ($allowedColumns as $field) {
                            $newData[$field] = $input[$field] ?? null;
                        }

                        if ($allowInsertNew) {
                            $actionType = 'insert';
                        } else {
                            $actionType = 'error';
                            $errorMessage = 'ไม่พบ Cost Center เดิม และไม่อนุญาตให้เพิ่มสาขาใหม่';
                        }
                    }
                }

                if ($actionType === 'insert') {
                    $stats['new_rows']++;
                } elseif ($actionType === 'update') {
                    $stats['updated_rows']++;
                } elseif ($actionType === 'unchanged') {
                    $stats['unchanged_rows']++;
                } else {
                    $stats['error_rows']++;
                }

                $insertRow->execute([
                    ':batch_id' => $batchId,
                    ':row_no' => $rowNo,
                    ':main_branch_code' => $newData['main_branch_code'] ?? $input['main_branch_code'] ?? null,
                    ':branch_code' => $branchCode !== '' ? $branchCode : ($input['branch_code'] ?? null),
                    ':branch_name' => $branchName !== '' ? $branchName : ($input['branch_name'] ?? null),
                    ':full_address' => $newData['full_address'] ?? $input['full_address'] ?? null,
                    ':phone' => $newData['phone'] ?? $input['phone'] ?? null,
                    ':landmark' => $newData['landmark'] ?? $input['landmark'] ?? null,
                    ':is_active' => $newData['is_active'] ?? $input['is_active'] ?? 1,
                    ':action_type' => $actionType,
                    ':error_message' => $errorMessage !== '' ? $errorMessage : null,
                    ':old_data' => branchImportJson($oldData),
                    ':new_data' => branchImportJson($newData),
                ]);
            }

            $stmtUpdate = $pdo->prepare("UPDATE branch_import_batches
                SET total_rows = :total_rows,
                    new_rows = :new_rows,
                    updated_rows = :updated_rows,
                    unchanged_rows = :unchanged_rows,
                    error_rows = :error_rows,
                    updated_at = NOW()
                WHERE id = :id");
            $stmtUpdate->execute([
                ':total_rows' => $stats['total_rows'],
                ':new_rows' => $stats['new_rows'],
                ':updated_rows' => $stats['updated_rows'],
                ':unchanged_rows' => $stats['unchanged_rows'],
                ':error_rows' => $stats['error_rows'],
                ':id' => $batchId,
            ]);

            $pdo->commit();
            return $batchId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}


if (!function_exists('branchImportCancelBatch')) {
    function branchImportCancelBatch(PDO $pdo, int $batchId): void
    {
        $batch = branchImportGetBatch($pdo, $batchId);
        if (!$batch) {
            throw new RuntimeException('ไม่พบข้อมูล Batch');
        }
        if (($batch['status'] ?? '') === 'imported') {
            throw new RuntimeException('Batch นี้ถูก Import ไปแล้ว ไม่สามารถยกเลิกได้');
        }
        if (($batch['status'] ?? '') === 'cancelled') {
            return;
        }

        $stmt = $pdo->prepare("UPDATE branch_import_batches
            SET status = 'cancelled',
                updated_at = NOW()
            WHERE id = :id");
        $stmt->execute([':id' => $batchId]);
    }
}

if (!function_exists('branchImportGetBatch')) {
    function branchImportGetBatch(PDO $pdo, int $batchId)
    {
        $stmt = $pdo->prepare('SELECT * FROM branch_import_batches WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $batchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
