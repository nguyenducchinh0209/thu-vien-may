const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const operationsSource = fs.readFileSync(require.resolve('../../public/library/operations.js'), 'utf8');

test('authenticated staff can paginate while demo-only operations stay blocked', () => {
  const listeners = new Map();
  const loans = Array.from({length: 9}, (_, index) => ({
    id: index + 1,
    reader: `Reader ${index + 1}`,
    book: `Book ${index + 1}`,
    borrowed: '2026-10-20',
    due: '2026-11-03',
    status: 'Đang mượn',
  }));
  const context = vm.createContext({
    window: {libraryUser: {id: 1, role: 'Thủ thư'}},
    labels: {},
    location: {hash: ''},
    state: {page: 'loans'},
    localStorage: {
      getItem(key) {
        return key === 'may-loans' ? JSON.stringify(loans) : null;
      },
      setItem() {},
    },
    document: {
      addEventListener(name, handler) {
        if (!listeners.has(name)) listeners.set(name, []);
        listeners.get(name).push(handler);
      },
      getElementById() { return null; },
      querySelector() { return null; },
    },
    escapeHTML: value => String(value),
    render() {},
  });

  vm.runInContext(operationsSource, context);
  const click = listeners.get('click')[0];
  const event = selector => ({
    target: {
      closest(value) {
        return value === selector ? {dataset: {opPage: '2', opApprove: '1'}} : null;
      },
    },
  });

  click(event('[data-op-page]'));
  assert.equal(vm.runInContext('opPage', context), 2);

  click(event('[data-op-approve]'));
  assert.equal(vm.runInContext('opData.reservations[0].status', context), 'Chờ duyệt');
});
