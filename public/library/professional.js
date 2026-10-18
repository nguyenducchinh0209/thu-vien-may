/* Nâng cấp giao diện khám phá và luồng chi tiết sách. */
const proLabel={'book-detail':'Chi tiết sách'};
Object.assign(labels,proLabel);
state.proType=state.proType||'Tất cả';
state.proSort=state.proSort||'popular';
const proStock={1:4,2:8,3:3,4:0,5:2,6:5,7:6,8:1,9:0,10:7,11:2,12:5};
const proReviews=[
  {name:'Hà My',date:'12/10/2026',rating:5,text:'Một cuốn sách khiến mình muốn đọc chậm lại và ghi chú nhiều hơn.'},
  {name:'Quang Minh',date:'08/10/2026',rating:4,text:'Nội dung gần gũi, phần giới thiệu giúp mình chọn được sách đúng chủ đề.'}
];
function proBooks(){return Array.isArray(state.adminBooks)?state.adminBooks:books;}
function proFindBook(id){return proBooks().find(item=>Number(item.id)===Number(id));}
function proCard(book){
  const liked=state.favorites.includes(book.id),stock=proStock[book.id]??3;
  return `<article class="pro-book-card"><button class="pro-book-open" data-open-book="${book.id}" aria-label="Xem chi tiết ${escapeHTML(book.title)}"><div class="pro-cover-area"><span class="pro-cover-kind">${book.type==='Đọc online'?'E-BOOK':'SÁCH GIẤY'}</span>${cover(book)}<span class="pro-stock ${stock?'':'out'}">${stock?`Còn ${stock} bản`:'Tạm hết'}</span></div><div class="pro-book-info"><span class="pro-category">${escapeHTML(book.category)}</span><h3>${escapeHTML(book.title)}</h3><p>${escapeHTML(book.author)}</p><div class="pro-book-meta"><span>★ ${escapeHTML(book.rating||'4.8')} <small>(${(book.reads||0).toLocaleString('vi-VN')})</small></span><span>${book.type==='Đọc online'?'Đọc online':'Mượn tại thư viện'}</span></div></div></button><button class="favorite-button ${liked?'liked':''}" data-favorite="${book.id}" aria-label="${liked?'Bỏ yêu thích':'Thêm yêu thích'}">${liked?'♥':'♡'}</button></article>`;
}
function proHome(){
  const list=proBooks(),featured=list.find(book=>book.id===2)||list[0],popular=[...list].sort((a,b)=>(b.reads||0)-(a.reads||0)).slice(0,4),latest=[...list].sort((a,b)=>Number(b.id)-Number(a.id)).slice(0,4),homeCategories=[...new Set(list.map(book=>book.category))].slice(0,5);
  if(!list.length)return `<div class="pro-home"><div class="pro-eyebrow">KHÁM PHÁ THƯ VIỆN</div><section class="empty-state"><span>✳</span><strong>Thư viện đang cập nhật danh mục</strong><p>Sách sẽ xuất hiện tại đây khi được thêm vào kho.</p></section></div>`;
  const date=new Intl.DateTimeFormat('vi-VN',{weekday:'long',day:'numeric',month:'long'}).format(new Date()).toLocaleUpperCase('vi');
  return `<div class="pro-home"><div class="pro-eyebrow">${date} · KHÁM PHÁ THƯ VIỆN</div><section class="pro-hero"><div class="pro-hero-copy"><span class="pro-kicker"><i></i> GỢI Ý TỪ THƯ VIỆN MÂY</span><h1>Một cuốn sách hay<br><em>có thể mở ra một ngày mới.</em></h1><p>Khám phá những câu chuyện được chọn lọc cho nhịp đọc và sở thích của bạn.</p><div class="pro-hero-actions"><button class="primary-button" data-page-link="catalog">Khám phá bộ sưu tập <span>↗</span></button><button class="pro-text-action" data-open-book="${featured.id}">Cuốn sách nổi bật <span>→</span></button></div></div><button class="pro-featured-book" data-open-book="${featured.id}" aria-label="Xem ${escapeHTML(featured.title)}">${cover(featured,'pro-featured-cover')}<span class="pro-featured-tag">LỰA CHỌN CỦA TUẦN</span><span class="pro-featured-rating">★ ${escapeHTML(featured.rating||'4.8')}</span></button><div class="pro-hero-orbit"></div></section><section class="pro-section"><div class="pro-section-heading"><div><span class="pro-section-overline">ĐƯỢC ĐỘC GIẢ YÊU THÍCH</span><h2>Sách nổi bật tuần này</h2><p>Những cuốn sách được nhiều độc giả tìm đọc.</p></div><button class="pro-see-all" data-page-link="catalog">Xem tất cả <span>↗</span></button></div><div class="pro-book-grid">${popular.map(proCard).join('')}</div></section><section class="pro-section pro-home-category-section"><div class="pro-section-heading"><div><span class="pro-section-overline">TÌM THEO SỞ THÍCH</span><h2>Bạn muốn đọc thể loại nào?</h2><p>Chọn một chủ đề để xem những cuốn sách phù hợp.</p></div></div><div class="pro-chips">${homeCategories.map(category=>`<button class="pro-chip" data-pro-category="${escapeHTML(category)}">${escapeHTML(category)}</button>`).join('')}</div></section><section class="pro-section"><div class="pro-section-heading"><div><span class="pro-section-overline">VỪA ĐƯỢC THÊM</span><h2>Sách mới trong thư viện</h2><p>Khám phá thêm những lựa chọn mới cho lần đọc tiếp theo.</p></div><button class="pro-see-all" data-page-link="catalog">Đến danh mục <span>↗</span></button></div><div class="pro-book-grid">${latest.map(proCard).join('')}</div></section><section class="pro-quote"><span>“</span><div><p>Một căn phòng không có sách giống như một cơ thể không có linh hồn.</p><small>— Cicero</small></div><button data-page-link="catalog" class="secondary-button">Tìm một cuốn cho mình</button></section></div>`;
}
function proCatalog(){
  const all=proBooks(),cats=['Tất cả',...new Set(all.map(book=>book.category))];
  let filtered=all.filter(book=>(state.category==='Tất cả'||book.category===state.category)&&(state.proType==='Tất cả'||(state.proType==='E-book'?book.type==='Đọc online':book.type!=='Đọc online'))&&(`${book.title} ${book.author} ${book.category}`).toLocaleLowerCase('vi').includes((state.query||'').toLocaleLowerCase('vi')));
  if(state.proSort==='popular')filtered.sort((a,b)=>(b.reads||0)-(a.reads||0));
  if(state.proSort==='title')filtered.sort((a,b)=>a.title.localeCompare(b.title,'vi'));
  if(state.proSort==='price')filtered.sort((a,b)=>(a.price||0)-(b.price||0));
  const size=8,pages=Math.max(1,Math.ceil(filtered.length/size));state.catalogPage=Math.min(state.catalogPage||1,pages);
  const shown=filtered.slice((state.catalogPage-1)*size,state.catalogPage*size);
  const chips=cats.map(category=>`<button class="pro-chip ${state.category===category?'active':''}" data-pro-category="${escapeHTML(category)}">${escapeHTML(category)}</button>`).join('');
  const pager=pages>1?`<nav class="pro-pagination" aria-label="Phân trang"><button class="pro-page-button" data-pro-page="${Math.max(1,state.catalogPage-1)}" ${state.catalogPage===1?'disabled':''}>← Trước</button><span>Trang <strong>${state.catalogPage}</strong> / ${pages}</span><button class="pro-page-button" data-pro-page="${Math.min(pages,state.catalogPage+1)}" ${state.catalogPage===pages?'disabled':''}>Tiếp theo →</button></nav>`:'';
  return `<div class="pro-catalog"><div class="pro-catalog-banner"><div><span class="pro-section-overline">TỦ SÁCH MÂY</span><h1 class="page-title">Tìm câu chuyện tiếp theo.</h1><p class="subheading">Tìm theo tên sách, tác giả hoặc chọn một thể loại bạn yêu thích.</p></div><div class="pro-catalog-mark">M<span>+</span></div></div><div class="pro-catalog-tools"><label class="pro-catalog-search"><span>${icon('search')}</span><input id="pro-search" type="search" value="${escapeHTML(state.query||'')}" placeholder="Tên sách hoặc tác giả" autocomplete="off"><kbd>⌘ K</kbd></label><label class="pro-select-label">Hình thức<select id="pro-type"><option ${state.proType==='Tất cả'?'selected':''}>Tất cả</option><option ${state.proType==='Sách giấy'?'selected':''}>Sách giấy</option><option ${state.proType==='E-book'?'selected':''}>E-book</option></select></label><label class="pro-select-label">Sắp xếp<select id="pro-sort"><option value="popular" ${state.proSort==='popular'?'selected':''}>Phổ biến</option><option value="title" ${state.proSort==='title'?'selected':''}>Tên sách A–Z</option><option value="price" ${state.proSort==='price'?'selected':''}>Giá tham khảo</option></select></label></div><div class="pro-filter-row"><div class="pro-chips">${chips}</div><span class="pro-count"><strong>${filtered.length}</strong> cuốn sách</span></div>${shown.length?`<div class="pro-book-grid">${shown.map(proCard).join('')}</div>${pager}`:`<div class="empty-state"><strong>Chưa tìm thấy sách phù hợp</strong><p>Thử từ khóa hoặc bộ lọc khác nhé.</p><button class="secondary-button" data-pro-clear>Xóa bộ lọc</button></div>`}<p class="pro-price-note">Giá hiển thị là giá tham khảo của ấn bản. Phí mượn thực tế sẽ được thông báo khi thư viện xác nhận yêu cầu.</p></div>`;
}
function proDetail(){
  const book=proFindBook(state.bookId);if(!book)return `<div class="empty-state"><strong>Không tìm thấy cuốn sách này</strong><button class="secondary-button" data-page-link="catalog">Quay lại danh mục</button></div>`;
  const stock=proStock[book.id]??3,similar=proBooks().filter(item=>item.id!==book.id&&item.category===book.category).slice(0,3);
  return `<div class="pro-detail"><button class="pro-back-link" data-page-link="catalog">← Quay lại danh mục</button><div class="pro-detail-layout"><div class="pro-detail-cover-area"><span class="pro-detail-stamp">THƯ VIỆN MÂY · ẤN BẢN 2026</span><div class="pro-detail-cover-bg">${cover(book,'pro-detail-cover')}</div><div class="pro-cover-caption">${book.type==='Đọc online'?'E-BOOK':'SÁCH GIẤY'} <span>·</span> TIẾNG VIỆT</div></div><section class="pro-detail-info"><span class="pro-section-overline">${escapeHTML(book.category)}</span><h1>${escapeHTML(book.title)}</h1><p class="pro-author-line">Tác giả <strong>${escapeHTML(book.author)}</strong></p><div class="pro-rating-line"><span>★★★★★</span><strong>${escapeHTML(book.rating||'4.8')}</strong><small>${(book.reads||0).toLocaleString('vi-VN')} lượt đọc</small></div><p class="pro-detail-description">${escapeHTML(book.description||'Một lựa chọn đáng đọc trong bộ sưu tập của Thư viện Mây.')}</p><div class="pro-availability ${stock?'':'unavailable'}"><span class="pro-availability-dot"></span><div><strong>${stock?'Sẵn sàng cho bạn':'Hiện đang được mượn hết'}</strong><small>${stock?`${stock} bản có thể đăng ký mượn tại thư viện`:'Bạn có thể gửi yêu cầu đặt trước'}</small></div></div><div class="pro-price-box"><div><small>GIÁ THAM KHẢO ẤN BẢN</small><strong>${fmtPrice(book.price||0)}</strong></div><span>Phí mượn được xác nhận<br>trước khi hoàn tất</span></div><div class="pro-detail-actions"><button class="primary-button" data-add-cart="${book.id}">${stock?'Đăng ký mượn sách':'Đặt trước cuốn này'} <span>→</span></button>${book.type==='Đọc online'?`<button class="secondary-button" data-reader-book>Đọc thử chương đầu</button>`:''}<button class="pro-detail-favorite" data-favorite="${book.id}">${state.favorites.includes(book.id)?'♥ Đã lưu yêu thích':'♡ Lưu yêu thích'}</button></div><div class="pro-detail-policies"><span>✓ Không cần thanh toán để gửi yêu cầu</span><span>✓ Thư viện sẽ xác nhận thời hạn và phí mượn</span></div></section></div><section class="pro-detail-description-block"><div><span class="pro-section-overline">VỀ CUỐN SÁCH</span><h2>Một trải nghiệm đọc đáng nhớ</h2></div><p>${escapeHTML(book.description||'Khám phá tác phẩm trong không gian đọc của Thư viện Mây.')}</p></section><section class="pro-reviews"><div class="pro-section-heading"><div><span class="pro-section-overline">CỘNG ĐỒNG ĐỌC</span><h2>Độc giả nói gì</h2></div><span class="pro-rating-summary">★ ${escapeHTML(book.rating||'4.8')} <small>/ 5</small></span></div><div class="pro-review-grid">${proReviews.map(review=>`<article class="pro-review-card"><div><strong>${escapeHTML(review.name)}</strong><span>${review.date}</span></div><p class="pro-review-stars">${'★'.repeat(review.rating)}${'☆'.repeat(5-review.rating)}</p><p>${escapeHTML(review.text)}</p></article>`).join('')}</div></section>${similar.length?`<section class="pro-section"><div class="pro-section-heading"><div><span class="pro-section-overline">CÙNG THỂ LOẠI</span><h2>Có thể bạn cũng thích</h2></div><button class="pro-see-all" data-page-link="catalog">Xem bộ sưu tập ↗</button></div><div class="pro-book-grid">${similar.map(proCard).join('')}</div></section>`:''}</div>`;
}
function proCart(){
  const items=state.cart.map(proFindBook).filter(Boolean);
  if(!items.length)return `<div class="eyebrow">CHỌN SÁCH HAY</div><div class="heading-row"><div><h1 class="page-title">Giỏ sách của bạn</h1><p class="subheading">Những cuốn sách bạn đang cân nhắc mang về.</p></div></div><div class="empty-state"><span>✳</span><strong>Giỏ sách đang đợi một cuốn hay</strong><p>Khám phá tủ sách và thêm những cuốn bạn yêu thích nhé.</p><button class="primary-button" data-page-link="catalog">Khám phá sách</button></div>`;
  const total=items.reduce((sum,book)=>sum+(Number(book.price)||0),0);
  return `<div class="eyebrow">CHỌN SÁCH HAY</div><div class="heading-row"><div><h1 class="page-title">Giỏ sách của bạn</h1><p class="subheading">${items.length} cuốn sách đang chờ bạn.</p></div></div><div class="cart-layout"><div class="cart-items">${items.map(book=>`<div class="cart-row">${miniCover(book)}<span><strong>${escapeHTML(book.title)}</strong><small>${escapeHTML(book.author)} · ${escapeHTML(book.category)}</small></span><b>${fmtPrice(book.price||0)}</b><button class="remove-button" data-remove-cart="${book.id}" aria-label="Xóa ${escapeHTML(book.title)}">×</button></div>`).join('')}</div><aside class="summary-card"><h3>Tóm tắt yêu cầu mượn</h3><div class="summary-line"><span>Sách đã chọn (${items.length} cuốn)</span><span>${fmtPrice(total)}</span></div><div class="summary-line"><span>Phí mượn</span><span>Thông báo sau</span></div><div class="summary-line summary-total"><span>Giá tham khảo</span><span>${fmtPrice(total)}</span></div><button class="primary-button" data-checkout>Gửi yêu cầu mượn <span>→</span></button><p class="fine-print">Thư viện sẽ xác nhận tình trạng sách, thời hạn và phí mượn trước khi hoàn tất.</p></aside></div>`;
}
function proShelf(){
  const current=loanItems.map(item=>({...item,book:proFindBook(item.id)})).filter(item=>item.book);
  const favorites=state.favorites.map(proFindBook).filter(Boolean);
  const rows=current.map(item=>`<div class="list-row"><div class="list-book">${miniCover(item.book)}<span><strong>${escapeHTML(item.book.title)}</strong><small>${escapeHTML(item.book.author)}</small></span></div><span>${item.due}</span><span><i class="status-pill ${item.tone}">${item.status}</i></span><span>${item.book.type==='Đọc online'?'E-book':'Bản giấy'}</span><button class="link-button" ${item.book.type==='Đọc online'?`data-reader-book="${item.book.id}"`:`data-open-book="${item.book.id}"`}>Mở</button></div>`).join('');
  return `<div class="eyebrow">GÓC CỦA BẠN</div><div class="heading-row"><div><h1 class="page-title">Kệ sách của tôi</h1><p class="subheading">Theo dõi sách đang đọc, yêu thích và lịch sử mượn.</p></div><button class="primary-button" data-page-link="catalog">+ Tìm sách</button></div><section class="section"><div class="section-head"><div><h2 class="section-title">Mượn gần đây</h2><div class="section-note">Nhớ trả sách đúng hạn để tiếp tục khám phá nhé</div></div></div><div class="list-card"><div class="list-head"><span>Tên sách</span><span>Ngày hẹn</span><span>Trạng thái</span><span>Loại</span><span></span></div>${rows}</div></section><section class="section"><div class="section-head"><div><h2 class="section-title">Sách bạn yêu thích</h2><div class="section-note">${favorites.length} cuốn đã lưu cho lần đọc sau</div></div><button class="link-button" data-page-link="catalog">Thêm sách ↗</button></div>${favorites.length?`<div class="book-grid">${favorites.map(bookCard).join('')}</div>`:`<div class="empty-state"><strong>Bạn chưa lưu sách nào</strong><p>Hãy lưu những cuốn bạn muốn đọc vào lần sau.</p></div>`}</section>`;
}
renderCart=proCart;
renderShelf=proShelf;
const proPreviousRender=render;
render=function(){
  if(!['home','catalog','book-detail'].includes(state.page)){proPreviousRender();return;}
  document.getElementById('page-label').textContent=labels[state.page]||'Chi tiết sách';
  document.querySelectorAll('.nav-link').forEach(a=>a.classList.toggle('active',a.dataset.page===state.page));
  syncCounts();app.innerHTML=state.page==='home'?proHome():state.page==='catalog'?proCatalog():proDetail();
  app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');
};
function proSyncRoute(){
  const route=location.hash.slice(1),match=route.match(/^(book|reader)\/(\d+)$/);
  if(match){state.page=match[1]==='book'?'book-detail':'reader';if(match[1]==='book')state.bookId=Number(match[2]);else{state.readerBookId=Number(match[2]);saveStore('may-reader-book',state.readerBookId);}}
  else state.page=labels[route]?route:'home';
  render();
}
go=function(page){
  if(page==='reader'){history.pushState(null,'',`#reader/${state.readerBookId}`);state.page='reader';render();window.scrollTo({top:0,behavior:'smooth'});return;}
  if(page==='book-detail'){if(!state.bookId)return;history.pushState(null,'',`#book/${state.bookId}`);state.page='book-detail';render();window.scrollTo({top:0,behavior:'smooth'});return;}
  if(!labels[page])return;state.page=page;history.pushState(null,'',`#${page}`);render();window.scrollTo({top:0,behavior:'smooth'});
};
openBook=function(id){state.bookId=Number(id);go('book-detail');};
const baseSetSearch=setSearch;
setSearch=function(value){
  const input=document.getElementById('global-search'),position=input?.selectionStart;
  baseSetSearch(value);const next=document.getElementById('global-search');
  if(next){next.focus();if(position!==null&&position!==undefined)next.setSelectionRange(position,position);}
};
window.addEventListener('popstate',proSyncRoute);
window.addEventListener('hashchange',()=>{if(/^(book|reader)\//.test(location.hash.slice(1)))proSyncRoute();});
document.addEventListener('click',e=>{
  const category=e.target.closest('[data-pro-category]');if(category){state.category=category.dataset.proCategory;state.catalogPage=1;go('catalog');return;}
  const page=e.target.closest('[data-pro-page]');if(page){state.catalogPage=Number(page.dataset.proPage);render();return;}
  if(e.target.closest('[data-pro-clear]')){state.query='';state.category='Tất cả';state.proType='Tất cả';document.getElementById('global-search').value='';render();return;}
});
document.addEventListener('input',e=>{
  if(e.target.id==='pro-search'){state.query=e.target.value;state.catalogPage=1;const position=e.target.selectionStart;render();const next=document.getElementById('pro-search');if(next){next.focus();next.setSelectionRange(position,position);}}
});
document.addEventListener('change',e=>{
  if(e.target.id==='pro-type'){state.proType=e.target.value;state.catalogPage=1;render();}
  if(e.target.id==='pro-sort'){state.proSort=e.target.value;state.catalogPage=1;render();}
});
const routeAtStart=location.hash.slice(1),bookAtStart=routeAtStart.match(/^(book|reader)\/(\d+)$/);
if(bookAtStart){state.page=bookAtStart[1]==='book'?'book-detail':'reader';if(bookAtStart[1]==='book')state.bookId=Number(bookAtStart[2]);else{state.readerBookId=Number(bookAtStart[2]);saveStore('may-reader-book',state.readerBookId);}}
else if(labels[routeAtStart])state.page=routeAtStart;
render();
