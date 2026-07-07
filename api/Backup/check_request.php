<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getPdoConnection(): PDO
{
    $possibleFiles = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../config/db.php',
        __DIR__ . '/../includes/database.php',
        __DIR__ . '/../includes/db.php',
    ];

    foreach ($possibleFiles as $file) {
        if (!file_exists($file)) {
            continue;
        }

        require_once $file;

        if (isset($pdo) && $pdo instanceof PDO) {
            return $pdo;
        }

        if (isset($conn) && $conn instanceof PDO) {
            return $conn;
        }

        if (isset($db) && $db instanceof PDO) {
            return $db;
        }

        if (function_exists('getConnection')) {
            $connection = getConnection();
            if ($connection instanceof PDO) {
                return $connection;
            }
        }

        if (function_exists('getPdo')) {
            $connection = getPdo();
            if ($connection instanceof PDO) {
                return $connection;
            }
        }
    }

    throw new Exception('ไม่พบไฟล์เชื่อมต่อฐานข้อมูล PDO');
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $columnName): bool
{
    return in_array($columnName, $columns, true);
}

function cleanValue($value): string
{
    return trim((string)($value ?? ''));
}

function getRequestValue(array $names): string
{
    foreach ($names as $name) {
        if (isset($_POST[$name]) && cleanValue($_POST[$name]) !== '') {
            return cleanValue($_POST[$name]);
        }

        if (isset($_GET[$name]) && cleanValue($_GET[$name]) !== '') {
            return cleanValue($_GET[$name]);
        }
    }

    return '';
}

function extractCostCenter(string $value): string
{
    $value = cleanValue($value);

    if ($value === '') {
        return '';
    }

    /*
     * Cost Center เช่น 2002424, 2002799
     * ใช้เลขตั้งแต่ 4 หลักขึ้นไป
     * ไม่ใช้รหัสสาขาใหญ่ เช่น 017
     */
    if (preg_match('/\d{4,}/', $value, $matches)) {
        return $matches[0];
    }

    return '';
}

function formatMainBranchCode($value): string
{
    $value = cleanValue($value);

    if ($value === '') {
        return '';
    }

    if (is_numeric($value)) {
        $value = (string)(int)$value;
    }

    if (ctype_digit($value) && strlen($value) < 3) {
        return str_pad($value, 3, '0', STR_PAD_LEFT);
    }

    return $value;
}

function statusText(string $status): string
{
    $status = cleanValue($status);

    $map = [
        'pending' => 'รอดำเนินการ',
        'approved' => 'อนุมัติแล้ว',
        'reserved' => 'จับคู่ HDD แล้ว',
        'shipped' => 'จัดส่งแล้ว',
        'received' => 'รับแล้ว',
        'installed' => 'ติดตั้งแล้ว',
        'cancelled' => 'ยกเลิก',
        'rejected' => 'ไม่อนุมัติ',
        'completed' => 'เสร็จสิ้น',
    ];

    return $map[$status] ?? $status;
}

try {
    $pdo = getPdoConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $mainBranchCode = formatMainBranchCode(getRequestValue([
        'main_branch_code',
        'mainBranchCode',
        'branch_main_code'
    ]));

    $rawBranchCode = getRequestValue([
        'branch_code',
        'cost_center',
        'selected_branch_code',
        'selected_cost_center'
    ]);

    $branchName = getRequestValue([
        'branch_name',
        'selected_branch_name'
    ]);

    /*
     * หา Cost Center จากค่าที่หน้า create.php ส่งมา
     */
    $costCenter = extractCostCenter($rawBranchCode);

    if ($costCenter === '') {
        $costCenter = extractCostCenter($branchName);
    }

    /*
     * เผื่อหน้าเว็บส่งค่า Cost Center มาในชื่อ field อื่น
     */
    if ($costCenter === '') {
        foreach (array_merge($_GET, $_POST) as $value) {
            if (is_array($value)) {
                continue;
            }

            $found = extractCostCenter((string)$value);

            if ($found !== '') {
                $costCenter = $found;
                break;
            }
        }
    }

    if ($costCenter === '') {
        jsonResponse([
            'success' => true,
            'exists' => false,
            'message' => 'ยังไม่ได้รับ Cost Center จึงยังไม่ตรวจสอบรายการซ้ำ',
            'selected_branch' => null,
            'debug' => [
                'received_main_branch_code' => $mainBranchCode,
                'received_branch_code' => $rawBranchCode,
                'received_branch_name' => $branchName,
                'cost_center_used_to_compare' => ''
            ],
            'data' => null
        ]);
    }

    /*
     * ตรวจว่า Cost Center นี้มีอยู่ใน branch_directory.branch_code จริง
     */
    $branchDirectoryColumns = getTableColumns($pdo, 'branch_directory');

    if (!hasColumn($branchDirectoryColumns, 'branch_code')) {
        throw new Exception('ตาราง branch_directory ไม่มีคอลัมน์ branch_code');
    }

    $branchSelectColumns = [];

    foreach (['main_branch_code', 'branch_code', 'branch_name'] as $column) {
        if (hasColumn($branchDirectoryColumns, $column)) {
            $branchSelectColumns[] = $column;
        }
    }

    if (empty($branchSelectColumns)) {
        $branchSelectColumns[] = 'branch_code';
    }

    $branchWhere = [
        'branch_code = :branch_code'
    ];

    $branchParams = [
        ':branch_code' => $costCenter
    ];

    if (hasColumn($branchDirectoryColumns, 'is_active')) {
        $branchWhere[] = 'is_active = 1';
    }

    $stmtBranch = $pdo->prepare("
        SELECT " . implode(', ', $branchSelectColumns) . "
        FROM branch_directory
        WHERE " . implode(' AND ', $branchWhere) . "
        LIMIT 1
    ");

    $stmtBranch->execute($branchParams);
    $selectedBranch = $stmtBranch->fetch(PDO::FETCH_ASSOC);

    if (!$selectedBranch) {
        jsonResponse([
            'success' => false,
            'exists' => false,
            'message' => 'ไม่พบ Cost Center นี้ในตาราง branch_directory',
            'selected_branch' => null,
            'debug' => [
                'cost_center_used_to_compare' => $costCenter,
                'compare_source' => 'branch_directory.branch_code'
            ],
            'data' => null
        ]);
    }

    /*
     * จุดสำคัญ:
     * เอา Cost Center จาก branch_directory.branch_code
     * ไปเทียบกับ harddisk_delivery_requests.branch_code
     */
    $compareCostCenter = cleanValue($selectedBranch['branch_code'] ?? $costCenter);

    $requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');

    if (!hasColumn($requestColumns, 'branch_code')) {
        throw new Exception('ตาราง harddisk_delivery_requests ไม่มีคอลัมน์ branch_code');
    }

    $requestWhere = [
        'branch_code = :branch_code'
    ];

    $requestParams = [
        ':branch_code' => $compareCostCenter
    ];

    if (hasColumn($requestColumns, 'deleted_at')) {
        $requestWhere[] = 'deleted_at IS NULL';
    }

    if (hasColumn($requestColumns, 'status')) {
        $requestWhere[] = "status NOT IN ('cancelled', 'rejected')";
    }

    /*
     * เลือกเฉพาะคอลัมน์ที่มีจริงใน harddisk_delivery_requests
     * เพื่อกัน error Unknown column เช่น created_by
     */
    $requestSelectColumns = [];

    $possibleRequestColumns = [
        'id',
        'request_no',
        'main_branch_code',
        'branch_code',
        'branch_name',
        'request_reason',
        'status',
        'created_by',
        'created_at',
        'remark'
    ];

    foreach ($possibleRequestColumns as $column) {
        if (hasColumn($requestColumns, $column)) {
            $requestSelectColumns[] = $column;
        }
    }

    if (empty($requestSelectColumns)) {
        $requestSelectColumns[] = 'id';
    }

    $stmtRequest = $pdo->prepare("
        SELECT " . implode(', ', $requestSelectColumns) . "
        FROM harddisk_delivery_requests
        WHERE " . implode(' AND ', $requestWhere) . "
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmtRequest->execute($requestParams);
    $existingRequest = $stmtRequest->fetch(PDO::FETCH_ASSOC);

    if (!$existingRequest) {
        jsonResponse([
            'success' => true,
            'exists' => false,
            'message' => 'ไม่พบรายการซ้ำของ Cost Center นี้',
            'selected_branch' => [
                'main_branch_code' => formatMainBranchCode($selectedBranch['main_branch_code'] ?? ''),
                'branch_code' => $compareCostCenter,
                'branch_name' => $selectedBranch['branch_name'] ?? ''
            ],
            'debug' => [
                'cost_center_from_branch_directory' => $compareCostCenter,
                'compare_with' => 'harddisk_delivery_requests.branch_code',
                'compare_result' => 'NOT_FOUND'
            ],
            'data' => null
        ]);
    }

    $requestCostCenter = cleanValue($existingRequest['branch_code'] ?? '');
    $status = cleanValue($existingRequest['status'] ?? '');

    jsonResponse([
        'success' => true,
        'exists' => true,
        'message' => 'พบรายการซ้ำของ Cost Center นี้',
        'selected_branch' => [
            'main_branch_code' => formatMainBranchCode($selectedBranch['main_branch_code'] ?? ''),
            'branch_code' => $compareCostCenter,
            'branch_name' => $selectedBranch['branch_name'] ?? ''
        ],
        'debug' => [
            'cost_center_from_branch_directory' => $compareCostCenter,
            'cost_center_from_request' => $requestCostCenter,
            'compare_with' => 'harddisk_delivery_requests.branch_code',
            'compare_result' => $compareCostCenter === $requestCostCenter ? 'MATCH' : 'NOT_MATCH'
        ],
        'data' => [
            'id' => $existingRequest['id'] ?? null,
            'request_no' => $existingRequest['request_no'] ?? '',
            'main_branch_code' => formatMainBranchCode($existingRequest['main_branch_code'] ?? ''),
            'branch_code' => $requestCostCenter,
            'branch_name' => $existingRequest['branch_name'] ?? '',
            'request_reason' => $existingRequest['request_reason'] ?? '',
            'status' => $status,
            'status_text' => statusText($status),
            'created_by' => $existingRequest['created_by'] ?? '',
            'created_at' => $existingRequest['created_at'] ?? '',
            'remark' => $existingRequest['remark'] ?? ''
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    jsonResponse([
        'success' => false,
        'exists' => false,
        'message' => 'API Error: ' . $e->getMessage(),
        'data' => null
    ]);
}