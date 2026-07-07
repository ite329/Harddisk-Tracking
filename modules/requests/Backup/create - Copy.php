<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pageTitle = 'บันทึกคำขอส่ง HDD';
require_once __DIR__ . '/../../includes/header.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function currentLoginNameForRequestCreate(): string
{
    $fullName = trim((string)($_SESSION['full_name'] ?? ''));

    if ($fullName === '') {
        $firstName = trim((string)($_SESSION['first_name'] ?? ''));
        $lastName = trim((string)($_SESSION['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
    }

    $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));

    if ($fullName !== '' && $employeeCode !== '') {
        return $fullName . ' (' . $employeeCode . ')';
    }

    if ($fullName !== '') {
        return $fullName;
    }

    if ($employeeCode !== '') {
        return $employeeCode;
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $user = $_SESSION['user'];
        $userFullName = trim((string)($user['full_name'] ?? ''));

        if ($userFullName === '') {
            $userFullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }

        $userEmployeeCode = trim((string)($user['employee_code'] ?? ''));

        if ($userFullName !== '' && $userEmployeeCode !== '') {
            return $userFullName . ' (' . $userEmployeeCode . ')';
        }

        if ($userFullName !== '') {
            return $userFullName;
        }

        if ($userEmployeeCode !== '') {
            return $userEmployeeCode;
        }
    }

    return 'IT';
}

$loginName = currentLoginNameForRequestCreate();
?>

<style>
    body { background: #f3f6fb; }
    .request-page { padding: 10px 0 16px 0; }
    .request-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .request-subtitle { font-size: 13px; color: #64748b; }
    .request-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .request-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .request-card .card-body { padding: 12px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .step-box { border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px; background: #f8fafc; }
    .step-title { font-size: 13px; font-weight: 900; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .step-badge { width: 22px; height: 22px; border-radius: 8px; background: #2563eb; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
    .form-label { font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 4px; }
    .form-control, .form-select { font-size: 13px; border-radius: 10px; }
    .form-control-lg, .form-select-lg { font-size: 15px; border-radius: 12px; }
    .btn { border-radius: 10px; }
    .btn-sm { font-size: 12px; padding: 4px 8px; }
    .selected-branch-box { background: #eef6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 10px; font-size: 13px; color: #0f172a; }
    .selected-branch-box .row > div { padding-top: 2px; padding-bottom: 2px; }
    .selected-branch-label { color: #64748b; font-weight: 700; }
    .selected-branch-value { color: #0f172a; font-weight: 700; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    .next-step-box { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; border-radius: 12px; padding: 10px; font-size: 13px; }
    .duplicate-zone .alert { border-radius: 12px; font-size: 13px; padding: 10px; margin-bottom: 0; }
    .duplicate-zone table { font-size: 12px; }
    .top-actions .btn { font-size: 12px; padding: 5px 10px; }
    .status-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 9px; background: rgba(255,255,255,0.18); font-size: 12px; }
    @media (max-width: 1366px) {
        .request-page { padding-top: 8px; }
        .request-title { font-size: 20px; }
        .request-card .card-body { padding: 10px; }
        .hero-card .card-body { padding: 10px 14px; }
        .form-control, .form-select { font-size: 12px; }
        .form-control-lg, .form-select-lg { font-size: 14px; }
        .step-box { padding: 9px; }
    }
</style>

<div class="container-fluid request-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="request-title">บันทึกคำขอส่ง Harddisk</h3>
            <div class="request-subtitle">ค้นหาสาขา เลือกสาขาปลายทาง ตรวจสอบรายการซ้ำ และบันทึกคำขอส่ง HDD</div>
        </div>

        <div class="d-flex gap-2 top-actions">
            <a href="../dashboard/index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            <a href="index.php" class="btn btn-outline-primary btn-sm">รายการคำขอทั้งหมด</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success py-2 mb-2">
            บันทึกคำขอส่ง HDD เรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['duplicate'])): ?>
        <div class="alert alert-warning py-2 mb-2">
            ระบบตรวจพบรายการซ้ำของ Cost Center นี้ แต่สามารถบันทึกคำขอส่ง HDD ซ้ำได้ หากมีความจำเป็น
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger py-2 mb-2">
            เกิดข้อผิดพลาด กรุณาตรวจสอบข้อมูลอีกครั้ง
        </div>
    <?php endif; ?>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">Workflow: เลือกสาขา → ตรวจสอบซ้ำ → บันทึกคำขอ → ยิงบาร์โค้ด HDD</div>
                <div class="small opacity-75">หลังบันทึกคำขอ ระบบจะส่งต่อไปยังขั้นตอนยิงบาร์โค้ดเพื่อจับคู่ HDD กับสาขา</div>
            </div>
            <div class="status-pill">👤 ผู้ใช้งานปัจจุบัน: <strong><?php echo h($loginName); ?></strong></div>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-xl-4 col-lg-5">
            <div class="card request-card h-100">
                <div class="card-header">ค้นหาและเลือกสาขาปลายทาง</div>
                <div class="card-body">

                    <div class="step-box mb-2">
                        <div class="step-title"><span class="step-badge">1</span> ค้นหารหัสสาขาใหญ่</div>

                        <div class="row g-2">
                            <div class="col-8">
                                <label class="form-label">รหัสสาขาใหญ่</label>
                                <input type="text"
                                       id="search_branch_code"
                                       class="form-control form-control-lg"
                                       placeholder="เช่น 017, 088, 240"
                                       autocomplete="off"
                                       maxlength="3"
                                       autofocus>
                            </div>

                            <div class="col-4 d-grid align-items-end">
                                <label class="form-label">&nbsp;</label>
                                <button type="button" class="btn btn-primary" id="btnSearchBranch">ค้นหา</button>
                            </div>

                            <div class="col-12">
                                <div class="form-text">กรอกตัวเลข 3 หลัก เพื่อดึงสาขาในสังกัดทั้งหมด</div>
                            </div>
                        </div>

                        <div id="branchSearchResult" class="d-none mt-2"></div>
                    </div>

                    <div class="step-box mb-2">
                        <div class="step-title"><span class="step-badge">2</span> เลือกสาขาในสังกัด</div>

                        <div class="mb-2">
                            <label class="form-label">เลือกสาขาที่ต้องการจัดส่ง</label>
                            <select id="branch_select" class="form-select form-select-lg" disabled>
                                <option value="">-- กรุณาค้นหาสาขาก่อน --</option>
                            </select>
                        </div>

                        <div id="selectedBranchBox" class="selected-branch-box d-none">
                            <div class="fw-bold mb-2">ข้อมูลสาขาที่เลือก</div>
                            <div class="row small">
                                <div class="col-5 selected-branch-label">รหัสสาขาใหญ่</div>
                                <div class="col-7 selected-branch-value" id="show_main_branch_code">-</div>

                                <div class="col-5 selected-branch-label">Cost Center</div>
                                <div class="col-7 selected-branch-value text-primary" id="show_branch_code">-</div>

                                <div class="col-5 selected-branch-label">ชื่อสาขา</div>
                                <div class="col-7 selected-branch-value" id="show_branch_name">-</div>

                                <div class="col-5 selected-branch-label">เบอร์โทร</div>
                                <div class="col-7 selected-branch-value" id="show_phone">-</div>

                                <div class="col-5 selected-branch-label">ที่อยู่</div>
                                <div class="col-7 selected-branch-value" id="show_address">-</div>

                                <div class="col-5 selected-branch-label">สถานที่ใกล้เคียง</div>
                                <div class="col-7 selected-branch-value" id="show_landmark">-</div>
                            </div>
                        </div>
                    </div>

                    <div class="step-box duplicate-zone">
                        <div class="step-title"><span class="step-badge">3</span> ตรวจสอบรายการส่งซ้ำ</div>
                        <div id="duplicateBox" class="alert d-none"></div>
                        <div class="help-box" id="duplicateHelpBox">
                            หลังเลือกสาขา ระบบจะตรวจสอบ Cost Center กับรายการคำขอเดิมให้อัตโนมัติ และยังสามารถบันทึกซ้ำได้หากมีความจำเป็น
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7">
            <form action="save.php" method="post" id="requestForm" autocomplete="off">

                <input type="hidden" name="main_branch_code" id="main_branch_code">
                <input type="hidden" name="branch_code" id="branch_code">
                <input type="hidden" name="branch_name" id="branch_name">
                <input type="hidden" name="has_shipment_history" id="has_shipment_history" value="0">
                <input type="hidden" name="is_duplicate_request" id="is_duplicate_request" value="0">
                <input type="hidden" name="duplicate_request_no" id="duplicate_request_no" value="">

                <div class="card request-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>รายละเอียดคำขอส่ง HDD</span>
                        <span class="small text-muted">บันทึกแล้วไปขั้นตอนยิงบาร์โค้ด</span>
                    </div>

                    <div class="card-body">
                        <div class="step-box mb-2">
                            <div class="step-title"><span class="step-badge">4</span> ระบุสาเหตุและรายละเอียด</div>

                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">สาเหตุที่ต้องส่ง HDD <span class="text-danger">*</span></label>
                                    <select name="request_reason" class="form-select" required>
                                        <option value="">-- เลือกสาเหตุ --</option>
                                        <option value="HDD เสีย">HDD เสีย</option>
                                        <option value="เครื่องบันทึกไม่เห็น HDD">เครื่องบันทึกไม่เห็น HDD</option>
                                        <option value="เปลี่ยนทดแทนของเดิม">เปลี่ยนทดแทนของเดิม</option>
                                        <option value="ส่งสำรองให้สาขา">ส่งสำรองให้สาขา</option>
                                        <option value="อื่น ๆ">อื่น ๆ</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">สถานะหลังบันทึก</label>
                                    <div class="next-step-box">
                                        <strong>รอยิงบาร์โค้ด</strong><br>
                                        รายการจะรอให้ IT ยิง Serial HDD เพื่อจับคู่กับสาขา
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">หมายเหตุ</label>
                                    <textarea name="remark"
                                              class="form-control"
                                              rows="6"
                                              placeholder="ระบุรายละเอียดเพิ่มเติม เช่น อาการเสีย / เลข Ticket / ข้อมูลประกอบ"></textarea>
                                    <div class="form-text">
                                        หากเป็นการส่งซ้ำ แนะนำให้ระบุเหตุผลเพิ่มเติม เช่น HDD ตัวเดิมเสียซ้ำ / สาขายังใช้งานไม่ได้ / ต้องการส่งทดแทนอีกครั้ง
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="step-box">
                            <div class="step-title"><span class="step-badge">5</span> ยืนยันการบันทึก</div>
                            <div class="help-box mb-2">
                                ระบบจะบันทึกคำขอส่ง HDD โดยอ้างอิงรหัสสาขาใหญ่, Cost Center และชื่อสาขาที่เลือกไว้จากด้านซ้าย
                            </div>

                            <div class="d-flex flex-wrap justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary">ล้างข้อมูล</button>
                                <button type="submit" class="btn btn-success" id="btnSaveRequest" disabled>
                                    บันทึกคำขอส่ง HDD
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('search_branch_code');
    const btnSearch = document.getElementById('btnSearchBranch');
    const branchSelect = document.getElementById('branch_select');

    const requestForm = document.getElementById('requestForm');
    const selectedBranchBox = document.getElementById('selectedBranchBox');
    const duplicateBox = document.getElementById('duplicateBox');
    const duplicateHelpBox = document.getElementById('duplicateHelpBox');
    const branchSearchResult = document.getElementById('branchSearchResult');

    const mainBranchCodeInput = document.getElementById('main_branch_code');
    const branchCodeInput = document.getElementById('branch_code');
    const branchNameInput = document.getElementById('branch_name');
    const btnSaveRequest = document.getElementById('btnSaveRequest');

    const hasShipmentHistoryInput = document.getElementById('has_shipment_history');
    const isDuplicateRequestInput = document.getElementById('is_duplicate_request');
    const duplicateRequestNoInput = document.getElementById('duplicate_request_no');

    let branchData = [];

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cleanText(value) {
        return String(value ?? '').trim();
    }

    function isValidMainBranchCode(value) {
        return /^\d{3}$/.test(cleanText(value));
    }

    function formatMainBranchCode(value) {
        value = cleanText(value);

        if (value === '') {
            return '';
        }

        if (/^\d+$/.test(value) && value.length < 3) {
            return value.padStart(3, '0');
        }

        return value;
    }

    function getBranchAddress(branch) {
        return branch.branch_address || branch.full_address || '';
    }

    function showSearchMessage(type, message) {
        branchSearchResult.className = 'alert alert-' + type + ' py-2 mb-0';
        branchSearchResult.innerHTML = message;
        branchSearchResult.classList.remove('d-none');
    }

    function clearSearchMessage() {
        branchSearchResult.className = 'd-none';
        branchSearchResult.innerHTML = '';
    }

    function clearDuplicateBox() {
        duplicateBox.className = 'alert d-none';
        duplicateBox.innerHTML = '';

        if (duplicateHelpBox) {
            duplicateHelpBox.classList.remove('d-none');
        }

        if (isDuplicateRequestInput) {
            isDuplicateRequestInput.value = '0';
        }

        if (duplicateRequestNoInput) {
            duplicateRequestNoInput.value = '';
        }
    }

    function clearSelectedBranch() {
        selectedBranchBox.classList.add('d-none');

        clearDuplicateBox();

        mainBranchCodeInput.value = '';
        branchCodeInput.value = '';
        branchNameInput.value = '';

        if (hasShipmentHistoryInput) {
            hasShipmentHistoryInput.value = '0';
        }

        btnSaveRequest.disabled = true;
    }

    function getStatusBadgeHtml(status) {
        let statusText = status || '-';
        let statusBadgeClass = 'bg-secondary';

        if (status === 'pending_scan') {
            statusText = 'รอยิงบาร์โค้ด';
            statusBadgeClass = 'bg-warning text-dark';
        } else if (status === 'matched') {
            statusText = 'รอยืนยันจัดส่ง';
            statusBadgeClass = 'bg-info text-dark';
        } else if (status === 'pending') {
            statusText = 'รอดำเนินการ';
            statusBadgeClass = 'bg-warning text-dark';
        } else if (status === 'reserved') {
            statusText = 'จับคู่ HDD แล้ว';
            statusBadgeClass = 'bg-primary';
        } else if (status === 'shipped') {
            statusText = 'จัดส่งแล้ว';
            statusBadgeClass = 'bg-primary';
        } else if (status === 'received') {
            statusText = 'สาขาได้รับแล้ว';
            statusBadgeClass = 'bg-success';
        } else if (status === 'cancelled') {
            statusText = 'ยกเลิก';
            statusBadgeClass = 'bg-danger';
        } else if (status === 'rejected') {
            statusText = 'ไม่อนุมัติ';
            statusBadgeClass = 'bg-danger';
        }

        return `<span class="badge ${statusBadgeClass}">${escapeHtml(statusText)}</span>`;
    }

    function searchBranch() {
        const keyword = cleanText(searchInput.value);

        clearSelectedBranch();
        clearSearchMessage();

        branchData = [];
        branchSelect.innerHTML = '<option value="">กำลังค้นหาข้อมูล...</option>';
        branchSelect.disabled = true;

        if (keyword === '') {
            showSearchMessage('warning', 'กรุณากรอกรหัสสาขาใหญ่ก่อนค้นหา');
            branchSelect.innerHTML = '<option value="">-- กรุณากรอกรหัสสาขาใหญ่ --</option>';
            return;
        }

        if (!isValidMainBranchCode(keyword)) {
            showSearchMessage('warning', 'กรุณากรอกรหัสสาขาใหญ่เป็นตัวเลข 3 หลักเท่านั้น เช่น 017, 088, 123');
            branchSelect.innerHTML = '<option value="">-- รหัสสาขาใหญ่ไม่ถูกต้อง --</option>';
            return;
        }

        const mainBranchCode = formatMainBranchCode(keyword);

        const params = new URLSearchParams();
        params.append('main_branch_code', mainBranchCode);
        params.append('branch_code', mainBranchCode);

        fetch('/harddisk_delivery_web/api/get_branches.php?' + params.toString())
            .then(response => response.json())
            .then(result => {
                branchData = [];
                branchSelect.innerHTML = '<option value="">-- เลือกสาขา --</option>';

                if (!result.success) {
                    showSearchMessage('danger', escapeHtml(result.message || 'ไม่สามารถค้นหาข้อมูลสาขาได้'));
                    branchSelect.innerHTML = '<option value="">-- ไม่พบข้อมูล --</option>';
                    branchSelect.disabled = true;
                    return;
                }

                const rows = Array.isArray(result.data) ? result.data : [];
                const total = Number(result.total ?? rows.length);

                if (rows.length === 0 || total === 0) {
                    showSearchMessage('warning', 'ไม่พบข้อมูลสาขาภายใต้รหัสสาขาใหญ่ ' + escapeHtml(mainBranchCode));
                    branchSelect.innerHTML = '<option value="">-- ไม่พบข้อมูลสาขา --</option>';
                    branchSelect.disabled = true;
                    return;
                }

                branchData = rows;

                rows.forEach(function (branch, index) {
                    const option = document.createElement('option');
                    option.value = String(index);
                    option.dataset.branchCode = branch.branch_code || '';
                    option.dataset.mainBranchCode = branch.main_branch_code || '';
                    option.textContent = (branch.branch_code || '-') + ' - ' + (branch.branch_name || '-');
                    branchSelect.appendChild(option);
                });

                branchSelect.disabled = false;
                showSearchMessage(
                    'success',
                    'พบรายชื่อสาขาภายใต้รหัสสาขาใหญ่ <strong>' + escapeHtml(mainBranchCode) + '</strong> จำนวน <strong>' + rows.length + '</strong> รายการ กรุณาเลือกสาขาที่ต้องการจัดส่ง'
                );
            })
            .catch(function () {
                showSearchMessage('danger', 'เกิดข้อผิดพลาดในการเชื่อมต่อ API ค้นหาสาขา');
                branchSelect.innerHTML = '<option value="">-- ไม่สามารถโหลดข้อมูลได้ --</option>';
                branchSelect.disabled = true;
            });
    }

    function checkDuplicateByCostCenter(branch) {
        const costCenter = cleanText(branch.branch_code);

        clearDuplicateBox();
        btnSaveRequest.disabled = true;

        if (hasShipmentHistoryInput) {
            hasShipmentHistoryInput.value = '0';
        }

        if (costCenter === '') {
            if (duplicateHelpBox) {
                duplicateHelpBox.classList.add('d-none');
            }
            duplicateBox.className = 'alert alert-danger py-2 mb-0';
            duplicateBox.innerHTML = 'ไม่พบ Cost Center ของสาขาที่เลือก จึงไม่สามารถตรวจสอบรายการซ้ำได้';
            duplicateBox.classList.remove('d-none');
            return;
        }

        if (duplicateHelpBox) {
            duplicateHelpBox.classList.add('d-none');
        }

        duplicateBox.className = 'alert alert-info py-2 mb-0';
        duplicateBox.innerHTML =
            'กำลังตรวจสอบรายการซ้ำ โดยนำ Cost Center <strong>' +
            escapeHtml(costCenter) +
            '</strong> ไปเทียบกับตาราง <code>harddisk_delivery_requests.branch_code</code>...';
        duplicateBox.classList.remove('d-none');

        const params = new URLSearchParams();
        params.append('branch_code', costCenter);
        params.append('cost_center', costCenter);
        params.append('main_branch_code', branch.main_branch_code || '');
        params.append('branch_name', branch.branch_name || '');

        fetch('/harddisk_delivery_web/api/check_request.php?' + params.toString())
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    duplicateBox.className = 'alert alert-danger py-2 mb-0';
                    duplicateBox.innerHTML = escapeHtml(result.message || 'ไม่สามารถตรวจสอบรายการซ้ำได้');
                    btnSaveRequest.disabled = true;
                    return;
                }

                const isDuplicate = result.has_pending === true || result.exists === true;

                if (isDuplicate) {
                    const item = result.data || result.latest || {};
                    const requestBranchCode = item.branch_code || '';
                    const oldRequestNo = item.request_no || '';

                    if (hasShipmentHistoryInput) {
                        hasShipmentHistoryInput.value = '1';
                    }

                    if (isDuplicateRequestInput) {
                        isDuplicateRequestInput.value = '1';
                    }

                    if (duplicateRequestNoInput) {
                        duplicateRequestNoInput.value = oldRequestNo;
                    }

                    duplicateBox.className = 'alert alert-warning py-2 mb-0';
                    duplicateBox.innerHTML = `
                        <strong>ตรวจพบรายการส่งซ้ำของ Cost Center นี้</strong><br>
                        Cost Center <strong>${escapeHtml(costCenter)}</strong> เคยมีรายการคำขอส่ง HDD อยู่แล้ว
                        แต่ระบบยังอนุญาตให้บันทึกคำขอส่ง HDD อีกรอบได้ หากมีความจำเป็น<br><br>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-2">
                                <tbody>
                                    <tr><th width="170">เลขที่คำขอเดิม</th><td>${escapeHtml(oldRequestNo || '-')}</td></tr>
                                    <tr><th>Cost Center ที่เลือก</th><td><strong>${escapeHtml(costCenter)}</strong></td></tr>
                                    <tr><th>Cost Center ในคำขอเดิม</th><td><strong>${escapeHtml(requestBranchCode || '-')}</strong></td></tr>
                                    <tr><th>รหัสสาขาใหญ่</th><td>${escapeHtml(item.main_branch_code || branch.main_branch_code || '-')}</td></tr>
                                    <tr><th>ชื่อสาขา</th><td>${escapeHtml(item.branch_name || branch.branch_name || '-')}</td></tr>
                                    <tr><th>สาเหตุเดิม</th><td>${escapeHtml(item.request_reason || '-')}</td></tr>
                                    <tr><th>สถานะ</th><td>${getStatusBadgeHtml(item.status || '')}</td></tr>
                                    <tr><th>วันที่บันทึก</th><td>${escapeHtml(item.created_at || item.requested_at || '-')}</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="fw-bold text-danger">
                            ผลการตรวจสอบ: ${escapeHtml(costCenter)} = ${escapeHtml(requestBranchCode || '-')} จึงถือว่าเป็นรายการซ้ำ
                        </div>

                        <div class="mt-1">
                            <strong>หมายเหตุ:</strong> หากต้องการส่งอีกรอบ ให้ระบุเหตุผลเพิ่มเติมในช่องหมายเหตุ แล้วกดบันทึกได้ตามปกติ
                        </div>
                    `;

                    btnSaveRequest.disabled = false;
                    return;
                }

                if (hasShipmentHistoryInput) {
                    hasShipmentHistoryInput.value = '0';
                }

                if (isDuplicateRequestInput) {
                    isDuplicateRequestInput.value = '0';
                }

                if (duplicateRequestNoInput) {
                    duplicateRequestNoInput.value = '';
                }

                duplicateBox.className = 'alert alert-success py-2 mb-0';
                duplicateBox.innerHTML =
                    'ไม่พบรายการซ้ำของ Cost Center <strong>' +
                    escapeHtml(costCenter) +
                    '</strong> สามารถบันทึกคำขอส่ง HDD ได้';

                btnSaveRequest.disabled = false;
            })
            .catch(function () {
                duplicateBox.className = 'alert alert-danger py-2 mb-0';
                duplicateBox.innerHTML = 'เกิดข้อผิดพลาดในการตรวจสอบรายการซ้ำ';
                btnSaveRequest.disabled = true;
            });
    }

    btnSearch.addEventListener('click', searchBranch);

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchBranch();
        }
    });

    searchInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 3);
    });

    branchSelect.addEventListener('change', function () {
        clearSelectedBranch();

        const selectedIndex = branchSelect.value;

        if (selectedIndex === '') {
            return;
        }

        const branch = branchData[Number(selectedIndex)];

        if (!branch) {
            return;
        }

        const mainBranchCode = formatMainBranchCode(branch.main_branch_code || '');
        const costCenter = cleanText(branch.branch_code);
        const branchName = cleanText(branch.branch_name);

        document.getElementById('show_main_branch_code').textContent = mainBranchCode || '-';
        document.getElementById('show_branch_code').textContent = costCenter || '-';
        document.getElementById('show_branch_name').textContent = branchName || '-';
        document.getElementById('show_phone').textContent = branch.phone || '-';
        document.getElementById('show_address').textContent = getBranchAddress(branch) || '-';
        document.getElementById('show_landmark').textContent = branch.landmark || '-';

        mainBranchCodeInput.value = mainBranchCode;
        branchCodeInput.value = costCenter;
        branchNameInput.value = branchName;

        selectedBranchBox.classList.remove('d-none');

        checkDuplicateByCostCenter(branch);
    });

    requestForm.addEventListener('submit', function (event) {
        const costCenter = cleanText(branchCodeInput.value);

        if (costCenter === '') {
            event.preventDefault();
            alert('กรุณาเลือกสาขาก่อนบันทึกคำขอส่ง HDD');
            branchSelect.focus();
            return;
        }

        /*
         * ถ้าตรวจพบรายการซ้ำ ระบบยังอนุญาตให้บันทึกอีกรอบได้
         * จึงไม่บล็อก submit จาก duplicateBox แล้ว
         */
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
