<?php
require_once __DIR__ . '/../../includes/auth.php';

// ป้องกัน Warning/Notice ของ PHP ปะปนกับ JSON ที่ส่งกลับ AJAX
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ob_start();

require_login();
require_permission('wcs_quote.manage');

header('Content-Type: application/json; charset=UTF-8');

function wcsImportResponse(bool $success, string $message, array $data = []): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wcsExcelColumnIndex(string $letters): int
{
    $letters = strtoupper($letters);
    $result = 0;
    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $result = ($result * 26) + (ord($letters[$i]) - 64);
    }
    return $result;
}

function wcsExcelReadSharedStrings(ZipArchive $zip): array
{
    $xmlText = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlText === false) {
        return [];
    }

    $xml = simplexml_load_string($xmlText);
    if (!$xml) {
        return [];
    }

    $result = [];
    foreach ($xml->si as $item) {
        if (isset($item->t)) {
            $result[] = (string)$item->t;
            continue;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string)$run->t;
        }
        $result[] = $text;
    }
    return $result;
}

function wcsExcelReadSheet(ZipArchive $zip, array $sharedStrings): array
{
    $workbookText = $zip->getFromName('xl/workbook.xml');
    $relsText = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookText === false || $relsText === false) {
        throw new RuntimeException('ไฟล์ Excel ไม่มีโครงสร้าง Workbook ที่ถูกต้อง');
    }

    $workbook = simplexml_load_string($workbookText);
    $rels = simplexml_load_string($relsText);
    if (!$workbook || !$rels) {
        throw new RuntimeException('ไม่สามารถอ่านโครงสร้าง Workbook ได้');
    }

    $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $sheets = $workbook->xpath('//m:sheets/m:sheet');
    if (!$sheets) {
        throw new RuntimeException('ไม่พบ Worksheet ในไฟล์ Excel');
    }

    $targetRelationId = '';
    foreach ($sheets as $sheet) {
        $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        if (trim((string)$sheet['name']) === 'ใบเสนอราคา') {
            $targetRelationId = (string)$attributes['id'];
            break;
        }
    }
    if ($targetRelationId === '') {
        $attributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $targetRelationId = (string)$attributes['id'];
    }

    $targetPath = '';
    // workbook.xml.rels ใช้ Default Namespace จึงต้องอ่านผ่าน XPath
    // การเรียก $rels->Relationship โดยตรงอาจคืนค่าว่างใน PHP บางเวอร์ชัน
    $rels->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
    foreach ($rels->xpath('//pr:Relationship') ?: [] as $relationship) {
        if ((string)$relationship['Id'] === $targetRelationId) {
            $targetPath = (string)$relationship['Target'];
            break;
        }
    }
    if ($targetPath === '') {
        throw new RuntimeException('ไม่พบไฟล์ Worksheet ของใบเสนอราคา');
    }

    $sheetPath = str_starts_with($targetPath, '/')
        ? ltrim($targetPath, '/')
        : 'xl/' . ltrim($targetPath, '/');
    $sheetPath = preg_replace('#xl/\.\./#', '', $sheetPath);

    $sheetText = $zip->getFromName($sheetPath);
    if ($sheetText === false) {
        throw new RuntimeException('ไม่สามารถเปิด Worksheet ใบเสนอราคาได้');
    }

    $sheet = simplexml_load_string($sheetText);
    if (!$sheet) {
        throw new RuntimeException('ข้อมูล Worksheet ไม่ถูกต้อง');
    }

    $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $cells = [];
    foreach ($sheet->xpath('//m:sheetData/m:row/m:c') as $cell) {
        $reference = (string)$cell['r'];
        if (!preg_match('/^([A-Z]+)(\d+)$/', $reference, $match)) {
            continue;
        }

        $type = (string)$cell['t'];
        $value = '';
        if ($type === 'inlineStr') {
            if (isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            } else {
                foreach ($cell->is->r as $run) {
                    $value .= (string)$run->t;
                }
            }
        } else {
            $raw = isset($cell->v) ? (string)$cell->v : '';
            if ($type === 's' && $raw !== '') {
                $value = $sharedStrings[(int)$raw] ?? '';
            } else {
                $value = $raw;
            }
        }

        $row = (int)$match[2];
        $column = wcsExcelColumnIndex($match[1]);
        $cells[$row][$column] = trim((string)$value);
    }

    return $cells;
}

function wcsExcelCell(array $cells, int $row, int $column): string
{
    return trim((string)($cells[$row][$column] ?? ''));
}

function wcsExcelNumber($value): float
{
    $value = str_replace([',', ' ', 'บาท'], '', trim((string)$value));
    return is_numeric($value) ? (float)$value : 0.0;
}

function wcsExcelDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (is_numeric($value)) {
        $timestamp = ((float)$value - 25569) * 86400;
        return gmdate('Y-m-d', (int)round($timestamp));
    }
    $timestamp = strtotime(str_replace('/', '-', $value));
    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
}

function wcsNormalizePrinterModel(string $text): string
{
    $models = [
        'HP LaserJet Pro M402dn',
        'HP LaserJet Pro M404dn',
        'HP LaserJet Pro MFP M426',
        'HP LaserJet Pro MFP M428fdn',
        'HP LaserJet MFP M430 series',
        'Brother DCP-L5600DN',
        'Brother MFC-L5900DW',
        'Brother MFC-L5915DW',
    ];

    $normalizedText = mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
    foreach ($models as $model) {
        $needle = mb_strtolower($model, 'UTF-8');
        if (mb_strpos($normalizedText, $needle) !== false) {
            return $model;
        }
    }

    foreach ($models as $model) {
        $short = preg_replace('/^(HP LaserJet(?: Pro)?(?: MFP)?|Brother)\s+/i', '', $model);
        if ($short !== '' && mb_stripos($normalizedText, mb_strtolower($short, 'UTF-8')) !== false) {
            return $model;
        }
    }

    return '';
}


function wcsExcelNormalizePackagePath(string $baseDir, string $target): string
{
    $target = str_replace('\\', '/', trim($target));
    if (str_starts_with($target, '/')) {
        $path = ltrim($target, '/');
    } else {
        $path = rtrim($baseDir, '/') . '/' . $target;
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function wcsExcelRelationshipMap(ZipArchive $zip, string $relsPath): array
{
    $text = $zip->getFromName($relsPath);
    if ($text === false) {
        return [];
    }

    $xml = simplexml_load_string($text);
    if (!$xml) {
        return [];
    }

    // ไฟล์ .rels ใช้ Default Namespace จึงต้องอ่านผ่าน XPath
    // การเรียก $xml->Relationship โดยตรงอาจได้ผลลัพธ์ว่างใน PHP บางเวอร์ชัน
    $xml->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relationships = $xml->xpath('//pr:Relationship');

    $map = [];
    foreach ($relationships ?: [] as $relationship) {
        $id = trim((string)$relationship['Id']);
        if ($id === '') {
            continue;
        }
        $map[$id] = [
            'target' => (string)$relationship['Target'],
            'type' => (string)$relationship['Type'],
        ];
    }

    return $map;
}

function wcsExcelWorkbookSheets(ZipArchive $zip): array
{
    $workbookText = $zip->getFromName('xl/workbook.xml');
    if ($workbookText === false) {
        return [];
    }
    $workbook = simplexml_load_string($workbookText);
    if (!$workbook) {
        return [];
    }
    $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookRels = wcsExcelRelationshipMap($zip, 'xl/_rels/workbook.xml.rels');
    $result = [];
    foreach ($workbook->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
        $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationId = (string)$attributes['id'];
        if (!isset($workbookRels[$relationId])) {
            continue;
        }
        $result[(string)$sheet['name']] = wcsExcelNormalizePackagePath('xl', $workbookRels[$relationId]['target']);
    }
    return $result;
}

function wcsExcelExtractSheetImages(ZipArchive $zip, array $sheetNames, string $originalName): array
{
    $sheetMap = wcsExcelWorkbookSheets($zip);
    $uploadRoot = dirname(__DIR__, 2) . '/uploads/wcs_repair_quotes';
    if (!is_dir($uploadRoot) && !mkdir($uploadRoot, 0775, true) && !is_dir($uploadRoot)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์เก็บรูปภาพใบเสนอราคาได้');
    }

    $token = date('YmdHis') . '_' . bin2hex(random_bytes(8));
    $targetDir = $uploadRoot . '/' . $token;
    if (!mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์สำหรับไฟล์นำเข้าได้');
    }

    $attachments = [];
    $allowedMime = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
    ];

    foreach ($sheetNames as $sheetName) {
        $sheetPath = $sheetMap[$sheetName] ?? '';
        if ($sheetPath === '') {
            continue;
        }

        $sheetDir = dirname($sheetPath);
        $sheetRelsPath = $sheetDir . '/_rels/' . basename($sheetPath) . '.rels';
        $sheetRels = wcsExcelRelationshipMap($zip, $sheetRelsPath);
        $drawingPath = '';
        foreach ($sheetRels as $relationship) {
            if (str_ends_with($relationship['type'], '/drawing')) {
                $drawingPath = wcsExcelNormalizePackagePath($sheetDir, $relationship['target']);
                break;
            }
        }
        if ($drawingPath === '') {
            continue;
        }

        $drawingText = $zip->getFromName($drawingPath);
        if ($drawingText === false) {
            continue;
        }
        $drawing = simplexml_load_string($drawingText);
        if (!$drawing) {
            continue;
        }
        $drawing->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $drawingRelsPath = dirname($drawingPath) . '/_rels/' . basename($drawingPath) . '.rels';
        $drawingRels = wcsExcelRelationshipMap($zip, $drawingRelsPath);

        $imageIndex = 0;
        foreach ($drawing->xpath('//a:blip') ?: [] as $blip) {
            $attributes = $blip->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationId = (string)$attributes['embed'];
            if ($relationId === '' || !isset($drawingRels[$relationId])) {
                continue;
            }
            $mediaPath = wcsExcelNormalizePackagePath(dirname($drawingPath), $drawingRels[$relationId]['target']);
            $bytes = $zip->getFromName($mediaPath);
            if ($bytes === false || $bytes === '') {
                continue;
            }

            $imageInfo = @getimagesizefromstring($bytes);
            $mimeType = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
            if (!isset($allowedMime[$mimeType])) {
                continue;
            }

            $imageIndex++;
            $extension = $allowedMime[$mimeType];
            $sheetFilePrefixes = [
                'หมายเลขเครื่อง' => 'machine_number',
                'รูปอะไหล่เสีย' => 'damaged_part',
                'ใบรายงาน' => 'service_report',
            ];
            $safeSheet = $sheetFilePrefixes[$sheetName] ?? 'attachment';
            $fileName = $safeSheet . '_' . $imageIndex . '.' . $extension;
            $absolutePath = $targetDir . '/' . $fileName;
            if (file_put_contents($absolutePath, $bytes) === false) {
                continue;
            }

            $relativePath = 'uploads/wcs_repair_quotes/' . $token . '/' . $fileName;
            $attachments[] = [
                'sheet_name' => $sheetName,
                'file_name' => $fileName,
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => strlen($bytes),
                'sort_order' => $imageIndex,
                'source_file_name' => $originalName,
            ];
        }
    }

    if (!$attachments) {
        @rmdir($targetDir);
    }

    return $attachments;
}

function wcsPdfDecodeLiteralString(string $value): string
{
    $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $match): string {
        return chr(octdec($match[1]));
    }, $value) ?? $value;
    return strtr($value, [
        '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\x08", '\\f' => "\x0C",
        '\\(' => '(', '\\)' => ')', '\\\\' => '\\',
    ]);
}

function wcsPdfBasicExtractText(string $path): string
{
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return '';
    }

    $chunks = [];
    if (preg_match_all('/stream\R(.*?)\Rendstream/s', $bytes, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate($stream);
            if ($decoded === false) $decoded = $stream;
            $chunks[] = $decoded;
        }
    }
    $chunks[] = $bytes;

    $text = [];
    foreach ($chunks as $chunk) {
        if (!is_string($chunk) || $chunk === '') continue;
        if (preg_match_all('/BT(.*?)ET/s', $chunk, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/\((?:\\.|[^\\)])*\)\s*Tj|\[(.*?)\]\s*TJ/s', $block, $ops)) {
                    foreach ($ops[0] as $operation) {
                        if (preg_match_all('/\(((?:\\.|[^\\)])*)\)/s', $operation, $strings)) {
                            $line = '';
                            foreach ($strings[1] as $literal) $line .= wcsPdfDecodeLiteralString($literal);
                            if (trim($line) !== '') $text[] = trim($line);
                        }
                    }
                }
            }
        }
    }
    return trim(implode("\n", $text));
}

function wcsPdfFindPdftotext(): string
{
    $candidates = [
        __DIR__ . '/tools/pdftotext.exe',
        dirname(__DIR__, 2) . '/tools/pdftotext.exe',
        'C:/xampp/pdftotext/pdftotext.exe',
        'C:/Program Files/poppler/Library/bin/pdftotext.exe',
        'pdftotext',
    ];
    foreach ($candidates as $candidate) {
        if ($candidate === 'pdftotext') return $candidate;
        if (is_file($candidate)) return $candidate;
    }
    return '';
}

function wcsPdfExtractText(string $path): string
{
    $autoloadCandidates = [
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];
    foreach ($autoloadCandidates as $autoload) {
        if (is_file($autoload)) require_once $autoload;
    }

    if (class_exists('Smalot\\PdfParser\\Parser')) {
        try {
            $parser = new Smalot\PdfParser\Parser();
            $text = trim((string)$parser->parseFile($path)->getText());
            if ($text !== '') return $text;
        } catch (Throwable $e) {
        }
    }

    $binary = wcsPdfFindPdftotext();
    if ($binary !== '' && function_exists('exec')) {
        $outputFile = tempnam(sys_get_temp_dir(), 'wcs_pdf_');
        if ($outputFile !== false) {
            $command = escapeshellarg($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
            $output = [];
            $exitCode = 1;
            @exec($command, $output, $exitCode);
            if ($exitCode === 0 && is_file($outputFile)) {
                $text = trim((string)@file_get_contents($outputFile));
                @unlink($outputFile);
                if ($text !== '') return $text;
            } else {
                @unlink($outputFile);
            }
        }
    }

    return wcsPdfBasicExtractText($path);
}

function wcsPdfNormalizeDate(string $value): string
{
    $value = trim($value);
    if (!preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $value, $m)) return '';
    $day = (int)$m[1]; $month = (int)$m[2]; $year = (int)$m[3];
    if ($year < 100) $year += 2000;
    if ($year > 2400) $year -= 543;
    return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
}

function wcsProcessPdfUpload(array $file): array
{
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ PDF ไม่สำเร็จ');
    }
    $originalName = (string)($file['name'] ?? '');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
        throw new RuntimeException('ชนิดไฟล์ PDF ไม่ถูกต้อง');
    }
    if ((int)($file['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new RuntimeException('ไฟล์ PDF มีขนาดเกิน 20 MB');
    }

    $text = wcsPdfExtractText((string)$file['tmp_name']);
    $text = preg_replace('/\r\n?|\x{00A0}/u', "\n", $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    if (mb_strlen(trim($text), 'UTF-8') < 30) {
        throw new RuntimeException('ไม่สามารถอ่านข้อความจาก PDF ได้ ไฟล์อาจเป็นภาพสแกนหรือไม่มี Text Layer');
    }

    $jobNo = '';
    if (preg_match('/\bQ\s*[-_]?\s*(\d{4,})\b/i', $text, $m)) $jobNo = 'Q' . $m[1];
    if ($jobNo === '' && preg_match('/(?:เลขที่งานซ่อม|เลขที่ใบเสนอราคา|QUOTATION\s*(?:NO\.?|NUMBER)?)\s*[:#-]?\s*(Q?\d{4,})/iu', $text, $m)) {
        $jobNo = strtoupper(preg_replace('/\s+/', '', $m[1]));
        if (preg_match('/^\d+$/', $jobNo)) $jobNo = 'Q' . $jobNo;
    }
    if ($jobNo === '' && preg_match('/Q?\d{4,}/i', pathinfo($originalName, PATHINFO_FILENAME), $m)) {
        $jobNo = strtoupper($m[0]);
        if (preg_match('/^\d+$/', $jobNo)) $jobNo = 'Q' . $jobNo;
    }

    $quoteDate = '';
    if (preg_match('/(?:วันที่|DATE)\s*[:#-]?\s*(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})/iu', $text, $m)) $quoteDate = wcsPdfNormalizeDate($m[1]);
    if ($quoteDate === '' && preg_match('/\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}/', $text, $m)) $quoteDate = wcsPdfNormalizeDate($m[0]);

    $branchName = '';
    $assetCode = '';
    if (preg_match('/(?:สาขาที่ซ่อม|สาขา)\s*[:#-]?\s*([^\n]{2,120})/u', $text, $m)) {
        $branchName = trim($m[1]);
        $branchName = trim((string)preg_replace('/\s*(?:รหัสทรัพย์สิน|ทส\.?)\s*[:#-]?.*$/u', '', $branchName));
    }
    if (preg_match('/(?:รหัสทรัพย์สิน|ทส\.?)\s*[:#-]?\s*([A-Za-z0-9_-]{3,})/u', $text, $m)) $assetCode = trim($m[1]);

    $printerModel = wcsNormalizePrinterModel($text);
    $serialNumber = '';
    if (preg_match('/(?:S\/?N|SERIAL(?:\s*NUMBER)?)\s*[:#-]?\s*([A-Za-z0-9_-]{4,})/iu', $text, $m)) $serialNumber = trim($m[1]);

    $items = [];
    $remarkParts = [];
    foreach (preg_split('/\n/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/(?:ยอดรวม|จำนวนเงินรวม|GRAND TOTAL|VAT|ภาษีมูลค่าเพิ่ม)/iu', $line)) continue;
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._\/-]{1,30})\s+(.+?)\s+(\d+(?:\.\d+)?)\s+([\d,]+(?:\.\d{1,2})?)(?:\s+[\d,]+(?:\.\d{1,2})?)?$/u', $line, $m)) {
            $description = trim($m[2]);
            $quantity = wcsExcelNumber($m[3]);
            $unitPrice = wcsExcelNumber($m[4]);
            if ($description !== '' && $quantity > 0 && $unitPrice >= 0) {
                $items[] = ['product_code'=>$m[1], 'repair_description'=>$description, 'quantity'=>$quantity, 'unit_price'=>$unitPrice];
            }
        }
    }

    if (!$items) {
        $items[] = [
            'product_code' => 'SERVICE',
            'repair_description' => 'กรุณาตรวจสอบและกรอกรายการซ่อมจากไฟล์ PDF',
            'quantity' => 1,
            'unit_price' => 0,
        ];
        $remarkParts[] = 'ระบบอ่านตารางรายการซ่อมจาก PDF ได้ไม่ครบ กรุณาตรวจสอบรายการและราคาก่อนบันทึก';
    }

    if ($jobNo === '' || $quoteDate === '') {
        throw new RuntimeException('อ่านเลขที่งานซ่อมหรือวันที่จาก PDF ไม่ครบ กรุณาตรวจสอบว่าเป็นใบเสนอราคา WCS ที่มีข้อความ');
    }

    return [
        'repair_job_no'=>$jobNo,
        'quote_date'=>$quoteDate,
        'branch_name'=>$branchName,
        'asset_code'=>$assetCode,
        'printer_model'=>$printerModel,
        'serial_number'=>$serialNumber,
        'remark'=>implode("\n", $remarkParts),
        'items'=>$items,
        'attachments'=>[],
        'attachment_count'=>0,
        'source_type'=>'pdf',
    ];
}

function wcsProcessImportUpload(array $file): array
{
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'xlsx') return wcsProcessExcelUpload($file);
    if ($extension === 'pdf') return wcsProcessPdfUpload($file);
    throw new RuntimeException('รองรับเฉพาะไฟล์ .xlsx และ .pdf เท่านั้น');
}

function wcsProcessExcelUpload(array $file): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ยังไม่ได้เปิดใช้งาน ZipArchive กรุณาเปิด extension=zip ใน php.ini');
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ Excel ไม่สำเร็จ');
    }

    $originalName = (string)($file['name'] ?? '');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new RuntimeException('รองรับเฉพาะไฟล์ .xlsx เท่านั้น');
    }
    if ((int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new RuntimeException('ไฟล์มีขนาดเกิน 15 MB');
    }

    $zip = new ZipArchive();
    if ($zip->open((string)$file['tmp_name']) !== true) {
        throw new RuntimeException('ไม่สามารถเปิดไฟล์ Excel ได้');
    }

    try {
        $sharedStrings = wcsExcelReadSharedStrings($zip);
        $cells = wcsExcelReadSheet($zip, $sharedStrings);
        $attachments = wcsExcelExtractSheetImages($zip, ['หมายเลขเครื่อง', 'รูปอะไหล่เสีย', 'ใบรายงาน'], $originalName);
    } finally {
        $zip->close();
    }

    $jobNoRaw = wcsExcelCell($cells, 9, 24);
    $jobNo = trim($jobNoRaw);
    if ($jobNo !== '' && preg_match('/^\d+$/', $jobNo)) {
        $jobNo = 'Q' . $jobNo;
    }
    if ($jobNo === '') {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        if (preg_match('/Q?\d+/i', $baseName, $match)) {
            $jobNo = strtoupper($match[0]);
            if (preg_match('/^\d+$/', $jobNo)) {
                $jobNo = 'Q' . $jobNo;
            }
        }
    }

    $quoteDate = wcsExcelDate(wcsExcelCell($cells, 11, 24));
    $placeText = wcsExcelCell($cells, 13, 5);
    $branchName = '';
    $assetCode = '';

    if (preg_match('/(?:รหัสทรัพย์สิน|ทส\.?)\s*[:#-]?\s*([A-Za-z0-9_-]+)/u', $placeText, $match)) {
        $assetCode = trim($match[1]);
    }

    $branchName = trim((string)preg_replace('/^\s*สาขา\s*/u', '', $placeText));
    $branchName = trim((string)preg_replace('/\s*(?:รหัสทรัพย์สิน|ทส\.?)\s*[:#-]?\s*[A-Za-z0-9_-]+.*$/u', '', $branchName));
    $branchName = trim((string)preg_replace('/\s+สังกัด\s+\d+\s*$/u', '', $branchName));

    $machineText = wcsExcelCell($cells, 22, 7);
    $printerModel = wcsNormalizePrinterModel($machineText);
    $serialNumber = '';
    if (preg_match('/(?:S\/?N|SERIAL(?:\s*NUMBER)?)\s*[:#-]?\s*([A-Za-z0-9_-]+)/i', $machineText, $match)) {
        $serialNumber = trim($match[1]);
    }

    $items = [];
    $remarkParts = [];
    for ($row = 21; $row <= 60; $row++) {
        $productCode = wcsExcelCell($cells, $row, 4);
        $description = wcsExcelCell($cells, $row, 7);
        $quantity = wcsExcelNumber(wcsExcelCell($cells, $row, 19));
        $unitPrice = wcsExcelNumber(wcsExcelCell($cells, $row, 21));

        if ($description === '' && $productCode === '') continue;
        if (preg_match('/^(หมายเหตุ|COMMENT)$/iu', trim($productCode . ' ' . $description))) continue;
        if (mb_strpos($description, 'จำนวนเงินรวม') !== false || mb_strpos($description, 'GRAND TOTAL') !== false) break;

        if ($quantity > 0 || $unitPrice > 0) {
            $items[] = [
                'product_code' => $productCode !== '' ? $productCode : 'SERVICE',
                'repair_description' => trim($description),
                'quantity' => $quantity > 0 ? $quantity : 1,
                'unit_price' => $unitPrice,
            ];
            continue;
        }

        $isRemark = preg_match('/^(\*\*|รออนุมัติ|เครื่องนี้|หมายเหตุ)/u', trim($description)) === 1;
        if ($isRemark) {
            $remarkParts[] = trim($description);
        } elseif (!empty($items) && ($productCode !== '' || $description !== '')) {
            $append = trim(implode(' ', array_filter([$productCode, $description])));
            if ($append !== '') $items[count($items) - 1]['repair_description'] .= ' ' . $append;
        }
    }

    if ($jobNo === '' || $quoteDate === '' || $branchName === '' || $assetCode === '') {
        throw new RuntimeException('อ่านข้อมูลส่วนหัวไม่ครบ กรุณาตรวจสอบว่าเป็นไฟล์ใบเสนอราคา WCS รูปแบบเดียวกับตัวอย่าง');
    }
    if (empty($items)) {
        throw new RuntimeException('ไม่พบรายการซ่อมในไฟล์ Excel');
    }

    return [
        'repair_job_no' => $jobNo,
        'quote_date' => $quoteDate,
        'branch_name' => $branchName,
        'asset_code' => $assetCode,
        'printer_model' => $printerModel,
        'serial_number' => $serialNumber,
        'remark' => implode("\n", array_values(array_unique(array_filter($remarkParts)))),
        'items' => $items,
        'attachments' => $attachments,
        'attachment_count' => count($attachments),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wcsImportResponse(false, 'Method ไม่ถูกต้อง');
    }
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        wcsImportResponse(false, 'CSRF Token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
    $files = [];
    if (!empty($_FILES['import_files']) && is_array($_FILES['import_files']['name'] ?? null)) {
        $count = count($_FILES['import_files']['name']);
        if ($count > 5) {
            wcsImportResponse(false, 'อัปโหลดได้สูงสุดครั้งละ 5 ไฟล์');
        }
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name' => $_FILES['import_files']['name'][$i] ?? '',
                'type' => $_FILES['import_files']['type'][$i] ?? '',
                'tmp_name' => $_FILES['import_files']['tmp_name'][$i] ?? '',
                'error' => $_FILES['import_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['import_files']['size'][$i] ?? 0,
            ];
        }
    } elseif (!empty($_FILES['excel_files']) && is_array($_FILES['excel_files']['name'] ?? null)) {
        $count = count($_FILES['excel_files']['name']);
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name' => $_FILES['excel_files']['name'][$i] ?? '',
                'type' => $_FILES['excel_files']['type'][$i] ?? '',
                'tmp_name' => $_FILES['excel_files']['tmp_name'][$i] ?? '',
                'error' => $_FILES['excel_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['excel_files']['size'][$i] ?? 0,
            ];
        }
    } elseif (!empty($_FILES['excel_file']) && is_array($_FILES['excel_file'])) {
        $files[] = $_FILES['excel_file'];
    }

    if (!$files) {
        wcsImportResponse(false, 'ไม่พบไฟล์ Excel หรือ PDF ที่อัปโหลด');
    }

    $results = [];
    foreach ($files as $file) {
        $fileName = (string)($file['name'] ?? '');
        try {
            $results[] = [
                'success' => true,
                'file_name' => $fileName,
                'message' => 'อ่านข้อมูลสำเร็จ',
                'data' => wcsProcessImportUpload($file),
            ];
        } catch (Throwable $fileError) {
            $results[] = [
                'success' => false,
                'file_name' => $fileName,
                'message' => $fileError->getMessage(),
                'data' => [],
            ];
        }
    }

    $successCount = count(array_filter($results, static fn(array $row): bool => !empty($row['success'])));
    wcsImportResponse(true, 'ประมวลผลไฟล์ Excel / PDF เรียบร้อยแล้ว', [
        'results' => $results,
        'total' => count($results),
        'success_count' => $successCount,
        'failed_count' => count($results) - $successCount,
    ]);
} catch (Throwable $e) {
    wcsImportResponse(false, 'อ่านไฟล์ไม่สำเร็จ: ' . $e->getMessage());
}
