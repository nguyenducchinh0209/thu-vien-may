const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../../public/library/staff-workspace.js'), 'utf8');

function setup(data) {
  const requests = [];
  const context = vm.createContext({
    state: {page: 'admin', adminBooks: Array.from({length: 8}, () => ({})), catalogMeta: {total: 1}},
    authSession: {id: 1, role: 'Admin'},
    authEscape: value => String(value),
    authMoney: value => Number(value || 0).toLocaleString('vi-VN') + 'đ',
    authRequest: async path => { requests.push(path); return data; },
    document: {addEventListener() {}},
    render() {},
  });
  vm.runInContext(source, context);
  return {context, requests};
}

test('admin dashboard uses server totals and links to managerial actions', async () => {
  const {context, requests} = setup({books: 18, active_readers: 3, staff_accounts: 2, expired_paid_reservations: 1, pending_reservations: 4, unsettled_deposits: 1});
  await vm.runInContext('staffRefreshDashboard()', context);
  assert.deepEqual(requests, ['/staff/dashboard']);
  const html = vm.runInContext('adminDashboardView()', context);
  assert.match(html, /<strong>18<\/strong><span>Đầu sách trong danh mục/);
  assert.match(html, /data-staff-page="accounts" data-staff-filter="staff"><strong>2<\/strong><span>Tài khoản nhân viên/);
  assert.match(html, /data-staff-page="accounts" data-staff-filter="Độc giả" data-account-status="active"><strong>3<\/strong>/);
  assert.match(html, /data-report-open="refunds"><strong>1<\/strong>/);
  assert.match(html, /data-report-open="deposits"><strong>1<\/strong>/);
  assert.doesNotMatch(html, /Đặt sách chờ duyệt|Khoản thu chờ xác nhận/);
  assert.doesNotMatch(html, /data-report-open="overdue"|data-staff-filter="deposit_due"/);
  assert.doesNotMatch(html, /Minh họa|Số sách đang hiển thị/);
});

test('zero counts render as zero and a different identity cannot see the previous dashboard', async () => {
  const {context} = setup({books: 0});
  await vm.runInContext('staffRefreshDashboard()', context);
  assert.match(vm.runInContext('adminDashboardView()', context), /<strong>0<\/strong><span>Đầu sách trong danh mục/);
  const admin = vm.runInContext('adminDashboardView()', context);
  assert.match(admin, /Không có phiếu quá hạn/);
  assert.match(admin, /data-report-open="finance"/);
  assert.doesNotMatch(admin, /data-report-open="refunds"/);
  context.authSession = {id: 2, role: 'Thủ thư'};
  const next = vm.runInContext('librarianDashboardView()', context);
  assert.match(next, /Đang tải công việc/);
  assert.doesNotMatch(next, /Đầu sách trong danh mục/);
});

test('librarian dashboard prioritizes the circulation queue', async () => {
  const {context} = setup({pending_reservations: 4, pending_payments: 2, expired_paid_reservations: 1, staff_accounts: 3});
  context.authSession = {id: 2, role: 'Thủ thư'};
  await vm.runInContext('staffRefreshDashboard()', context);
  const html = vm.runInContext('librarianDashboardView()', context);
  assert.match(html, /data-staff-filter="pending"><strong>4<\/strong><span>Đặt sách chờ duyệt/);
  assert.match(html, /<strong>2<\/strong><span>Khoản thu chờ xác nhận/);
  assert.doesNotMatch(html, /Tài khoản nhân viên|Đặt sách chờ hoàn phí/);
});

test('admin shows the seven-day report link, accessible data and held deposits', async () => {
  const circulation_days = Array.from({length: 7}, (_, day) => ({
    date: '2026-11-' + (10 + day), issued: day === 2 ? 4 : 0, returned: day === 6 ? 2 : 0,
  }));
  const finance_days = circulation_days.map(({date}, index) => ({
    date, received: index === 0 ? '100000.00' : '0.00', refunded: index === 6 ? '25000.00' : '0.00',
  }));
  const {context} = setup({circulation_days, finance_days, deposit_held_now: '100000.00'});
  await vm.runInContext('staffRefreshDashboard()', context);
  const html = vm.runInContext('adminDashboardView()', context);
  assert.match(html, /100\.000đ<\/strong><span>Tiền cọc đang giữ/);
  assert.match(html, /data-report-from="2026-11-10" data-report-to="2026-11-16"/);
  assert.match(html, /--bar-height:50%/);
  assert.match(html, /<th scope="row">16\/11<\/th><td>0<\/td><td>2<\/td>/);
  assert.match(html, /<option value="finance">Tiền thu và tiền hoàn<\/option>/);
  vm.runInContext('staffDashboard.chartMode = "finance"', context);
  const finance = vm.runInContext('adminDashboardView()', context);
  assert.match(finance, /data-report-open="finance" data-report-from="2026-11-10"/);
  assert.match(finance, /<th scope="row">16\/11<\/th><td>0đ<\/td><td>25\.000đ<\/td>/);
});
