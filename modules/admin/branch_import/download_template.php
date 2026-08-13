<?php
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/includes/import_helpers.php';

branchImportRequireAccess();

$filename = 'branch_directory_template.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, [
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
]);
fputcsv($out, [
    '314',
    '2008777',
    '(ศ.10) ศูนย์ฯ รุญบุล (ย.3)',
    '(ศ.10) ศูนย์ฯ รุญบุล (ย.3)',
    'ศูนย์บริการ',
    'ที่อยู่ตัวอย่าง',
    '02xxxxxxx',
    'ใกล้ตลาด',
    '0',
    '200001',
    'บ้านเลขที่/ถนนตัวอย่าง',
    'บางพลัด',
    'เขตบางพลัด',
    'กรุงเทพมหานคร',
    '10700',
    '',
    '01/07/2026',
    '',
    '13.79049024',
    '100.503868',
    '',
    '',
    '',
    '1',
    'branch_directory_template.csv',
]);
fclose($out);
exit;
