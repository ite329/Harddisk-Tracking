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

function getRequestValue(array $names): string
{
    foreach ($names as $name) {
        if (isset($_POST[$name]) && trim((string)$_POST[$name]) !== '') {
            return trim((string)$_POST[$name]);
        }

        if (isset($_GET[$name]) && trim((string)$_GET[$name]) !== '') {
            return trim((string)$_GET[$name]);
        }
    }

    return '';
}

function extractCostCenter(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/\b\d{4,}\b/', $value, $matches)) {
        return $matches[0];
    }

    return '';
}

function formatMainBranchCode($value): string
{
    $value = trim((string)($value ?? ''));

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
        'selected_cost_center',
        'branch',
        'selected_branch'
    ]);

    $branchName = getRequestValue([
        'branch_name',
        'selected_branch_name',
        'branch_text',
        'selected_branch_text'
    ]);

    $selectedCostCenter = extractCostCenter($rawBranchCode);

    if ($selectedCostCenter === '') {
        $selectedCostCenter = extractCostCenter($branchName);
    }

    if ($selectedCostCenter === '') {
        foreach (array_merge($_GET, $_POST) as $value) {
            if (is_array($value)) {
                continue;
            }

            $selectedCostCenter = extractCostCenter((string)$value);

            if ($selectedCostCenter !== '') {
                break;
            }
        }
    }

    $branchDirectoryColumns = getTableColumns($pdo, 'branch_directory');

    if (!hasColumn($branchDirectoryColumns, 'branch_code')) {
        throw new Exception('ตาราง branch_directory ไม่มีคอลัมน์ branch_code');
    }

    if ($selectedCostCenter === '' && $branchName !== '') {
        $branchWhere = [];
        $branchParams = [];

        if (hasColumn($branchDirectoryColumns, 'branch_name')) {
            $branchWhere[] = 'branch_name = :branch_name';
            $branchParams[':branch_name'] = $branchName;
        }

        if ($mainBranchCode !== '' && hasColumn($branchDirectoryColumns, 'main_branch_code')) {
            $branchWhere[] = "LPAD(main_branch_code, 3, '0') = :main_branch_code";
            $branchParams[':main_branch_code'] = $mainBranchCode;
        }

        if (hasColumn($branchDirectoryColumns, 'is_active')) {
            $branchWhere[] = 'is_active = 1';
        }

        if (!empty($branchWhere)) {
            $stmtFindBranch = $pdo->prepare("
                SELECT main_branch_code, branch_code, branch_name
                FROM branch_directory
                WHERE " . implode(' AND ', $branchWhere) . "
                LIMIT 1
            ");
            $stmtFindBranch->execute($branchParams);
            $foundBranch = $stmtFindBranch->fetch(PDO::FETCH_ASSOC);

            if ($foundBranch) {
                $selectedCostCenter = trim((string)$foundBranch['branch_code']);
            }
        }
    }

    $selectedBranch = null;

    if ($selectedCostCenter !== '') {
        $branchWhere = [
            'branch_code = :branch_code'
        ];

        $branchParams = [
            ':branch_code' => $selectedCostCenter
        ];

        if ($mainBranchCode !== '' && hasColumn($branchDirectoryColumns, 'main_branch_code')) {
            $branchWhere[] = "LPAD(main_branch_code, 3, '0') = :main_branch_code";
            $branchParams[':main_branch_code'] = $mainBranchCode;
        }

        if (hasColumn($branchDirectoryColumns, 'is_active')) {
            $branchWhere[] = 'is_active = 1';
        }

        $stmtBranch = $pdo->prepare("
            SELECT
                main_branch_code,
                branch_code,
                branch_name
            FROM branch_directory
            WHERE " . implode(' AND ', $branchWhere) . "
            LIMIT 1
        ");
        $stmtBranch->execute($branchParams);
        $selectedBranch = $stmtBranch->fetch(PDO::FETCH_ASSOC);
    }

    if (!$selectedBranch) {
        jsonResponse([
            'success' => true,
            'exists' => false,
            'count' => 0,
            'message' => 'ยังไม่พบ Cost Center ของสาขาที่เลือก จึงยังไม่ตรวจสอบประวัติการจัดส่ง',
            'selected_branch' => null,
            'debug' => [
                'received_main_branch_code' => $mainBranchCode,
                'received_branch_code' => $rawBranchCode,
                'received_branch_name' => $branchName,
                'selected_cost_center' => $selectedCostCenter
            ],
            'data' => []
        ]);
    }

    /*
     * จุดสำคัญ:
     * เอา Cost Center จาก branch_directory.branch_code
     * ไปเทียบกับ harddisk_shipments.branch_code
     */
    $compareCostCenter = trim((string)$selectedBranch['branch_code']);

    $shipmentColumns = getTableColumns($pdo, 'harddisk_shipments');

    if (!hasColumn($shipmentColumns, 'branch_code')) {
        throw new Exception('ตาราง harddisk_shipments ไม่มีคอลัมน์ branch_code');
    }

    $where = [
        'branch_code = :branch_code'
    ];

    $params = [
        ':branch_code' => $compareCostCenter
    ];

    if (hasColumn($shipmentColumns, 'deleted_at')) {
        $where[] = 'deleted_at IS NULL';
    }

    $selectColumns = [];

    foreach ([
        'id',
        'delivery_request_no',
        'main_branch_code',
        'branch_code',
        'branch_name',
        'hdd_serial',
        'shipment_status',
        'status',
        'shipped_date',
        'reported_by',
        'created_by',
        'remark',
        'created_at'
    ] as $column) {
        if (hasColumn($shipmentColumns, $column)) {
            $selectColumns[] = $column;
        }
    }

    if (empty($selectColumns)) {
        $selectColumns[] = 'id';
    }

    $stmt = $pdo->prepare("
        SELECT " . implode(', ', $selectColumns) . "
        FROM harddisk_shipments
        WHERE " . implode(' AND ', $where) . "
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        if (isset($row['main_branch_code'])) {
            $row['main_branch_code'] = formatMainBranchCode($row['main_branch_code']);
        }
    }
    unset($row);

    jsonResponse([
        'success' => true,
        'exists' => count($rows) > 0,
        'count' => count($rows),
        'message' => count($rows) > 0
            ? 'พบประวัติการจัดส่ง HDD ของ Cost Center นี้'
            : 'ไม่พบประวัติการจัดส่ง HDD ของ Cost Center นี้',
        'selected_branch' => [
            'main_branch_code' => formatMainBranchCode($selectedBranch['main_branch_code'] ?? ''),
            'branch_code' => $compareCostCenter,
            'branch_name' => $selectedBranch['branch_name'] ?? ''
        ],
        'debug' => [
            'compare_cost_center_from_branch_directory' => $compareCostCenter,
            'compare_with' => 'harddisk_shipments.branch_code'
        ],
        'data' => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    jsonResponse([
        'success' => false,
        'exists' => false,
        'count' => 0,
        'message' => 'API Error: ' . $e->getMessage(),
        'data' => []
    ]);
}