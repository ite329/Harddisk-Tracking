function byId(id){ return document.getElementById(id); }

function escapeHtml(value) {
  return String(value ?? '-')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

async function loadBranches() {
  const mainCode = byId('main_branch_code').value.trim();
  const branchSelect = byId('branch_code');
  const box = byId('branchInfoBox');
  if (!mainCode) { alert('กรุณากรอกรหัสสาขาใหญ่'); return; }
  branchSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
  box.innerHTML = '';
  const res = await fetch('../../api/get_branches.php?main_branch_code=' + encodeURIComponent(mainCode));
  const data = await res.json();
  branchSelect.innerHTML = '<option value="">-- เลือกสาขา --</option>';
  if (!data.success || data.data.length === 0) { branchSelect.innerHTML = '<option value="">ไม่พบข้อมูลสาขา</option>'; return; }
  data.data.forEach(b => {
    const opt = document.createElement('option');
    opt.value = b.branch_code;
    opt.textContent = b.branch_code + ' - ' + b.branch_name;
    opt.dataset.name = b.branch_name || '';
    opt.dataset.phone = b.phone || '';
    opt.dataset.address = b.branch_address || '';
    opt.dataset.landmark = b.landmark || '';
    branchSelect.appendChild(opt);
  });
}

async function onBranchChanged() {
  const select = byId('branch_code');
  const opt = select.options[select.selectedIndex];
  const box = byId('branchInfoBox');
  const btn = byId('btnSaveRequest');
  byId('branch_name').value = opt.dataset.name || '';
  btn.disabled = true;
  box.innerHTML = '';
  if (!select.value) return;
  const res = await fetch('../../api/check_request.php?branch_code=' + encodeURIComponent(select.value));
  const data = await res.json();
  let info = `<div class="card card-body mb-3"><div class="row g-2"><div class="col-md-3"><div class="detail-label">รหัสสาขา</div><div class="detail-value">${escapeHtml(select.value)}</div></div><div class="col-md-3"><div class="detail-label">ชื่อสาขา</div><div class="detail-value">${escapeHtml(opt.dataset.name || '-')}</div></div><div class="col-md-3"><div class="detail-label">เบอร์โทร</div><div class="detail-value">${escapeHtml(opt.dataset.phone || '-')}</div></div><div class="col-md-3"><div class="detail-label">จุดสังเกต</div><div class="detail-value">${escapeHtml(opt.dataset.landmark || '-')}</div></div><div class="col-12"><div class="detail-label">ที่อยู่</div><div>${escapeHtml(opt.dataset.address || '-')}</div></div></div></div>`;
  if (data.has_pending) {
    const request = data.data || data.request || {};
    info += `<div class="alert alert-warning"><strong>พบรายการรอจัดส่งอยู่แล้ว</strong><br>เลขที่คำขอ ${escapeHtml(request.request_no || '-')} สถานะ ${escapeHtml(request.status || '-')} ไม่สามารถบันทึกซ้ำได้</div>`;
    btn.disabled = true;
  } else if (data.has_history) {
    info += `<div class="alert alert-info"><strong>พบประวัติเคยส่ง HDD ให้สาขานี้</strong><br>สามารถบันทึกคำขอใหม่ได้ แต่ควรตรวจสอบข้อมูลก่อน</div>`;
    btn.disabled = false;
  } else {
    info += `<div class="alert alert-success">ไม่พบรายการค้างส่ง สามารถบันทึกคำขอได้</div>`;
    btn.disabled = false;
  }
  box.innerHTML = info;
}

document.addEventListener('DOMContentLoaded', () => {
  if (byId('btnLoadBranches')) byId('btnLoadBranches').addEventListener('click', loadBranches);
  if (byId('branch_code')) byId('branch_code').addEventListener('change', onBranchChanged);
  if (byId('hdd_serial_scan')) byId('hdd_serial_scan').focus();
});
