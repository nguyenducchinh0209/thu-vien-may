/* Xác thực và dữ liệu nghiệp vụ của Thư viện Mây. */
const authPageLabels = {login:'Đăng nhập',register:'Tạo tài khoản',forgot:'Quên mật khẩu'};
Object.assign(labels, authPageLabels, {'librarian-home':'Bàn làm việc thủ thư','profile-edit':'Chỉnh sửa hồ sơ',vip:'Thành viên VIP',checkout:'Thanh toán',help:'Hướng dẫn sử dụng',reports:'Báo cáo thư viện'});
labels.admin = 'Tổng quan';

let authSession=window.libraryUser||null;
const authServerData={cartItems:[],reservations:[],loans:[],fines:[],readingBooks:[],reviews:[],comments:[],profile:null,profileLoading:!!authSession,profileError:'',favoriteBooks:[]};
let authFavoriteBusy = false;
let authFavoriteVersion = 0;
let authCatalogVersion = 0;
let authProfileSaving=false;
let authLogoutBusy=false;
let authReservationSubmitBusy=false;
if(authSession){state.name=authSession.name;state.role=authSession.role;state.email=authSession.email||'';}
else {state.name='Khách';state.role='Độc giả';state.email='';state.avatar='';}

function authEscape(value){return escapeHTML(value||'')}
function authMoney(value){return fmtPrice(Number(value)||0)}
async function authRequest(url,{method='GET',body=null}={}){
  const headers={'Accept':'application/json','X-Requested-With':'XMLHttpRequest'};
  if(method!=='GET')headers['X-CSRF-TOKEN']=document.querySelector('meta[name="csrf-token"]')?.content||'';
  if(body&&!(body instanceof FormData))headers['Content-Type']='application/json';
  const identity=authSession;
  let response;
  try {
    response=await fetch(url,{method,headers,body:body instanceof FormData?body:body?JSON.stringify(body):undefined,credentials:'same-origin',signal:AbortSignal.timeout(28000)});
  } catch (error) {
    if (error.name==='TimeoutError'||error.name==='AbortError') throw new Error('Máy chủ phản hồi quá lâu. Hãy thử lại.');
    throw new Error('Không kết nối được máy chủ. Hãy kiểm tra kết nối và thử lại.');
  }
  const payload=await response.json().catch(()=>({}));
  if(authSession!==identity) throw new Error('Tài khoản đã thay đổi. Hãy thao tác lại.');
  if(payload.csrf_token){const meta=document.querySelector('meta[name="csrf-token"]');if(meta)meta.content=payload.csrf_token;}
  if(!response.ok){
    const errors=payload.errors||{};
    const first=Object.values(errors).find(messages=>messages?.length);
    const message=[401,419].includes(response.status)?'Phiên đã hết hạn. Tải lại trang và đăng nhập để tiếp tục.':first?.[0]||payload.message||'Không thể xử lý yêu cầu.';
    throw Object.assign(new Error(message),{status:response.status,fields:errors});
  }
  return payload;
}
function authNormalizeBook(book){return {...book,has_epub:book.has_epub===true,author:(book.authors||[]).map(author=>author.name).join(', ')||'Chưa cập nhật tác giả',category:book.category||'Chưa phân loại',type:book.has_epub&&Number(book.total_quantity)===0?'Đọc online':'Sách giấy',price:null,rating:book.reviews_avg_rating?Number(book.reviews_avg_rating).toFixed(1):'—',reads:Number(book.reviews_count)||0,available_quantity:Number(book.available_quantity)||0,preview_page_count:Number(book.preview_page_count)||0,color:Number(book.id)%12+1};}
async function authLoadCatalog(query='',page=1,rerender=false){
  const version=++authCatalogVersion;
  const home=state.page==='home';
  state.catalogLoading=true;
  state.catalogError='';
  if(state.page==='catalog'||home)render();
  try{
    const params=new URLSearchParams({per_page:home?'12':'8',page:String(page)});
    if(query)params.set('q',query);
    if(!home&&state.catalogCategory)params.set('category',state.catalogCategory);
    if(home)params.set('sort','updated_desc');
    else if(state.catalogSort&&state.catalogSort!=='relevance')params.set('sort',state.catalogSort);
    const response=await authRequest('/catalog/books?'+params);
    if(version!==authCatalogVersion)return;
    state.adminBooks=(response.data||[]).map(authNormalizeBook);
    state.catalogCategories=response.categories||[];
    state.catalogMeta={total:response.total||0,last_page:response.last_page||1,current_page:response.current_page||1};
    if(!home)state.catalogPage=state.catalogMeta.current_page;
    state.catalogLoading=false;
    if(rerender||['home','catalog','book-detail','reader'].includes(state.page))render();
  }catch(error){
    if(version!==authCatalogVersion)return;
    state.catalogLoading=false;state.catalogError=error.message;
    if(['home','catalog'].includes(state.page))render();
  }
}
async function authRefreshCatalog(){
  await authLoadCatalog(state.page==='catalog'?state.query:'',state.page==='catalog'?state.catalogPage||1:1,true);
}
async function authRefreshFavorites(){
  if(authSession?.role!=='Độc giả')return;
  const identity=authSession, version=++authFavoriteVersion;
  try{
    const result=await authRequest('/my/favorites');
    if(authSession!==identity||version!==authFavoriteVersion)return;
    authServerData.favoriteBooks=(result.data||[]).map(authNormalizeBook);
    state.favorites=authServerData.favoriteBooks.map(book=>Number(book.id));
    if(['home','catalog','shelf','book-detail'].includes(state.page))render();
  }catch(error){
    if(authSession===identity&&version===authFavoriteVersion)toast(error.message);
  }
}
async function authToggleFavorite(bookId,button){
  if(authSession?.role!=='Độc giả'||authFavoriteBusy)return;
  const identity=authSession;
  const book=state.adminBooks.find(item=>Number(item.id)===bookId);
  const remove=state.favorites.includes(bookId);
  authFavoriteBusy=true;
  button.disabled=true;
  ++authFavoriteVersion;
  try{
    await authRequest(remove?'/my/favorites/'+bookId:'/my/favorites',
      remove?{method:'DELETE'}:{method:'POST',body:{book_id:bookId}});
    if(authSession!==identity)return;
    if(remove){
      state.favorites=state.favorites.filter(id=>id!==bookId);
      authServerData.favoriteBooks=authServerData.favoriteBooks.filter(item=>Number(item.id)!==bookId);
    }else if(book){
      state.favorites=[...new Set([...state.favorites,bookId])];
      authServerData.favoriteBooks=[book,...authServerData.favoriteBooks];
    }
    render();
    toast(remove?'Đã bỏ yêu thích.':'Đã lưu sách yêu thích.');
    await authRefreshFavorites();
  }catch(error){
    if(authSession===identity)toast(error.message);
  }finally{
    authFavoriteBusy=false;
    button.disabled=false;
  }
}
async function authRefreshReaderData(){
  if(!authSession||authSession.role!=='Độc giả')return;
  const identity=authSession.id;
  authServerData.profileLoading=true;authServerData.profileError='';
  try{
    const [cart,reservations,loans,fines,readingBooks,profile]=await Promise.all([authRequest('/my/cart'),authRequest('/my/reservations?per_page=50'),authRequest('/my/loans?per_page=50'),authRequest('/my/fines?per_page=50'),authRequest('/my/reading-books'),authRequest('/my/profile')]);
    if(authSession?.id!==identity)return;
    authServerData.cartItems=cart.cart?.items||[];
    state.cart=authServerData.cartItems.map(item=>Number(item.book_id));
    authServerData.reservations=(reservations.data||[]).map(row=>({...row,book:row.book?.title||'Sách',created:row.created_at,raw_status:row.status,status:authReservationStatus(row,false)}));
    authServerData.loans=loans.data||[];
    authServerData.readingBooks=readingBooks.data||[];
    authServerData.fines=(fines.data||[]).map(row=>({...row,id:row.id,reason:`${row.fine_code||'#'+row.id} · ${row.reason||`Trễ hạn ${row.overdue_days} ngày`}`,created:row.created_at,amount:row.amount,raw_status:row.status,payment_status:row.payment_status||row.payment?.status||null,payment:row.payment||null,status:row.status==='unpaid'?'Chưa thanh toán':row.status==='paid'?'Đã thanh toán':row.status}));
    authServerData.profile=profile.profile||null;
    state.avatar=authServerData.profile?.avatar_url||'';
    const items=authServerData.loans.flatMap(loan=>(loan.items||[]).map(item=>({id:item.id,loanId:loan.id,bookId:item.book_id,book:item.book,due:loan.due_at?new Date(loan.due_at).toLocaleDateString('vi-VN'):'—',status:loan.status==='active'?'Đang mượn':'Đã trả',tone:loan.status==='active'?'':'done'})));
    loanItems.splice(0,loanItems.length,...items);
    syncCounts();if(['cart','shelf','profile','reservations','fines'].includes(state.page)||(state.page==='profile-edit'&&!document.getElementById('profile-form')?.dataset.dirty))render();
  }catch(error){if(authSession?.id===identity)authServerData.profileError=error.message;console.error('Không tải được dữ liệu độc giả:',error);}
  finally{if(authSession?.id===identity){authServerData.profileLoading=false;if(!authServerData.profile&&['profile','profile-edit'].includes(state.page))render();}}
}
function authReservationStatus(row,staff=false){if(row.payment_status==='refunded')return 'Đã hoàn phí';if(row.status==='ready'&&row.expires_at&&Date.parse(row.expires_at)<=Date.now())return row.payment_status==='paid'?'Hết hạn · chờ hoàn phí':'Hết hạn';const base=({pending:'Chờ duyệt',ready:'Đã giữ',fulfilled:'Đã giao',cancelled:'Đã hủy',expired:'Hết hạn'})[row.status]||row.status;if(!window.libraryFeatureApi.capabilities.reservationWorkflow||row.status!=='ready')return base;if(row.payment_status==='paid')return staff?'Thanh toán thành công':'Đã giữ';if(row.payment_status==='confirming')return staff?(row.payment_method==='bank_transfer'?'Cần kiểm tra chuyển khoản mô phỏng':'Chờ xác nhận tiền mặt'):'Chờ xác nhận thanh toán';if(row.payment_status==='unpaid')return staff?'Chờ độc giả thanh toán':'Chờ thanh toán';if(row.payment_status==='pending')return staff?(row.payment_method==='cash'?'Chờ thu tiền mặt':'Chờ khách báo chuyển khoản mô phỏng'):'Chờ xác nhận thanh toán';return staff?'Chưa rõ thanh toán':'Đã giữ · chưa rõ thanh toán';}
function authMapReservation(row){return {id:row.id,reader:row.reader?.name||'',email:row.reader?.email||'',book:row.book?.title||'',book_id:row.book_id,quantity:row.quantity,created:new Date(row.created_at).toLocaleDateString('vi-VN'),raw_status:row.status,payment_status:row.payment_status,payment_method:row.payment_method,amount:row.amount,expires_at:row.expires_at,status:authReservationStatus(row,true)};}
function authMapStaffRow(key, row){
  const date=value=>value?staffVietnamTime(value).split(' ')[0]:'—';
  switch(key){
    case 'reservations': return authMapReservation(row);
    case 'loans':
      return {id:row.id,raw_status:row.status,reader:row.reader_name_snapshot||row.reader?.name||'',
        book:(row.items||[]).map(item=>(item.book_title_snapshot||item.book?.title||'Sách')+' ×'+item.quantity).join(', '),
        borrowed:date(row.issued_at),due:date(row.due_at),
        status:(row.status==='active'?(row.is_overdue?'Quá hạn':'Đang mượn'):'Đã trả')+(row.money_summary?' · '+row.money_summary.label:''),
        moneySummary:row.money_summary};
    case 'returns': {
      const loan=row.loan||{};
      return {id:row.id,loan_id:row.loan_id,reader:loan.reader_name_snapshot||loan.reader?.name||'',
        book:(row.items||[]).map(item=>(item.loan_item?.book_title_snapshot||item.loan_item?.book?.title||'Sách')+' ×'+item.quantity).join(', '),
        due:date(loan.due_at),returned:date(row.returned_at),
        lateDays:Math.max(0,...(row.items||[]).map(item=>Number(item.fine?.overdue_days)||0)),
        fine:(row.items||[]).reduce((sum,item)=>sum+Number(item.fine?.amount||0),0),status:'Đã nhận trả'};
    }
    case 'readers':
      return {id:row.id,name:row.name,email:row.email,phone:row.reader_profile?.phone||'',status:row.is_locked?'Tạm khóa':'Hoạt động'};
    case 'authors':
      return {id:row.id,name:row.name,country:row.biography||'—',books:row.books_count||0};
    case 'inventory':
      return {id:row.book_code,book_id:row.id,book:row.title,total:row.total_quantity,onLoan:row.on_loan_quantity,
        reserved:row.reserved_quantity,available:row.available_quantity,status:row.available_quantity?'Đang lưu hành':'Hết bản khả dụng'};
    case 'fines':
      return {id:row.id,code:row.fine_code,loan_id:row.loan_id,loan_item_id:row.loan_item_id,reader:row.reader?.name||'',
        reason:(row.fine_code||'#'+row.id)+' · '+(row.reason||'Phạt trễ '+row.overdue_days+' ngày'),type:row.type,amount:row.amount,
        created:date(row.created_at),status:({unpaid:'Chưa thanh toán',waived:'Đã miễn',paid:'Đã thanh toán'})[row.status]||row.status,raw_status:row.status};
    case 'accounts':
      return {id:row.id,name:row.name,email:row.email,role:row.role,status:row.is_locked?'Tạm khóa':'Hoạt động'};
    case 'payments':
      return {id:row.id,reader:row.reader?.name||'Độc giả đã ngừng hoạt động',reference:row.reference||row.purpose,
        method:row.method,amount:row.amount,purpose:row.purpose,raw_status:row.status};
  }
}
async function authRefreshStaffData(){
  if(!staffIsSignedIn())return;
  const identity=authSession;
  const work=[staffRefreshDashboard()];
  if(opStaffPage(state.page))work.push(opLoad(state.page));
  if(!authServerData.profile){
    authServerData.profileLoading=true;
    authServerData.profileError='';
    work.push(authRequest('/my/profile').then(profile=>{
      if(authSession!==identity)return;
      authServerData.profile=profile.profile||null;
      state.avatar=authServerData.profile?.avatar_url||'';
    }).catch(error=>{
      if(authSession===identity)authServerData.profileError=error.message;
    }).finally(()=>{
      if(authSession!==identity)return;
      authServerData.profileLoading=false;
      if(['profile','profile-edit'].includes(state.page)&&!document.getElementById('profile-form')?.dataset.dirty)render();
    }));
  }
  await Promise.all(work);
}
async function authRefreshAppData(){await authRefreshCatalog();await Promise.all([authRefreshReaderData(),authRefreshFavorites(),authRefreshStaffData(),accountFeatures.refreshMembership()]);}
function authLoanDueDateField(){
  const localInput=date=>new Date(date.getTime()+7*3600000).toISOString().slice(0,16);
  const now=new Date(),due=new Date(now.getTime()+14*86400000);
  return '<label class="field">Ngày, giờ hẹn trả (giờ Việt Nam)<input name="due_at" type="datetime-local" value="'+
    localInput(due)+'" min="'+localInput(new Date(now.getTime()+60000))+
    '" required></label><p class="modal-note">Mặc định 14 ngày. Có thể điều chỉnh trước khi phát phiếu.</p>';
}
function authLoanDueAt(values){return new Date(values.get('due_at')+'+07:00').toISOString();}
function authRequestKey(){return globalThis.crypto.randomUUID?.() || Array.from(crypto.getRandomValues(new Uint8Array(16)), n=>n.toString(16).padStart(2,'0')).join('');}
async function authShowDepositSettlement(id){
  try{
    const s=await authRequest('/staff/loans/'+id+'/deposit/settlement');
    if(s.settlement){toast('Phiếu đã tất toán. Bạn có thể in biên nhận.');await authRefreshStaffData();return;}
    const fineList=(s.unpaid_fines||[]).map(f=>'<li>'+authEscape(f.fine_code||'#'+f.id)+' · '+authEscape(f.reason)+' · '+authMoney(f.amount)+'</li>').join('')||'<li>Chưa có khoản phạt chưa thu.</li>';
    const method=(name,label)=>'<label class="field">'+label+'<select name="'+name+'" required><option value="">Chọn phương thức</option><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label>';
    const expected=Object.entries({expected_deposit_deduction:s.deposit_deduction,expected_refund:s.refund,expected_additional_due:s.additional_due})
      .map(([name,value])=>'<input type="hidden" name="'+name+'" value="'+authEscape(value)+'">').join('');
    const stateMessage=s.pending_fine_payment?'Hãy xác nhận hoặc hủy giao dịch phạt đang chờ.':
      !s.all_copies_accounted_for?'Cần nhận trả hoặc xác nhận mất toàn bộ số bản.':'';
    const refund=Number(s.refund)>0, extra=Number(s.additional_due)>0;
    const confirmation=refund?'Tôi xác nhận đã hoàn đủ tiền cho độc giả.':
      extra?'Tôi xác nhận đã thu đủ phần tiền còn thiếu.':'Tôi xác nhận tất toán theo số liệu trên.';
    showModal('<form id="api-deposit-settle-form" class="login-form" data-loan-id="'+id+'"><h2 id="modal-title">Đối soát cọc phiếu #'+id+
      '</h2><p>Đã thu cọc '+authMoney(s.deposit)+'</p><ul>'+fineList+'</ul><p>Khấu trừ <strong>'+authMoney(s.deposit_deduction)+
      '</strong> · Hoàn <strong>'+authMoney(s.refund)+'</strong> · Thu thêm <strong>'+authMoney(s.additional_due)+'</strong></p>'+expected+
      (refund?method('refund_method','Phương thức hoàn'):'')+(extra?method('additional_method','Phương thức thu thêm'):'')+
      '<label class="field">Ghi chú biên nhận<input name="receipt_note" maxlength="500"></label>'+
      (stateMessage?'<p class="workspace-error">'+stateMessage+'</p>':'<label class="workspace-check"><input type="checkbox" required> '+confirmation+'</label>')+
      '<p class="workspace-error" role="alert" data-settlement-error></p><button type="button" class="link-button" data-op-settle="'+id+
      '" data-settlement-reload hidden>Tải lại số liệu</button><button class="primary-button" type="submit" '+(s.ready_to_settle?'':'disabled')+'>Xác nhận tất toán</button></form>');
  }catch(error){toast(error.message);}
}
function authShowReservationHandover(id){
  const row=opData.reservations.find(item=>String(item.id)===String(id));
  showModal(`<form id="api-reservation-handover-form" class="login-form" data-reservation-id="${authEscape(id)}"><h2 id="modal-title">Giao sách và phát phiếu mượn</h2><p>${authEscape(row?.reader||'Độc giả')} · ${authEscape(row?.book||'Yêu cầu #'+id)}</p>${authLoanDueDateField()}<label class="field">Tiền cọc đã thu<input name="deposit_amount" type="number" min="0.01" step="0.01" value="${(100000*Number(row?.quantity||1)).toFixed(2)}" required></label><label class="field">Phương thức cọc<select name="deposit_method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận cọc<input name="deposit_note" maxlength="500"></label><p class="modal-note">QR demo: quét sẽ mở YouTube; thư viện phải xác nhận đã nhận tiền. Phí mượn đã được xác nhận riêng.</p><button class="primary-button" type="submit">Xác nhận cọc và giao sách</button></form>`);
}
async function authShowReservationFulfill(id){
  const reservation=opData.reservations.find(item=>Number(item.id)===Number(id));
  if(!reservation)return;
  try{
    const book=authNormalizeBook((await authRequest(`/catalog/books/${reservation.book_id}`)).book);
    const amount=(Number(book.rental_fee)||0)*Number(reservation.quantity||0);
    showModal(`<form id="api-reservation-fulfill-form" class="login-form" data-reservation-id="${reservation.id}"><h2 id="modal-title">Xác nhận thu phí, cọc và giao sách</h2><p>${authEscape(reservation.reader)} · ${authEscape(book.title)} × ${reservation.quantity}</p><label class="field">Phí đã thu<input name="amount" type="number" min="0" step="0.01" value="${amount.toFixed(2)}" required></label><label class="field">Hình thức phí<select name="method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận phí<input name="receipt_note" maxlength="500"></label><label class="field">Tiền cọc đã thu<input name="deposit_amount" type="number" min="0.01" step="0.01" value="${(100000*Number(reservation.quantity)).toFixed(2)}" required></label><label class="field">Phương thức cọc<select name="deposit_method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận cọc<input name="deposit_note" maxlength="500"></label><p class="modal-note">Cọc gợi ý 100.000đ mỗi bản. QR demo mở YouTube; nhân viên xác nhận tiền thủ công.</p><button class="primary-button" type="submit">Xác nhận thu và giao sách</button></form>`);
    document.getElementById('api-reservation-fulfill-form').querySelector('[name="amount"]').closest('.field').insertAdjacentHTML('beforebegin',authLoanDueDateField());
  }catch(error){toast(error.message||'Không thể tải thông tin sách.');}
}
function authShowAccountForm(id){const old=opData.accounts.find(item=>String(item.id)===String(id));showModal(`<form id="api-account-form" class="login-form" data-account-id="${old?.id||''}"><h2 id="modal-title">${old?'Cập nhật tài khoản':'Tạo tài khoản'}</h2><label class="field">Họ tên<input name="name" value="${authEscape(old?.name||'')}" required minlength="2"></label><label class="field">Email<input name="email" type="email" value="${authEscape(old?.email||'')}" required></label><label class="field">Mật khẩu<input name="password" type="password" ${old?'':'required minlength="8"'}></label><label class="field">Vai trò<select name="role"><option ${old?.role==='Độc giả'?'selected':''}>Độc giả</option><option ${old?.role==='Thủ thư'?'selected':''}>Thủ thư</option><option ${old?.role==='Admin'?'selected':''}>Admin</option></select></label><label class="field">Trạng thái<select name="is_locked"><option value="0" ${!old||old.status==='Hoạt động'?'selected':''}>Hoạt động</option><option value="1" ${old?.status==='Tạm khóa'?'selected':''}>Tạm khóa</option></select></label><button class="primary-button" type="submit">Lưu tài khoản</button></form>`);}
function authShowStaffEntityForm(key,id){const old=(opData[key]||[]).find(item=>String(item.id)===String(id));if(key==='authors')showModal(`<form id="api-author-form" class="login-form" data-entity-id="${old?.id||''}"><h2 id="modal-title">${old?'Cập nhật tác giả':'Thêm tác giả'}</h2><label class="field">Tên tác giả<input name="name" value="${authEscape(old?.name||'')}" required></label><label class="field">Tiểu sử<textarea name="biography">${authEscape(old?.country==='—'?'':old?.country||'')}</textarea></label><button class="primary-button" type="submit">Lưu tác giả</button></form>`);else showModal(`<form id="api-reader-form" class="login-form" data-entity-id="${old?.id||''}"><h2 id="modal-title">${old?'Cập nhật độc giả':'Thêm độc giả'}</h2><label class="field">Họ tên<input name="name" value="${authEscape(old?.name||'')}" required minlength="2"></label><label class="field">Email<input name="email" type="email" value="${authEscape(old?.email||'')}" required></label>${old?'':'<label class="field">Mật khẩu ban đầu<input name="password" type="password" minlength="8" required></label><label class="field">Nhập lại mật khẩu<input name="password_confirmation" type="password" minlength="8" required></label>'}<label class="field">Số điện thoại<input name="phone" value="${authEscape(old?.phone||'')}"></label><button class="primary-button" type="submit">Lưu độc giả</button></form>`);}
function authShowFinePayment(id){const fine=opData.fines.find(item=>String(item.id)===String(id));if(!fine)return;showModal(`<form id="api-fine-payment-form" class="login-form" data-fine-id="${fine.id}"><h2 id="modal-title">Ghi nhận thu phạt</h2><p>${authEscape(fine.reader)} · ${authEscape(fine.reason)}</p><label class="field">Số tiền thu<input name="amount" type="number" min="0.01" step="0.01" value="${Number(fine.amount)}" required></label><label class="field">Hình thức<select name="method"><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option><option value="manual">Khác</option></select></label><label class="field">Ghi chú biên nhận<input name="receipt_note" maxlength="500"></label><p class="modal-note">Hệ thống ghi nhận thu nội bộ, không xử lý giao dịch qua cổng thanh toán.</p><button class="primary-button" type="submit">Xác nhận đã thu</button></form>`);}
function authShowLoanDetail(id){authRequest(`/my/loans/${id}`).then(({loan})=>showModal(`<section class="login-form"><h2 id="modal-title">Biên nhận phiếu mượn #${loan.id}</h2><a class="secondary-button" href="/receipts/loans/${loan.id}" target="_blank" rel="noopener">In / Lưu PDF</a><p>Nhận: ${new Date(loan.issued_at).toLocaleString('vi-VN')} · Hạn trả: ${new Date(loan.due_at).toLocaleString('vi-VN')}</p><p>Trạng thái: ${authEscape(loan.status)}</p><p>Trạng thái tiền: ${authEscape(loan.money_summary?.label||'Không có cọc')} · Số dư: ${loan.money_summary?.balance!=null?authMoney(loan.money_summary.balance):'—'}</p><h3>Phí mượn</h3><p>Đã thu: ${authMoney(loan.rental_fee_received||0)} · ${authEscape(loan.rental_fee_payment_method||'—')}</p>${loan.rental_fee_receipt_note?`<p>Ghi chú: ${authEscape(loan.rental_fee_receipt_note)}</p>`:''}<h3>Tiền cọc</h3><p>Cọc mất sách: ${authMoney(loan.deposit?.loss_protection_amount||0)} · Cọc giữ sách: ${authMoney(loan.deposit?.book_holding_amount||0)} · Đã thu: ${authMoney(loan.deposit?.amount||0)} · ${authEscape(loan.deposit?.method||'—')} · ${loan.deposit?.received_at?new Date(loan.deposit.received_at).toLocaleString('vi-VN'):''}</p>${loan.deposit?.receipt_note?`<p>Ghi chú: ${authEscape(loan.deposit.receipt_note)}</p>`:''}${loan.deposit?.settlement?`<p>Đối soát: khấu trừ ${authMoney(loan.deposit.settlement.deposit_used)} · hoàn ${authMoney(loan.deposit.settlement.refund_amount)} · thu thêm ${authMoney(loan.deposit.settlement.additional_received)}</p>`:''}<h3>Sách giấy</h3>${(loan.items||[]).map(item=>`<p>${authEscape(item.book?.title||'Sách')} × ${item.quantity} · đã trả ${item.returned_quantity} · mất ${item.lost_quantity||0}</p>`).join('')}<h3>Khoản phạt</h3>${(loan.fines||[]).map(f=>`<p>${authEscape(f.fine_code)} · ${authEscape(f.reason)} · ${authMoney(f.amount)} · ${authEscape(f.status)}${(f.payments||[]).map(p=>`<br>Biên nhận ${authMoney(p.amount)} · ${authEscape(p.method)} · ${p.paid_at?new Date(p.paid_at).toLocaleString('vi-VN'):''}`).join('')}${f.waiver_reason?`<br>Lý do miễn: ${authEscape(f.waiver_reason)}`:''}</p>`).join('')||'<p>Chưa có khoản phạt.</p>'}</section>`)).catch(error=>toast(error.message));}
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

function authAdminPage(){return adminDashboardView();}

function authReaderRecords(kind){
  if(kind==='reservations')return authServerData.reservations;
  if(kind==='fines')return authServerData.fines;
  return [];
}
function authPersonalPage(kind){
  const reservations=kind==='reservations',rows=authReaderRecords(kind);
  const title=reservations?'Sách bạn đã đặt trước':'Phí và lịch sử vi phạm';
  const subtitle=reservations?'Theo dõi yêu cầu giữ sách và trạng thái xử lý.':'Theo dõi khoản phạt, lý do và thời điểm thanh toán.';
  const columns=reservations?['Mã yêu cầu','Tên sách','Ngày gửi','Trạng thái']:['Mã khoản','Nội dung','Ngày ghi nhận','Số tiền','Trạng thái'];
  return `<div class="reader-account-page"><div class="eyebrow">TÀI KHOẢN ĐỘC GIẢ</div><div class="heading-row"><div><h1 class="page-title">${title}</h1><p class="subheading">${subtitle}</p></div>${reservations?'<button class="primary-button" data-page-link="catalog">Tìm sách để đặt trước</button>':''}</div>${rows.length?`<div class="reader-account-list">${rows.map(row=>{const status=String(row.status||'').toLocaleLowerCase('vi'),statusClass=status.includes('chưa thanh toán')||status.includes('chờ')?'pending':status.includes('thanh toán thành công')||status==='đã giữ'||status==='đã thanh toán'?'paid':'done';return `<article class="reader-account-row"><div class="reader-record-icon">${reservations?'▤':'₫'}</div><div class="reader-record-main"><strong>${authEscape(reservations?row.book:row.reason)}</strong><small>${authEscape(row.id)} · ${authEscape(row.created)}</small>${reservations?`<small>${row.payment_method==='bank_transfer'?'Phương thức: Chuyển khoản mô phỏng (không chuyển tiền thật)':row.payment_method==='cash'?'Phương thức: Tiền mặt tại thư viện':'Phương thức: Chưa có thông tin'}${row.amount!=null?` · ${fmtPrice(Number(row.amount))}`:''}${row.expires_at?` · Hạn ${authEscape(new Date(row.expires_at).toLocaleString('vi-VN'))}`:''}</small>`:''}</div>${!reservations?`<b class="reader-fine-amount">${row.amount==null?'Chưa có thông tin':fmtPrice(Number(row.amount))}</b>`:''}<span class="reader-record-status ${statusClass}">${authEscape(row.status)}</span>${reservations?`${accountFeatures.reservationViewAction(row)}${accountFeatures.paymentAction({...row,status:row.raw_status})}`:accountFeatures.finePaymentAction(row)}</article>`}).join('')}</div>`:`<div class="empty-state"><strong>${reservations?'Bạn chưa đặt trước cuốn sách nào':'Bạn chưa có khoản phạt'}</strong><p>${reservations?'Khi gửi yêu cầu đặt trước, trạng thái sẽ xuất hiện ở đây.':'Các khoản phí phát sinh sẽ được lưu trong lịch sử này.'}</p></div>`}${reservations&&window.libraryFeatureApi.capabilities.reservationWorkflow?'':reservations?'<p class="reader-record-note">Thanh toán mới sẽ khả dụng sau khi thư viện kết nối dịch vụ xác nhận giao dịch.</p>':''}</div>`;
}
function authLibrarianHome(){return librarianDashboardView();}

const authPrivatePages=new Set(['shelf','cart','profile','profile-edit','vip','checkout','reservation-detail','reservations','payments','loans','returns','readers','authors','accounts','inventory','fines','librarian-home','reports']);
const authLibrarianPages=new Set(['librarian-home','reservations','payments','loans','returns','readers','inventory','fines','reports']);
const authAdminPages=new Set(['admin','accounts','authors']);
const authReaderRecordPages=new Set(['reservations','fines']);
const authCounterPages=new Set(['librarian-home','reservations','payments','loans','returns','readers']);
function authAdminCounterPage(page){
  return authSession?.role==='Admin'&&authCounterPages.has(page);
}
function authIsLibrarianPage(page){
  const isReaderRecord=authSession?.role==='Độc giả'&&authReaderRecordPages.has(page);
  return authLibrarianPages.has(page)&&!isReaderRecord;
}
function authSetIdentity(session){
  if(authSession?.id!==session.id||authSession?.role!==session.role){
    opReset();authServerData.profile=null;authServerData.profileLoading=true;authServerData.profileError='';authServerData.reservations=[];accountFeatures.reset(session);
    window.libraryNotifications?.clear();
  }
  authSession=session;state.role=session.role;state.name=session.name;state.email=session.email;
  state.cart=[];state.favorites=[];state.avatar='';authServerData.favoriteBooks=[];
  window.libraryUser=session;
  window.libraryNotifications?.refresh();
}
function authGo(page){
  const feature=accountFeatures.resolve(page);
  if(feature?.page==='reservation-detail'){
    if(!authSession){sessionStorage.setItem('may-next-route',page);go('login');return;}
    if(authSession.role!=='Độc giả'){toast('Chi tiết đặt trước dành cho tài khoản độc giả.');return;}
    accountFeatures.setRoute(page);state.page=feature.page;history.pushState(null,'',`#${page}`);render();window.scrollTo({top:0,behavior:'smooth'});return;
  }
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
  if(authIsLibrarianPage(page)&&authSession?.role==='Độc giả'){toast('Khu vực này dành cho nhân viên thư viện.');page='home';}
  if(page==='home'&&authSession?.role==='Admin')page='admin';
  if(page==='home'&&authSession?.role==='Thủ thư')page='librarian-home';
  if(authAdminCounterPage(page))page='admin';
  authBaseGo(page);
  if(page==='home')authLoadCatalog('',1,true);
  if(page==='catalog')authLoadCatalog(state.query,state.catalogPage||1,true);
}
const authBaseGo=go;
go=authGo;

const authBaseRender=render;
render=function(){
  if(state.page==='home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');}
  if(state.page==='home'&&authSession?.role==='Thủ thư'){state.page='librarian-home';history.replaceState(null,'','#librarian-home');}
  if(!authPageLabels[state.page]&&authPrivatePages.has(state.page)&&!authSession){sessionStorage.setItem('may-next-route',state.page);state.page='login';history.replaceState(null,'','#login');}
  if(authAdminPages.has(state.page)&&authSession?.role!=='Admin'){state.page=authSession?'home':'login';history.replaceState(null,'','#'+state.page);}
  if(authIsLibrarianPage(state.page)&&authSession?.role==='Độc giả'){state.page='home';history.replaceState(null,'','#home');}
  if(authAdminCounterPage(state.page)){state.page='admin';history.replaceState(null,'','#admin');}
  if(['vip','checkout'].includes(state.page)&&authSession&&authSession.role!=='Độc giả'){state.page='profile';history.replaceState(null,'','#profile');}
  const authMode=!!authPageLabels[state.page];
  document.body.classList.toggle('auth-mode',authMode);
  if(authMode){document.getElementById('page-label').textContent=authPageLabels[state.page];app.innerHTML=authPage(state.page);app.focus({preventScroll:true});}
  else if(state.page==='admin'){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels.admin;document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authAdminPage();app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(state.page==='librarian-home'){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels['librarian-home'];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authLibrarianHome();app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(authSession?.role==='Độc giả'&&authReaderRecordPages.has(state.page)){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels[state.page];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authPersonalPage(state.page);app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(state.page==='reports'){
    staffEnterReports();
    document.getElementById('page-label').textContent=labels.reports;
    document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));
    app.innerHTML=staffReportView();
    document.getElementById('sidebar').classList.remove('open');
  }
  else if(accountFeatures.handles(state.page)){accountFeatures.enter();document.getElementById('page-label').textContent=labels[state.page];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));app.innerHTML=accountFeatures.view();document.getElementById('sidebar').classList.remove('open');}
  else {document.body.classList.remove('auth-mode');authBaseRender();}
  if(state.page==='cart'&&authSession?.role==='Độc giả'&&state.cart.length){
    const summary=app.querySelector('.summary-card');
    if(summary)summary.insertAdjacentHTML('afterbegin',window.libraryFeatureApi.capabilities.reservationWorkflow&&window.libraryFeatureApi.capabilities.payments?`<fieldset class="reservation-method-picker"><legend>Phương thức thanh toán</legend><label><input type="radio" name="reservation_method" value="bank_transfer" checked> Mã QR</label><label><input type="radio" name="reservation_method" value="cash"> Tiền mặt tại thư viện</label></fieldset>`:`<div class="feature-notice reservation-workflow-note" role="status"><span aria-hidden="true">◷</span><div><strong>Thanh toán sau khi duyệt</strong><p>Yêu cầu mới sẽ hiển thị phương thức thanh toán sau khi dịch vụ được bật.</p></div></div>`);
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
  document.body.classList.toggle('admin-mode',isAdmin);
  document.getElementById('reader-nav').hidden=isStaff;
  document.getElementById('librarian-nav').hidden=!isLibrarian;
  document.getElementById('admin-nav').hidden=!isAdmin;
  document.getElementById('breadcrumb-area').textContent=isAdmin?'Quản trị':'Thư viện';
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
document.addEventListener('change',event=>{if(!event.target.matches('select[name="method"],select[name="deposit_method"],select[name="additional_method"]'))return;const form=event.target.closest('form');if(!form||form.id==='reservation-refund-form')return;let qr=form.querySelector('.payment-demo-qr-inline');if(event.target.value==='bank_transfer'&&!qr){event.target.closest('.field')?.insertAdjacentHTML('afterend','<div class="payment-demo-qr-inline"><strong>QR demo · không thanh toán thật</strong><br><img src="/library/payment-demo-qr.svg" width="160" height="160" alt="QR demo mở YouTube"><br><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" target="_blank" rel="noopener">Mở liên kết QR</a><small>Thư viện xác nhận khoản thu thủ công.</small></div>');}else if(event.target.value!=='bank_transfer')qr?.remove();});
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
  if(event.target.id==='api-fine-waive-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,submit=form.querySelector('button[type="submit"]'),feedback=form.querySelector('[data-fine-error]');
    if(authSession?.role!=='Admin'||submit.disabled)return;
    const reason=form.elements.reason.value.trim();
    if(reason.length<3){feedback.textContent='Nhập lý do miễn phạt từ 3 ký tự.';form.elements.reason.focus();return;}
    feedback.textContent='';
    submit.disabled=true;
    try{
      await authRequest('/staff/fines/'+encodeURIComponent(form.dataset.fineId)+'/waive',{method:'PATCH',body:{reason}});
      closeModal();
      toast('Đã miễn phạt và gửi thông báo cho độc giả.');
      await authRefreshStaffData();
    }catch(error){feedback.textContent=error.message||'Chưa thể miễn phạt. Thử lại.';}
    finally{submit.disabled=false;}
    return;
  }
  if(event.target.id==='api-fine-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,submit=form.querySelector('button[type="submit"]'),feedback=form.querySelector('[data-fine-error]');
    if(submit.disabled)return;
    feedback.textContent='';
    submit.disabled=true;
    try{
      const result=await authRequest('/staff/fines',{method:'POST',body:{
        ...Object.fromEntries(new FormData(form)),request_key:form.dataset.requestKey,
      }});
      closeModal();toast(result.message);await Promise.all([authRefreshStaffData(),authRefreshCatalog()]);
    }catch(error){feedback.textContent=error.message;}
    finally{submit.disabled=false;}
    return;
  }
  if(event.target.id==='api-deposit-settle-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,submit=form.querySelector('button[type="submit"]');
    if(submit.disabled)return;
    submit.disabled=true;
    const errorNode=form.querySelector('[data-settlement-error]');
    errorNode.textContent='';
    let stale=false;
    try{
      const result=await authRequest('/staff/loans/'+form.dataset.loanId+'/deposit/settle',{
        method:'POST',body:Object.fromEntries(new FormData(form)),
      });
      closeModal();toast(result.message);await authRefreshStaffData();
    }catch(error){
      errorNode.textContent=error.message;
      stale=error.status===409;
      form.querySelector('[data-settlement-reload]').hidden=!stale;
    }finally{submit.disabled=stale;}
    return;
  }
  if(event.target.id==='api-loan-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,submit=form.querySelector('button[type="submit"]'),feedback=form.querySelector('[data-loan-error]');
    if(submit.disabled)return;
    const values=new FormData(form),quantities=new Map();
    for(const row of form.querySelectorAll('.loan-book-row')){
      const bookId=Number(row.querySelector('[name=book_id]').value);
      const quantity=Number(row.querySelector('[name=quantity]').value);
      quantities.set(bookId,(quantities.get(bookId)||0)+quantity);
    }
    const items=[...quantities].map(([book_id,quantity])=>({book_id,quantity}));
    feedback.textContent='';
    submit.disabled=true;
    try{
      const result=await authRequest('/staff/loans',{method:'POST',body:{
        reader_id:Number(values.get('reader_id')),items,due_at:authLoanDueAt(values),issuance_key:form.dataset.issuanceKey,
        rental_fee_payment:{amount:values.get('amount'),method:values.get('method'),receipt_note:values.get('receipt_note')},
        deposit_payment:{amount:values.get('deposit_amount'),method:values.get('deposit_method'),receipt_note:values.get('deposit_note')},
      }});
      closeModal();toast(result.message);await Promise.all([authRefreshStaffData(),authRefreshCatalog()]);
    }catch(error){feedback.textContent=error.message;}
    finally{submit.disabled=false;}
    return;
  }
  if(event.target.id==='api-reservation-fulfill-form'){
    event.preventDefault();event.stopImmediatePropagation();const values=new FormData(event.target),id=event.target.dataset.reservationId;
    try{await authRequest(`/staff/reservations/${id}/fulfill`,{method:'POST',body:{due_at:authLoanDueAt(values),rental_fee_payment:{amount:values.get('amount'),method:values.get('method'),receipt_note:values.get('receipt_note')},deposit_payment:{amount:values.get('deposit_amount'),method:values.get('deposit_method'),receipt_note:values.get('deposit_note')}}});closeModal();await authRefreshStaffData();await authRefreshCatalog();toast('Đã xác nhận thu phí, cọc và giao sách.');}
    catch(error){toast(error.message||'Không thể giao sách.');}
    return;
  }
  if(event.target.id==='api-reservation-handover-form'){
    event.preventDefault();event.stopImmediatePropagation();const form=event.target,submit=form.querySelector('[type="submit"]');submit.disabled=true;
    try{const values=new FormData(form);await window.libraryFeatureApi.reservationAction(form.dataset.reservationId,'handover',{due_at:authLoanDueAt(values),deposit_payment:{amount:values.get('deposit_amount'),method:values.get('deposit_method'),receipt_note:values.get('deposit_note')}});closeModal();await authRefreshStaffData();await authRefreshCatalog();toast('Đã thu cọc, giao sách và lưu ngày hẹn trả.');}
    catch(error){toast(error.message||'Không thể giao sách.');}
    finally{submit.disabled=false;}
    return;
  }
  if(event.target.id==='api-return-form'){
    event.preventDefault();event.stopImmediatePropagation();const values=new FormData(event.target),items=[];
    for(const [key,value] of values.entries()){if(key.startsWith('item-')&&Number(value)>0)items.push({loan_item_id:Number(key.slice(5)),quantity:Number(value)});}
    const feedback=event.target.querySelector('[data-return-error]');
    if(!items.length){feedback.textContent='Nhập số lượng sách cần trả.';return;}
    feedback.textContent='';
    const submit=event.target.querySelector('button[type="submit"]');
    if(submit.disabled)return;
    submit.disabled=true;
    try{const result=await authRequest(`/staff/loans/${event.target.dataset.loanId}/returns`,{method:'POST',body:{items,return_key:event.target.dataset.returnKey}});closeModal();await authRefreshStaffData();await authRefreshCatalog();const fine=(result.fines||[]).reduce((sum,row)=>sum+Number(row.amount||0),0);toast(fine?`Đã nhận sách; khoản phạt ${fmtPrice(fine)} do máy chủ tính.`:'Đã ghi nhận trả sách.');}
    catch(error){feedback.textContent=error.message||'Không thể ghi nhận trả sách.';}
    finally{submit.disabled=false;}
    return;
  }
  if(event.target.id==='book-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const form=event.target,values=new FormData(form),id=form.dataset.editId,details=form.querySelector('[name="detail_images[]"]')?.files||[];
    const cover=values.get('cover_image');
    if(details.length>3){toast('Ảnh chi tiết chỉ được chọn tối đa 3 ảnh.');return;}
    if(cover?.size>2*1024*1024){toast('Ảnh bìa không được vượt quá 2 MB.');return;}
    if(Array.from(details).some(file=>file.size>2*1024*1024)){toast('Mỗi ảnh chi tiết không được vượt quá 2 MB.');return;}
    const payload=new FormData();
    for(const name of ['book_code','title','category','description','total_quantity','rental_fee'])payload.append(name,name==='total_quantity'?String(Number(values.get(name))):String(values.get(name)||'').trim());
    values.getAll('author_ids').forEach(authorId=>payload.append('author_ids[]',String(Number(authorId))));
    if(cover instanceof File&&cover.size)payload.append('cover_image',cover);
    if(id)payload.append('_method','PATCH');
    const submit=form.querySelector('[type="submit"]');if(submit)submit.disabled=true;
    try{
      const saved=await authRequest(id?`/staff/books/${id}`:'/staff/books',{method:'POST',body:payload});
      const bookId=id||saved.book?.id;
      if(details.length){
        const detailPayload=new FormData();Array.from(details).forEach(file=>detailPayload.append('pages[]',file));
        try{await authRequest(`/staff/books/${bookId}/preview`,{method:'POST',body:detailPayload});}
        catch(error){closeModal();await Promise.all([authRefreshCatalog(),authRefreshStaffData()]);toast(`Đã lưu thông tin sách, nhưng ảnh chi tiết chưa tải lên được: ${error.message||'lỗi máy chủ'}`);return;}
      }
      closeModal();await Promise.all([authRefreshCatalog(),authRefreshStaffData()]);if(state.page==='admin')render();toast(id?'Đã cập nhật sách và hình ảnh.':'Đã thêm sách và hình ảnh.');
    }
    catch(error){toast(error.message||'Không thể lưu sách.');}
    finally{if(submit?.isConnected)submit.disabled=false;}
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
  const favorite=event.target.closest('[data-favorite]');
  if(favorite){
    event.preventDefault();event.stopImmediatePropagation();
    if(authSession?.role==='Độc giả')authToggleFavorite(Number(favorite.dataset.favorite),favorite);
    else toast('Tính năng yêu thích dành cho độc giả.');
    return;
  }
  if(event.target.closest('[data-catalog-retry]')){
    event.preventDefault();authLoadCatalog(state.page==='home'?'':state.query,state.page==='home'?1:state.catalogPage,true);return;
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
  const addFine=event.target.closest('[data-op-add="fines"],[data-staff-fine-loan]');
  if(addFine){
    event.preventDefault();event.stopImmediatePropagation();
    if(addFine.dataset.staffFineLoan)authShowFineForm(Number(addFine.dataset.staffFineLoan));
    else authChooseLoanForFine();
    return;
  }
  const settleDeposit=event.target.closest('[data-op-settle]');if(settleDeposit){event.preventDefault();event.stopImmediatePropagation();authShowDepositSettlement(settleDeposit.dataset.opSettle);return;}
  const waiveFine=event.target.closest('[data-op-waive]');
  if(waiveFine){
    event.preventDefault();event.stopImmediatePropagation();
    const fine=opData.fines.find(row=>String(row.id)===waiveFine.dataset.opWaive);
    if(authSession?.role==='Admin'&&fine?.raw_status==='unpaid'){
      showModal(`<form id="api-fine-waive-form" class="login-form" data-fine-id="${authEscape(fine.id)}">
        <h2 id="modal-title">Miễn phạt</h2>
        <p><strong>${authEscape(fine.reason)}</strong></p>
        <p>${authEscape(fine.reader)} · ${authMoney(fine.amount)}</p>
        <label class="field">Lý do miễn phạt<textarea name="reason" rows="3" minlength="3" maxlength="1000" required aria-describedby="fine-waive-error"></textarea></label>
        <p id="fine-waive-error" class="workspace-error" role="alert" data-fine-error></p>
        <button class="primary-button" type="submit">Xác nhận miễn phạt</button>
      </form>`);
    }
    return;
  }
  const cancelFinePayment=event.target.closest('[data-op-cancel-payment]');if(cancelFinePayment){event.preventDefault();event.stopImmediatePropagation();const reason=prompt('Nhập lý do hủy giao dịch đang chờ:');if(reason?.trim())authRequest(`/staff/payments/${encodeURIComponent(cancelFinePayment.dataset.opCancelPayment)}/cancel`,{method:'POST',body:{reason:reason.trim()}}).then(()=>authRefreshStaffData()).then(()=>toast('Đã hủy giao dịch và thông báo độc giả.')).catch(error=>toast(error.message));return;}
  const openLoan=event.target.closest('[data-open-loan]');if(openLoan){event.preventDefault();authShowLoanDetail(openLoan.dataset.openLoan);return;}
  const unsupportedOperation=event.target.closest('[data-op-add="reservations"],[data-op-add="returns"],[data-op-delete^="inventory:"],[data-op-edit^="reservations:"],[data-op-delete^="reservations:"],[data-op-edit^="loans:"],[data-op-delete^="loans:"],[data-op-edit^="fines:"],[data-op-delete^="fines:"]');
  if(unsupportedOperation){event.preventDefault();event.stopImmediatePropagation();toast(unsupportedOperation.dataset.opAdd==='inventory'||unsupportedOperation.dataset.opEdit?.startsWith('inventory:')?'Tồn kho được suy ra từ sách đang mượn và giữ; hãy sửa tổng số bản ở danh mục sách.':'Thao tác này không có API lưu an toàn nên chưa được ghi nhận.');return;}
  const addAccount=event.target.closest('[data-op-add="accounts"]');if(addAccount){event.preventDefault();event.stopImmediatePropagation();authShowAccountForm();return;}
  const addStaffEntity=event.target.closest('[data-op-add="authors"],[data-op-add="readers"]');if(addStaffEntity){event.preventDefault();event.stopImmediatePropagation();authShowStaffEntityForm(addStaffEntity.dataset.opAdd);return;}
  const editAccount=event.target.closest('[data-op-edit^="accounts:"]');if(editAccount){event.preventDefault();event.stopImmediatePropagation();authShowAccountForm(editAccount.dataset.opEdit.split(':')[1]);return;}
  const editStaffEntity=event.target.closest('[data-op-edit^="authors:"],[data-op-edit^="readers:"]');if(editStaffEntity){event.preventDefault();event.stopImmediatePropagation();const [key,id]=editStaffEntity.dataset.opEdit.split(':');authShowStaffEntityForm(key,id);return;}
  const deleteStaffEntity=event.target.closest('[data-op-delete^="authors:"],[data-op-delete^="readers:"]');if(deleteStaffEntity){event.preventDefault();event.stopImmediatePropagation();const [key,id]=deleteStaffEntity.dataset.opDelete.split(':');if(confirm('Xóa mềm bản ghi này trên máy chủ?'))authRequest(`/staff/${key}/${id}`,{method:'DELETE'}).then(()=>authRefreshStaffData()).then(()=>toast('Đã xóa mềm bản ghi.')).catch(error=>toast(error.message));return;}
  const deleteAccount=event.target.closest('[data-op-delete^="accounts:"]');if(deleteAccount){event.preventDefault();event.stopImmediatePropagation();toast('Tài khoản được khóa thay vì xóa để giữ lịch sử giao dịch.');return;}
  const readyReservation=event.target.closest('[data-op-approve]');if(readyReservation){event.preventDefault();event.stopImmediatePropagation();const row=opData.reservations.find(item=>String(item.id)===readyReservation.dataset.opApprove);const run=window.libraryFeatureApi.capabilities.reservationWorkflow?window.libraryFeatureApi.reservationAction(readyReservation.dataset.opApprove,'approve',{payment_method:row?.payment_method}):authRequest(`/staff/reservations/${readyReservation.dataset.opApprove}/ready`,{method:'POST',body:{}});readyReservation.disabled=true;run.then(()=>authRefreshStaffData()).then(()=>authRefreshCatalog()).then(()=>toast('Đã duyệt yêu cầu và bắt đầu thời hạn 48 giờ.')).catch(error=>toast(error.message)).finally(()=>{readyReservation.disabled=false});return;}
  const handoverAction=event.target.closest('[data-op-handover]');if(handoverAction){event.preventDefault();event.stopImmediatePropagation();authShowReservationHandover(handoverAction.dataset.opHandover);return;}
  const paymentAction=event.target.closest('[data-op-confirm-payment]');if(paymentAction){event.preventDefault();event.stopImmediatePropagation();const id=paymentAction.dataset.opConfirmPayment;const row=opData.reservations.find(item=>String(item.id)===String(id));const method=row?.payment_method==='bank_transfer'?'chuyển khoản mô phỏng':'tiền mặt';if(!confirm(`Xác nhận đã nhận ${method} cho yêu cầu #${id}${row?.amount!=null?` · ${authMoney(row.amount)}`:''}?`))return;paymentAction.disabled=true;window.libraryFeatureApi.reservationAction(id,'confirm-payment',{method:row?.payment_method}).then(()=>authRefreshStaffData()).then(()=>{toast('Đã xác nhận thanh toán từ API thư viện.');}).catch(error=>toast(error.message)).finally(()=>{paymentAction.disabled=false});return;}
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
      authSession=null;window.libraryUser=null;window.libraryNotifications?.clear();
      accountFeatures.reset(null);authServerData.profile=null;authServerData.profileLoading=false;authServerData.profileError='';authServerData.reservations=[];authServerData.cartItems=[];authServerData.loans=[];authServerData.fines=[];authServerData.favoriteBooks=[];loanItems.splice(0);
      state.role='Độc giả';state.name='Khách';state.email='';state.cart=[];state.favorites=[];state.avatar='';
      opReset();
      toast('Bạn đã đăng xuất.');go('home');
    }).catch(error=>toast(error.message||'Không thể đăng xuất.')).finally(()=>{authLogoutBusy=false;});
  }
},true);

document.addEventListener('input',event=>{if(event.target.closest('#auth-form')){const error=event.target.closest('.field')?.querySelector('.auth-error');if(error)error.textContent='';}const counterId=event.target.dataset.countFor;if(counterId){const counter=document.getElementById(counterId);if(counter)counter.textContent=event.target.value.length;const error=event.target.closest('form')?.querySelector('.community-error');if(error)error.textContent='';}});

function authSyncRoute(){
  const [routeValue,query='']=location.hash.slice(1).split('?');let route=routeValue||'home';
  const feature=accountFeatures.resolve(route);accountFeatures.setRoute(route);
  if(feature?.page==='reservation-detail'){
    if(!authSession){sessionStorage.setItem('may-next-route',route);state.page='login';history.replaceState(null,'','#login');render();return;}
    if(authSession.role!=='Độc giả'){toast('Chi tiết đặt trước dành cho tài khoản độc giả.');route=authSession.role==='Admin'?'admin':'librarian-home';}
    else {state.page='reservation-detail';render();return;}
  }
  if(feature?.page==='checkout'){
    if(!authSession){sessionStorage.setItem('may-next-route',route);state.page='login';history.replaceState(null,'','#login');render();return;}
    if(authSession.role!=='Độc giả'){toast('Thanh toán dành cho tài khoản độc giả.');route=authSession.role==='Admin'?'admin':'librarian-home';state.page=route;render();return;}
    state.page='checkout';render();return;
  }
  if(route==='catalog'){
    const params=new URLSearchParams(query);
    state.query=params.get('q')||'';state.catalogDraft=state.query;
    state.catalogCategory=params.get('category')||'';state.catalogSort=params.get('sort')||'relevance';
    state.catalogPage=Math.max(1,Number(params.get('page'))||1);
    authLoadCatalog(state.query,state.catalogPage,true);
  }
  const dynamic=route.match(/^(book|reader)\/(\d+)$/);
  if(dynamic){
    const id=Number(dynamic[2]);
    authLoadBookDetails(id);
    proSyncRoute();
    if(dynamic[1]==='book')authLoadBookCommunity(id);
    return;
  }
  if(authPrivatePages.has(route)&&!authSession){sessionStorage.setItem('may-next-route',route);route='login';}
  if(authAdminPages.has(route)&&authSession?.role!=='Admin')route=authSession?'home':'login';
  if(authIsLibrarianPage(route)&&authSession?.role==='Độc giả')route='home';
  state.page=route;render();
}
window.addEventListener('popstate',authSyncRoute);
window.addEventListener('hashchange',()=>{const [route]=location.hash.slice(1).split('?');if(accountFeatures.resolve(route)||/^(book|reader)\/\d+$/.test(route)||route==='catalog'||authPageLabels[route]||authPrivatePages.has(route)||authAdminPages.has(route))authSyncRoute();});

accountFeatures.init();
const [routeAtAuthStart]=location.hash.slice(1).split('?');
const routeAtAuthStartMatch=routeAtAuthStart.match(/^(book|reader)\/(\d+)$/);
if(accountFeatures.resolve(routeAtAuthStart)){authSyncRoute();}
else if(routeAtAuthStartMatch){authSyncRoute();}
else if(authPageLabels[routeAtAuthStart]){state.page=routeAtAuthStart;render();}
else if(authPrivatePages.has(routeAtAuthStart)&&!authSession){sessionStorage.setItem('may-next-route',routeAtAuthStart);state.page='login';history.replaceState(null,'','#login');render();}
else if(authAdminPages.has(routeAtAuthStart)&&authSession?.role!=='Admin'){state.page=authSession?'home':'login';history.replaceState(null,'','#'+state.page);render();}
else if(authIsLibrarianPage(routeAtAuthStart)&&authSession?.role==='Độc giả'){state.page='home';history.replaceState(null,'','#home');render();}
else if(routeAtAuthStart==='home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');render();}
else if(routeAtAuthStart==='home'&&authSession?.role==='Thủ thư'){state.page='librarian-home';history.replaceState(null,'','#librarian-home');render();}
else if(labels[routeAtAuthStart]){state.page=routeAtAuthStart;render();}
else render();
const authOriginalOpenBook=openBook;
openBook=function(id){authOriginalOpenBook(id);authLoadBookDetails(Number(id));authLoadBookCommunity(Number(id));};
async function authLoadBookDetails(bookId){
  const request={id:bookId,error:''};
  state.bookDetailRequest=request;
  try{
    const response=await authRequest('/catalog/books/'+bookId);
    state.bookDetails.set(bookId,authNormalizeBook(response.book));
  }catch(error){request.error=error.message;}
  finally{
    if(state.bookDetailRequest===request){
      state.bookDetailRequest=null;
      state.bookDetailError=request.error;
      if((state.page==='book-detail'&&Number(state.bookId)===bookId)||(state.page==='reader'&&Number(state.readerBookId)===bookId))render();
    }
  }
}
authRefreshAppData().then(()=>{if(state.page==='book-detail')authLoadBookCommunity(state.bookId);});
