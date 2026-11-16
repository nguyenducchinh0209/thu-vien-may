/* Server searches for staff forms keep selected values when search results change. */
function staffBindSearch(input, status, fetchMatches, update) {
  let revision = 0;
  let timer;
  input.addEventListener('input', () => {
    const version = ++revision;
    clearTimeout(timer);
    timer = setTimeout(async () => {
      status.textContent = 'Đang tìm…';
      try {
        const matches = await fetchMatches(input.value.trim());
        if (version !== revision || !input.isConnected || modal.hidden) return;
        update(matches);
        status.textContent = matches.length ? '' : 'Không tìm thấy kết quả. Hãy thử mã hoặc từ khóa khác.';
      } catch (error) {
        if (version === revision && input.isConnected) status.textContent = error.message;
      }
    }, 260);
  });
}

function staffSetChoices(select, rows, label, selectedRows = []) {
  const selected = new Set([...select.selectedOptions].map(option => option.value).filter(Boolean));
  const choices = new Map(rows.map(row => [String(row.id), row]));
  for (const row of selectedRows) {
    if (selected.has(String(row.id)) && !choices.has(String(row.id))) choices.set(String(row.id), row);
  }
  if (!select.multiple) select.replaceChildren(new Option('Chọn trong kết quả', ''));
  else select.replaceChildren();
  for (const [id, row] of choices) select.add(new Option(label(row), id, false, selected.has(id)));
  if (!select.multiple && ![...selected].some(id => choices.has(id))) select.value = '';
}

async function staffReaderChoices(q) {
  const params = new URLSearchParams({status:'active', per_page:'20', q});
  return (await authRequest('/staff/readers?' + params)).data || [];
}

async function staffBookChoices(q) {
  const params = new URLSearchParams({status:'available', per_page:'20', q});
  return (await authRequest('/staff/inventory?' + params)).data || [];
}

async function authShowLoanForm() {
  try {
    const [firstReaders, firstBooks] = await Promise.all([staffReaderChoices(''), staffBookChoices('')]);
    showModal('<form id="api-loan-form" class="login-form"><h2 id="modal-title">Lập phiếu mượn</h2>' +
      '<label class="field">Tìm độc giả<input type="search" data-reader-search placeholder="Tên, mã độc giả hoặc email" maxlength="100"></label>' +
      '<p class="section-note" role="status" data-reader-results></p><label class="field">Độc giả<select name="reader_id" required></select></label>' +
      '<label class="field">Tìm sách còn bản<input type="search" id="loan-book-search" placeholder="Tên hoặc mã sách" maxlength="100"></label>' +
      '<p class="section-note" role="status" data-book-results></p><div id="loan-book-rows"></div>' +
      '<button type="button" class="secondary-button" data-loan-add-book>+ Thêm đầu sách</button>' +
      authLoanDueDateField() +
      '<label class="field">Phí mượn (đ)<input name="amount" readonly></label><label class="field">Phương thức thu phí' + staffCollectionMethod('method') + '</label>' +
      '<label class="field">Ghi chú biên nhận phí<input name="receipt_note" maxlength="500"></label>' +
      '<label class="field">Tiền cọc (đ)<input name="deposit_amount" readonly></label>' +
      '<p class="section-note">Mỗi bản cọc 100.000đ, gồm 70.000đ cọc mất sách và 30.000đ cọc giữ sách.</p>' +
      '<label class="field">Phương thức thu cọc' + staffCollectionMethod('deposit_method') + '</label>' +
      '<label class="field">Ghi chú biên nhận cọc<input name="deposit_note" maxlength="500"></label>' +
      '<label class="workspace-check"><input type="checkbox" required> Tôi đã thu đủ phí và cọc nêu trên.</label>' +
      '<p class="workspace-error" role="alert" data-loan-error></p>' +
      '<button class="primary-button" type="submit">Xác nhận thu và giao sách</button></form>');
    const form = document.getElementById('api-loan-form');
    form.dataset.issuanceKey = authRequestKey();
    const readerLabel = reader => reader.name + ' · ' + (reader.reader_profile?.reader_code || reader.email);
    const bookLabel = book => book.title + ' · ' + book.book_code + ' · còn ' + book.available_quantity;
    const knownReaders = new Map(firstReaders.map(reader => [String(reader.id), reader]));
    const knownBooks = new Map(firstBooks.map(book => [String(book.id), book]));
    const readerSelect = form.elements.reader_id;
    let bookMatches = firstBooks;
    staffSetChoices(readerSelect, firstReaders, readerLabel);
    staffBindSearch(form.querySelector('[data-reader-search]'), form.querySelector('[data-reader-results]'), staffReaderChoices, rows => {
      rows.forEach(row => knownReaders.set(String(row.id), row));
      staffSetChoices(readerSelect, rows, readerLabel, [...knownReaders.values()]);
    });
    const totals = () => {
      let cents = 0;
      let copies = 0;
      const quantities = new Map();
      for (const row of form.querySelectorAll('.loan-book-row')) {
        const bookId = row.querySelector('select').value;
        const quantity = Number(row.querySelector('input').value || 0);
        const book = knownBooks.get(bookId);
        if (!book) continue;
        cents += Math.round(Number(book.rental_fee) * 100) * quantity;
        copies += quantity;
        quantities.set(bookId, (quantities.get(bookId) || 0) + quantity);
      }
      form.elements.amount.value = (cents / 100).toFixed(2);
      form.elements.deposit_amount.value = (copies * 100000).toFixed(2);
      for (const row of form.querySelectorAll('.loan-book-row')) {
        const book = knownBooks.get(row.querySelector('select').value);
        const quantity = row.querySelector('input');
        const shortage = book && quantities.get(String(book.id)) > Number(book.available_quantity);
        quantity.setCustomValidity(shortage ? 'Tổng số bản chọn vượt tồn khả dụng của đầu sách này.' : '');
      }
    };
    const addRow = () => {
      const row = document.createElement('div');
      row.className = 'loan-book-row';
      row.innerHTML = '<label class="field">Sách<select name="book_id" required></select></label>' +
        '<label class="field">Số lượng<input name="quantity" type="number" min="1" value="1" required></label>' +
        '<button type="button" class="link-button" data-loan-remove-book>Xóa dòng</button>';
      form.querySelector('#loan-book-rows').append(row);
      staffSetChoices(row.querySelector('select'), bookMatches, bookLabel);
      totals();
    };
    form.querySelector('[data-loan-add-book]').addEventListener('click', addRow);
    form.addEventListener('click', event => {
      const remove = event.target.closest('[data-loan-remove-book]');
      if (!remove || form.querySelectorAll('.loan-book-row').length <= 1) return;
      remove.closest('.loan-book-row').remove();
      totals();
    });
    form.addEventListener('input', totals);
    staffBindSearch(form.querySelector('#loan-book-search'), form.querySelector('[data-book-results]'), staffBookChoices, rows => {
      bookMatches = rows;
      rows.forEach(row => knownBooks.set(String(row.id), row));
      form.querySelectorAll('[name="book_id"]').forEach(select => staffSetChoices(select, rows, bookLabel, [...knownBooks.values()]));
      totals();
    });
    addRow();
  } catch (error) { toast(error.message); }
}

function staffCollectionMethod(name) {
  return '<select name="' + name + '"><option value="cash">Tiền mặt</option>' +
    '<option value="bank_transfer">Chuyển khoản demo</option><option value="manual">Khác</option></select>';
}

async function authShowLoanReturn(id) {
  try {
    const {loan} = await authRequest('/staff/loans/' + encodeURIComponent(id));
    const remaining = item => item.quantity - item.returned_quantity - (item.lost_quantity || 0);
    const items = (loan.items || []).filter(item => remaining(item) > 0);
    if (!items.length || loan.status !== 'active') { toast('Phiếu này đã xử lý hết số bản.'); return; }
    showModal('<form id="api-return-form" class="login-form" data-loan-id="' + loan.id + '">' +
      '<h2 id="modal-title">Ghi nhận trả sách #' + loan.id + '</h2><p>' +
      authEscape(loan.reader_name_snapshot || loan.reader?.name) + ' · Hạn ' + staffVietnamTime(loan.due_at) + '</p>' +
      items.map(item => '<label class="field">' + authEscape(item.book_title_snapshot || item.book?.title || 'Sách') +
        ' · Còn ' + remaining(item) + ' bản<input name="item-' + item.id + '" type="number" min="0" max="' + remaining(item) +
        '" value="0" aria-describedby="return-preview"></label>').join('') +
      '<p class="section-note" id="return-preview" role="status"></p><p class="workspace-error" role="alert" data-return-error></p>' +
      '<button class="primary-button" type="submit">Xác nhận trả</button></form>');
    const form = document.getElementById('api-return-form');
    form.dataset.returnKey = authRequestKey();
    const update = () => {
      const quantity = [...form.querySelectorAll('input[type="number"]')].reduce((sum, input) => sum + Number(input.value || 0), 0);
      form.querySelector('#return-preview').textContent = loan.days_overdue > 0
        ? 'Tạm tính phạt trễ ' + loan.days_overdue + ' ngày × ' + quantity + ' bản = ' + authMoney(3000 * loan.days_overdue * quantity)
        : 'Đang trong hạn trả.';
    };
    form.addEventListener('input', update);
    update();
  } catch (error) { toast(error.message); }
}

function authChooseLoanForFine() {
  showModal('<form id="fine-choose-loan" class="login-form"><h2 id="modal-title">Chọn phiếu cần lập phạt</h2>' +
    '<label class="field">Mã phiếu mượn<input name="loan" inputmode="numeric" pattern="#?[0-9]+" required></label>' +
    '<p class="workspace-error" role="alert" data-fine-lookup-error></p><button class="primary-button">Mở phiếu</button></form>');
}
async function authShowFineForm(loanId, errorNode = null) {
  try {
    const {loan} = await authRequest('/staff/loans/' + encodeURIComponent(loanId));
    const items = loan.items || [];
    if (!items.length) throw new Error('Không tìm thấy sách trong phiếu mượn này.');
    showModal('<form id="api-fine-form" class="login-form"><h2 id="modal-title">Tạo phiếu phạt · Mượn #' + loan.id + '</h2><p>' +
      authEscape(loan.reader_name_snapshot || loan.reader?.name) + '</p><label class="field">Sách<select name="loan_item_id" required>' +
      items.map(item => '<option value="' + item.id + '">' + authEscape(item.book_title_snapshot || item.book?.title || 'Sách') +
        '</option>').join('') + '</select></label><label class="field">Loại vi phạm<select name="type">' +
      '<option value="damage">Rách, bong bìa, bung gáy</option><option value="wet_dirty">Ướt, bẩn, mốc, viết/vẽ</option>' +
      '<option value="missing_parts">Thiếu trang hoặc phụ kiện</option><option value="unusable">Hỏng đến mức phải thu hồi</option>' +
      '<option value="lost">Mất hoặc không thể thu hồi</option><option value="other">Vi phạm khác</option></select></label>' +
      '<label class="field">Số bản<input name="quantity" type="number" min="1" value="1" required></label><p class="section-note" data-fine-limit></p>' +
      '<label class="field">Mô tả tình trạng<textarea name="reason" required minlength="3" maxlength="2000"></textarea></label>' +
      '<label class="field">Tiền phạt (đ)<input name="amount" type="number" min="0.01" step="0.01" required></label>' +
      '<p class="workspace-error" role="alert" data-fine-error></p><button class="primary-button" type="submit">Phát hành phiếu phạt</button></form>');
    const form = document.getElementById('api-fine-form');
    form.dataset.requestKey = authRequestKey();
    const update = () => {
      const item = items.find(row => String(row.id) === form.elements.loan_item_id.value);
      const type = form.elements.type.value;
      const max = type === 'lost' ? item.quantity - item.returned_quantity - item.lost_quantity :
        type === 'unusable' ? item.returned_quantity - item.written_off_quantity : item.quantity;
      form.elements.quantity.max = max;
      if (type !== 'lost' && form.elements.amount.readOnly) form.elements.amount.value = '';
      form.elements.amount.readOnly = type === 'lost';
      if (type === 'lost') form.elements.amount.value = 70000 * Number(form.elements.quantity.value);
      form.querySelector('[data-fine-limit]').textContent = max === 0 ? 'Không còn số bản phù hợp với lỗi đã chọn.' :
        'Có thể ghi nhận tối đa ' + max + ' bản.' + (type === 'lost' ? ' Bồi thường 70.000đ mỗi bản. Phạt trễ được tính riêng.' : '');
    };
    form.addEventListener('input', update);
    update();
  } catch (error) {
    if (errorNode) errorNode.textContent = error.message;
    else toast(error.message);
  }
}

document.addEventListener('submit', event => {
  const form = event.target;
  if (form.id !== 'fine-choose-loan' || !staffIsSignedIn()) return;
  event.preventDefault();
  const id = Number(form.elements.loan.value.trim().replace(/^#/, ''));
  const feedback = form.querySelector('[data-fine-lookup-error]');
  if (!Number.isSafeInteger(id) || id < 1) { feedback.textContent = 'Nhập mã phiếu mượn hợp lệ.'; return; }
  authShowFineForm(id, feedback);
});
