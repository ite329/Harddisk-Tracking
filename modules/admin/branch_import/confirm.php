<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/includes/import_helpers.php';

branchImportRequireAccess();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method ไม่ถูกต้อง');
    }

    branchImportVerifyCsrf();

    $batchId = (int)($_POST['batch_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? 'confirm'));
    if ($batchId <= 0) {
        throw new RuntimeException('ไม่พบ Batch ที่ต้องการดำเนินการ');
    }

    $batch = branchImportGetBatch($pdo, $batchId);
    if (!$batch) {
        throw new RuntimeException('ไม่พบข้อมูล Batch');
    }

    if ($action === 'cancel') {
        branchImportCancelBatch($pdo, $batchId);
        header('Location: preview.php?batch_id=' . $batchId . '&cancelled=1');
        exit;
    }

    if ($batch['status'] !== 'validated') {
        throw new RuntimeException('Batch นี้ไม่อยู่ในสถานะที่สามารถ Import ได้');
    }

    $tableColumns = branchImportTableColumns($pdo, 'branch_directory');
    $allowedColumns = array_values(array_filter(branchImportAllowedColumns(), static function ($column) use ($tableColumns) {
        return isset($tableColumns[$column]);
    }));

    if (!isset($tableColumns['branch_code']) || !isset($tableColumns['branch_name'])) {
        throw new RuntimeException('ตาราง branch_directory ไม่มีคอลัมน์หลัก branch_code / branch_name');
    }

    $pdo->beginTransaction();

    $stmtRows = $pdo->prepare("SELECT * FROM branch_import_rows WHERE batch_id = :batch_id AND action_type IN ('insert','update') ORDER BY row_no ASC");
    $stmtRows->execute([':batch_id' => $batchId]);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

    $importedCount = 0;

    foreach ($rows as $row) {
        $data = branchImportDecodeJson($row['new_data'] ?? '{}');
        if (empty($data['branch_code']) || empty($data['branch_name'])) {
            continue;
        }

        $actionType = (string)$row['action_type'];
        if ($actionType === 'insert') {
            $columns = [];
            $placeholders = [];
            $params = [];

            foreach ($allowedColumns as $column) {
                if (!array_key_exists($column, $data)) {
                    continue;
                }
                $columns[] = $column;
                $placeholder = ':' . $column;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $data[$column];
            }

            if (isset($tableColumns['created_at'])) {
                $columns[] = 'created_at';
                $placeholders[] = 'NOW()';
            }
            if (isset($tableColumns['updated_at'])) {
                $columns[] = 'updated_at';
                $placeholders[] = 'NOW()';
            }

            $sql = 'INSERT INTO branch_directory (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $importedCount++;
        } elseif ($actionType === 'update') {
            $sets = [];
            $params = [':branch_code_key' => $data['branch_code']];

            foreach ($allowedColumns as $column) {
                if ($column === 'branch_code') {
                    continue;
                }
                if (!array_key_exists($column, $data)) {
                    continue;
                }
                $placeholder = ':' . $column;
                $sets[] = $column . ' = ' . $placeholder;
                $params[$placeholder] = $data[$column];
            }

            if (isset($tableColumns['updated_at'])) {
                $sets[] = 'updated_at = NOW()';
            }

            if (!empty($sets)) {
                $sql = 'UPDATE branch_directory SET ' . implode(', ', $sets) . ' WHERE branch_code = :branch_code_key LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $importedCount++;
            }
        }
    }

    if ((int)$batch['deactivate_missing'] === 1 && isset($tableColumns['is_active'])) {
        $sqlDeactivate = "UPDATE branch_directory
            SET is_active = 0" . (isset($tableColumns['updated_at']) ? ', updated_at = NOW()' : '') . "
            WHERE branch_code NOT IN (
                SELECT branch_code FROM branch_import_rows
                WHERE batch_id = :batch_id
                  AND action_type IN ('insert','update','unchanged')
                  AND branch_code IS NOT NULL
                  AND branch_code <> ''
            )";
        $stmtDeactivate = $pdo->prepare($sqlDeactivate);
        $stmtDeactivate->execute([':batch_id' => $batchId]);
    }

    $stmtUpdateBatch = $pdo->prepare("UPDATE branch_import_batches
        SET status = 'imported',
            imported_by = :imported_by,
            imported_at = NOW(),
            updated_at = NOW(),
            remark = :remark
        WHERE id = :id");
    $stmtUpdateBatch->execute([
        ':imported_by' => branchImportCurrentUserDisplay(),
        ':remark' => 'Imported valid rows: ' . $importedCount,
        ':id' => $batchId,
    ]);

    $pdo->commit();
    header('Location: preview.php?batch_id=' . $batchId . '&imported=1');
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (($action ?? 'confirm') !== 'cancel' && !empty($batchId ?? 0)) {
        try {
            $stmtFail = $pdo->prepare("UPDATE branch_import_batches SET status = 'failed', remark = :remark, updated_at = NOW() WHERE id = :id");
            $stmtFail->execute([':remark' => $e->getMessage(), ':id' => $batchId]);
        } catch (Throwable $ignore) {
            // ignore
        }
    }

    $id = !empty($batchId) ? (int)$batchId : 0;
    header('Location: preview.php?batch_id=' . $id . '&error=' . urlencode($e->getMessage()));
    exit;
}
