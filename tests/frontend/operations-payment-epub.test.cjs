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
  vm.runInContext(`Object.assign(opView(${JSON.stringify(key)}), {loaded: true, total: ${rows.length}})`, context);
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

test('reader reservations and fines are personal while staff operations stay protected', () => {
  const start = authAdminSource.indexOf('const authLibrarianPages=');
  const end = authAdminSource.indexOf('\nfunction authSetIdentity', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const routeSource = authAdminSource.slice(start, end);
  const context = vm.createContext({authSession:{role:'Độc giả'}});
  vm.runInContext(`${routeSource}; globalThis.isStaffPage=authIsLibrarianPage; globalThis.isAdminCounterPage=authAdminCounterPage;`, context);
  assert.equal(context.isStaffPage('reservations'), false);
  assert.equal(context.isStaffPage('fines'), false);
  assert.equal(context.isStaffPage('loans'), true);
  context.authSession = {role:'Thủ thư'};
  assert.equal(context.isStaffPage('authors'), true);
  assert.equal(context.isStaffPage('accounts'), false);
  assert.equal(context.isStaffPage('reservations'), true);
  assert.equal(context.isStaffPage('fines'), true);
  assert.equal(context.isStaffPage('loans'), true);
  assert.equal(context.isAdminCounterPage('loans'), false);
  context.authSession = {role:'Admin'};
  assert.equal(context.isStaffPage('authors'), true);
  assert.equal(context.isStaffPage('fines'), true);
  for (const page of ['librarian-home','reservations','payments','loans','returns','readers']) {
    assert.equal(context.isAdminCounterPage(page), true);
  }
  for (const page of ['accounts','reports','fines','inventory']) {
    assert.equal(context.isAdminCounterPage(page), false);
  }
  context.authSession = null;
  assert.equal(context.isStaffPage('fines'), true);

  assert.match(sidebarSource, /id="reader-nav"[\s\S]*?href="#reservations"[^>]*data-page="reservations"/);
  assert.match(sidebarSource, /id="reader-nav"[\s\S]*?href="#fines"[^>]*data-page="fines"/);
  assert.match(authAdminSource, /if\(authIsLibrarianPage\(route\)&&authSession\?\.role==='Độc giả'\)route='home';/);
  assert.match(authAdminSource, /if\(authIsLibrarianPage\(state\.page\)&&authSession\?\.role==='Độc giả'\)/);
});

test('fine rows offer waiver to Admin and collection to the librarian', () => {
  const fine = {id:17, reader:'Reader One', reason:'Rách bìa', amount:25000, status:'Chưa thanh toán', raw_status:'unpaid'};
  const admin = renderOperationRows('fines', [fine], 'Admin');
  assert.match(admin, /data-op-waive="17"/);
  assert.match(admin, /\/staff\/receipts\/fines\/17"[^>]*>Xem phiếu/);
  assert.doesNotMatch(admin, /data-op-pay=/);
  const librarian = renderOperationRows('fines', [fine]);
  assert.match(librarian, /data-op-pay="17"/);
  assert.doesNotMatch(librarian, /data-op-waive=/);
  assert.doesNotMatch(renderOperationRows('fines', [{...fine,status:'Đã miễn',raw_status:'waived'}], 'Admin'), /data-op-waive=/);
});

test('authors workspace is available to both staff roles with complete CRUD controls', () => {
  for (const role of ['Admin', 'Thủ thư']) {
    const html = renderOperationRows('authors', [{id:12, name:'Author One', country:'Biography', books:2}], role);
    assert.match(html, /Author One/);
    assert.match(html, /data-op-edit="authors:12"/);
    assert.match(html, /data-op-delete="authors:12"/);
  }
  const context = vm.createContext({window:{libraryUser:{role:'Thủ thư'}}});
  const start = operationsSource.indexOf('function opStaffPage(key){');
  const end = operationsSource.indexOf('\nfunction opReset', start);
  context.opConfig={authors:{},accounts:{}};
  vm.runInContext(`${operationsSource.slice(start,end)}; globalThis.canOpen=opStaffPage;`, context);
  assert.equal(context.canOpen('authors'), true);
  assert.equal(context.canOpen('accounts'), false);
  context.window.libraryUser.role='Độc giả';
  assert.equal(context.canOpen('authors'), false);
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

test('counter navigation is in the librarian menu and the payment link follows the enabled capability', () => {
  const librarianMenu = sidebarSource.slice(sidebarSource.indexOf('id="librarian-nav"'), sidebarSource.indexOf('id="admin-nav"'));
  const adminMenu = sidebarSource.slice(sidebarSource.indexOf('id="admin-nav"'), sidebarSource.indexOf('class="sidebar-bottom"'));
  assert.match(librarianMenu, /data-page="payments" data-feature="payments"/);
  assert.match(librarianMenu, /href="#authors" class="nav-link" data-page="authors"/);
  for (const page of ['reservations', 'payments', 'loans', 'returns']) {
    assert.ok(librarianMenu.includes(`data-page="${page}"`));
    assert.ok(!adminMenu.includes(`data-page="${page}"`));
  }
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

test('book detail groups its available preview and EPUB actions', () => {
  const start = professionalSource.indexOf('function proDetail(){');
  const end = professionalSource.indexOf('\nfunction proCart', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const detailSource = professionalSource.slice(start, end);
  const context = vm.createContext({
    state: {bookId: 1, favorites: []},
    proFindBook: id => ({id, title:'A Book', author:'An Author', category:'Fiction', description:'Description', has_epub:true, preview_page_count:1, available_quantity:1}),
    proBooks: () => [],
    cover: () => '<div></div>',
    escapeHTML: value => String(value),
    fmtPrice: value => `${value}đ`,
    Number,
  });
  vm.runInContext(`${detailSource}; globalThis.renderBookDetail = proDetail;`, context);
  let html = vm.runInContext('renderBookDetail()', context);
  assert.match(html, /data-add-cart="1"/);
  assert.match(html, /class="pro-reading-actions" role="group"/);
  assert.match(html, /data-open-preview="1" aria-label="Xem ảnh giới thiệu \(1 trang\)">Xem ảnh<\/button>/);
  assert.match(html, /href="\/books\/1\/read">Đọc EPUB<\/a>/);
  assert.doesNotMatch(html, /pro-detail-policies/);

  context.proFindBook = id => ({id, title:'A Book', author:'An Author', category:'Fiction', description:'Description', has_epub:false, preview_page_count:1, available_quantity:1});
  html = vm.runInContext('renderBookDetail()', context);
  assert.match(html, /Chưa có EPUB/);
  assert.match(html, /data-open-preview="1"/);
  assert.doesNotMatch(html, /href="\/books\/1\/read"/);

  context.proFindBook = id => ({id, title:'A Book', author:'An Author', category:'Fiction', has_epub:true, preview_page_count:0, type:'Đọc online'});
  html = vm.runInContext('renderBookDetail()', context);
  assert.match(html, /href="\/books\/1\/read">Đọc EPUB<\/a>/);
  assert.doesNotMatch(html, /data-open-preview|data-add-cart|Chưa có EPUB/);
});
