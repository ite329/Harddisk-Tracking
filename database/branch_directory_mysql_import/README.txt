# branch_directory import

Source file: 06-Costcenter_June2026.xlsx
Sheet: รวมทุกเขต
Imported rows: 8,915
Unique branch_code / Cost Center: 8,915

Files:
- 01_create_branch_directory.sql = schema only
- 02_branch_directory_full_import.sql = drop/create table + insert all branch rows
- branch_directory_data.csv = cleaned CSV version

Import suggestion:
1. Open phpMyAdmin.
2. Select your project database, e.g. harddisk_delivery_web.
3. Import 02_branch_directory_full_import.sql.

Note:
- branch_code maps from Excel column 'Cost Center'.
- main_branch_code maps from Excel column 'รหัสสาขา'.
- Thai Buddhist year dates such as '15 พ.ค. 2569' were converted to '2026-05-15'.
