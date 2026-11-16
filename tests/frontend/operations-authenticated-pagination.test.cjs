const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../../public/library/operations.js'), 'utf8');

test('staff requests the selected search, status and page from the API', async () => {
  const requests = [];
  const context = vm.createContext({
    window: {libraryUser: {id: 1, role: 'Thủ thư'}},
    labels: {}, state: {page: 'loans'},
    URLSearchParams,
    authRequest: async path => {
      requests.push(path);
      return {current_page: 8, last_page: 9, total: 67, data: [{
        id: 64, reader: 'Reader', book: 'Book', raw_status: 'active', status: 'Đang mượn · Đang giữ cọc',
      }]};
    },
    authMapStaffRow: (key, row) => row,
    document: {addEventListener() {}, getElementById() { return null; }},
    escapeHTML: value => String(value),
    render() {},
  });
  vm.runInContext(source, context);
  await vm.runInContext("opView('loans').q = '#64'; opSetFilter('loans','active'); opLoad('loans', 8)", context);
  assert.deepEqual(requests, ['/staff/loans?page=8&per_page=8&q=%2364&status=active&summary=1']);
  const result = vm.runInContext("opRows('loans')", context);
  assert.equal(result.count, 67);
  assert.match(result.pagination, /8 \/ 9/);
  assert.match(result.table, /data-op-return="64"/);
  assert.match(result.table, /\/receipts\/loans\/64/);
});

test('author access and customer access are restricted before requesting staff data', async () => {
  const calls = [];
  const context = vm.createContext({
    window: {libraryUser: {id: 1, role: 'Thủ thư'}}, labels: {}, state: {},
    URLSearchParams,
    authRequest: async path => calls.push(path),
    document: {addEventListener() {}}, render() {},
  });
  vm.runInContext(source, context);
  await vm.runInContext("opLoad('authors')", context);
  assert.deepEqual(calls, ['/staff/authors?page=1&per_page=8']);
  context.window.libraryUser = {id: 2, role: 'Độc giả'};
  await vm.runInContext("opLoad('loans')", context);
  assert.deepEqual(calls, ['/staff/authors?page=1&per_page=8']);
});
