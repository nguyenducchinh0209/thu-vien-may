const books = [];
const loanItems = [];
const categories = ['Tất cả','Văn học','Tiểu thuyết','Tâm lý','Thiếu nhi'];
const icons = {
  home:'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  book:'<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21z"/><path d="M4 5.5v13A2.5 2.5 0 0 1 6.5 16H20M8 7h8m-8 3h8"/>',
  bookmark:'<path d="M6 4.5A1.5 1.5 0 0 1 7.5 3h9A1.5 1.5 0 0 1 18 4.5V21l-6-4-6 4z"/>',
  bag:'<path d="M5 8h14l1 13H4z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
  user:'<circle cx="12" cy="8" r="3.5"/><path d="M5 21v-1a7 7 0 0 1 14 0v1"/>',
  headphones:'<path d="M4 13v-1a8 8 0 0 1 16 0v1"/><rect x="3" y="12" width="4" height="7" rx="2"/><rect x="17" y="12" width="4" height="7" rx="2"/>',
  search:'<circle cx="10.8" cy="10.8" r="6.5"/><path d="m16 16 4.5 4.5"/>',
  bell:'<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
  menu:'<path d="M4 7h16M4 12h16M4 17h16"/>',
  chart:'<path d="M4 19V5m0 14h17"/><path d="m7 15 4-4 3 2 5-6"/><circle cx="7" cy="15" r="1"/><circle cx="11" cy="11" r="1"/><circle cx="14" cy="13" r="1"/><circle cx="19" cy="7" r="1"/>',
  star:'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/>',
  clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
  sparkle:'<path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>'
};
const app = document.getElementById('app');
const modal = document.getElementById('modal');
const modalBody = document.getElementById('modal-body');
const labels = {home:'Khám phá',catalog:'Danh mục sách',shelf:'Kệ sách của tôi',cart:'Giỏ sách',profile:'Hồ sơ cá nhân',reader:'Đọc online',admin:'Quản trị thư viện'};
const state = {
  readerBookId:readStore('may-reader-book',2),
  page: location.hash.slice(1) || 'home', query:'', category:'Tất cả',
  cart: [], favorites: [],
  role: 'Độc giả', name: 'Khách',
  adminBooks: [], bookDetails: new Map()
};
function readStore(key,fallback){try{const data=localStorage.getItem(key);return data?JSON.parse(data):fallback}catch{return fallback}}
function saveStore(key,value){try{localStorage.setItem(key,JSON.stringify(value))}catch{}}
function escapeHTML(text){return String(text).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function icon(name){return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name]||''}</svg>`}
document.querySelectorAll('[data-icon]').forEach(el=>el.innerHTML=icon(el.dataset.icon));
function bookCoverUrl(book){const path=String(book.cover_image_path||'').trim();return path?`/storage/${path.split('/').map(encodeURIComponent).join('/')}`:''}
function cover(book,extra=''){const image=bookCoverUrl(book);return `<div class="book-cover cover-${book.color||1} ${extra}" style="position:relative;overflow:hidden">${image?`<img src="${escapeHTML(image)}" alt="Bìa ${escapeHTML(book.title)}" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">`:`<span class="cover-title">${escapeHTML(book.title)}</span><span class="cover-author">${escapeHTML(book.author)}</span><i class="cover-mark"></i>`}</div>`}
function miniCover(book){const image=bookCoverUrl(book);return `<div class="mini-cover cover-${book.color||1}" style="position:relative;overflow:hidden">${image?`<img src="${escapeHTML(image)}" alt="Bìa ${escapeHTML(book.title)}" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">`:escapeHTML(book.title)}</div>`}
function fmtPrice(value){return new Intl.NumberFormat('vi-VN').format(value)+'đ'}
function toast(message){const el=document.getElementById('toast');el.textContent=message;el.classList.add('show');clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.classList.remove('show'),2400)}
function syncCounts(){document.getElementById('cart-count').textContent=state.cart.length;document.getElementById('shelf-count').textContent=loanItems.filter(x=>x.status==='Đang mượn').length;document.querySelector('.profile-copy strong').textContent=state.name;const staffNav=document.getElementById('staff-nav');if(staffNav)staffNav.hidden=state.role==='Độc giả';document.querySelectorAll('.avatar,.top-avatar,.profile-big-avatar').forEach(el=>el.textContent=state.name.split(/\s+/).map(s=>s[0]).join('').slice(-2).toUpperCase())}
function bookCard(book){const liked=state.favorites.includes(book.id);return `<article class="book-card" data-open-book="${book.id}" tabindex="0" aria-label="Xem ${escapeHTML(book.title)}"><div class="cover-wrap"><span class="cover-badge">${book.type==='Đọc online'?'E-BOOK':'SÁCH GIẤY'}</span>${cover(book)}<button class="favorite-button ${liked?'liked':''}" data-favorite="${book.id}" aria-label="${liked?'Bỏ yêu thích':'Yêu thích'}">${liked?'♥':'♡'}</button></div><div class="book-info"><h3>${escapeHTML(book.title)}</h3><p>${escapeHTML(book.author)}</p><div class="book-meta"><span class="rating">★ ${book.rating}</span><span>${book.reads.toLocaleString('vi-VN')} lượt đọc</span></div><div class="book-footer"><span class="book-type">${book.category}</span><span class="book-price">${fmtPrice(book.price)}</span></div></div></article>`}
function renderHome(){return '<p role="status">Đang tải danh mục…</p>';}
function renderShelf(){const current=loanItems.map(x=>({...x,book:books.find(b=>b.id===x.id)}));const mine=state.favorites.map(id=>books.find(b=>b.id===id)).filter(Boolean);return `<div class="eyebrow">GÓC CỦA BẠN</div><div class="heading-row"><div><h1 class="page-title">Kệ sách của tôi</h1><p class="subheading">Theo dõi những cuốn đang đọc, yêu thích và lịch sử mượn.</p></div><button class="primary-button" data-page-link="catalog">+ Tìm sách</button></div><div class="section-head"><div><h2 class="section-title">Mượn gần đây</h2><div class="section-note">Nhớ trả sách đúng hạn để tiếp tục khám phá nhé</div></div></div><div class="list-card"><div class="list-head"><span>Tên sách</span><span>Ngày hẹn</span><span>Trạng thái</span><span>Loại</span><span></span></div>${current.map(x=>`<div class="list-row"><div class="list-book">${miniCover(x.book)}<span><strong>${escapeHTML(x.book.title)}</strong><small>${escapeHTML(x.book.author)}</small></span></div><span>${x.due}</span><span><i class="status-pill ${x.tone}">${x.status}</i></span><span>${x.book.type==='Đọc online'?'E-book':'Bản giấy'}</span><button class="link-button" data-page-link="reader">Mở</button></div>`).join('')}</div><section class="section"><div class="section-head"><div><h2 class="section-title">Sách bạn yêu thích</h2><div class="section-note">${mine.length} cuốn đã lưu cho lần đọc sau</div></div><button class="link-button" data-page-link="catalog">Thêm sách ↗</button></div><div class="book-grid">${mine.map(bookCard).join('')}</div></section>`}
function renderCart(){if(!state.cart.length)return `<div class="eyebrow">CHỌN SÁCH HAY</div><div class="heading-row"><div><h1 class="page-title">Giỏ sách của bạn</h1><p class="subheading">Những cuốn sách bạn đang cân nhắc mang về.</p></div></div><div class="empty-state"><span>✳</span><strong>Giỏ sách đang đợi một cuốn hay</strong><p>Khám phá tủ sách và thêm những cuốn bạn yêu thích nhé.</p><button class="primary-button" data-page-link="catalog">Khám phá sách</button></div>`;const items=state.cart.map(id=>books.find(b=>b.id===id)).filter(Boolean);const total=items.reduce((sum,b)=>sum+b.price,0);return `<div class="eyebrow">CHỌN SÁCH HAY</div><div class="heading-row"><div><h1 class="page-title">Giỏ sách của bạn</h1><p class="subheading">${items.length} cuốn sách đang chờ bạn.</p></div></div><div class="cart-layout"><div class="cart-items">${items.map(b=>`<div class="cart-row">${miniCover(b)}<span><strong>${escapeHTML(b.title)}</strong><small>${escapeHTML(b.author)} · ${b.category}</small></span><b>${fmtPrice(b.price)}</b><button class="remove-button" data-remove-cart="${b.id}" aria-label="Xóa ${escapeHTML(b.title)}">×</button></div>`).join('')}</div><aside class="summary-card"><h3>Tóm tắt đơn sách</h3><div class="summary-line"><span>Tạm tính (${items.length} cuốn)</span><span>${fmtPrice(total)}</span></div><div class="summary-line"><span>Phí mượn</span><span>Miễn phí</span></div><div class="summary-line summary-total"><span>Tổng cộng</span><span>${fmtPrice(total)}</span></div><button class="primary-button" data-checkout>Tiếp tục đặt sách <span>→</span></button><p class="fine-print">Bước tiếp theo sẽ kết nối dịch vụ mượn sách của thư viện.</p></aside></div>`}
function renderProfile(){return `<div class="eyebrow">THÔNG TIN CỦA BẠN</div><div class="heading-row"><div><h1 class="page-title">Hồ sơ cá nhân</h1><p class="subheading">Cập nhật thông tin cá nhân.</p></div></div><div class="profile-layout"><aside class="profile-card"><div class="profile-identity"><div class="profile-big-avatar">MA</div><strong>${escapeHTML(state.name)}</strong><small>minhanh@example.com</small><span class="member-tag">✦ Thành viên thân thiết</span></div><div class="profile-stats"><div><strong>24</strong><small>Cuốn đã đọc</small></div><div><strong>1.280</strong><small>Điểm đọc</small></div></div></aside><section class="profile-card form-card"><h3>Thông tin cá nhân</h3><form id="profile-form"><div class="form-grid"><div class="field"><label for="name">Họ và tên</label><input id="name" name="name" value="${escapeHTML(state.name)}" required minlength="2"></div><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="minhanh@example.com" required></div><div class="field"><label for="phone">Số điện thoại</label><input id="phone" name="phone" value="090 123 4567"></div><div class="field"><label for="birthday">Ngày sinh</label><input id="birthday" name="birthday" type="date" value="2002-06-18"></div><div class="field full"><label for="address">Địa chỉ</label><input id="address" name="address" value="Quận Bình Thạnh, TP. Hồ Chí Minh"></div><div class="field full"><label>Vai trò</label><input value="${escapeHTML(state.role)}" readonly></div></div><div class="form-actions"><button type="button" class="secondary-button" data-cancel-profile>Hủy</button><button class="primary-button" type="submit">Lưu thay đổi</button></div></form><p class="fine-print profile-note" style="text-align:left"></p></section></div>`}
function openReaderBook(id){
  const book=state.adminBooks.find(item=>Number(item.id)===Number(id));
  if(!book){toast('Không tìm thấy cuốn sách này.');return;}
  closeModal();
  state.readerBookId=Number(book.id);state.readerPreviewPage=1;saveStore('may-reader-book',state.readerBookId);go('reader');
}
function renderReader(){
  const b=state.bookDetails.get(Number(state.readerBookId))||state.adminBooks.find(item=>Number(item.id)===Number(state.readerBookId));
  if(!b&&state.bookDetailRequest?.id===Number(state.readerBookId))return '<p role="status">Đang tải bản đọc thử…</p>';
  if(!b)return `<div class="empty-state"><strong>Không tìm thấy sách đang đọc</strong><p>Hãy chọn một e-book từ danh mục.</p><button class="primary-button" data-page-link="catalog">Chọn sách</button></div>`;
  const count=Math.min(3,Number(b.preview_page_count)||0),page=Math.min(Math.max(1,Number(state.readerPreviewPage)||1),Math.max(1,count));state.readerPreviewPage=page;
  if(!count)return `<div class="eyebrow">ĐỌC THỬ MIỄN PHÍ</div><div class="heading-row"><div><h1 class="page-title">${escapeHTML(b.title)}</h1><p class="subheading">Bản đọc thử tối đa 3 trang.</p></div><button class="secondary-button" data-open-book="${b.id}">← Quay lại chi tiết</button></div><div class="empty-state reader-preview-empty"><span>▤</span><strong>Chưa có bản đọc thử</strong><p>Thư viện chưa tải trang xem thử cho cuốn sách này.</p></div>`;
  return `<div class="eyebrow">ĐỌC THỬ MIỄN PHÍ · TỐI ĐA 3 TRANG</div><div class="heading-row"><div><h1 class="page-title">${escapeHTML(b.title)}</h1><p class="subheading">${escapeHTML(b.author)}</p></div><button class="secondary-button" data-open-book="${b.id}">← Quay lại chi tiết</button></div><section class="reader-preview-viewer"><div class="reader-preview-paper"><img src="/catalog/books/${b.id}/preview/${page}" alt="Trang ${page} xem thử của ${escapeHTML(b.title)}"></div><div class="reader-preview-controls"><button class="secondary-button" data-preview-page="${page-1}" ${page<=1?'disabled':''}>← Trang trước</button><span>Trang <strong>${page}</strong> / ${count}</span><button class="secondary-button" data-preview-page="${page+1}" ${page>=count?'disabled':''}>Trang tiếp →</button></div></section>`;
}
function renderAdmin(){return '<p role="status">Đang tải công việc…</p>';}
function adminTable(items){return `<div class="list-head"><span>Mã sách</span><span>Tên sách</span><span>Tổng bản</span><span>Phí mượn</span><span>Thao tác</span></div>${items.map(b=>`<div class="list-row"><span>${escapeHTML(b.book_code||'—')}</span><div class="list-book">${miniCover(b)}<span><strong>${escapeHTML(b.title)}</strong><small>${escapeHTML(b.author)}</small></span></div><span>${Number(b.total_quantity)||0}</span><span>${fmtPrice(b.rental_fee||0)}</span><span><button class="link-button" data-edit-book="${b.id}">Sửa</button><button class="link-button" data-preview-upload="${b.id}">Bản đọc thử</button><button class="link-button" data-delete-book="${b.id}" style="color:#b87870">Xóa</button></span></div>`).join('')}`}
function go(page){if(!labels[page])return;state.page=page;history.replaceState(null,'','#'+page);render();window.scrollTo({top:0,behavior:'smooth'})}
let modalTrigger = null;
function showModal(content) {
  if (modal.hidden) modalTrigger = document.activeElement;
  modalBody.innerHTML = content;
  modal.hidden = false;
  app.inert = true;
  document.body.style.overflow = 'hidden';
  modal.querySelector('[data-close-modal]').focus();
}
function closeModal() {
  if (modal.hidden) return;
  modal.hidden = true;
  app.inert = false;
  document.body.style.overflow = '';
  if (modalTrigger?.isConnected) modalTrigger.focus();
  else app.focus({preventScroll:true});
  modalTrigger = null;
}
modal.addEventListener('keydown', event => {
  if (event.key !== 'Tab') return;
  const items = Array.from(modal.querySelectorAll('button, input, select, textarea, a[href], [tabindex]'))
    .filter(node => !node.disabled && node.tabIndex >= 0 && node.getClientRects().length);
  const first = items[0], last = items.at(-1);
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first?.focus();
  }
});
function openBook(id){const b=books.find(x=>x.id===Number(id))||state.adminBooks.find(x=>x.id===Number(id));if(!b)return;showModal(`<div class="book-detail"><div class="detail-cover cover-${b.color}">${cover(b)}</div><div class="detail-copy"><div class="eyebrow">${escapeHTML(b.category)}</div><h2 id="modal-title">${escapeHTML(b.title)}</h2><p class="author">${escapeHTML(b.author)}</p><div class="rating">★ ${b.rating} <span style="color:#98a19b;font-weight:400">· ${b.reads.toLocaleString('vi-VN')} lượt đọc</span></div><div class="detail-tags"><span>${b.type==='Đọc online'?'E-book':'Sách giấy'}</span><span>Tiếng Việt</span></div><p class="description">${escapeHTML(b.description)}</p><strong style="font-size:12px;color:#355d48">${fmtPrice(b.price)}</strong><div class="detail-actions"><button class="primary-button" data-add-cart="${b.id}">Thêm vào giỏ</button><button class="secondary-button" data-reader-book>Đọc thử</button></div></div></div><p class="modal-note">Chi tiết hiển thị bằng dữ liệu mẫu; đặt sách và thanh toán sẽ kết nối backend Laravel.</p>`)}
async function openBookForm(id){
  let old=state.adminBooks.find(book=>Number(book.id)===Number(id));
  if(id&&!old){
    try{
      const response=await authRequest(`/staff/books/${id}`);
      old=response.book;
      state.bookDetails.set(Number(old.id),authNormalizeBook(old));
    }catch(error){toast(error.message);return}
  }
  let authors=[];
  try{const response=await authRequest('/staff/authors?per_page=20');authors=[...new Map([...(old?.authors||[]),...(response.data||[])].map(author=>[author.id,author])).values()]}catch(error){toast(error.message);return}
  const selected=new Set((old?.authors||[]).map(author=>Number(author.id)));
  const coverPreview=old?.cover_image_path?`<img src="${escapeHTML(bookCoverUrl(old))}" alt="Bìa hiện tại của ${escapeHTML(old.title)}" style="display:block;width:72px;height:96px;object-fit:cover;border-radius:6px;margin-top:8px">`:'';
  const detailCount=Number(old?.preview_page_count??old?.preview_pages?.length)||0;
  showModal(`<form id="book-form" class="login-form"><div class="eyebrow">DANH MỤC THƯ VIỆN</div><h2 id="modal-title">${old?'Cập nhật sách':'Thêm sách mới'}</h2><div class="field"><label for="book-code">Mã sách</label><input id="book-code" name="book_code" value="${escapeHTML(old?.book_code||'')}" maxlength="32" required></div><div class="field"><label for="book-title">Tên sách</label><input id="book-title" name="title" value="${escapeHTML(old?.title||'')}" maxlength="255" required></div><div class="field"><label for="book-cover-image">Ảnh bìa</label><input id="book-cover-image" name="cover_image" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG hoặc WebP; tối đa 2 MB.${old?.cover_image_path?' Chọn ảnh mới để thay bìa hiện tại.':''}</small>${coverPreview}</div><div class="field"><label for="book-detail-images">Ảnh chi tiết / trang đọc thử</label><input id="book-detail-images" name="detail_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Tối đa 3 ảnh, mỗi ảnh không quá 2 MB. Ảnh sẽ hiển thị trong mục Đọc thử.${detailCount?` Hiện có ${detailCount} ảnh; chọn ảnh mới sẽ thay bộ ảnh hiện tại.`:' Chưa chọn ảnh thì sách không có ảnh đọc thử.'}</small></div><div class="field"><label for="book-description">Ghi chú / giới thiệu sách</label><textarea id="book-description" name="description" rows="4" maxlength="10000">${escapeHTML(old?.description||'')}</textarea></div><div class="field"><label for="book-quantity">Tổng số bản</label><input id="book-quantity" name="total_quantity" type="number" min="0" max="4294967295" value="${Number(old?.total_quantity)||0}" required></div><div class="field"><label for="book-fee">Phí mượn mỗi bản</label><input id="book-fee" name="rental_fee" type="number" min="0" max="9999999999.99" step="0.01" value="${old?.rental_fee||0}" required></div><div class="field"><label for="book-author-search">Tìm tác giả</label><input type="search" id="book-author-search" placeholder="Nhập tên tác giả" maxlength="100"><small id="book-author-results" role="status"></small></div><div class="field"><label for="book-authors">Tác giả</label><select id="book-authors" name="author_ids" multiple size="5">${authors.map(author=>`<option value="${author.id}" ${selected.has(Number(author.id))?'selected':''}>${escapeHTML(author.name)}</option>`).join('')}</select><small>Giữ Ctrl để chọn nhiều tác giả.</small></div><button class="primary-button" type="submit">${old?'Lưu thay đổi':'Thêm sách'}</button></form>`);
  const form=document.getElementById('book-form');
  form.dataset.editId=old?.id||'';
  const authorSelect=form.querySelector('#book-authors');
  const knownAuthors=new Map(authors.map(author=>[String(author.id),author]));
  staffBindSearch(form.querySelector('#book-author-search'),form.querySelector('#book-author-results'),async q=>{
    const result=await authRequest('/staff/authors?'+new URLSearchParams({q,per_page:'20'}));
    return result.data||[];
  },rows=>{
    rows.forEach(author=>knownAuthors.set(String(author.id),author));
    staffSetChoices(authorSelect,rows,author=>author.name,[...knownAuthors.values()]);
  });
  document.getElementById('book-title').closest('.field').insertAdjacentHTML('afterend',`<div class="field"><label for="book-category">Thể loại</label><input id="book-category" name="category" value="${escapeHTML(old?.category==='Chưa phân loại'?'':old?.category||'')}" maxlength="100" placeholder="Ví dụ: Văn học"></div>`);
}
document.addEventListener('click',e=>{
  const previewPage=e.target.closest('[data-preview-page]');if(previewPage){state.readerPreviewPage=Number(previewPage.dataset.previewPage);render();return;}
  const nav=e.target.closest('[data-page], [data-page-link]');if(nav){e.preventDefault();go(nav.dataset.page||nav.dataset.pageLink);return}
  const opened=e.target.closest('[data-open-book]');if(opened){openBook(opened.dataset.openBook);return}
  if(e.target.closest('[data-close-modal]')||e.target===modal){closeModal();return}
  const cat=e.target.closest('[data-category]');if(cat){state.category=cat.dataset.category;state.catalogPage=1;render();return}
  const catalogPage=e.target.closest('[data-catalog-page]');if(catalogPage){state.catalogPage=Number(catalogPage.dataset.catalogPage);render();return}
  if(e.target.closest('[data-checkout]')){if(typeof window.submitReservation==='function'){window.submitReservation();return}showModal(`<div class="login-form"><div class="eyebrow">ĐẶT SÁCH</div><h2 id="modal-title">Không thể gửi yêu cầu</h2><p>Vui lòng tải lại trang rồi thử lại.</p><button class="primary-button" data-close-modal>Đã hiểu</button></div>`);return}
  const readerBook=e.target.closest('[data-reader-book]');if(readerBook){openReaderBook(readerBook.dataset.readerBook||state.bookId||state.readerBookId);return}
  if(e.target.closest('[data-clear-search]')){state.query='';state.category='Tất cả';state.catalogPage=1;document.getElementById('global-search').value='';render();return}
  if(e.target.closest('[data-cancel-profile]')){render();return}
  if(e.target.closest('[data-auth]')){openAuth('login');return}
  const authMode=e.target.closest('[data-auth-mode]');if(authMode){openAuth(authMode.dataset.authMode);return}
  if(e.target.closest('[data-add-book]')){openBookForm();return}
  const edit=e.target.closest('[data-edit-book]');if(edit){openBookForm(edit.dataset.editBook);return}
  if(e.target.closest('[data-chapter="next"]')){toast('Chương tiếp theo sẽ mở sau khi quyền đọc được xác nhận.');return}
  if(e.target.closest('[data-chapter="previous"]')){toast('Bạn đang ở chương đầu tiên.');return}
});
document.getElementById('global-search').addEventListener('input',e=>{state.catalogDraft=e.target.value;});
document.addEventListener('input',e=>{if(e.target.id==='admin-search'){const q=e.target.value.toLocaleLowerCase('vi');const filtered=state.adminBooks.filter(b=>(b.title+' '+b.author+' '+b.category).toLocaleLowerCase('vi').includes(q));document.getElementById('admin-table').innerHTML=adminTable(filtered)}});
const sidebar=document.getElementById('sidebar'),sidebarToggle=document.getElementById('menu-toggle'),sidebarBackdrop=document.getElementById('sidebar-backdrop');
const desktopSidebarQuery=matchMedia('(min-width: 821px)');
try{document.body.classList.toggle('sidebar-collapsed',localStorage.getItem('may-sidebar-collapsed')==='1')}catch{}
document.querySelectorAll('.nav-link').forEach(link=>link.setAttribute('aria-label',link.querySelector(':scope > span:not(.nav-icon)')?.textContent?.trim()||link.textContent.trim()));
function syncSidebar(){const mobile=!desktopSidebarQuery.matches,open=sidebar.classList.contains('open'),collapsed=document.body.classList.contains('sidebar-collapsed');document.querySelectorAll('[data-feature="payments"]').forEach(link=>{link.hidden=window.libraryFeatureApi?.capabilities.payments!==true});document.body.classList.toggle('sidebar-open',mobile&&open);sidebarBackdrop.classList.toggle('is-visible',mobile&&open);sidebarToggle.setAttribute('aria-expanded',String(mobile?open:!collapsed));sidebarToggle.setAttribute('aria-label',mobile?(open?'Đóng menu':'Mở menu'):(collapsed?'Mở rộng menu':'Thu gọn menu'));}
function closeSidebar(){sidebar.classList.remove('open');syncSidebar()}
sidebarToggle.addEventListener('click',()=>{if(desktopSidebarQuery.matches){document.body.classList.toggle('sidebar-collapsed');try{localStorage.setItem('may-sidebar-collapsed',document.body.classList.contains('sidebar-collapsed')?'1':'0')}catch{}}else sidebar.classList.toggle('open');syncSidebar()});
sidebarBackdrop.addEventListener('click',closeSidebar);
desktopSidebarQuery.addEventListener('change',()=>{closeSidebar();syncSidebar()});syncSidebar();
document.addEventListener('keydown',e=>{if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='k'){e.preventDefault();document.getElementById('global-search').focus()}if(e.key==='Escape'){closeModal();closeSidebar()}if((e.key==='Enter'||e.key===' ')&&e.target.matches('[data-open-book]'))openBook(e.target.dataset.openBook)});
window.addEventListener('hashchange',()=>{const page=location.hash.slice(1);if(labels[page]){state.page=page;render()}});
state.avatar='';state.catalogPage=1;
function openAuth(mode){const isRegister=mode==='register';showModal(`<form class="login-form" id="auth-form"><div class="eyebrow">THƯ VIỆN MÂY</div><h2 id="modal-title">${isRegister?'Tạo tài khoản mới':'Rất vui được gặp lại'}</h2><p>${isRegister?'Đăng ký để lưu kệ sách và theo dõi những cuốn bạn yêu thích.':'Đăng nhập để tiếp tục hành trình đọc của bạn.'}</p>${isRegister?`<div class="field"><label for="auth-name">Họ và tên</label><input id="auth-name" name="name" required minlength="2" placeholder="Tên của bạn"></div>`:''}<div class="field"><label for="auth-email">Email</label><input id="auth-email" name="email" type="email" required placeholder="ban@email.com"></div><div class="field"><label for="auth-password">Mật khẩu</label><input id="auth-password" name="password" type="password" required minlength="8" placeholder="Ít nhất 8 ký tự"></div><button class="primary-button" type="submit">${isRegister?'Tạo tài khoản':'Đăng nhập'}</button><button class="link-button" type="button" data-auth-mode="${isRegister?'login':'register'}">${isRegister?'Đã có tài khoản? Đăng nhập':'Chưa có tài khoản? Đăng ký'}</button><p class="modal-note">Form minh họa có validation trình duyệt. Xác thực thật do backend Laravel xử lý.</p></form>`)}
function renderCatalog(){const filtered=books.filter(b=>(state.category==='Tất cả'||b.category===state.category)&&(`${b.title} ${b.author} ${b.category}`).toLocaleLowerCase('vi').includes(state.query.toLocaleLowerCase('vi')));const size=8,pages=Math.max(1,Math.ceil(filtered.length/size));state.catalogPage=Math.min(state.catalogPage,pages);const visible=filtered.slice((state.catalogPage-1)*size,state.catalogPage*size);const base=`<div class="eyebrow">TỦ SÁCH MÂY</div><div class="heading-row"><div><h1 class="page-title">Tìm cuốn sách tiếp theo.</h1><p class="subheading">Mỗi cuốn sách là một cánh cửa. Bạn muốn mở cánh cửa nào hôm nay?</p></div></div><div class="toolbar">${categories.map(c=>`<button class="filter-chip ${state.category===c?'active':''}" data-category="${escapeHTML(c)}">${escapeHTML(c)}</button>`).join('')}<span class="results-label">${filtered.length} cuốn sách</span></div><div class="book-grid">${visible.map(bookCard).join('')}</div>`;if(!filtered.length)return base+`<div class="empty-state"><strong>Chưa tìm thấy cuốn sách phù hợp</strong><p>Thử tìm bằng tên sách hoặc tên tác giả khác nhé.</p><button class="secondary-button" data-clear-search>Xóa bộ lọc</button></div>`;if(pages>1)return base+`<div class="toolbar" style="justify-content:center;margin-top:20px"><button class="filter-chip" data-catalog-page="${Math.max(1,state.catalogPage-1)}" ${state.catalogPage===1?'disabled':''}>← Trước</button>${Array.from({length:pages},(_,i)=>`<button class="filter-chip ${state.catalogPage===i+1?'active':''}" data-catalog-page="${i+1}">${i+1}</button>`).join('')}<button class="filter-chip" data-catalog-page="${Math.min(pages,state.catalogPage+1)}" ${state.catalogPage===pages?'disabled':''}>Sau →</button></div>`;return base}
function render(){if(!labels[state.page])state.page='home';document.getElementById('page-label').textContent=labels[state.page];document.querySelectorAll('.nav-link').forEach(a=>a.classList.toggle('active',a.dataset.page===state.page));syncCounts();const pages={home:renderHome,catalog:renderCatalog,shelf:renderShelf,cart:renderCart,profile:renderProfile,reader:renderReader,admin:renderAdmin};app.innerHTML=pages[state.page]();if(state.page==='profile'){const grid=app.querySelector('#profile-form .form-grid');if(grid)grid.insertAdjacentHTML('beforeend',`<div class="field full"><label for="avatar-file">Ảnh đại diện</label><div class="avatar-upload"><img class="avatar-preview" id="avatar-preview" src="${state.avatar||''}" alt="Xem trước ảnh đại diện" ${state.avatar?'':'hidden'}><input id="avatar-file" name="avatar" type="file" accept="image/png,image/jpeg,image/webp"></div></div>`);app.querySelector('.form-card').insertAdjacentHTML('beforeend','<button class="link-button" type="button" data-auth>Đăng nhập hoặc tạo tài khoản ↗</button>')}app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');if(state.avatar)document.querySelectorAll('.avatar,.top-avatar,.profile-big-avatar').forEach(el=>{el.innerHTML=`<img src="${state.avatar}" alt="Ảnh đại diện" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">`})}
render();
