const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const operationsSource = fs.readFileSync(require.resolve('../../public/library/operations.js'), 'utf8');
const authAdminSource = fs.readFileSync(require.resolve('../../public/library/auth-admin.js'), 'utf8');
const appSource = fs.readFileSync(require.resolve('../../public/library/app.js'), 'utf8');
const sidebarSource = fs.readFileSync(require.resolve('../../resources/views/library/partials/sidebar.blade.php'), 'utf8');
const professionalSource = fs.readFileSync(require.resolve('../../public/library/professional.js'), 'utf8');

function renderOperationRows(key, rows, role = 'Thủ thư', paymentsEnabled = true) {
  const context = vm.createContext({
    window: {
      libraryUser: role ? {id: 1, role} : null,
      libraryFeatureApi: {capabilities: {payments: paymentsEnabled, reservationWorkflow: paymentsEnabled}},
    },
    labels: {},
    location: {hash: ''},
    state: {page: key},
    localStorage: {getItem: () => null, setItem() {}},
    document: {addEventListener() {}, getElementById() { return null; }, querySelector() { return null; }},
    escapeHTML: value => String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character])),
    render() {},
    Intl,
    encodeURIComponent,
  });
  vm.runInContext(operationsSource, context);
  vm.runInContext(`opData[${JSON.stringify(key)}] = ${JSON.stringify(rows)};`, context);
  return vm.runInContext(`opRows(${JSON.stringify(key)}).table`, context);
}

test('staff payment queue shows server purpose, amount, method, and confirmation only for eligible states', () => {
  const html = renderOperationRows('payments', [
    {id:'cash-1', reader:'Reader One', purpose:'reservation', reference:'Reservation 41', amount:45000, method:'cash', raw_status:'pending'},
    {id:'bank-1', reader:'Reader Two', purpose:'vip', reference:'VIP month', amount:200000, method:'bank_transfer', raw_status:'confirming'},
    {id:'bank-pending', reader:'Reader Three', purpose:'vip', amount:200000, method:'bank_transfer', raw_status:'pending'},
    {id:'paid-1', reader:'Reader Four', purpose:'reservation', amount:10000, method:'cash', raw_status:'paid'},
  ]);

  assert.match(html, /Gói VIP · 1 tháng/);
  assert.match(html, /Reservation 41/);
  assert.match(html, /45\.000đ/);
  assert.match(html, /200\.000đ/);
  assert.match(html, /Chuyển khoản mô phỏng/);
  assert.match(html, /data-op-confirm-ledger="cash-1"/);
  assert.match(html, /data-op-confirm-ledger="bank-1"/);
  assert.doesNotMatch(html, /data-op-confirm-ledger="bank-pending"/);
  assert.doesNotMatch(html, /data-op-confirm-ledger="paid-1"/);
});

test('approved unpaid reservations are not shown as collected or confirmable', () => {
  const match = authAdminSource.match(/function authReservationStatus\(row,staff=false\)\{[\s\S]*?\nfunction authMapReservation/);
  assert.ok(match);
  const statusSource = match[0].replace(/\nfunction authMapReservation[\s\S]*$/, '');
  const context = vm.createContext({window:{libraryFeatureApi:{capabilities:{reservationWorkflow:true}}}});
  vm.runInContext(`${statusSource}; globalThis.mapStatus=authReservationStatus;`, context);
  const reservation = {status:'ready', payment_method:'cash', payment_status:'unpaid'};
  const unpaidStatus = context.mapStatus(reservation, true);
  assert.equal(unpaidStatus, 'Chờ độc giả thanh toán');
  assert.doesNotMatch(renderOperationRows('reservations', [{id:41, reader:'Reader One', book:'Book One', status:unpaidStatus}]), /data-op-confirm-payment/);
  assert.match(renderOperationRows('reservations', [{id:41, reader:'Reader One', book:'Book One', status:'Chờ thu tiền mặt'}]), /data-op-confirm-payment="41"/);
});

test('reader reservation navigation remains personal while staff reservation routing stays protected', () => {
  const start = authAdminSource.indexOf('const authLibrarianPages=');
  const end = authAdminSource.indexOf('\nfunction authSetIdentity', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const routeSource = authAdminSource.slice(start, end);
  const context = vm.createContext({authSession:{role:'Độc giả'}});
  vm.runInContext(`${routeSource}; globalThis.isStaffPage=authIsLibrarianPage;`, context);
  assert.equal(context.isStaffPage('reservations'), false);
  assert.equal(context.isStaffPage('loans'), true);
  context.authSession = {role:'Thủ thư'};
  assert.equal(context.isStaffPage('reservations'), true);
  assert.equal(context.isStaffPage('loans'), true);

  assert.match(sidebarSource, /id="reader-nav"[\s\S]*?href="#reservations"[^>]*data-page="reservations"/);
  assert.match(authAdminSource, /if\(authIsLibrarianPage\(route\)&&authSession\?\.role==='Độc giả'\)route='home';/);
  assert.match(authAdminSource, /if\(authIsLibrarianPage\(state\.page\)&&authSession\?\.role==='Độc giả'\)/);
});

test('reservation request uses the concise QR label and removes redundant cart fee notes', () => {
  assert.match(authAdminSource, /name="reservation_method" value="bank_transfer" checked> Mã QR/);
  assert.match(authAdminSource, /<legend>Phương thức thanh toán<\/legend>/);
  assert.doesNotMatch(authAdminSource, /Nhân viên duyệt yêu cầu trước; bạn chỉ thanh toán sau khi yêu cầu được duyệt\./);

  const start = professionalSource.indexOf('function proCart(){');
  const end = professionalSource.indexOf('\nfunction ', start + 1);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const cartSource = professionalSource.slice(start, end);
  const context = vm.createContext({
    state: {},
    authServerData: {cartItems:[{id:1, book_id:7, quantity:2, book:{id:7,title:'A Book',author:'An Author',rental_fee:5000}}]},
    proFindBook: () => null,
    authNormalizeBook: book => book,
    miniCover: () => '<div></div>',
    escapeHTML: value => String(value),
    fmtPrice: value => `${value}đ`,
  });
  vm.runInContext(`${cartSource}; globalThis.renderCart = proCart;`, context);
  const html = context.renderCart();
  assert.match(html, /data-checkout/);
  assert.match(html, /Gửi yêu cầu đặt trước/);
  assert.doesNotMatch(html, /Phí mượn theo cấu hình|Máy chủ xác nhận|Chưa thu tiền ở bước gửi yêu cầu/);
});

test('inventory exposes the protected upload form only for authenticated staff rows with a book id', () => {
  const inventory = [{id:'TV-1', book_id:17, book:'Book One', status:'Đang lưu hành'}];
  assert.match(renderOperationRows('inventory', inventory, 'Admin'), /href="\/staff\/books\/17\/epub\/upload"/);
  assert.match(renderOperationRows('inventory', inventory, 'Thủ thư'), /href="\/staff\/books\/17\/epub\/upload"/);
  assert.doesNotMatch(renderOperationRows('inventory', inventory, 'Độc giả'), /epub\/upload/);
  assert.doesNotMatch(renderOperationRows('inventory', [{...inventory[0], book_id:null}], 'Admin'), /epub\/upload/);
});

test('payments navigation is staff-only in the sidebar and follows the enabled capability', () => {
  assert.match(sidebarSource, /id="librarian-nav"[\s\S]*?data-page="payments" data-feature="payments"/);
  assert.match(sidebarSource, /id="admin-nav"[\s\S]*?data-page="payments" data-feature="payments"/);
  assert.doesNotMatch(sidebarSource.slice(sidebarSource.indexOf('id="reader-nav"'), sidebarSource.indexOf('id="librarian-nav"')), /data-page="payments"/);

  const start = appSource.indexOf('function syncSidebar(){');
  const end = appSource.indexOf('\nfunction closeSidebar', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const source = appSource.slice(start, end);
  const link = {hidden:true};
  const toggles = [];
  const context = vm.createContext({
    window: {libraryFeatureApi: {capabilities: {payments:true}}},
    document: {
      querySelectorAll: selector => selector === '[data-feature="payments"]' ? [link] : [],
      body: {classList: {contains: () => false, toggle(...args) { toggles.push(args); }}},
    },
    sidebar: {classList: {contains: () => false}},
    desktopSidebarQuery: {matches:true},
    sidebarBackdrop: {classList: {toggle() {}}},
    sidebarToggle: {setAttribute() {}},
  });
  vm.runInContext(`${source}; syncSidebar();`, context);
  assert.equal(link.hidden, false);
  context.window.libraryFeatureApi.capabilities.payments = false;
  vm.runInContext('syncSidebar();', context);
  assert.equal(link.hidden, true);
});

test('book detail links to the full EPUB only when available and explains server-checked reading access', () => {
  const start = professionalSource.indexOf('function proDetail(){');
  const end = professionalSource.indexOf('\nfunction proCart', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const detailSource = professionalSource.slice(start, end);
  const context = vm.createContext({
    state: {bookId: 1, favorites: []},
    proFindBook: id => ({id, title:'A Book', author:'An Author', category:'Fiction', description:'Description', has_epub:true, available_quantity:1}),
    proBooks: () => [],
    cover: () => '<div></div>',
    escapeHTML: value => String(value),
    fmtPrice: value => `${value}đ`,
    Number,
  });
  vm.runInContext(`${detailSource}; globalThis.renderBookDetail = proDetail;`, context);
  let html = vm.runInContext('renderBookDetail()', context);
  assert.match(html, /href="\/books\/1\/read"/);
  assert.match(html, /Đọc EPUB/);
  assert.doesNotMatch(html, /Đọc toàn bộ EPUB/);
  assert.match(html, /Đọc EPUB miễn phí 3 chương đầu/);
  assert.match(html, /Gói VIP đọc toàn bộ EPUB/);
  assert.match(html, /quyền truy cập được máy chủ xác nhận/);

  context.proFindBook = id => ({id, title:'A Book', author:'An Author', category:'Fiction', description:'Description', has_epub:false, available_quantity:1});
  html = vm.runInContext('renderBookDetail()', context);
  assert.match(html, /Chưa có EPUB/);
  assert.doesNotMatch(html, /href="\/books\/1\/read"/);
});
