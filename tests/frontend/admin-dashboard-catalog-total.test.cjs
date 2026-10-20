const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../../public/library/auth-admin.js'), 'utf8');
const start = source.indexOf('function authAdminPage(){');
const end = source.indexOf('\nfunction authReaderRecords', start);
assert.notEqual(start, -1);
assert.notEqual(end, -1);
const adminPageSource = source.slice(start, end);
const refreshStart = source.indexOf('async function authRefreshCatalog(){');
const refreshEnd = source.indexOf('\nasync function authRefreshReaderData', refreshStart);
assert.notEqual(refreshStart, -1);
assert.notEqual(refreshEnd, -1);
const refreshCatalogSource = source.slice(refreshStart, refreshEnd);

function renderAdminPage({total, filteredTotal, books, signedIn = true}) {
  const context = vm.createContext({
    state: {
      name: 'Admin',
      adminBooks: books,
      ...(total === undefined ? {} : {catalogTotal: total}),
      ...(filteredTotal === undefined ? {} : {catalogMeta: {total: filteredTotal}}),
    },
    authSession: signedIn ? {id: 1, role: 'Admin'} : null,
    opData: {loans: [], readers: [], reservations: [], returns: []},
    authEscape: value => String(value),
    icon: () => '',
    adminTable: rows => `${rows.length} loaded rows`,
  });
  vm.runInContext(`${adminPageSource}\nglobalThis.renderAdminPage = authAdminPage;`, context);
  return vm.runInContext('renderAdminPage()', context);
}

function catalogMetric(html) {
  return html.match(/<small>Tổng đầu sách<\/small><strong>(.*?)<\/strong><em>(.*?)<\/em>/)?.slice(1);
}

async function refreshCatalog({total, filteredTotal}) {
  const requests = [];
  const context = vm.createContext({
    state: {page: 'catalog', query: 'mưa', catalogPage: 2, catalogCategory: 'Văn học'},
    authLoadCatalog: async (...args) => {
      requests.push({kind: 'page', args});
      context.state.catalogMeta = {total: filteredTotal};
    },
    authRequest: async path => {
      requests.push({kind: 'total', path});
      return {total};
    },
    render() {},
  });
  vm.runInContext(`${refreshCatalogSource}\nglobalThis.refreshCatalog = authRefreshCatalog;`, context);
  await vm.runInContext('refreshCatalog()', context);
  return {requests, catalogTotal: context.state.catalogTotal, filteredTotal: context.state.catalogMeta.total};
}

test('admin dashboard uses the unfiltered server total instead of current page or search totals', async () => {
  const refreshed = await refreshCatalog({total: 18, filteredTotal: 1});
  assert.deepEqual(refreshed.requests[0], {kind: 'page', args: ['mưa', 2, true]});
  assert.equal(refreshed.requests[1].path, '/catalog/books?per_page=1&page=1');
  assert.equal(refreshed.catalogTotal, 18);
  assert.equal(refreshed.filteredTotal, 1);

  const html = renderAdminPage({total: refreshed.catalogTotal, filteredTotal: refreshed.filteredTotal, books: Array.from({length: 8}, () => ({}))});
  assert.deepEqual(catalogMetric(html), ['18', 'Tổng trong danh mục máy chủ']);
  assert.doesNotMatch(html, /Số liệu và thao tác hiện lưu trong trình duyệt/);
});

test('zero is a valid unfiltered server total and missing metadata falls back to visible books', async () => {
  const refreshed = await refreshCatalog({total: 0, filteredTotal: 1});
  assert.equal(refreshed.catalogTotal, 0);
  assert.equal(refreshed.filteredTotal, 1);

  const zero = renderAdminPage({total: refreshed.catalogTotal, filteredTotal: refreshed.filteredTotal, books: Array.from({length: 8}, () => ({}))});
  assert.deepEqual(catalogMetric(zero), ['0', 'Tổng trong danh mục máy chủ']);

  const fallback = renderAdminPage({books: Array.from({length: 8}, () => ({})), signedIn: false});
  assert.deepEqual(catalogMetric(fallback), ['8', 'Số sách đang hiển thị']);
});
