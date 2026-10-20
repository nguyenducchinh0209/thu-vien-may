/* Trang xác thực và bảng điều khiển quản trị. Đây là chế độ trình diễn phía trình duyệt. */
const authPageLabels = {login:'Đăng nhập',register:'Tạo tài khoản',forgot:'Quên mật khẩu'};
Object.assign(labels, authPageLabels, {'librarian-home':'Bàn làm việc thủ thư','profile-edit':'Chỉnh sửa hồ sơ',vip:'Thành viên VIP',checkout:'Thanh toán',help:'Hướng dẫn sử dụng'});

let authSession=window.libraryUser||null;
const authServerData={cartItems:[],reservations:[],loans:[],fines:[],reviews:[],comments:[],profile:null,profileLoading:!!authSession,profileError:'',favoriteBooks:[]};
const authBootEmail=authSession?.email||null;
let authActiveEmail=null;
let authProfileSaving=false;
let authLogoutBusy=false;
let authReservationSubmitBusy=false;
if(authSession){state.name=authSession.name;state.role=authSession.role;state.email=authSession.email||'minhanh@example.com';}
else {state.name='Khách';state.role='Độc giả';state.email='';state.avatar='';saveStore('may-role',state.role);}

function authAccountKey(email){return `may-account-${String(email||'guest').trim().toLowerCase().replace(/[^a-z0-9@._-]/g,'_')}`}
function authPersistAccountData(){if(!authActiveEmail)return;saveStore(`${authAccountKey(authActiveEmail)}-bookshelf`,{cart:state.cart,favorites:state.favorites,avatar:state.avatar||''});}
function authLoadAccountData(email){
  const key=authAccountKey(email),saved=readStore(`${key}-bookshelf`,null);
  if(saved){state.cart=Array.isArray(saved.cart)?saved.cart:[];state.favorites=Array.isArray(saved.favorites)?saved.favorites:[];state.avatar=saved.avatar||'';}
  else if(authBootEmail&&String(email).toLowerCase()===String(authBootEmail).toLowerCase()){state.cart=readStore('may-cart',[]);state.favorites=readStore('may-favorites',[3,6]);state.avatar=readStore('may-avatar','');}
  else {state.cart=[];state.favorites=[];state.avatar='';}
  authActiveEmail=email;saveStore('may-cart',state.cart);saveStore('may-favorites',state.favorites);authPersistAccountData();
}
if(authSession)authLoadAccountData(authSession.email);

function authEscape(value){return escapeHTML(value||'')}
async function authRequest(url,{method='GET',body=null}={}){
  const headers={'Accept':'application/json','X-Requested-With':'XMLHttpRequest'};
  if(method!=='GET')headers['X-CSRF-TOKEN']=document.querySelector('meta[name="csrf-token"]')?.content||'';
  if(body&&!(body instanceof FormData))headers['Content-Type']='application/json';
  const response=await fetch(url,{method,headers,body:body instanceof FormData?body:body?JSON.stringify(body):undefined,credentials:'same-origin'});
  const payload=await response.json().catch(()=>({}));
  if(payload.csrf_token){const meta=document.querySelector('meta[name="csrf-token"]');if(meta)meta.content=payload.csrf_token;}
  if(!response.ok){const errors=payload.errors||{};const first=Object.entries(errors).find(([,messages])=>messages?.length);throw new Error(first?.[1]?.[0]||payload.message||'Không thể xử lý yêu cầu.');}
  return payload;
}
function authNormalizeBook(book){return {...book,has_epub:book.has_epub===true,author:(book.authors||[]).map(author=>author.name).join(', ')||'Chưa cập nhật tác giả',category:book.category||'Chưa phân loại',type:'Sách giấy',price:null,rating:book.reviews_avg_rating?Number(book.reviews_avg_rating).toFixed(1):'—',reads:Number(book.reviews_count)||0,available_quantity:Number(book.available_quantity)||0,preview_page_count:Number(book.preview_page_count)||0,color:Number(book.id)%12+1};}
async function authLoadCatalog(query='',page=1,rerender=false){
  state.catalogLoading=true;if(state.page==='catalog')render();
  try{const params=new URLSearchParams({per_page:'8',page:String(page)});if(query)params.set('q',query);if(state.catalogCategory)params.set('category',state.catalogCategory);if(state.catalogSort&&state.catalogSort!=='relevance')params.set('sort',state.catalogSort);const response=await authRequest(`/catalog/books?${params}`);state.adminBooks=(response.data||[]).map(authNormalizeBook);state.catalogCategories=response.categories||[];state.catalogMeta={total:response.total||0,last_page:response.last_page||1,current_page:response.current_page||1};state.catalogPage=state.catalogMeta.current_page;state.catalogLoading=false;if(rerender||['home','catalog','book-detail','reader','admin'].includes(state.page))render();}
  catch(error){state.catalogLoading=false;state.catalogError=error.message;console.error('Không tải được catalog:',error);if(state.page==='catalog')render();}
}
async function authRefreshCatalog(){
  const pageLoad=authLoadCatalog(state.page==='catalog'?state.query:'',state.page==='catalog'?state.catalogPage||1:1,true);
  const totalLoad=authRequest('/catalog/books?per_page=1&page=1').catch(()=>null);
  const [,catalog]=await Promise.all([pageLoad,totalLoad]);
  state.catalogTotal=Number.isFinite(catalog?.total)?catalog.total:null;
  if(state.page==='admin')render();
}
async function authRefreshReaderData(){
  if(!authSession||authSession.role!=='Độc giả')return;
  const identity=authSession.id;
  authServerData.profileLoading=true;authServerData.profileError='';
  try{
    const [cart,reservations,loans,fines,profile]=await Promise.all([authRequest('/my/cart'),authRequest('/my/reservations?per_page=50'),authRequest('/my/loans?per_page=50'),authRequest('/my/fines?per_page=50'),authRequest('/my/profile')]);
    if(authSession?.id!==identity)return;
    authServerData.cartItems=cart.cart?.items||[];
    state.cart=authServerData.cartItems.map(item=>Number(item.book_id));
    authServerData.reservations=(reservations.data||[]).map(row=>({...row,book:row.book?.title||'Sách',created:row.created_at,raw_status:row.status,status:authReservationStatus(row,false)}));
    authServerData.loans=loans.data||[];
    authServerData.fines=(fines.data||[]).map(row=>({id:row.id,reason:`Trễ hạn ${row.overdue_days} ngày`,created:row.created_at,amount:row.amount,status:row.status==='unpaid'?'Chưa thanh toán':row.status}));
    authServerData.profile=profile.profile||null;
    state.avatar=authServerData.profile?.avatar_url||'';
    const favoriteBooks=await Promise.all(state.favorites.map(async id=>{try{return authNormalizeBook((await authRequest(`/catalog/books/${id}`)).book);}catch{return null;}}));
    if(authSession?.id!==identity)return;
    authServerData.favoriteBooks=favoriteBooks;
    const items=authServerData.loans.flatMap(loan=>(loan.items||[]).map(item=>({id:item.id,loanId:loan.id,bookId:item.book_id,book:item.book,due:loan.due_at?new Date(loan.due_at).toLocaleDateString('vi-VN'):'—',status:loan.status==='active'?'Đang mượn':'Đã trả',tone:loan.status==='active'?'':'done'})));
    loanItems.splice(0,loanItems.length,...items);
    saveStore('may-cart',state.cart);syncCounts();if(['cart','shelf','profile','reservations','fines'].includes(state.page)||(state.page==='profile-edit'&&!document.getElementById('profile-form')?.dataset.dirty))render();
  }catch(error){if(authSession?.id===identity)authServerData.profileError=error.message;console.error('Không tải được dữ liệu độc giả:',error);}
  finally{if(authSession?.id===identity){authServerData.profileLoading=false;if(!authServerData.profile&&['profile','profile-edit'].includes(state.page))render();}}
}
function authReservationStatus(row,staff=false){const base=({pending:'Chờ duyệt',ready:'Đã giữ',fulfilled:'Đã giao',cancelled:'Đã hủy',expired:'Hết hạn'})[row.status]||row.status;if(!window.libraryFeatureApi.capabilities.reservationWorkflow||row.status!=='ready')return base;if(row.payment_status==='paid')return staff?'Thanh toán thành công':'Đã giữ';if(row.payment_status==='confirming')return staff?(row.payment_method==='bank_transfer'?'Cần kiểm tra chuyển khoản mô phỏng':'Chờ xác nhận tiền mặt'):'Chờ xác nhận thanh toán';if(row.payment_status==='unpaid')return staff?'Chờ độc giả thanh toán':'Chờ thanh toán';if(row.payment_status==='pending')return staff?(row.payment_method==='cash'?'Chờ thu tiền mặt':'Chờ khách báo chuyển khoản mô phỏng'):'Chờ xác nhận thanh toán';return staff?'Chưa rõ thanh toán':'Đã giữ · chưa rõ thanh toán';}
function authMapReservation(row){return {id:row.id,reader:row.reader?.name||'',email:row.reader?.email||'',book:row.book?.title||'',book_id:row.book_id,quantity:row.quantity,created:new Date(row.created_at).toLocaleDateString('vi-VN'),raw_status:row.status,payment_status:row.payment_status,payment_method:row.payment_method,amount:row.amount,expires_at:row.expires_at,status:authReservationStatus(row,true)};}
async function authRefreshStaffData(){
  if(!authSession||!['Admin','Thủ thư'].includes(authSession.role))return;
  const identity=authSession.id;
  authServerData.profileLoading=true;authServerData.profileError='';
  try{
    const accountRequest=authSession.role==='Admin'?authRequest('/staff/accounts?per_page=50'):Promise.resolve({data:[]});
    const paymentRequest=window.libraryFeatureApi.capabilities.payments?authRequest('/staff/payments?per_page=50'):Promise.resolve({data:[]});
    const [reservations,loans,inventory,readers,authors,fines,accounts,profile]=await Promise.all([authRequest('/staff/reservations?per_page=50'),authRequest('/staff/loans?per_page=50'),authRequest('/staff/inventory?per_page=50'),authRequest('/staff/readers?per_page=50'),authRequest('/staff/authors?per_page=50'),authRequest('/staff/fines?per_page=50'),accountRequest,authRequest('/my/profile')]);
    const payments=await paymentRequest;
    if(authSession?.id!==identity)return;
    authServerData.profile=profile.profile||null;
    state.avatar=authServerData.profile?.avatar_url||'';
    opData.reservations=(reservations.data||[]).map(authMapReservation);
    opData.loans=(loans.data||[]).map(loan=>({id:loan.id,reader:loan.reader?.name||'',book:(loan.items||[]).map(item=>`${item.book?.title||'Sách'} ×${item.quantity-item.returned_quantity}`).join(', '),borrowed:new Date(loan.issued_at).toLocaleDateString('vi-VN'),due:new Date(loan.due_at).toLocaleDateString('vi-VN'),status:loan.status==='active'?(new Date(loan.due_at)<new Date()?'Quá hạn':'Đang mượn'):'Đã trả',items:loan.items||[]}));
    opData.returns=(loans.data||[]).flatMap(loan=>(loan.returns||[]).map(record=>({id:record.id,reader:loan.reader?.name||'',book:(record.items||[]).map(item=>item.loan_item?.book?.title||'Sách').join(', '),due:new Date(loan.due_at).toLocaleDateString('vi-VN'),returned:new Date(record.returned_at).toLocaleDateString('vi-VN'),lateDays:0,fine:(record.items||[]).reduce((sum,item)=>sum+Number(item.fine?.amount||0),0),status:'Đã nhận trả'})));
    opData.readers=(readers.data||[]).map(row=>({id:row.id,name:row.name,email:row.email,phone:row.reader_profile?.phone||'',status:'Hoạt động'}));
    opData.authors=(authors.data||[]).map(row=>({id:row.id,name:row.name,country:row.biography||'—',books:(row.books_count||0).toString()}));
    opData.inventory=(inventory.data||[]).map(row=>({id:row.book_code,book_id:row.id,book:row.title,shelf:'—',total:row.total_quantity,available:row.available_quantity,status:row.available_quantity?'Đang lưu hành':'Đang mượn hết'}));
    opData.fines=(fines.data||[]).map(row=>({id:row.id,reader:row.reader?.name||'',reason:`Trễ ${row.overdue_days} ngày · ${row.quantity} bản`,amount:row.amount,created:new Date(row.created_at).toLocaleDateString('vi-VN'),status:row.status==='unpaid'?'Chưa thanh toán':row.status==='waived'?'Đã miễn':row.status}));
    opData.accounts=(accounts.data||[]).map(row=>({id:row.id,name:row.name,email:row.email,role:row.role,status:row.is_locked?'Tạm khóa':'Hoạt động'}));
    opData.payments=(payments.data||[]).map(row=>({id:row.id,reader:row.reader?.name||'Độc giả đã ngừng hoạt động',email:row.reader?.email||'',reference:row.reference||row.purpose,method:row.method,amount:row.amount,purpose:row.purpose,raw_status:row.status,status:row.status==='confirming'?'Cần kiểm tra chuyển khoản mô phỏng':row.method==='cash'?'Chờ thu tiền mặt':'Chờ báo chuyển khoản mô phỏng'}));
    if(['admin','librarian-home','profile','reservations','payments','loans','returns','readers','authors','accounts','inventory','fines'].includes(state.page)||(state.page==='profile-edit'&&!document.getElementById('profile-form')?.dataset.dirty))render();
    if(state.page==='reservations')document.querySelectorAll('#ops-list tbody tr').forEach(row=>{const id=row.querySelector('td')?.textContent?.trim();const reservation=opData.reservations.find(item=>String(item.id)===String(id));if(reservation?.status==='Đã giữ'&&!row.querySelector('[data-op-fulfill]'))row.querySelector('.op-actions')?.insertAdjacentHTML('beforeend',`<button class="secondary-button" data-op-fulfill="${reservation.id}">Giao sách</button>`);});
  }catch(error){if(authSession?.id===identity)authServerData.profileError=error.message;console.error('Không tải được dữ liệu nhân viên:',error);}
  finally{if(authSession?.id===identity){authServerData.profileLoading=false;if(!authServerData.profile&&['profile','profile-edit'].includes(state.page))render();}}
}
async function authRefreshAppData(){await authRefreshCatalog();await Promise.all([authRefreshReaderData(),authRefreshStaffData(),accountFeatures.refreshMembership()]);}
function authShowLoanForm(){
  const readers=opData.readers.map(reader=>`<option value="${reader.id}">${authEscape(reader.name)} · ${authEscape(reader.email)}</option>`).join('');
  let loanOptionBooks=[...state.adminBooks];
  const options=loanOptionBooks.map(book=>`<option value="${book.id}" data-fee="${Number(book.rental_fee)||0}">${authEscape(book.title)} · ${authEscape(book.book_code)}</option>`).join('');
  showModal(`<form id="api-loan-form" class="login-form"><h2 id="modal-title">Lập phiếu mượn</h2><label class="field">Độc giả<select name="reader_id" required>${readers}</select></label><label class="field">Tìm sách<input type="search" id="loan-book-search" placeholder="Nhập tên hoặc mã sách"></label><div id="loan-book-rows"><div class="loan-book-row"><label class="field">Sách<select name="book_id" required>${options}</select></label><label class="field">Số lượng<input name="quantity" type="number" min="1" value="1" required></label></div></div><button type="button" class="secondary-button" data-loan-add-book>+ Thêm đầu sách</button><label class="field">Phí đã thu<input name="amount" type="number" min="0" step="0.01" required></label><label class="field">Hình thức<select name="method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận<input name="receipt_note" maxlength="500"></label><p class="modal-note">Xác nhận đã thu đủ phí mượn trước khi giao sách.</p><button class="primary-button" type="submit">Xác nhận thu và giao sách</button></form>`);
  const form=document.getElementById('api-loan-form');
  const addRow=()=>{const row=document.createElement('div');row.className='loan-book-row';row.innerHTML=`<label class="field">Sách<select name="book_id" required>${options}</select></label><label class="field">Số lượng<input name="quantity" type="number" min="1" value="1" required></label><button type="button" class="secondary-button" data-loan-remove-book>Xóa sách</button>`;document.getElementById('loan-book-rows').append(row);updateTotal();};
  form.querySelector('[data-loan-add-book]').addEventListener('click',addRow);
  form.addEventListener('click',event=>{if(event.target.closest('[data-loan-remove-book]')){event.target.closest('.loan-book-row').remove();updateTotal();}});
  const updateTotal=()=>{let cents=0;form.querySelectorAll('.loan-book-row').forEach(row=>{const option=row.querySelector('select').selectedOptions[0];cents+=Math.round(Number(option?.dataset.fee||0)*100)*Number(row.querySelector('[name=quantity]').value||0);});form.elements.amount.value=(cents/100).toFixed(2);};
  form.addEventListener('change',event=>{if(event.target.matches('select[name=book_id],input[name=quantity]'))updateTotal();});
  updateTotal();
  let searchTimer;
  document.getElementById('loan-book-search').addEventListener('input',event=>{clearTimeout(searchTimer);searchTimer=setTimeout(async()=>{const query=event.target.value.trim();if(query.length<2)return;try{const result=await authRequest(`/catalog/books?q=${encodeURIComponent(query)}&per_page=50`);const found=(result.data||[]).map(authNormalizeBook);const selected=[...form.querySelectorAll('select[name=book_id]')].map(select=>select.value);found.forEach(book=>{if(!loanOptionBooks.some(existing=>existing.id===book.id))loanOptionBooks.push(book);});form.querySelectorAll('select[name=book_id]').forEach((select,index)=>{const choices=[...found];const selectedBook=loanOptionBooks.find(book=>String(book.id)===selected[index]);if(selectedBook&&!choices.some(book=>String(book.id)===selected[index]))choices.unshift(selectedBook);select.innerHTML=choices.map(book=>`<option value="${book.id}" data-fee="${Number(book.rental_fee)||0}">${authEscape(book.title)} · ${authEscape(book.book_code)}</option>`).join('');if(selected[index])select.value=selected[index];});updateTotal();}catch(error){toast(error.message);}},250);});
}
async function authShowReservationFulfill(id){
  const reservation=opData.reservations.find(item=>Number(item.id)===Number(id));
  if(!reservation)return;
  try{
    const book=authNormalizeBook((await authRequest(`/catalog/books/${reservation.book_id}`)).book);
    const amount=(Number(book.rental_fee)||0)*Number(reservation.quantity||0);
    showModal(`<form id="api-reservation-fulfill-form" class="login-form" data-reservation-id="${reservation.id}"><h2 id="modal-title">Xác nhận thu phí và giao sách</h2><p>${authEscape(reservation.reader)} · ${authEscape(book.title)} × ${reservation.quantity}</p><label class="field">Phí đã thu<input name="amount" type="number" min="0" step="0.01" value="${amount.toFixed(2)}" required></label><label class="field">Hình thức<select name="method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận<input name="receipt_note" maxlength="500"></label><p class="modal-note">Xác nhận đã thu đủ phí mượn trước khi giao sách.</p><button class="primary-button" type="submit">Xác nhận thu và giao sách</button></form>`);
  }catch(error){toast(error.message||'Không thể tải thông tin sách.');}
}
function authShowLoanReturn(loanId){const loan=opData.loans.find(item=>String(item.id)===String(loanId));if(!loan)return;showModal(`<form id="api-return-form" class="login-form" data-loan-id="${loan.id}"><h2 id="modal-title">Ghi nhận trả sách</h2><p>${authEscape(loan.reader)} · ${authEscape(loan.book)}</p>${loan.items.filter(item=>item.quantity>item.returned_quantity).map(item=>`<label class="field">${authEscape(item.book?.title||'Sách')} · còn ${item.quantity-item.returned_quantity}<input name="item-${item.id}" type="number" min="0" max="${item.quantity-item.returned_quantity}" value="0"></label>`).join('')}<button class="primary-button" type="submit">Xác nhận trả</button></form>`);}
function authShowAccountForm(id){const old=opData.accounts.find(item=>String(item.id)===String(id));showModal(`<form id="api-account-form" class="login-form" data-account-id="${old?.id||''}"><h2 id="modal-title">${old?'Cập nhật tài khoản':'Tạo tài khoản'}</h2><label class="field">Họ tên<input name="name" value="${authEscape(old?.name||'')}" required minlength="2"></label><label class="field">Email<input name="email" type="email" value="${authEscape(old?.email||'')}" required></label><label class="field">Mật khẩu<input name="password" type="password" ${old?'':'required minlength="8"'}></label><label class="field">Vai trò<select name="role"><option ${old?.role==='Độc giả'?'selected':''}>Độc giả</option><option ${old?.role==='Thủ thư'?'selected':''}>Thủ thư</option><option ${old?.role==='Admin'?'selected':''}>Admin</option></select></label><label class="field">Trạng thái<select name="is_locked"><option value="0" ${!old||old.status==='Hoạt động'?'selected':''}>Hoạt động</option><option value="1" ${old?.status==='Tạm khóa'?'selected':''}>Tạm khóa</option></select></label><button class="primary-button" type="submit">Lưu tài khoản</button></form>`);}
function authShowStaffEntityForm(key,id){const old=(opData[key]||[]).find(item=>String(item.id)===String(id));if(key==='authors')showModal(`<form id="api-author-form" class="login-form" data-entity-id="${old?.id||''}"><h2 id="modal-title">${old?'Cập nhật tác giả':'Thêm tác giả'}</h2><label class="field">Tên tác giả<input name="name" value="${authEscape(old?.name||'')}" required></label><label class="field">Tiểu sử<textarea name="biography">${authEscape(old?.country==='—'?'':old?.country||'')}</textarea></label><button class="primary-button" type="submit">Lưu tác giả</button></form>`);else showModal(`<form id="api-reader-form" class="login-form" data-entity-id="${old?.id||''}"><h2 id="modal-title">${old?'Cập nhật độc giả':'Thêm độc giả'}</h2><label class="field">Họ tên<input name="name" value="${authEscape(old?.name||'')}" required minlength="2"></label><label class="field">Email<input name="email" type="email" value="${authEscape(old?.email||'')}" required></label>${old?'':'<label class="field">Mật khẩu ban đầu<input name="password" type="password" minlength="8" required></label><label class="field">Nhập lại mật khẩu<input name="password_confirmation" type="password" minlength="8" required></label>'}<label class="field">Số điện thoại<input name="phone" value="${authEscape(old?.phone||'')}"></label><button class="primary-button" type="submit">Lưu độc giả</button></form>`);}
function authShowFinePayment(id){const fine=opData.fines.find(item=>String(item.id)===String(id));if(!fine)return;showModal(`<form id="api-fine-payment-form" class="login-form" data-fine-id="${fine.id}"><h2 id="modal-title">Ghi nhận thu phạt</h2><p>${authEscape(fine.reader)} · ${authEscape(fine.reason)}</p><label class="field">Số tiền thu<input name="amount" type="number" min="0.01" step="0.01" value="${Number(fine.amount)}" required></label><label class="field">Hình thức<select name="method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận<input name="receipt_note" maxlength="500"></label><p class="modal-note">Hệ thống ghi nhận thu nội bộ, không xử lý giao dịch qua cổng thanh toán.</p><button class="primary-button" type="submit">Xác nhận đã thu</button></form>`);}
async function authLoadBookCommunity(bookId){
  state.communityLoading=true;state.communityError='';
  try{
    const [reviews,comments]=await Promise.all([authRequest(`/books/${bookId}/reviews`),authRequest(`/books/${bookId}/comments`)]);
    authServerData.reviews=reviews.reviews?.data||[];authServerData.comments=comments.data||[];
    state.communityBookId=Number(bookId);
    state.bookReviews=authServerData.reviews.map(review=>({name:review.user?.name||'Độc giả',date:new Date(review.created_at).toLocaleDateString('vi-VN'),rating:Number(review.rating)||0,text:review.body||review.title||''}));
    state.bookComments=authServerData.comments;
    const book=proFindBook(bookId);if(book){book.rating=reviews.average_rating?Number(reviews.average_rating).toFixed(1):'—';book.reads=reviews.review_count||0;}
    state.communityLoading=false;
    if(state.page==='book-detail'&&Number(state.bookId)===Number(bookId))render();
  }catch(error){state.communityLoading=false;state.communityError=error.message;console.error('Không tải được đánh giá/bình luận:',error);if(state.page==='book-detail')render();}
}
function authCommunityForms(){
  const comments=(state.bookComments||[]).map(comment=>`<article class="community-comment"><div class="community-avatar">${authEscape((comment.user?.name||'Đ').trim().split(/\s+/).map(part=>part[0]).slice(-2).join('').toUpperCase())}</div><div class="community-comment-body"><div class="community-comment-meta"><strong>${authEscape(comment.user?.name||'Độc giả')}</strong><time>${new Date(comment.created_at).toLocaleDateString('vi-VN',{day:'numeric',month:'short',year:'numeric'})}</time></div><p>${authEscape(comment.body)}</p>${(authSession?.role==='Admin'||authSession?.id===comment.user_id)?`<button type="button" class="community-delete" data-delete-comment="${comment.id}">Xóa bình luận</button>`:''}</div></article>`).join('')||'<div class="community-empty"><span>✳</span><strong>Chưa có bình luận</strong><p>Hãy là người đầu tiên chia sẻ cảm nhận về cuốn sách.</p></div>';
  const reviews=(state.bookReviews||[]).map(review=>`<article class="pro-review-card"><div><strong>${authEscape(review.name)}</strong><time>${authEscape(review.date)}</time></div><p class="pro-review-stars">${'★'.repeat(review.rating)}${'☆'.repeat(5-review.rating)}</p><p>${authEscape(review.text)}</p></article>`).join('')||'<div class="community-empty"><span>☆</span><strong>Chưa có đánh giá</strong><p>Điểm và nhận xét của độc giả sẽ hiển thị tại đây.</p></div>';
  const isReader=authSession?.role==='Độc giả';
  const reviewForm=isReader?`<form id="book-review-form" class="community-form"><label for="review-rating">Điểm đánh giá</label><select id="review-rating" name="rating"><option value="5">★★★★★ · Rất hay</option><option value="4">★★★★☆ · Hay</option><option value="3">★★★☆☆ · Bình thường</option><option value="2">★★☆☆☆ · Chưa phù hợp</option><option value="1">★☆☆☆☆ · Không phù hợp</option></select><input name="title" maxlength="150" placeholder="Tiêu đề (không bắt buộc)"><textarea name="body" maxlength="5000" placeholder="Chia sẻ cảm nhận của bạn" data-count-for="review-count"></textarea><div class="community-form-footer"><small><span id="review-count">0</span>/5000</small><button class="primary-button" type="submit">Gửi đánh giá</button></div><small class="community-error" data-error-for="review"></small></form>`:'';
  const commentForm=isReader?`<form id="book-comment-form" class="community-form"><label for="comment-body">Viết bình luận</label><textarea id="comment-body" name="body" maxlength="2000" required placeholder="Chia sẻ suy nghĩ của bạn về cuốn sách…" data-count-for="comment-count"></textarea><div class="community-form-footer"><small><span id="comment-count">0</span>/2000</small><button class="primary-button" type="submit">Đăng bình luận</button></div><small class="community-error" data-error-for="comment"></small></form>`:'';
  const accessNote=isReader?'':authSession?'<p class="community-signin-note">Tài khoản nhân viên có thể đọc ý kiến độc giả.</p>':'<p class="community-signin-note">Đăng nhập bằng tài khoản độc giả để gửi bình luận hoặc đánh giá.</p>';
  if(state.communityLoading)return '<p class="community-loading">Đang tải ý kiến độc giả…</p>';
  if(state.communityError)return `<div class="community-error-state"><p>${authEscape(state.communityError)}</p><button class="secondary-button" type="button" data-community-retry>Thử tải lại</button></div>`;
  return `<div class="community-tabs" role="tablist"><button type="button" class="community-tab ${state.communityTab!=='comments'?'active':''}" data-community-tab="reviews">Đánh giá <span>${state.bookReviews?.length||0}</span></button><button type="button" class="community-tab ${state.communityTab==='comments'?'active':''}" data-community-tab="comments">Bình luận <span>${state.bookComments?.length||0}</span></button></div><section class="community-pane" ${state.communityTab==='comments'?'hidden':''}><div class="pro-review-grid">${reviews}</div>${reviewForm}${accessNote}</section><section class="community-pane" ${state.communityTab!=='comments'?'hidden':''}><div class="community-comments">${comments}</div>${commentForm}${accessNote}</section>`;
}
function authPage(mode='login'){
  const register=mode==='register',forgot=mode==='forgot';
  const title=register?'Bắt đầu hành trình đọc':forgot?'Khôi phục mật khẩu':'Chào mừng bạn trở lại';
  const intro=register?'Tạo tài khoản để lưu sách, theo dõi đơn mượn và tiếp tục đọc ở mọi nơi.':forgot?'Nhập email đã đăng ký, chúng tôi sẽ hướng dẫn bạn đặt lại mật khẩu.':'Đăng nhập để mở kệ sách và tiếp tục hành trình đọc của bạn.';
  return `<section class="auth-page"><div class="auth-story"><a class="auth-brand" href="#home"><span class="auth-brand-mark">⌑</span><span><strong>mây</strong><small>THƯ VIỆN CỦA BẠN</small></span></a><div class="auth-story-copy"><span class="pro-section-overline">MỘT GÓC NHỎ DÀNH CHO BẠN</span><h1>Mỗi trang sách<br>mở ra một <em>thế giới.</em></h1><p>Giữ lại những cuốn sách yêu thích, theo dõi ngày trả và tìm thấy câu chuyện tiếp theo của bạn.</p><div class="auth-quote"><span>“</span><div><p>Đọc sách là cách ta trò chuyện với những tâm hồn tuyệt vời nhất.</p><small>THƯ VIỆN MÂY</small></div></div></div><span class="auth-story-foot">ĐỌC CHẬM THÔI, THẾ GIỚI VẪN CHỜ. ✳</span></div><div class="auth-panel"><a href="#home" class="auth-mobile-brand">← Trở về thư viện</a><div class="auth-form-wrap"><span class="auth-kicker">${register?'THÀNH VIÊN MỚI':forgot?'HỖ TRỢ TÀI KHOẢN':'KHÔNG GIAN ĐỌC CỦA BẠN'}</span><h2>${title}</h2><p class="auth-intro">${intro}</p><form id="auth-form" data-auth-mode="${mode}" novalidate>${register?`<div class="field"><label for="auth-name">Họ và tên</label><input id="auth-name" name="name" autocomplete="name" required minlength="2" placeholder="Ví dụ: Nguyễn Minh Anh"><small class="auth-error" data-error-for="name"></small></div>`:''}<div class="field"><label for="auth-email">Email</label><input id="auth-email" name="email" type="email" autocomplete="email" required placeholder="ban@email.com"><small class="auth-error" data-error-for="email"></small></div>${!forgot?`<div class="field"><label for="auth-password">Mật khẩu</label><div class="password-field"><input id="auth-password" name="password" type="password" autocomplete="${register?'new-password':'current-password'}" required minlength="8" placeholder="Tối thiểu 8 ký tự"><button type="button" class="password-toggle" data-password-toggle aria-label="Hiện mật khẩu">Hiện</button></div><small class="auth-error" data-error-for="password"></small></div>`:''}${register?`<div class="field"><label for="auth-confirm">Nhập lại mật khẩu</label><input id="auth-confirm" name="confirm" type="password" autocomplete="new-password" required minlength="8" placeholder="Nhập lại mật khẩu"><small class="auth-error" data-error-for="confirm"></small></div>`:''}${!register&&!forgot?`<div class="auth-form-options"><label><input type="checkbox" name="remember"> Ghi nhớ phiên đăng nhập</label><button type="button" class="auth-text-link" data-auth-switch="forgot">Quên mật khẩu?</button></div>`:''}<button class="primary-button auth-submit" type="submit">${register?'Tạo tài khoản':forgot?'Gửi hướng dẫn':'Đăng nhập'} <span>→</span></button><div class="auth-divider"><span>hoặc</span></div><p class="auth-switch">${register?'Đã có tài khoản?':forgot?'Nhớ ra mật khẩu rồi?':'Bạn chưa có tài khoản?'} <button type="button" data-auth-switch="${register||forgot?'login':'register'}">${register||forgot?'Đăng nhập':'Tạo tài khoản'}</button></p></form></div></div></section>`;
}

function authAdminPage(){
  const hasServerBookTotal=!!authSession&&Number.isFinite(state.catalogTotal);
  const booksTotal=hasServerBookTotal?state.catalogTotal:state.adminBooks.length;
  const loans=opData.loans||[], readers=opData.readers||[], reservations=opData.reservations||[], returns=opData.returns||[];
  const activeLoans=loans.filter(row=>['Đang mượn','Quá hạn','Chờ trả'].includes(row.status)).length;
  const activeReaders=readers.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('hoạt động')).length;
  const pending=reservations.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length+returns.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length;
  const links=[['inventory','Kho sách','Theo dõi số bản và vị trí kệ','▤'],['accounts','Tài khoản & vai trò','Quản lý quyền truy cập','♙'],['loans','Phiếu mượn','Theo dõi sách đang mượn','↗'],['returns','Xử lý trả sách','Nhận sách và ghi nhận trễ hạn','↩'],['reservations','Đặt trước','Duyệt yêu cầu của độc giả','☆'],['fines','Phạt & vi phạm','Theo dõi khoản phạt','₫']];
  return `<div class="admin-pro"><div class="admin-welcome"><div><span class="pro-section-overline">BÀN ĐIỀU KHIỂN · ${new Date().toLocaleDateString('vi-VN',{day:'2-digit',month:'long',year:'numeric'})}</span><h1 class="page-title">Chào ${authEscape(state.name)}.</h1><p class="subheading">Đây là tình hình vận hành thư viện của bạn.</p></div><div class="admin-heading-actions"><span class="demo-data-badge"><i></i> Dữ liệu máy chủ</span><button class="primary-button" data-add-book>+ Thêm sách</button></div></div><div class="admin-metrics"><article class="metric-card"><small>Tổng đầu sách</small><strong>${booksTotal.toLocaleString('vi-VN')}</strong><em>${hasServerBookTotal?'Tổng trong danh mục máy chủ':'Số sách đang hiển thị'}</em></article><article class="metric-card"><small>Phiếu đang mượn</small><strong>${activeLoans.toLocaleString('vi-VN')}</strong><em>Theo dõi hạn trả sách</em></article><article class="metric-card"><small>Độc giả hoạt động</small><strong>${activeReaders.toLocaleString('vi-VN')}</strong><em>Tài khoản trong danh sách</em></article><article class="metric-card"><small>Việc cần xử lý</small><strong>${pending.toLocaleString('vi-VN')}</strong><em class="down">Đặt trước và trả sách</em></article></div><div class="admin-pro-grid"><section class="panel admin-chart-panel"><div class="panel-head"><div><h3>Hoạt động mượn sách</h3><span class="section-note">Minh họa lượt mượn trong tuần</span></div><button class="link-button" data-page-link="loans">Mở phiếu mượn ↗</button></div><div class="admin-week-chart">${[38,57,45,73,52,91,66].map((height,index)=>`<div class="admin-week-day"><span style="height:${height}%"></span><small>${['T2','T3','T4','T5','T6','T7','CN'][index]}</small></div>`).join('')}</div></section><section class="panel admin-activity"><div class="panel-head"><div><h3>Hàng đợi hôm nay</h3><span class="section-note">${pending} mục đang chờ xử lý</span></div></div>${pending?`<div class="admin-queue-row"><i class="activity-dot orange"></i><span><strong>${reservations.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length} yêu cầu đặt trước</strong><small>Cần kiểm tra và duyệt</small></span><button class="link-button" data-page-link="reservations">Mở</button></div><div class="admin-queue-row"><i class="activity-dot"></i><span><strong>${returns.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length} lượt trả sách</strong><small>Xác nhận sách được nhận lại</small></span><button class="link-button" data-page-link="returns">Mở</button></div>`:`<div class="admin-empty-queue">Không có mục nào đang chờ xử lý.</div>`}</section></div><section class="admin-shortcuts"><div class="admin-section-heading"><div><span class="pro-section-overline">CÔNG CỤ QUẢN TRỊ</span><h2>Đi đến chức năng</h2></div><span>Chọn một khu vực để tiếp tục</span></div><div class="admin-shortcut-grid">${links.map(([page,title,description,glyph])=>`<button class="admin-shortcut-card" data-page-link="${page}"><span class="admin-shortcut-icon">${glyph}</span><span><strong>${title}</strong><small>${description}</small></span><b>→</b></button>`).join('')}</div></section><section class="section admin-book-section"><div class="section-head"><div><span class="pro-section-overline">DANH MỤC</span><h2 class="section-title">Quản lý sách</h2><div class="section-note">Thêm, sửa, xóa hoặc tìm trong danh mục thư viện.</div></div><label class="global-search admin-book-search"><span>${icon('search')}</span><input id="admin-search" placeholder="Tìm tên sách, tác giả…"></label></div><div class="list-card" id="admin-table">${adminTable(state.adminBooks)}</div></section></div>`;
}

function authReaderRecords(kind){
  if(kind==='reservations')return authServerData.reservations;
  if(kind==='fines')return authServerData.fines;
  const key=`${authAccountKey(authSession?.email)}-${kind}`;
  const records=readStore(key,[]);return Array.isArray(records)?records:[];
}
function authPersonalPage(kind){
  const reservations=kind==='reservations',rows=authReaderRecords(kind);
  const title=reservations?'Sách bạn đã đặt trước':'Phí và lịch sử vi phạm';
  const subtitle=reservations?'Theo dõi yêu cầu giữ sách và trạng thái xử lý.':'Theo dõi khoản phạt, lý do và thời điểm thanh toán.';
  const columns=reservations?['Mã yêu cầu','Tên sách','Ngày gửi','Trạng thái']:['Mã khoản','Nội dung','Ngày ghi nhận','Số tiền','Trạng thái'];
  return `<div class="reader-account-page"><div class="eyebrow">TÀI KHOẢN ĐỘC GIẢ</div><div class="heading-row"><div><h1 class="page-title">${title}</h1><p class="subheading">${subtitle}</p></div>${reservations?'<button class="primary-button" data-page-link="catalog">Tìm sách để đặt trước</button>':''}</div>${rows.length?`<div class="reader-account-list">${rows.map(row=>{const status=String(row.status||'').toLocaleLowerCase('vi'),statusClass=status.includes('chưa thanh toán')||status.includes('chờ')?'pending':status.includes('thanh toán thành công')||status==='đã giữ'?'paid':'done';return `<article class="reader-account-row"><div class="reader-record-icon">${reservations?'▤':'₫'}</div><div class="reader-record-main"><strong>${authEscape(reservations?row.book:row.reason)}</strong><small>${authEscape(row.id)} · ${authEscape(row.created)}</small>${reservations?`<small>${row.payment_method==='bank_transfer'?'Phương thức: Chuyển khoản mô phỏng (không chuyển tiền thật)':row.payment_method==='cash'?'Phương thức: Tiền mặt tại thư viện':'Phương thức: Chưa có thông tin'}${row.amount!=null?` · ${fmtPrice(Number(row.amount))}`:''}${row.expires_at?` · Hạn ${authEscape(new Date(row.expires_at).toLocaleString('vi-VN'))}`:''}</small>`:''}</div>${!reservations?`<b class="reader-fine-amount">${fmtPrice(Number(row.amount)||0)}</b>`:''}<span class="reader-record-status ${statusClass}">${authEscape(row.status)}</span>${reservations?accountFeatures.paymentAction({...row,status:row.raw_status}):''}</article>`}).join('')}</div>`:`<div class="empty-state"><strong>${reservations?'Bạn chưa đặt trước cuốn sách nào':'Bạn chưa có khoản phạt'}</strong><p>${reservations?'Khi gửi yêu cầu đặt trước, trạng thái sẽ xuất hiện tại đây.':'Các khoản phí phát sinh sẽ được lưu trong lịch sử này.'}</p></div>`}${reservations&&window.libraryFeatureApi.capabilities.reservationWorkflow?'':reservations?'<p class="reader-record-note">Thanh toán mới sẽ khả dụng sau khi thư viện kết nối dịch vụ xác nhận giao dịch.</p>':''}</div>`;
}
function authLibrarianHome(){
  const loans=opData.loans||[],reservations=opData.reservations||[],returns=opData.returns||[],readers=opData.readers||[];
  const active=loans.filter(row=>['Đang mượn','Quá hạn','Chờ trả'].includes(row.status)).length;
  const overdue=loans.filter(row=>row.status==='Quá hạn').length;
  const waitingReservations=reservations.filter(row=>String(row.status).includes('Chờ')).length;
  const waitingReturns=returns.filter(row=>String(row.status).includes('Chờ')).length;
  const shortcuts=[['reservations','Duyệt yêu cầu đặt trước',waitingReservations,'Cần kiểm tra'],['returns','Nhận trả sách',waitingReturns,'Chờ xác nhận'],['loans','Quản lý phiếu mượn',active,'Đang lưu hành'],['readers','Tra cứu độc giả',readers.length,'Hồ sơ độc giả'],['inventory','Kiểm tra kho sách',(opData.inventory||[]).length,'Bản sách']];
  return `<div class="staff-desk"><div class="eyebrow">BÀN LÀM VIỆC · THỦ THƯ</div><div class="heading-row"><div><h1 class="page-title">Chào ${authEscape(state.name)}.</h1><p class="subheading">Các yêu cầu và công việc cần xử lý trong thư viện.</p></div><span class="staff-shift-badge"><i></i> Ca trực hôm nay</span></div><div class="staff-desk-metrics"><article><span class="staff-metric-icon">▤</span><small>Đang mượn</small><strong>${active}</strong><em>phiếu sách</em></article><article><span class="staff-metric-icon amber">◷</span><small>Quá hạn</small><strong>${overdue}</strong><em>cần theo dõi</em></article><article><span class="staff-metric-icon blue">☆</span><small>Chờ đặt trước</small><strong>${waitingReservations}</strong><em>yêu cầu mới</em></article><article><span class="staff-metric-icon rose">↩</span><small>Chờ nhận trả</small><strong>${waitingReturns}</strong><em>sách bàn giao</em></article></div><section class="section"><div class="section-head"><div><span class="pro-section-overline">THAO TÁC NHANH</span><h2 class="section-title">Chọn công việc</h2></div><span class="section-note">Danh sách nghiệp vụ thư viện</span></div><div class="staff-task-grid">${shortcuts.map(([page,title,count,note])=>`<button class="staff-task-card" data-page-link="${page}"><span><strong>${title}</strong><small>${note}</small></span><b>${count}</b><i>→</i></button>`).join('')}</div></section><section class="staff-desk-bottom"><article class="panel"><div class="panel-head"><div><h3>Đặt trước cần duyệt</h3><span class="section-note">${waitingReservations} yêu cầu đang chờ</span></div><button class="link-button" data-page-link="reservations">Xem tất cả ↗</button></div>${reservations.filter(row=>String(row.status).includes('Chờ')).slice(0,3).map(row=>`<div class="admin-queue-row"><i class="activity-dot orange"></i><span><strong>${authEscape(row.reader)}</strong><small>${authEscape(row.book)} · ${authEscape(row.created)}</small></span><span class="status-pill">Chờ duyệt</span></div>`).join('')||'<p class="staff-empty-note">Không có yêu cầu mới.</p>'}</article><article class="staff-tip-card"><span class="pro-section-overline">GỢI Ý CA TRỰC</span><h3>Nhận trả sách đúng quy trình</h3><p>Kiểm tra tình trạng sách, xác nhận ngày nhận và ghi nhận phí trễ hạn nếu có.</p><button class="secondary-button" data-page-link="returns">Mở danh sách trả sách →</button></article></section><p class="admin-data-note">Các số liệu trên lấy từ dữ liệu trình diễn của giao diện.</p></div>`;
}

const authPrivatePages=new Set(['shelf','cart','profile','profile-edit','vip','checkout','reservations','payments','loans','returns','readers','authors','accounts','inventory','fines','librarian-home']);
const authLibrarianPages=new Set(['librarian-home','reservations','payments','loans','returns','readers','inventory']);
const authAdminPages=new Set(['admin','accounts','authors']);
function authSetIdentity(session){
  authPersistAccountData();
  if(authSession?.id!==session.id){authServerData.profile=null;authServerData.profileLoading=true;authServerData.profileError='';authServerData.reservations=[];accountFeatures.reset(session);}
  authSession=session;state.role=session.role;state.name=session.name;state.email=session.email;
  authLoadAccountData(session.email);
  saveStore('may-role',session.role);saveStore('may-name',session.name);
  window.libraryUser=session;
}
function authGo(page){
  const feature=accountFeatures.resolve(page);
  if(feature?.page==='checkout'){
    if(!authSession){sessionStorage.setItem('may-next-route',page);go('login');return;}
    if(authSession.role!=='Độc giả'){toast('Thanh toán dành cho tài khoản độc giả.');return;}
    accountFeatures.setRoute(page);state.page='checkout';history.pushState(null,'',`#${page}`);render();window.scrollTo({top:0,behavior:'smooth'});return;
  }
  if(page==='vip'&&authSession&&authSession.role!=='Độc giả'){toast('Gói VIP dành cho tài khoản độc giả.');return;}
  accountFeatures.setRoute(page);
  const bookRoute=String(page).match(/^book\/(\d+)$/);
  if(bookRoute){
    state.bookId=Number(bookRoute[1]);history.pushState(null,'',`#book/${state.bookId}`);state.page='book-detail';render();window.scrollTo({top:0,behavior:'smooth'});authLoadBookCommunity(state.bookId);return;
  }
  if(authPrivatePages.has(page)&&!authSession){sessionStorage.setItem('may-next-route',page);page='login';}
  if(authAdminPages.has(page)&&authSession?.role!=='Admin'){toast('Chức năng này chỉ dành cho Admin.');page=authSession?'home':'login';}
  if(authLibrarianPages.has(page)&&authSession?.role==='Độc giả'){toast('Khu vực này dành cho nhân viên thư viện.');page='home';}
  if(page==='home'&&authSession?.role==='Admin')page='admin';
  if(page==='home'&&authSession?.role==='Thủ thư')page='librarian-home';
  if(page==='librarian-home'&&authSession?.role==='Admin')page='admin';
  authBaseGo(page);
}
const authBaseGo=go;
go=authGo;

const authBaseRender=render;
render=function(){
  if(state.page==='home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');}
  if(state.page==='home'&&authSession?.role==='Thủ thư'){state.page='librarian-home';history.replaceState(null,'','#librarian-home');}
  if(!authPageLabels[state.page]&&authPrivatePages.has(state.page)&&!authSession){sessionStorage.setItem('may-next-route',state.page);state.page='login';history.replaceState(null,'','#login');}
  if(authAdminPages.has(state.page)&&authSession?.role!=='Admin'){state.page=authSession?'home':'login';history.replaceState(null,'','#'+state.page);}
  if(authLibrarianPages.has(state.page)&&authSession?.role==='Độc giả'){state.page='home';history.replaceState(null,'','#home');}
  if(state.page==='librarian-home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');}
  if(['vip','checkout'].includes(state.page)&&authSession&&authSession.role!=='Độc giả'){state.page='profile';history.replaceState(null,'','#profile');}
  const authMode=!!authPageLabels[state.page];
  document.body.classList.toggle('auth-mode',authMode);
  if(authMode){document.getElementById('page-label').textContent=authPageLabels[state.page];app.innerHTML=authPage(state.page);app.focus({preventScroll:true});}
  else if(state.page==='admin'){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels.admin;document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authAdminPage();app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(state.page==='librarian-home'){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels['librarian-home'];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authLibrarianHome();app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(authSession?.role==='Độc giả'&&(state.page==='reservations'||state.page==='fines')){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels[state.page];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authPersonalPage(state.page);app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(accountFeatures.handles(state.page)){accountFeatures.enter();document.getElementById('page-label').textContent=labels[state.page];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));app.innerHTML=accountFeatures.view();document.getElementById('sidebar').classList.remove('open');}
  else {document.body.classList.remove('auth-mode');authBaseRender();}
  if(state.page==='cart'&&authSession?.role==='Độc giả'&&state.cart.length){
    const summary=app.querySelector('.summary-card');
    if(summary)summary.insertAdjacentHTML('afterbegin',window.libraryFeatureApi.capabilities.reservationWorkflow&&window.libraryFeatureApi.capabilities.payments?`<fieldset class="reservation-method-picker"><legend>Phương thức dự kiến</legend><label><input type="radio" name="reservation_method" value="bank_transfer" checked> Chuyển khoản mô phỏng (không chuyển tiền thật)</label><label><input type="radio" name="reservation_method" value="cash"> Tiền mặt tại thư viện</label><small>Nhân viên duyệt yêu cầu trước; bạn chỉ thanh toán sau khi yêu cầu được duyệt.</small></fieldset>`:`<div class="feature-notice reservation-workflow-note" role="status"><span aria-hidden="true">◷</span><div><strong>Thanh toán sau khi duyệt</strong><p>Yêu cầu mới sẽ hiển thị phương thức thanh toán sau khi dịch vụ được bật.</p></div></div>`);
  }
  if(typeof syncSidebar==='function')syncSidebar();
  if(state.page==='book-detail'){const community=document.getElementById('book-community');if(community)community.innerHTML=authCommunityForms();}
  const isSignedIn=!!authSession;
  document.getElementById('login-shortcut').hidden=isSignedIn;
  const logoutShortcut=document.getElementById('logout-shortcut');if(logoutShortcut)logoutShortcut.hidden=!isSignedIn;
  if(state.page==='profile'){
    const profileEmail=app.querySelector('#email');if(profileEmail)profileEmail.value=state.email||'minhanh@example.com';
    const profileEmailText=app.querySelector('.profile-identity small');if(profileEmailText)profileEmailText.textContent=state.email||'minhanh@example.com';
    const profileNote=app.querySelector('.profile-note');if(profileNote)profileNote.textContent=authSession?'Thông tin hồ sơ được lưu trên máy chủ.':'Bản xem thử lưu thay đổi trong trình duyệt.';
    if(authSession?.role!=='Độc giả'){app.querySelector('.member-tag')?.remove();app.querySelector('.profile-stats')?.remove();}
    const profile=authServerData.profile;
    if(profile){for(const [field,value] of Object.entries({phone:profile.phone||'',birthday:profile.date_of_birth||'',address:profile.address||''})){const input=app.querySelector(`[name="${field}"]`);if(input)input.value=value;}if(profile.avatar_path){state.avatar=profile.avatar_url||`/storage/${profile.avatar_path}`;const preview=app.querySelector('#avatar-preview');if(preview){preview.src=state.avatar;preview.hidden=false;}}}
    if(authSession)app.querySelector('[data-auth]')?.remove();
  }
  authSyncChrome();
};

function authSyncChrome(){
  const role=authSession?.role||'Độc giả',isAdmin=role==='Admin',isLibrarian=role==='Thủ thư',isStaff=isAdmin||isLibrarian;
  document.getElementById('reader-nav').hidden=isStaff;
  document.getElementById('librarian-nav').hidden=!isLibrarian;
  document.getElementById('admin-nav').hidden=!isAdmin;
  const navCaption=document.getElementById('nav-caption');
  navCaption.hidden=isLibrarian;
  navCaption.textContent=isAdmin?'QUẢN TRỊ HỆ THỐNG':'KHÔNG GIAN ĐỌC';
  document.querySelectorAll('#reader-nav [data-page]').forEach(link=>{link.hidden=!authSession&&authPrivatePages.has(link.dataset.page);});
  document.querySelector('.global-search-wrap').hidden=isStaff;
  document.getElementById('sidebar-help').hidden=isStaff;
  const sidebarRole=document.querySelector('.profile-copy small');if(sidebarRole)sidebarRole.textContent=!authSession?'Chưa đăng nhập':role==='Admin'?'Quản trị thư viện':role==='Thủ thư'?'Nhân viên thư viện':'Thành viên thân thiết';
  const sidebarName=document.querySelector('.profile-copy strong');if(sidebarName)sidebarName.textContent=authSession?.name||'Khách';
  const initials=(authSession?.name||'K').trim().split(/\s+/).slice(-2).map(part=>part[0]).join('').toLocaleUpperCase('vi');
  document.querySelectorAll('.sidebar-profile .avatar,.top-avatar,.profile-big-avatar').forEach(avatar=>{if(state.avatar){avatar.innerHTML=`<img src="${authEscape(state.avatar)}" alt="Ảnh đại diện" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">`;}else{avatar.textContent=initials||'K';}});
  const topAvatar=document.querySelector('.top-avatar');if(topAvatar)topAvatar.setAttribute('aria-label',`Mở menu tài khoản: ${state.name}`);
  accountFeatures.syncMenu();
}

async function authPasswordDigest(password,saltHex){
  if(!globalThis.crypto?.subtle)throw new Error('Không hỗ trợ mã hóa mật khẩu trong trình duyệt này.');
  const salt=saltHex?Uint8Array.from(saltHex.match(/.{2}/g)||[],byte=>parseInt(byte,16)):crypto.getRandomValues(new Uint8Array(16));
  const key=await crypto.subtle.importKey('raw',new TextEncoder().encode(password),'PBKDF2',false,['deriveBits']);
  const digest=await crypto.subtle.deriveBits({name:'PBKDF2',salt,iterations:120000,hash:'SHA-256'},key,256);
  const passwordHash=Array.from(new Uint8Array(digest),byte=>byte.toString(16).padStart(2,'0')).join('');
  const saltValue=Array.from(salt,byte=>byte.toString(16).padStart(2,'0')).join('');
  return {passwordHash,salt:saltValue};
}

async function authPost(url,formData){
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const response=await fetch(url,{method:'POST',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},body:formData,credentials:'same-origin'});
  const payload=await response.json().catch(()=>({}));
  if(payload.csrf_token){const meta=document.querySelector('meta[name="csrf-token"]');if(meta)meta.content=payload.csrf_token;}
  if(!response.ok){const errors=payload.errors||{};const first=Object.entries(errors).find(([,messages])=>messages?.length);const error=new Error(first?.[1]?.[0]||payload.message||'Không thể xử lý yêu cầu.');error.field=first?.[0]||'email';throw error;}
  return payload;
}

function authShowBookPreviewForm(bookId){
  const book=state.adminBooks.find(item=>Number(item.id)===Number(bookId));if(!book)return;
  showModal(`<form id="book-preview-form" class="login-form" data-book-id="${book.id}"><div class="eyebrow">BẢN ĐỌC THỬ</div><h2 id="modal-title">${authEscape(book.title)}</h2><p>Tải lên tối đa 3 ảnh trang sách, theo đúng thứ tự. Hỗ trợ JPG, PNG hoặc WebP; tối đa 2 MB mỗi trang.${book.preview_page_count?` Đang có ${book.preview_page_count} trang; tải lên sẽ thay bản hiện tại.`:''}</p><div class="field"><label for="preview-pages">Ảnh trang 1–3</label><input id="preview-pages" name="pages[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required></div><small class="community-error" data-preview-error></small><button class="primary-button" type="submit">Lưu bản đọc thử</button>${book.preview_page_count?'<button class="secondary-button" type="button" data-preview-delete="'+book.id+'">Xóa bản đọc thử hiện tại</button>':''}</form>`);
}

let authSearchTimer=null,authSearchController=null;
function authCloseSearch(){const box=document.getElementById('global-search-results'),input=document.getElementById('global-search');if(box)box.hidden=true;if(input){input.closest('.global-search')?.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');}}
function authRenderSearchSuggestions(rows,message=''){
  const box=document.getElementById('global-search-results'),input=document.getElementById('global-search');if(!box||!input)return;
  const suggestions=message?`<p class="global-search-message">${authEscape(message)}</p>`:rows.length?rows.map(book=>{
    const coverPath=book.cover_image_path?`/storage/${String(book.cover_image_path).split('/').map(encodeURIComponent).join('/')}`:'';
    const cover=coverPath?`<img class="global-search-result-cover" src="${authEscape(coverPath)}" alt="" loading="lazy">`:`<span class="global-search-result-cover global-search-result-cover-fallback" aria-hidden="true">${authEscape((book.title||'S').slice(0,1))}</span>`;
    const author=(book.authors||[]).map(item=>item.name).join(', ')||'Chưa cập nhật tác giả';
    return `<button class="global-search-result" type="button" role="option" data-search-book="${book.id}">${cover}<span class="global-search-result-copy"><strong>${authEscape(book.title)}</strong><small>${authEscape(author)}</small></span><b class="global-search-result-price">${fmtPrice(Number(book.rental_fee)||0)}</b></button>`;
  }).join(''):'<p class="global-search-message">Không tìm thấy sách phù hợp.</p>';
  const query=input.value.trim();
  const showAll=query&&message!=='Đang tìm sách…'?`<button class="global-search-all" type="button" data-search-all>Xem tất cả cuốn sách cho “${authEscape(query)}”</button>`:'';
  box.innerHTML=suggestions+showAll;
  box.hidden=false;input.closest('.global-search')?.setAttribute('aria-expanded','true');
}
function authFindSuggestions(query){
  clearTimeout(authSearchTimer);authSearchController?.abort();if(!query.trim()){authCloseSearch();return;}
  authRenderSearchSuggestions([],'Đang tìm sách…');const controller=new AbortController();authSearchController=controller;
  authSearchTimer=setTimeout(async()=>{try{const response=await fetch(`/catalog/books?q=${encodeURIComponent(query.trim())}&per_page=6`,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',signal:controller.signal});if(!response.ok)throw new Error('Không thể tải gợi ý.');const payload=await response.json();authRenderSearchSuggestions(payload.data||[],(payload.data||[]).length?'':'Không tìm thấy sách phù hợp.');}catch(error){if(error.name!=='AbortError')authRenderSearchSuggestions([],error.message||'Không thể tải gợi ý.');}},260);
}
async function authRunCatalogSearch(query){
  const term=String(query||'').trim();if(!term){toast('Nhập tên sách, tác giả hoặc mã sách để tìm.');return;}
  authCloseSearch();state.query=term;state.catalogDraft=term;state.catalogCategory='';state.catalogSort='relevance';state.catalogPage=1;state.catalogMeta={total:0,last_page:1};
  if(state.page==='catalog'){history.pushState(null,'',`#catalog?q=${encodeURIComponent(term)}&page=1`);render();}
  else go('catalog');
  await authLoadCatalog(term,1,true);
}
document.addEventListener('click',event=>{
  const confirmLedger=event.target.closest('[data-op-confirm-ledger]');if(confirmLedger){event.preventDefault();event.stopImmediatePropagation();const payment=opData.payments.find(item=>String(item.id)===confirmLedger.dataset.opConfirmLedger);if(!payment)return;const method=payment.method;if(!((method==='cash'&&payment.raw_status==='pending')||(method==='bank_transfer'&&payment.raw_status==='confirming')))return;const confirmation=method==='cash'?'Chỉ xác nhận sau khi đã trực tiếp nhận đủ tiền mặt.':'Chỉ xác nhận sau khi đã kiểm tra giao dịch chuyển khoản mô phỏng.';if(!window.confirm(confirmation))return;confirmLedger.disabled=true;authRequest(`/staff/payments/${encodeURIComponent(payment.id)}/confirm`,{method:'POST',body:{method}}).then(async()=>{await authRefreshStaffData();toast('Đã xác nhận khoản thu.');}).catch(error=>toast(error.message||'Không thể xác nhận khoản thu.')).finally(()=>{if(confirmLedger.isConnected)confirmLedger.disabled=false;});return;}
  const showAll=event.target.closest('[data-search-all]');if(!showAll)return;
  event.preventDefault();event.stopImmediatePropagation();authRunCatalogSearch(document.getElementById('global-search')?.value||'');
});
document.getElementById('global-search').addEventListener('focus',event=>authFindSuggestions(event.target.value));
document.getElementById('global-search').addEventListener('input',event=>authFindSuggestions(event.target.value));
document.addEventListener('click',event=>{if(!event.target.closest('.global-search-wrap'))authCloseSearch();});
document.addEventListener('keydown',event=>{
  if(event.target.id==='global-search'){
    if(event.isComposing||event.keyCode===229)return;
    if(event.key==='Enter'){event.preventDefault();event.stopImmediatePropagation();authRunCatalogSearch(event.target.value);}
    if(event.key==='Escape'){authCloseSearch();}
  }
},true);
document.addEventListener('submit',event=>{
  if(event.target.id==='catalog-search-form'){event.preventDefault();event.stopImmediatePropagation();authRunCatalogSearch(event.target.querySelector('#pro-search')?.value||'');}
},true);
document.addEventListener('change',event=>{
  if(event.target.id!=='catalog-category'&&event.target.id!=='catalog-sort')return;
  if(event.target.id==='catalog-category')state.catalogCategory=event.target.value;
  else state.catalogSort=event.target.value;
  state.catalogPage=1;go('catalog');authLoadCatalog(state.query,1,true);
});

document.addEventListener('submit',async event=>{
  if(event.target.id==='book-preview-form'){
    event.preventDefault();event.stopImmediatePropagation();const form=event.target,files=form.querySelector('[name="pages[]"]')?.files;
    if(!files?.length||files.length>3){form.querySelector('[data-preview-error]').textContent='Chọn từ 1 đến 3 ảnh trang.';return;}
    const payload=new FormData();Array.from(files).forEach(file=>payload.append('pages[]',file));
    try{await authRequest(`/staff/books/${form.dataset.bookId}/preview`,{method:'POST',body:payload});closeModal();await authRefreshCatalog();toast('Đã lưu bản đọc thử.');}
    catch(error){form.querySelector('[data-preview-error]').textContent=error.message||'Không thể lưu bản đọc thử.';}
    return;
  }
  if(event.target.id==='api-author-form'||event.target.id==='api-reader-form'){
    event.preventDefault();event.stopImmediatePropagation();const form=event.target,values=new FormData(form),id=form.dataset.entityId,author=form.id==='api-author-form';
    const payload=author?{name:values.get('name'),biography:values.get('biography')}:{name:values.get('name'),email:values.get('email'),phone:values.get('phone')};
    if(!author&&!id){payload.password=values.get('password');payload.password_confirmation=values.get('password_confirmation');}
    const route=author?'authors':'readers';
    try{await authRequest(id?`/staff/${route}/${id}`:`/staff/${route}`,{method:id?'PATCH':'POST',body:payload});closeModal();await authRefreshStaffData();toast(author?'Đã lưu tác giả trên máy chủ.':'Đã lưu độc giả trên máy chủ.');}
    catch(error){toast(error.message||'Không thể lưu bản ghi.');}
    return;
  }
  if(event.target.id==='api-account-form'){
    event.preventDefault();event.stopImmediatePropagation();const form=event.target,values=new FormData(form),id=form.dataset.accountId;const payload={name:values.get('name'),email:values.get('email'),role:values.get('role'),is_locked:values.get('is_locked')==='1'};if(values.get('password'))payload.password=values.get('password');
    try{await authRequest(id?`/staff/accounts/${id}`:'/staff/accounts',{method:id?'PATCH':'POST',body:payload});closeModal();await authRefreshStaffData();toast('Đã lưu tài khoản trên máy chủ.');}
    catch(error){toast(error.message||'Không thể lưu tài khoản.');}
    return;
  }
  if(event.target.id==='api-fine-payment-form'){
    event.preventDefault();event.stopImmediatePropagation();const values=new FormData(event.target),id=event.target.dataset.fineId;
    try{await authRequest(`/staff/fines/${id}/payments`,{method:'POST',body:{amount:values.get('amount'),method:values.get('method'),receipt_note:values.get('receipt_note')}});closeModal();await authRefreshStaffData();toast('Đã ghi nhận khoản thu.');}
    catch(error){toast(error.message||'Không thể ghi nhận thanh toán.');}
    return;
  }
  if(event.target.id==='api-loan-form'){
    event.preventDefault();event.stopImmediatePropagation();const values=new FormData(event.target);
    const quantities=new Map();for(const row of event.target.querySelectorAll('.loan-book-row')){const bookId=Number(row.querySelector('[name=book_id]').value),quantity=Number(row.querySelector('[name=quantity]').value)||0;quantities.set(bookId,(quantities.get(bookId)||0)+quantity);}
    const items=[...quantities].map(([book_id,quantity])=>({book_id,quantity}));
    try{await authRequest('/staff/loans',{method:'POST',body:{reader_id:Number(values.get('reader_id')),items,rental_fee_payment:{amount:values.get('amount'),method:values.get('method'),receipt_note:values.get('receipt_note')}}});closeModal();await authRefreshStaffData();await authRefreshCatalog();toast('Đã xác nhận thu phí và giao sách.');}
    catch(error){toast(error.message||'Không thể lập phiếu mượn.');}
    return;
  }
  if(event.target.id==='api-reservation-fulfill-form'){
    event.preventDefault();event.stopImmediatePropagation();const values=new FormData(event.target),id=event.target.dataset.reservationId;
    try{await authRequest(`/staff/reservations/${id}/fulfill`,{method:'POST',body:{rental_fee_payment:{amount:values.get('amount'),method:values.get('method'),receipt_note:values.get('receipt_note')}}});closeModal();await authRefreshStaffData();await authRefreshCatalog();toast('Đã xác nhận thu phí và giao sách.');}
    catch(error){toast(error.message||'Không thể giao sách.');}
    return;
  }
  if(event.target.id==='api-return-form'){
    event.preventDefault();event.stopImmediatePropagation();const values=new FormData(event.target),items=[];
    for(const [key,value] of values.entries()){if(key.startsWith('item-')&&Number(value)>0)items.push({loan_item_id:Number(key.slice(5)),quantity:Number(value)});}
    if(!items.length){toast('Nhập số lượng sách cần trả.');return;}
    try{const result=await authRequest(`/staff/loans/${event.target.dataset.loanId}/returns`,{method:'POST',body:{items}});closeModal();await authRefreshStaffData();await authRefreshCatalog();const fine=(result.fines||[]).reduce((sum,row)=>sum+Number(row.amount||0),0);toast(fine?`Đã nhận sách; khoản phạt ${fmtPrice(fine)} do máy chủ tính.`:'Đã ghi nhận trả sách.');}
    catch(error){toast(error.message||'Không thể ghi nhận trả sách.');}
    return;
  }
  if(event.target.id==='book-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,values=new FormData(form),id=form.dataset.editId;
  const payload={book_code:String(values.get('book_code')||'').trim(),title:String(values.get('title')||'').trim(),category:String(values.get('category')||'').trim(),total_quantity:Number(values.get('total_quantity')),rental_fee:String(values.get('rental_fee')),author_ids:values.getAll('author_ids').map(Number)};
    try{await authRequest(id?`/staff/books/${id}`:'/staff/books',{method:id?'PATCH':'POST',body:payload});await authRefreshCatalog();closeModal();if(state.page==='admin')render();toast(id?'Đã cập nhật sách trên máy chủ.':'Đã thêm sách trên máy chủ.');}
    catch(error){toast(error.message||'Không thể lưu sách.');}
    return;
  }
  if(event.target.id==='book-review-form'||event.target.id==='book-comment-form'){
    event.preventDefault();event.stopImmediatePropagation();const form=event.target,values=new FormData(form),bookId=Number(state.bookId);
    const review=form.id==='book-review-form',payload=review?{rating:Number(values.get('rating')),title:values.get('title'),body:values.get('body')}:{body:values.get('body')};
    const errorNode=form.querySelector(`[data-error-for="${review?'review':'comment'}"]`);if(errorNode)errorNode.textContent='';const submit=form.querySelector('[type="submit"]');if(submit)submit.disabled=true;
    try{await authRequest(`/books/${bookId}/${review?'reviews':'comments'}`,{method:'POST',body:payload});toast(review?'Đã gửi đánh giá.':'Đã gửi bình luận.');await authLoadBookCommunity(bookId);}
    catch(error){if(errorNode)errorNode.textContent=error.message||'Không thể gửi nội dung.';}
    finally{if(submit)submit.disabled=false;}
    return;
  }
  if(event.target.id==='profile-form'){
    event.preventDefault();event.stopImmediatePropagation();
    if(authProfileSaving)return;
    const form=event.target,identity=authSession?.id,submit=form.querySelector('[type="submit"]'),errorNode=form.querySelector('#profile-error');
    authProfileSaving=true;if(submit)submit.disabled=true;if(errorNode)errorNode.textContent='';
    const values=new FormData(form),avatar=values.get('avatar');values.set('date_of_birth',String(values.get('birthday')||''));values.delete('birthday');values.delete('role');
    if(!window.libraryFeatureApi.capabilities.gender)values.delete('gender');
    else if(values.get('gender')==='')values.set('gender','');
    const hasAvatar=avatar instanceof File&&avatar.size>0;
    const body=hasAvatar?values:{name:values.get('name'),email:values.get('email'),phone:values.get('phone'),address:values.get('address'),date_of_birth:values.get('date_of_birth')};
    if(!hasAvatar&&window.libraryFeatureApi.capabilities.gender)body.gender=values.get('gender')||null;
    const method=hasAvatar?'POST':'PATCH';
    if(hasAvatar)values.set('_method','PATCH');
    try{const result=await authRequest('/my/profile',{method,body});if(authSession?.id!==identity)return;if(!result.user||!result.profile)throw new Error('Chưa nhận được thông tin hồ sơ đã cập nhật.');authSession={...authSession,...result.user};window.libraryUser=authSession;state.name=result.user.name;state.email=result.user.email;authServerData.profile=result.profile;state.avatar=result.profile.avatar_url||'';if(!state.avatar){saveStore('may-avatar','');}accountFeatures.revokeAvatar();go('profile');toast('Đã cập nhật hồ sơ trên máy chủ.');}
    catch(error){if(authSession?.id===identity){if(errorNode)errorNode.textContent=error.message||'Không thể cập nhật hồ sơ.';else toast(error.message);}}
    finally{authProfileSaving=false;if(submit?.isConnected)submit.disabled=false;}
    return;
  }
  if(event.target.id!=='auth-form')return;
  event.preventDefault();event.stopImmediatePropagation();
  const form=event.target,mode=form.dataset.authMode,values=new FormData(form),email=String(values.get('email')||'').trim().toLowerCase();
  const setError=(name,message)=>{const node=form.querySelector(`[data-error-for="${name}"]`);if(node)node.textContent=message;};
  form.querySelectorAll('.auth-error').forEach(node=>node.textContent='');
  if(mode==='forgot'){if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){setError('email','Vui lòng nhập email hợp lệ.');return;}try{const result=await authRequest('/auth/forgot-password',{method:'POST',body:{email}});toast(result.message||'Nếu email đã được đăng ký, hướng dẫn sẽ được gửi.');}catch(error){setError('email',error.message||'Không thể gửi hướng dẫn lúc này.');}return;}
  const password=String(values.get('password')||'');
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){setError('email','Vui lòng nhập email hợp lệ.');return;}
  if(password.length<8){setError('password','Mật khẩu cần có ít nhất 8 ký tự.');return;}
  const payload=new FormData(form);
  if(mode==='register'){
    payload.set('email',email);
    payload.set('password_confirmation',String(values.get('confirm')||''));
  }
  try{
    const result=await authPost(window.libraryAuthUrls[mode==='register'?'register':'login'],payload);
    authSetIdentity(result.user);
    await authRefreshAppData();
    const requested=sessionStorage.getItem('may-next-route');sessionStorage.removeItem('may-next-route');
    const destination=requested||(result.user.role==='Admin'?'admin':result.user.role==='Thủ thư'?'librarian-home':'home');
    toast(mode==='register'?'Tạo tài khoản thành công.':`Đăng nhập thành công · ${result.user.role}`);go(destination);
  }catch(error){setError(error.field==='password_confirmation'?'confirm':error.field||'email',error.message);}
},true);

async function authSubmitReservation(){
  if(!authSession){toast('Vui lòng đăng nhập để gửi yêu cầu.');go('login');return;}
  if(!state.cart.length){toast('Giỏ sách đang trống.');render();return;}
  if(authReservationSubmitBusy)return;
  authReservationSubmitBusy=true;
  const button=document.querySelector('[data-checkout]');if(button)button.disabled=true;
  try{const workflow=window.libraryFeatureApi.capabilities.reservationWorkflow&&window.libraryFeatureApi.capabilities.payments;const method=document.querySelector('[name="reservation_method"]:checked')?.value;if(workflow&&!['bank_transfer','cash'].includes(method))throw new Error('Vui lòng chọn phương thức thanh toán dự kiến.');const result=workflow?await window.libraryFeatureApi.submitCartReservation(method):await authRequest('/my/cart/reservations',{method:'POST',body:{}});await authRefreshReaderData();toast(`Đã gửi ${result.reservations?.length||0} yêu cầu đặt trước.`);go('reservations');}
  catch(error){toast(error.message||'Không thể gửi yêu cầu đặt trước.');}
  finally{authReservationSubmitBusy=false;if(button?.isConnected)button.disabled=false;}
}
window.submitReservation=authSubmitReservation;

document.addEventListener('click',event=>{
  const routeLink=event.target.closest('[data-page],[data-page-link]');if(routeLink)accountFeatures.setRoute(routeLink.dataset.page||routeLink.dataset.pageLink);
  const startPreview=event.target.closest('[data-open-preview]');if(startPreview){event.preventDefault();const id=Number(startPreview.dataset.openPreview);state.readerBookId=id;state.readerPreviewPage=1;saveStore('may-reader-book',id);go('reader');return;}
  const searchSuggestion=event.target.closest('[data-search-book]');if(searchSuggestion){event.preventDefault();event.stopImmediatePropagation();authCloseSearch();openBook(Number(searchSuggestion.dataset.searchBook));return;}
  const previewUpload=event.target.closest('[data-preview-upload]');if(previewUpload){event.preventDefault();event.stopImmediatePropagation();authShowBookPreviewForm(previewUpload.dataset.previewUpload);return;}
  const previewDelete=event.target.closest('[data-preview-delete]');if(previewDelete){event.preventDefault();event.stopImmediatePropagation();authRequest(`/staff/books/${previewDelete.dataset.previewDelete}/preview`,{method:'DELETE'}).then(()=>{closeModal();return authRefreshCatalog();}).then(()=>toast('Đã xóa bản đọc thử.')).catch(error=>{const node=document.querySelector('[data-preview-error]');if(node)node.textContent=error.message;});return;}
  const communityTab=event.target.closest('[data-community-tab]');if(communityTab){state.communityTab=communityTab.dataset.communityTab;render();return;}
  if(event.target.closest('[data-community-retry]')){authLoadBookCommunity(state.bookId);return;}
  const deleteComment=event.target.closest('[data-delete-comment]');if(deleteComment){authRequest(`/comments/${deleteComment.dataset.deleteComment}`,{method:'DELETE'}).then(()=>authLoadBookCommunity(state.bookId)).then(()=>toast('Đã xóa bình luận.')).catch(error=>toast(error.message));return;}
  const protectedAction=event.target.closest('[data-add-cart],[data-remove-cart],[data-favorite],[data-reader-book],[data-checkout],[data-unlock]');
  if(protectedAction&&!authSession){
    event.preventDefault();event.stopImmediatePropagation();
    const currentRoute=location.hash.slice(1);
    if(/^(book|reader)\/\d+$/.test(currentRoute)||labels[currentRoute])sessionStorage.setItem('may-next-route',currentRoute);
    toast('Vui lòng đăng nhập để sử dụng chức năng này.');go('login');return;
  }
  const deleteBookButton=event.target.closest('[data-delete-book]');
  if(deleteBookButton){event.preventDefault();event.stopImmediatePropagation();const book=state.adminBooks.find(item=>Number(item.id)===Number(deleteBookButton.dataset.deleteBook));if(book&&confirm(`Xóa “${book.title}” khỏi danh mục?`))authRequest(`/staff/books/${book.id}`,{method:'DELETE'}).then(()=>authRefreshCatalog()).then(()=>{if(state.page==='admin')render();toast('Đã xóa mềm sách trên máy chủ.');}).catch(error=>toast(error.message));return;}
  const addCartButton=event.target.closest('[data-add-cart]');
  if(addCartButton){event.preventDefault();event.stopImmediatePropagation();authRequest('/my/cart/items',{method:'POST',body:{book_id:Number(addCartButton.dataset.addCart),quantity:1}}).then(()=>authRefreshReaderData()).then(()=>toast('Đã thêm sách vào giỏ.')).catch(error=>toast(error.message));return;}
  const removeCartButton=event.target.closest('[data-remove-cart]');
  if(removeCartButton){event.preventDefault();event.stopImmediatePropagation();const item=authServerData.cartItems.find(row=>Number(row.book_id)===Number(removeCartButton.dataset.removeCart));if(item)authRequest(`/my/cart/items/${item.id}`,{method:'DELETE'}).then(()=>authRefreshReaderData()).then(()=>toast('Đã xóa sách khỏi giỏ.')).catch(error=>toast(error.message));return;}
  const inventoryAdd=event.target.closest('[data-op-add="inventory"]');if(inventoryAdd){event.preventDefault();event.stopImmediatePropagation();openBookForm();return;}
  const inventoryEdit=event.target.closest('[data-op-edit^="inventory:"]');if(inventoryEdit){event.preventDefault();event.stopImmediatePropagation();openBookForm(inventoryEdit.dataset.opEdit.split(':')[1]);return;}
  const addOperation=event.target.closest('[data-op-add="loans"]');if(addOperation){event.preventDefault();event.stopImmediatePropagation();authShowLoanForm();return;}
  const unsupportedOperation=event.target.closest('[data-op-add="reservations"],[data-op-add="fines"],[data-op-add="returns"],[data-op-delete^="inventory:"],[data-op-edit^="reservations:"],[data-op-delete^="reservations:"],[data-op-edit^="loans:"],[data-op-delete^="loans:"],[data-op-edit^="fines:"],[data-op-delete^="fines:"]');
  if(unsupportedOperation){event.preventDefault();event.stopImmediatePropagation();toast(unsupportedOperation.dataset.opAdd==='inventory'||unsupportedOperation.dataset.opEdit?.startsWith('inventory:')?'Tồn kho được suy ra từ sách đang mượn và giữ; hãy sửa tổng số bản ở danh mục sách.':'Thao tác này không có API lưu an toàn nên chưa được ghi nhận.');return;}
  const addAccount=event.target.closest('[data-op-add="accounts"]');if(addAccount){event.preventDefault();event.stopImmediatePropagation();authShowAccountForm();return;}
  const addStaffEntity=event.target.closest('[data-op-add="authors"],[data-op-add="readers"]');if(addStaffEntity){event.preventDefault();event.stopImmediatePropagation();authShowStaffEntityForm(addStaffEntity.dataset.opAdd);return;}
  const editAccount=event.target.closest('[data-op-edit^="accounts:"]');if(editAccount){event.preventDefault();event.stopImmediatePropagation();authShowAccountForm(editAccount.dataset.opEdit.split(':')[1]);return;}
  const editStaffEntity=event.target.closest('[data-op-edit^="authors:"],[data-op-edit^="readers:"]');if(editStaffEntity){event.preventDefault();event.stopImmediatePropagation();const [key,id]=editStaffEntity.dataset.opEdit.split(':');authShowStaffEntityForm(key,id);return;}
  const deleteStaffEntity=event.target.closest('[data-op-delete^="authors:"],[data-op-delete^="readers:"]');if(deleteStaffEntity){event.preventDefault();event.stopImmediatePropagation();const [key,id]=deleteStaffEntity.dataset.opDelete.split(':');if(confirm('Xóa mềm bản ghi này trên máy chủ?'))authRequest(`/staff/${key}/${id}`,{method:'DELETE'}).then(()=>authRefreshStaffData()).then(()=>toast('Đã xóa mềm bản ghi.')).catch(error=>toast(error.message));return;}
  const deleteAccount=event.target.closest('[data-op-delete^="accounts:"]');if(deleteAccount){event.preventDefault();event.stopImmediatePropagation();toast('Tài khoản được khóa thay vì xóa để giữ lịch sử giao dịch.');return;}
  const readyReservation=event.target.closest('[data-op-approve]');if(readyReservation){event.preventDefault();event.stopImmediatePropagation();const row=opData.reservations.find(item=>String(item.id)===readyReservation.dataset.opApprove);const run=window.libraryFeatureApi.capabilities.reservationWorkflow?window.libraryFeatureApi.reservationAction(readyReservation.dataset.opApprove,'approve',{payment_method:row?.payment_method}):authRequest(`/staff/reservations/${readyReservation.dataset.opApprove}/ready`,{method:'POST',body:{}});readyReservation.disabled=true;run.then(()=>authRefreshStaffData()).then(()=>authRefreshCatalog()).then(()=>toast('Đã duyệt yêu cầu và bắt đầu thời hạn 48 giờ.')).catch(error=>toast(error.message)).finally(()=>{readyReservation.disabled=false});return;}
  const paymentAction=event.target.closest('[data-op-confirm-payment],[data-op-handover]');if(paymentAction){event.preventDefault();event.stopImmediatePropagation();const id=paymentAction.dataset.opConfirmPayment||paymentAction.dataset.opHandover;const row=opData.reservations.find(item=>String(item.id)===String(id));const action=paymentAction.hasAttribute('data-op-handover')?'handover':'confirm-payment';const method=row?.payment_method==='bank_transfer'?'chuyển khoản mô phỏng':'tiền mặt';const prompt=action==='handover'?`Xác nhận đã giao sách cho ${row?.reader||'độc giả'}? Phí đã được ghi nhận, thao tác này không thu lại tiền.`:`Xác nhận đã nhận ${method} cho yêu cầu #${id}${row?.amount!=null?` · ${authMoney(row.amount)}`:''}?`;if(!confirm(prompt))return;paymentAction.disabled=true;window.libraryFeatureApi.reservationAction(id,action,{method:row?.payment_method}).then(()=>authRefreshStaffData()).then(()=>{if(action==='handover')authRefreshCatalog();toast(action==='handover'?'Đã xác nhận giao sách.':'Đã xác nhận thanh toán từ API thư viện.');}).catch(error=>toast(error.message)).finally(()=>{paymentAction.disabled=false});return;}
  const fulfillReservation=event.target.closest('[data-op-fulfill]');if(fulfillReservation){event.preventDefault();event.stopImmediatePropagation();authShowReservationFulfill(fulfillReservation.dataset.opFulfill);return;}
  const returnLoan=event.target.closest('[data-op-return]');if(returnLoan){event.preventDefault();event.stopImmediatePropagation();authShowLoanReturn(returnLoan.dataset.opReturn);return;}
  const payFine=event.target.closest('[data-op-pay]');if(payFine){event.preventDefault();event.stopImmediatePropagation();authShowFinePayment(payFine.dataset.opPay);return;}
  const activeAuthForm=event.target.closest('#auth-form');
  if(activeAuthForm){
    const switchButton=event.target.closest('[data-auth-switch]');
    if(switchButton){event.preventDefault();event.stopImmediatePropagation();go(switchButton.dataset.authSwitch==='register'?'register':switchButton.dataset.authSwitch==='forgot'?'forgot':'login');return;}
    const visibility=event.target.closest('[data-password-toggle]');
    if(visibility){event.preventDefault();event.stopImmediatePropagation();const input=visibility.parentElement.querySelector('input');input.type=input.type==='password'?'text':'password';visibility.textContent=input.type==='password'?'Hiện':'Ẩn';visibility.setAttribute('aria-label',input.type==='password'?'Hiện mật khẩu':'Ẩn mật khẩu');return;}
    event.stopImmediatePropagation();
    return;
  }
  const oldAuth=event.target.closest('[data-auth]');
  if(oldAuth){event.preventDefault();event.stopImmediatePropagation();go('login');return;}
  const oldAuthMode=event.target.closest('[data-auth-mode]');
  if(oldAuthMode&&!event.target.closest('#auth-form')){event.preventDefault();event.stopImmediatePropagation();go(oldAuthMode.dataset.authMode==='register'?'register':'login');return;}
  if(event.target.closest('[data-auth-logout]')){
    event.preventDefault();event.stopImmediatePropagation();
    if(authLogoutBusy)return;authLogoutBusy=true;
    authPost(window.libraryAuthUrls.logout,new FormData()).then(()=>{
      authPersistAccountData();authSession=null;window.libraryUser=null;authActiveEmail=null;
      accountFeatures.reset(null);authServerData.profile=null;authServerData.profileLoading=false;authServerData.profileError='';authServerData.reservations=[];authServerData.cartItems=[];authServerData.loans=[];authServerData.fines=[];authServerData.favoriteBooks=[];loanItems.splice(0);
      state.role='Độc giả';state.name='Khách';state.email='';state.cart=[];state.favorites=[];state.avatar='';
      saveStore('may-avatar','');saveStore('may-role',state.role);saveStore('may-cart',state.cart);saveStore('may-favorites',state.favorites);
      toast('Bạn đã đăng xuất.');go('home');
    }).catch(error=>toast(error.message||'Không thể đăng xuất.')).finally(()=>{authLogoutBusy=false;});
  }
},true);

document.addEventListener('click',event=>{
  const favorite=event.target.closest('[data-favorite]');
  if(favorite){const id=Number(favorite.dataset.favorite);if(state.favorites.includes(id)){const book=state.adminBooks.find(item=>Number(item.id)===id);if(book&&!authServerData.favoriteBooks.some(item=>Number(item?.id)===id))authServerData.favoriteBooks.push(book);}else{authServerData.favoriteBooks=authServerData.favoriteBooks.filter(item=>Number(item?.id)!==id);}}
  if(event.target.closest('[data-add-cart],[data-remove-cart],[data-favorite]'))authPersistAccountData();
});
document.addEventListener('input',event=>{if(event.target.closest('#auth-form')){const error=event.target.closest('.field')?.querySelector('.auth-error');if(error)error.textContent='';}const counterId=event.target.dataset.countFor;if(counterId){const counter=document.getElementById(counterId);if(counter)counter.textContent=event.target.value.length;const error=event.target.closest('form')?.querySelector('.community-error');if(error)error.textContent='';}});

function authSyncRoute(){
  const [routeValue,query='']=location.hash.slice(1).split('?');let route=routeValue||'home';
  const feature=accountFeatures.resolve(route);accountFeatures.setRoute(route);
  if(feature?.page==='checkout'){
    if(!authSession){sessionStorage.setItem('may-next-route',route);state.page='login';history.replaceState(null,'','#login');render();return;}
    state.page='checkout';render();return;
  }
  if(route==='catalog'){const params=new URLSearchParams(query);state.query=params.get('q')||'';state.catalogDraft=state.query;state.catalogPage=Math.max(1,Number(params.get('page'))||1);}
  const dynamic=route.match(/^(book|reader)\/(\d+)$/);
  if(dynamic){proSyncRoute();return;}
  if(authPrivatePages.has(route)&&!authSession){sessionStorage.setItem('may-next-route',route);route='login';}
  if(authAdminPages.has(route)&&authSession?.role!=='Admin')route=authSession?'home':'login';
  if(authLibrarianPages.has(route)&&authSession?.role==='Độc giả')route='home';
  if(route==='librarian-home'&&authSession?.role==='Admin')route='admin';
  state.page=route;render();
}
window.addEventListener('popstate',authSyncRoute);
window.addEventListener('hashchange',()=>{const [route]=location.hash.slice(1).split('?');if(accountFeatures.resolve(route)||/^(book|reader)\/\d+$/.test(route)||route==='catalog'||authPageLabels[route]||authPrivatePages.has(route)||authAdminPages.has(route))authSyncRoute();});

accountFeatures.init();
const [routeAtAuthStart]=location.hash.slice(1).split('?');
const routeAtAuthStartMatch=routeAtAuthStart.match(/^(book|reader)\/(\d+)$/);
if(accountFeatures.resolve(routeAtAuthStart)){authSyncRoute();}
else if(routeAtAuthStartMatch){proSyncRoute();}
else if(authPageLabels[routeAtAuthStart]){state.page=routeAtAuthStart;render();}
else if(authPrivatePages.has(routeAtAuthStart)&&!authSession){sessionStorage.setItem('may-next-route',routeAtAuthStart);state.page='login';history.replaceState(null,'','#login');render();}
else if(authAdminPages.has(routeAtAuthStart)&&authSession?.role!=='Admin'){state.page=authSession?'home':'login';history.replaceState(null,'','#'+state.page);render();}
else if(authLibrarianPages.has(routeAtAuthStart)&&authSession?.role==='Độc giả'){state.page='home';history.replaceState(null,'','#home');render();}
else if(routeAtAuthStart==='home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');render();}
else if(routeAtAuthStart==='home'&&authSession?.role==='Thủ thư'){state.page='librarian-home';history.replaceState(null,'','#librarian-home');render();}
else if(labels[routeAtAuthStart]){state.page=routeAtAuthStart;render();}
else render();
const authOriginalOpenBook=openBook;
openBook=function(id){authOriginalOpenBook(id);authLoadBookDetails(Number(id));authLoadBookCommunity(Number(id));};
async function authLoadBookDetails(bookId){
  try{const response=await authRequest(`/catalog/books/${bookId}`);const book=authNormalizeBook(response.book);const index=state.adminBooks.findIndex(item=>Number(item.id)===Number(bookId));if(index<0)state.adminBooks.unshift(book);else state.adminBooks[index]=book;if(state.page==='book-detail'&&Number(state.bookId)===bookId)render();}
  catch(error){console.error('Không tải được chi tiết sách:',error);}
}
authRefreshAppData().then(()=>{if(state.page==='book-detail')authLoadBookCommunity(state.bookId);});
