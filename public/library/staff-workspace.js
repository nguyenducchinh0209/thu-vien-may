/* Staff dashboard and reports share the authenticated web session. */
const staffReportTypes = {
  circulation: 'Mượn và trả sách',
  overdue: 'Phiếu quá hạn',
  inventory: 'Tồn kho',
  finance: 'Thu, hoàn và cọc',
  refunds: 'Đặt sách chờ hoàn phí',
  deposits: 'Phiếu chờ tất toán cọc',
};
let staffDashboard = {identity: null, data: null, error: ''};
let staffReport = {identity: null, type: 'circulation', data: null, error: '', loading: false, page: 1, sequence: 0};

function staffIsSignedIn() {
  return ['Admin', 'Thủ thư'].includes(authSession?.role);
}

function staffVietnamDay(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);
  const value = type => parts.find(part => part.type === type)?.value;
  return [value('year'), value('month'), value('day')].join('-');
}

function staffVietnamTime(value) {
  if (!value) return '—';
  const source = String(value);
  const normalized = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(source) ? source.replace(' ', 'T') + 'Z' : source;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('vi-VN', {timeZone: 'Asia/Ho_Chi_Minh'});
}

async function staffRefreshDashboard() {
  if (!staffIsSignedIn()) return;
  const identity = authSession;
  const sameIdentity = staffDashboard.identity === identity;
  const current = {
    identity,
    data: sameIdentity ? staffDashboard.data : null,
    chartMode: sameIdentity ? staffDashboard.chartMode : 'circulation',
    error: '',
  };
  staffDashboard = current;
  try {
    current.data = await authRequest('/staff/dashboard');
  } catch (error) {
    current.error = error.message;
  }
  if (staffDashboard === current && authSession === identity && ['admin', 'librarian-home'].includes(state.page)) render();
}

function staffDashboardStatus(snapshot, loadingLabel) {
  if (snapshot.error) {
    return '<p class="workspace-error" role="alert">' + authEscape(snapshot.error) +
      ' <button class="link-button" data-staff-reload>Tải lại</button></p>';
  }
  return snapshot.data ? '' : '<p role="status">' + loadingLabel + '</p>';
}

function staffDashboardMetrics(data, counters) {
  if (!data) return '';

  return '<div class="workspace-metrics">' + counters.map(([key, label, page, report, filter, format, accountStatus]) =>
    '<button type="button" class="workspace-metric" ' +
    (report ? 'data-report-open="' + report + '"' : filter ? 'data-staff-page="' + page + '" data-staff-filter="' + filter + '"' :
      'data-page-link="' + page + '"') + (accountStatus ? ' data-account-status="' + accountStatus + '"' : '') + '>' +
    '<strong>' + (format ? authEscape(format(data[key])) : Number(data[key] || 0).toLocaleString('vi-VN')) + '</strong><span>' + label +
    '</span></button>').join('') + '</div>';
}

function librarianDashboardView() {
  const counters = [
    ['pending_reservations', 'Đặt sách chờ duyệt', 'reservations', null, 'pending'],
    ['pending_payments', 'Khoản thu chờ xác nhận', 'payments', null, 'queue'],
    ['due_today', 'Phiếu đến hạn hôm nay', 'loans', null, 'due_today'],
    ['overdue_loans', 'Phiếu quá hạn', 'reports', 'overdue'],
    ['unsettled_deposits', 'Phiếu chờ tất toán cọc', 'loans', null, 'deposit_due'],
    ['active_loans', 'Phiếu đang mượn', 'loans', null, 'active'],
    ['active_readers', 'Độc giả hoạt động', 'readers', null, 'active'],
    ['books', 'Đầu sách trong danh mục', 'inventory'],
  ];
  const snapshot = staffDashboard.identity === authSession ? staffDashboard : {};
  return '<section class="staff-workspace librarian-overview"><div class="heading-row"><div>' +
    '<h1 class="page-title">Bàn làm việc thủ thư</h1><p class="subheading">Xử lý các yêu cầu và phiếu mượn tại quầy.</p></div>' +
    '<button type="button" class="primary-button" data-page-link="loans">Mở phiếu mượn</button></div>' +
    staffDashboardStatus(snapshot, 'Đang tải công việc…') +
    staffDashboardMetrics(snapshot.data, counters) + '</section>';
}

const adminChartModes = {
  circulation: {label: 'Mượn và trả sách', dataKey: 'circulation_days', unit: 'Số bản', series: [['issued', 'Cho mượn'], ['returned', 'Nhận trả']], empty: 'Chưa có lượt cho mượn hoặc nhận trả trong 7 ngày này.'},
  finance: {label: 'Tiền thu và tiền hoàn', dataKey: 'finance_days', unit: 'VNĐ', series: [['received', 'Tiền thu'], ['refunded', 'Tiền hoàn']], empty: 'Chưa có khoản thu hoặc hoàn trong 7 ngày này.'},
};

function adminChartContent(data, mode) {
  const chart = adminChartModes[mode];
  const days = data?.[chart.dataKey];
  if (!Array.isArray(days) || days.length !== 7) {
    return '<p class="admin-overview-empty">Chưa tải được số liệu cho biểu đồ này.</p>';
  }

  const number = mode === 'finance' ? authMoney : value => Number(value).toLocaleString('vi-VN');
  const value = (day, key) => Number(day[key]) || 0;
  const dateLabel = date => authEscape(date.slice(8, 10) + '/' + date.slice(5, 7));
  const first = days[0].date;
  const last = days[days.length - 1].date;
  const maximum = Math.max(...days.flatMap(day => chart.series.map(([key]) => value(day, key))));
  const legend = chart.series.map(([key, label], index) => '<span class="chart-series-' + index + '">' +
    label + ' <strong>' + number(days.reduce((sum, day) => sum + value(day, key), 0)) + '</strong></span>').join('');
  const bars = day => chart.series.map(([key], index) => '<span class="admin-chart-bar chart-series-' + index +
    '" style="--bar-height:' + Math.round(value(day, key) * 10000 / maximum) / 100 + '%"></span>').join('');
  const visual = maximum > 0 ? '<div class="admin-chart" aria-hidden="true">' +
    '<div class="admin-chart-axis"><span>' + number(maximum) + '</span><span>' + number(0) + '</span></div>' +
    '<div class="admin-chart-plot">' + days.map(day => '<div class="admin-chart-day"><div class="admin-chart-bars">' +
      bars(day) + '</div><span>' + dateLabel(day.date) + '</span></div>').join('') +
    '</div></div>' : '<p class="admin-overview-empty admin-chart-empty">' + chart.empty + '</p>';
  const columns = chart.series.map(([, label]) => '<th scope="col">' + label + '</th>').join('');
  const rows = days.map(day => '<tr><th scope="row">' + dateLabel(day.date) + '</th>' +
    chart.series.map(([key]) => '<td>' + number(value(day, key)) + '</td>').join('') + '</tr>').join('');

  return '<div class="admin-activity-period"><p>' + dateLabel(first) + ' – ' + dateLabel(last) + ' · ' + chart.unit + '</p>' +
    '<button type="button" class="link-button" data-report-open="' + mode + '" data-report-from="' +
    authEscape(first) + '" data-report-to="' + authEscape(last) + '">Xem báo cáo</button></div>' +
    '<div class="admin-chart-legend">' + legend + '</div>' + visual +
    '<details class="admin-chart-data"><summary>Bảng số liệu theo ngày</summary>' +
    '<table aria-label="' + chart.label + ' theo ngày"><thead><tr>' +
    '<th scope="col">Ngày</th>' + columns + '</tr></thead><tbody>' + rows + '</tbody></table></details>';
}

function adminActivityPanel(snapshot) {
  const mode = snapshot.chartMode || 'circulation';
  const options = Object.entries(adminChartModes).map(([value, chart]) =>
    '<option value="' + value + '"' + (value === mode ? ' selected' : '') + '>' + chart.label + '</option>').join('');
  return '<section class="admin-overview-panel admin-activity" aria-labelledby="admin-activity-title">' +
    '<div class="admin-activity-header"><h2 id="admin-activity-title">Biểu đồ 7 ngày</h2>' +
    '<select id="admin-chart-mode" aria-label="Nội dung biểu đồ">' + options + '</select></div>' +
    '<div id="admin-chart-content">' + adminChartContent(snapshot.data, mode) + '</div></section>';
}

function adminDashboardView() {
  const snapshot = staffDashboard.identity === authSession ? staffDashboard : {};
  const overview = [
    ['books', 'Đầu sách trong danh mục', 'inventory'],
    ['active_readers', 'Độc giả hoạt động', 'accounts', null, 'Độc giả', null, 'active'],
    ['staff_accounts', 'Tài khoản nhân viên', 'accounts', null, 'staff'],
    ['deposit_held_now', 'Tiền cọc đang giữ', null, 'finance', null, authMoney],
  ];
  const review = [
    ['expired_paid_reservations', 'Đặt sách chờ hoàn phí', 'reports', 'refunds'],
    ['overdue_loans', 'Phiếu mượn quá hạn', 'reports', 'overdue'],
    ['unsettled_deposits', 'Phiếu chờ tất toán cọc', 'reports', 'deposits'],
  ].filter(([key]) => Number(snapshot.data?.[key]) > 0);
  const reportLinks = ['finance', 'inventory', 'deposits'].map(type =>
    '<button type="button" data-report-open="' + type + '"><span>' + staffReportTypes[type] +
    '</span><span aria-hidden="true">→</span></button>').join('');
  return '<section class="staff-workspace admin-overview"><header class="admin-overview-header">' +
    '<h1 class="page-title">Tổng quan quản trị</h1>' +
    (snapshot.data && !snapshot.error ? '<button type="button" class="secondary-button" data-staff-reload>Làm mới số liệu</button>' : '') +
    '</header>' +
    staffDashboardStatus(snapshot, 'Đang tải số liệu quản trị…') +
    (snapshot.data ? '<section class="admin-overview-summary" aria-label="Số liệu thư viện">' +
      staffDashboardMetrics(snapshot.data, overview) + '</section>' : '') +
    '<div class="admin-overview-grid">' +
    (snapshot.data ? adminActivityPanel(snapshot) : '') +
    '<div class="admin-overview-aside">' +
    (snapshot.data ? '<section class="admin-overview-panel admin-overview-review" aria-labelledby="admin-review-title">' +
      '<h2 id="admin-review-title">Cần xử lý</h2>' +
      (review.length ? staffDashboardMetrics(snapshot.data, review) :
        '<p class="admin-overview-empty">Không có phiếu quá hạn, cọc chờ tất toán hoặc khoản chờ hoàn phí.</p>') +
      '</section>' : '') +
    '<section class="admin-overview-panel" aria-labelledby="admin-reports-title"><h2 id="admin-reports-title">Báo cáo</h2>' +
    '<div class="admin-report-links">' + reportLinks + '</div></section></div></div></section>';
}

function staffEnterReports(load = true) {
  if (!staffIsSignedIn()) return;
  if (staffReport.identity !== authSession) {
    const now = new Date();
    staffReport = {
      identity: authSession, type: 'circulation', from: staffVietnamDay(new Date(now.getTime() - 29 * 86400000)),
      to: staffVietnamDay(now), q: '', page: 1, data: null, loading: false, error: '', sequence: 0,
    };
  }
  if (load && !staffReport.data && !staffReport.loading && !staffReport.error) staffLoadReport();
}

function staffReportParams(report = staffReport) {
  return new URLSearchParams({type: report.type, from: report.from, to: report.to, q: report.q, page: report.page});
}

async function staffLoadReport() {
  const report = staffReport;
  const sequence = ++report.sequence;
  report.loading = true;
  report.error = '';
  report.data = null;
  const current = () => staffReport === report && report.identity === authSession && sequence === report.sequence;
  try {
    const response = await authRequest('/staff/reports?' + staffReportParams(report));
    if (current()) report.data = response;
  } catch (error) {
    if (current()) report.error = error.message;
  } finally {
    if (current()) {
      report.loading = false;
      if (state.page === 'reports') render();
    }
  }
}

function staffReportView() {
  const report = staffReport;
  const data = report.data;
  const dateRange = ['circulation', 'finance'].includes(report.type);
  const options = Object.entries(staffReportTypes).filter(([value]) => value !== 'deposits' || authSession?.role === 'Admin').map(([value, name]) =>
    '<option value="' + value + '"' + (value === report.type ? ' selected' : '') + '>' + name + '</option>').join('');
  const fields = '<form id="staff-report-filter" class="workspace-filter"><label>Loại báo cáo<select name="type">' +
    options + '</select></label>' +
    '<label data-report-date' + (dateRange ? '' : ' hidden') + '>Từ ngày<input type="date" name="from" value="' + authEscape(report.from) + '"></label>' +
    '<label data-report-date' + (dateRange ? '' : ' hidden') + '>Đến ngày<input type="date" name="to" value="' + authEscape(report.to) + '"></label>' +
    '<label>Tra cứu<input name="q" value="' + authEscape(report.q) + '" maxlength="100" placeholder="Mã, tên sách hoặc tên độc giả"></label>' +
    '<button type="submit" class="primary-button">Áp dụng</button></form>';
  let content = '<p role="status">Đang tải báo cáo…</p>';
  if (report.error) content = '<p class="workspace-error" role="alert">' + authEscape(report.error) + '</p>';
  if (data) {
    const columns = Object.entries(data.columns || {});
    const rows = Array.isArray(data.rows?.data) ? data.rows.data : [];
    const methodLabels = {cash: 'Tiền mặt', bank_transfer: 'Chuyển khoản demo', manual: 'Thủ công', deposit: 'Từ cọc'};
    const cell = (key, value, row) => {
      if (key === 'occurred_at') return staffVietnamTime(value);
      if (key === 'amount' || key === 'unpaid_fines') return authMoney(value);
      if (key === 'reference' && report.type === 'deposits') {
        return '<a class="link-button" href="/receipts/loans/' + encodeURIComponent(value) +
          '" target="_blank" rel="noopener" aria-label="Xem phiếu mượn ' + authEscape(value) + '">' + authEscape(value) + '</a>';
      }
      if (key === 'reference' && ['Phí đặt trước','Phí VIP'].includes(row.event)) {
        return '<a class="link-button" href="/staff/receipts/payments/' + encodeURIComponent(value) +
          '" target="_blank" rel="noopener" aria-label="In biên nhận ' + authEscape(value) + '">' + authEscape(value) + '</a>';
      }
      if (key === 'method') return methodLabels[value] || '—';
      return authEscape(value ?? '—');
    };
    const canRefund = report.type === 'refunds' && authSession?.role === 'Admin';
    const headers = columns.map(([key, label]) => '<th scope="col">' +
      authEscape(key === 'occurred_at' ? label.replace('(UTC)', '(giờ Việt Nam)') : label) + '</th>').join('');
    const body = rows.map(row => '<tr>' + columns.map(([key]) => '<td>' + cell(key, row[key], row) + '</td>').join('') +
      (canRefund ? '<td><button type="button" class="secondary-button" data-refund-payment="' +
        authEscape(row.reference) + '">Ghi nhận hoàn phí</button></td>' : '') + '</tr>').join('');
    const table = rows.length ? '<div class="ops-table-wrap" role="region" aria-label="Dữ liệu báo cáo" tabindex="0">' +
      '<table class="ops-table"><thead><tr>' + headers + (canRefund ? '<th scope="col">Thao tác</th>' : '') +
      '</tr></thead><tbody>' + body + '</tbody></table></div>' :
      '<div class="empty-state"><strong>Không có bản ghi phù hợp</strong><p>Thử thay đổi bộ lọc.</p></div>';
    const summary = data.type === 'finance' ? '<div class="workspace-metrics workspace-totals">' +
      '<div class="workspace-metric"><strong>' + authMoney(data.deposit_held_now) +
      '</strong><span>Cọc đang giữ hiện tại</span></div>' +
      Object.entries(data.totals || {}).map(([name, value]) => '<div class="workspace-metric"><strong>' +
        authMoney(value) + '</strong><span>' + authEscape(name) + '</span></div>').join('') +
      '</div><p class="section-note">Khoản khấu trừ từ cọc là chuyển nội bộ. Các dòng hoàn thể hiện số tiền đã chi ra.</p>' : '';
    const pages = data.rows?.last_page || 1;
    const page = data.rows?.current_page || 1;
    const navigation = pages > 1 ? '<div class="ops-pages"><button class="filter-chip" data-report-page="' +
      (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Trước</button><span>Trang ' + page + ' / ' + pages +
      '</span><button class="filter-chip" data-report-page="' + (page + 1) + '"' +
      (page >= pages ? ' disabled' : '') + '>Sau</button></div>' : '';
    const exportParams = staffReportParams();
    exportParams.delete('page');
    content = summary + '<div class="workspace-report-header"><span>' + Number(data.rows?.total || 0).toLocaleString('vi-VN') +
      ' bản ghi</span><a class="secondary-button" href="/staff/reports/export?' + authEscape(exportParams.toString()) +
      '">Tải CSV</a></div>' + table + navigation;
  }
  return '<section class="staff-workspace"><div class="heading-row"><div><h1 class="page-title">Báo cáo thư viện</h1>' +
    '<p class="subheading">Tra cứu và xuất số liệu giao dịch, mượn trả và tồn kho.</p></div></div>' + fields + content + '</section>';
}

document.addEventListener('change', event => {
  if (event.target.matches('#admin-chart-mode') && authSession?.role === 'Admin' && staffDashboard.identity === authSession) {
    const mode = event.target.value;
    if (Object.hasOwn(adminChartModes, mode)) {
      staffDashboard.chartMode = mode;
      document.getElementById('admin-chart-content').innerHTML = adminChartContent(staffDashboard.data, mode);
    }
    return;
  }
  if (!event.target.matches('#staff-report-filter [name="type"]')) return;
  const show = ['circulation', 'finance'].includes(event.target.value);
  event.target.form.querySelectorAll('[data-report-date]').forEach(node => { node.hidden = !show; });
});

document.addEventListener('click', event => {
  const button = event.target.closest('[data-staff-reload],[data-report-open],[data-report-page],[data-refund-payment],[data-staff-loan],[data-staff-filter]');
  if (!button || !staffIsSignedIn()) return;
  if (button.matches('[data-staff-reload]')) staffRefreshDashboard();
  if (button.dataset.staffLoan) staffShowLoan(button.dataset.staffLoan);
  if (button.dataset.staffFilter && opStaffPage(button.dataset.staffPage)) {
    opView(button.dataset.staffPage).q = '';
    opSetFilter(button.dataset.staffPage, button.dataset.staffFilter, button.dataset.accountStatus);
    go(button.dataset.staffPage);
  }
  if (button.dataset.reportOpen) {
    staffEnterReports(false);
    staffReport.type = button.dataset.reportOpen;
    staffReport.page = 1;
    staffReport.q = '';
    if (button.dataset.reportFrom && button.dataset.reportTo) {
      staffReport.from = button.dataset.reportFrom;
      staffReport.to = button.dataset.reportTo;
    }
    staffLoadReport();
    go('reports');
  }
  if (button.dataset.reportPage) {
    staffReport.page = Number(button.dataset.reportPage);
    staffLoadReport();
    render();
  }
  if (button.dataset.refundPayment && authSession.role === 'Admin') {
    const row = staffReport.data?.rows?.data?.find(item => item.reference === button.dataset.refundPayment);
    if (!row) return;
    showModal('<form id="reservation-refund-form" class="login-form" data-payment="' + authEscape(row.reference) +
      '"><h2 id="modal-title">Hoàn phí đặt sách</h2><p>Yêu cầu #' + authEscape(row.related_record) +
      ' · ' + authMoney(row.amount) + '</p><label class="field">Phương thức hoàn<select name="method">' +
      '<option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option>' +
      '</select></label><label class="field">Mã biên nhận<input name="receipt_reference" maxlength="150" required></label>' +
      '<label class="field">Lý do<textarea name="reason" minlength="3" maxlength="500" required></textarea></label>' +
      '<label class="workspace-check"><input type="checkbox" required> Tôi xác nhận đã hoàn toàn bộ phí cho độc giả.</label>' +
      '<p class="workspace-error" role="alert" data-refund-error></p><button class="primary-button">Lưu biên nhận hoàn phí</button></form>');
  }
});

document.addEventListener('submit', async event => {
  const form = event.target;
  if (!staffIsSignedIn()) return;
  if (form.id === 'staff-report-filter') {
    event.preventDefault();
    const values = new FormData(form);
    Object.assign(staffReport, {type: values.get('type'), from: values.get('from'), to: values.get('to'),
      q: String(values.get('q')).trim(), page: 1});
    staffLoadReport();
    render();
  }
  if (form.id === 'reservation-refund-form' && authSession.role === 'Admin') {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"], button.primary-button');
    if (submit.disabled) return;
    submit.disabled = true;
    const errorNode = form.querySelector('[data-refund-error]');
    errorNode.textContent = '';
    try {
      const result = await authRequest('/staff/payments/' + encodeURIComponent(form.dataset.payment) + '/refund', {
        method: 'POST', body: Object.fromEntries(new FormData(form)),
      });
      closeModal();
      toast(result.message);
      staffLoadReport();
      authRefreshStaffData();
    } catch (error) {
      errorNode.textContent = error.message;
    } finally {
      submit.disabled = false;
    }
  }
});

async function staffShowLoan(id) {
  try {
    const {loan} = await authRequest('/staff/loans/' + encodeURIComponent(id));
    const status = loan.money_summary;
    const items = (loan.items || []).map(item => '<li>' + authEscape(item.book_title_snapshot || item.book?.title || 'Sách') +
      ' · ' + Number(item.quantity) + ' bản · đã trả ' + Number(item.returned_quantity) + ' · mất ' + Number(item.lost_quantity) +
      (Number(item.written_off_quantity) ? ' · thu hồi ' + Number(item.written_off_quantity) : '') + '</li>').join('');
    const events = [{at:loan.issued_at, text:'Phát phiếu · ' + (loan.issuer_name_snapshot || loan.issuer?.name || 'Thư viện')}];
    for (const returned of loan.returns || []) {
      const quantity = (returned.items || []).reduce((total, item) => total + Number(item.quantity), 0);
      events.push({at: returned.returned_at, text: 'Nhận trả ' + quantity + ' bản · ' + (returned.returned_by?.name || 'Thư viện')});
    }
    for (const fine of loan.fines || []) {
      events.push({at: fine.created_at, text: 'Lập phạt ' + (fine.fine_code || '#' + fine.id) + ' · ' + fine.reason + ' · ' + authMoney(fine.amount)});
      for (const payment of fine.payments || []) {
        events.push({at: payment.paid_at, text: 'Thu phạt ' + (fine.fine_code || '#' + fine.id) + ' · ' + authMoney(payment.amount)});
      }
      if (fine.waived_at) events.push({at: fine.waived_at, text: 'Miễn phạt ' + (fine.fine_code || '#' + fine.id) + ' · ' + (fine.waiver_reason || '')});
    }
    const settled = loan.deposit?.settlement;
    if (settled) events.push({at: settled.settled_at, text: 'Tất toán cọc · hoàn ' + authMoney(settled.refund_amount) +
      ' · thu thêm ' + authMoney(settled.additional_received)});
    const history = events.sort((left, right) => new Date(left.at) - new Date(right.at)).map(item =>
      '<li><small>' + staffVietnamTime(item.at) + '</small> · ' + authEscape(item.text) + '</li>').join('');
    showModal('<section class="login-form"><h2 id="modal-title">Phiếu mượn #' + loan.id + '</h2><p>' +
      authEscape(loan.reader_name_snapshot || loan.reader?.name) + ' · Hạn trả ' + staffVietnamTime(loan.due_at) +
      '</p><p><strong>' + authEscape(status?.label) + '</strong>' +
      (status?.balance !== null ? ' · Số dư ' + authMoney(status?.balance) : '') +
      '</p><h3>Sách trên phiếu</h3><ul>' + items + '</ul><h3>Lịch sử xử lý</h3><ol>' + history +
      '</ol><div class="workspace-receipt-actions"><a class="secondary-button" target="_blank" rel="noopener" href="/receipts/loans/' +
      loan.id + '">In / Lưu PDF</a><button class="secondary-button" data-staff-fine-loan="'+loan.id+'">Lập phiếu phạt</button>' +
      (loan.status==='active'?'<button class="secondary-button" data-op-return="'+loan.id+'">Nhận trả</button>':'') +
      (status?.can_settle ? '<button class="primary-button" data-op-settle="' +
      loan.id + '">Đối soát cọc</button>' : '') + '</div></section>');
  } catch (error) {
    toast(error.message);
  }
}
