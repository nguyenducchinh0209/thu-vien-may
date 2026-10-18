/* Trang xác thực và bảng điều khiển quản trị. Đây là chế độ trình diễn phía trình duyệt. */
const authPageLabels = {login:'Đăng nhập',register:'Tạo tài khoản',forgot:'Quên mật khẩu'};
Object.assign(labels, authPageLabels, {'librarian-home':'Bàn làm việc thủ thư'});

let authSession=window.libraryUser||null;
const authBootEmail=authSession?.email||null;
let authActiveEmail=null;
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
function authPage(mode='login'){
  const register=mode==='register',forgot=mode==='forgot';
  const title=register?'Bắt đầu hành trình đọc':forgot?'Khôi phục mật khẩu':'Chào mừng bạn trở lại';
  const intro=register?'Tạo tài khoản để lưu sách, theo dõi đơn mượn và tiếp tục đọc ở mọi nơi.':forgot?'Nhập email đã đăng ký, chúng tôi sẽ hướng dẫn bạn đặt lại mật khẩu.':'Đăng nhập để mở kệ sách và tiếp tục hành trình đọc của bạn.';
  return `<section class="auth-page"><div class="auth-story"><a class="auth-brand" href="#home"><span class="auth-brand-mark">⌑</span><span><strong>mây</strong><small>THƯ VIỆN CỦA BẠN</small></span></a><div class="auth-story-copy"><span class="pro-section-overline">MỘT GÓC NHỎ DÀNH CHO BẠN</span><h1>Mỗi trang sách<br>mở ra một <em>thế giới.</em></h1><p>Giữ lại những cuốn sách yêu thích, theo dõi ngày trả và tìm thấy câu chuyện tiếp theo của bạn.</p><div class="auth-quote"><span>“</span><div><p>Đọc sách là cách ta trò chuyện với những tâm hồn tuyệt vời nhất.</p><small>THƯ VIỆN MÂY</small></div></div></div><span class="auth-story-foot">ĐỌC CHẬM THÔI, THẾ GIỚI VẪN CHỜ. ✳</span></div><div class="auth-panel"><a href="#home" class="auth-mobile-brand">← Trở về thư viện</a><div class="auth-form-wrap"><span class="auth-kicker">${register?'THÀNH VIÊN MỚI':forgot?'HỖ TRỢ TÀI KHOẢN':'KHÔNG GIAN ĐỌC CỦA BẠN'}</span><h2>${title}</h2><p class="auth-intro">${intro}</p><form id="auth-form" data-auth-mode="${mode}" novalidate>${register?`<div class="field"><label for="auth-name">Họ và tên</label><input id="auth-name" name="name" autocomplete="name" required minlength="2" placeholder="Ví dụ: Nguyễn Minh Anh"><small class="auth-error" data-error-for="name"></small></div>`:''}<div class="field"><label for="auth-email">Email</label><input id="auth-email" name="email" type="email" autocomplete="email" required placeholder="ban@email.com"><small class="auth-error" data-error-for="email"></small></div>${!forgot?`<div class="field"><label for="auth-password">Mật khẩu</label><div class="password-field"><input id="auth-password" name="password" type="password" autocomplete="${register?'new-password':'current-password'}" required minlength="8" placeholder="Tối thiểu 8 ký tự"><button type="button" class="password-toggle" data-password-toggle aria-label="Hiện mật khẩu">Hiện</button></div><small class="auth-error" data-error-for="password"></small></div>`:''}${register?`<div class="field"><label for="auth-confirm">Nhập lại mật khẩu</label><input id="auth-confirm" name="confirm" type="password" autocomplete="new-password" required minlength="8" placeholder="Nhập lại mật khẩu"><small class="auth-error" data-error-for="confirm"></small></div>`:''}${!register&&!forgot?`<div class="auth-form-options"><label><input type="checkbox" name="remember"> Ghi nhớ phiên đăng nhập</label><button type="button" class="auth-text-link" data-auth-switch="forgot">Quên mật khẩu?</button></div>`:''}<button class="primary-button auth-submit" type="submit">${register?'Tạo tài khoản':forgot?'Gửi hướng dẫn':'Đăng nhập'} <span>→</span></button><div class="auth-divider"><span>hoặc</span></div><p class="auth-switch">${register?'Đã có tài khoản?':forgot?'Nhớ ra mật khẩu rồi?':'Bạn chưa có tài khoản?'} <button type="button" data-auth-switch="${register||forgot?'login':'register'}">${register||forgot?'Đăng nhập':'Tạo tài khoản'}</button></p></form></div></div></section>`;
}

function authAdminPage(){
  const booksTotal=state.adminBooks.length;
  const loans=opData.loans||[], readers=opData.readers||[], reservations=opData.reservations||[], returns=opData.returns||[];
  const activeLoans=loans.filter(row=>['Đang mượn','Quá hạn','Chờ trả'].includes(row.status)).length;
  const activeReaders=readers.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('hoạt động')).length;
  const pending=reservations.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length+returns.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length;
  const links=[['inventory','Kho sách','Theo dõi số bản và vị trí kệ','▤'],['accounts','Tài khoản & vai trò','Quản lý quyền truy cập','♙'],['loans','Phiếu mượn','Theo dõi sách đang mượn','↗'],['returns','Xử lý trả sách','Nhận sách và ghi nhận trễ hạn','↩'],['reservations','Đặt trước','Duyệt yêu cầu của độc giả','☆'],['fines','Phạt & vi phạm','Theo dõi khoản phạt','₫']];
  return `<div class="admin-pro"><div class="admin-welcome"><div><span class="pro-section-overline">BÀN ĐIỀU KHIỂN · ${new Date().toLocaleDateString('vi-VN',{day:'2-digit',month:'long',year:'numeric'})}</span><h1 class="page-title">Chào ${authEscape(state.name)}.</h1><p class="subheading">Đây là tình hình vận hành thư viện của bạn.</p></div><div class="admin-heading-actions"><span class="demo-data-badge"><i></i> Dữ liệu trình diễn</span><button class="primary-button" data-add-book>+ Thêm sách</button></div></div><div class="admin-metrics"><article class="metric-card"><small>Tổng đầu sách</small><strong>${booksTotal.toLocaleString('vi-VN')}</strong><em>Trong danh mục hiện tại</em></article><article class="metric-card"><small>Phiếu đang mượn</small><strong>${activeLoans.toLocaleString('vi-VN')}</strong><em>Theo dõi hạn trả sách</em></article><article class="metric-card"><small>Độc giả hoạt động</small><strong>${activeReaders.toLocaleString('vi-VN')}</strong><em>Tài khoản trong danh sách</em></article><article class="metric-card"><small>Việc cần xử lý</small><strong>${pending.toLocaleString('vi-VN')}</strong><em class="down">Đặt trước và trả sách</em></article></div><div class="admin-pro-grid"><section class="panel admin-chart-panel"><div class="panel-head"><div><h3>Hoạt động mượn sách</h3><span class="section-note">Minh họa lượt mượn trong tuần</span></div><button class="link-button" data-page-link="loans">Mở phiếu mượn ↗</button></div><div class="admin-week-chart">${[38,57,45,73,52,91,66].map((height,index)=>`<div class="admin-week-day"><span style="height:${height}%"></span><small>${['T2','T3','T4','T5','T6','T7','CN'][index]}</small></div>`).join('')}</div></section><section class="panel admin-activity"><div class="panel-head"><div><h3>Hàng đợi hôm nay</h3><span class="section-note">${pending} mục đang chờ xử lý</span></div></div>${pending?`<div class="admin-queue-row"><i class="activity-dot orange"></i><span><strong>${reservations.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length} yêu cầu đặt trước</strong><small>Cần kiểm tra và duyệt</small></span><button class="link-button" data-page-link="reservations">Mở</button></div><div class="admin-queue-row"><i class="activity-dot"></i><span><strong>${returns.filter(row=>String(row.status).toLocaleLowerCase('vi').includes('chờ')).length} lượt trả sách</strong><small>Xác nhận sách được nhận lại</small></span><button class="link-button" data-page-link="returns">Mở</button></div>`:`<div class="admin-empty-queue">Không có mục nào đang chờ xử lý.</div>`}</section></div><section class="admin-shortcuts"><div class="admin-section-heading"><div><span class="pro-section-overline">CÔNG CỤ QUẢN TRỊ</span><h2>Đi đến chức năng</h2></div><span>Chọn một khu vực để tiếp tục</span></div><div class="admin-shortcut-grid">${links.map(([page,title,description,glyph])=>`<button class="admin-shortcut-card" data-page-link="${page}"><span class="admin-shortcut-icon">${glyph}</span><span><strong>${title}</strong><small>${description}</small></span><b>→</b></button>`).join('')}</div></section><section class="section admin-book-section"><div class="section-head"><div><span class="pro-section-overline">DANH MỤC</span><h2 class="section-title">Quản lý sách</h2><div class="section-note">Thêm, sửa, xóa hoặc tìm trong danh mục thư viện.</div></div><label class="global-search admin-book-search"><span>${icon('search')}</span><input id="admin-search" placeholder="Tìm tên sách, tác giả…"></label></div><div class="list-card" id="admin-table">${adminTable(state.adminBooks)}</div></section><p class="admin-data-note">Số liệu và thao tác hiện lưu trong trình duyệt. Khi ghép Laravel, dashboard sẽ nhận dữ liệu thật từ API.</p></div>`;
}

function authReaderRecords(kind){
  const key=`${authAccountKey(authSession?.email)}-${kind}`;
  const records=readStore(key,[]);return Array.isArray(records)?records:[];
}
function authPersonalPage(kind){
  const reservations=kind==='reservations',rows=authReaderRecords(kind);
  const title=reservations?'Sách bạn đã đặt trước':'Phí và lịch sử vi phạm';
  const subtitle=reservations?'Theo dõi yêu cầu giữ sách và trạng thái xử lý.':'Theo dõi khoản phạt, lý do và thời điểm thanh toán.';
  const columns=reservations?['Mã yêu cầu','Tên sách','Ngày gửi','Trạng thái']:['Mã khoản','Nội dung','Ngày ghi nhận','Số tiền','Trạng thái'];
  return `<div class="reader-account-page"><div class="eyebrow">TÀI KHOẢN ĐỘC GIẢ</div><div class="heading-row"><div><h1 class="page-title">${title}</h1><p class="subheading">${subtitle}</p></div>${reservations?'<button class="primary-button" data-page-link="catalog">Tìm sách để đặt trước</button>':''}</div>${rows.length?`<div class="reader-account-list">${rows.map(row=>{const status=String(row.status||'').toLocaleLowerCase('vi'),statusClass=status.includes('chưa thanh toán')?'pending':status.includes('chờ')?'pending':status.includes('đã thanh toán')?'paid':'done';return `<article class="reader-account-row"><div class="reader-record-icon">${reservations?'▤':'₫'}</div><div class="reader-record-main"><strong>${authEscape(reservations?row.book:row.reason)}</strong><small>${authEscape(row.id)} · ${authEscape(row.created)}</small></div>${!reservations?`<b class="reader-fine-amount">${fmtPrice(Number(row.amount)||0)}</b>`:''}<span class="reader-record-status ${statusClass}">${authEscape(row.status)}</span></article>`}).join('')}</div>`:`<div class="empty-state"><strong>${reservations?'Bạn chưa đặt trước cuốn sách nào':'Bạn chưa có khoản phạt'}</strong><p>${reservations?'Khi gửi yêu cầu đặt trước, trạng thái sẽ xuất hiện tại đây.':'Các khoản phí phát sinh sẽ được lưu trong lịch sử này.'}</p></div>`}<p class="reader-record-note">Thông tin tài khoản đang dùng dữ liệu trình diễn trên trình duyệt.</p></div>`;
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

const authPrivatePages=new Set(['shelf','cart','profile','reader','book-detail','reservations','loans','returns','readers','authors','accounts','inventory','fines','librarian-home']);
const authLibrarianPages=new Set(['librarian-home','loans','returns','readers','inventory']);
const authAdminPages=new Set(['admin','accounts','authors']);
function authSetIdentity(session){
  authPersistAccountData();
  authSession=session;state.role=session.role;state.name=session.name;state.email=session.email;
  authLoadAccountData(session.email);
  saveStore('may-role',session.role);saveStore('may-name',session.name);
  window.libraryUser=session;
}
function authGo(page){
  const bookRoute=String(page).match(/^book\/(\d+)$/);
  if(bookRoute){
    if(!authSession){sessionStorage.setItem('may-next-route',`book/${Number(bookRoute[1])}`);page='login';}
    else {state.bookId=Number(bookRoute[1]);history.pushState(null,'',`#book/${state.bookId}`);state.page='book-detail';render();window.scrollTo({top:0,behavior:'smooth'});return;}
  }
  if(page==='book-detail'&&!authSession){sessionStorage.setItem('may-next-route',`book/${Number(state.bookId)||1}`);page='login';}
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
  if(state.page==='book-detail'&&!authSession){const route=location.hash.slice(1).match(/^book\/(\d+)$/);sessionStorage.setItem('may-next-route',route?`book/${Number(route[1])}`:`book/${Number(state.bookId)||1}`);state.page='login';history.replaceState(null,'','#login');}
  if(!authPageLabels[state.page]&&authPrivatePages.has(state.page)&&!authSession){sessionStorage.setItem('may-next-route',state.page);state.page='login';history.replaceState(null,'','#login');}
  if(authAdminPages.has(state.page)&&authSession?.role!=='Admin'){state.page=authSession?'home':'login';history.replaceState(null,'','#'+state.page);}
  if(authLibrarianPages.has(state.page)&&authSession?.role==='Độc giả'){state.page='home';history.replaceState(null,'','#home');}
  if(state.page==='librarian-home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');}
  const authMode=!!authPageLabels[state.page];
  document.body.classList.toggle('auth-mode',authMode);
  if(authMode){document.getElementById('page-label').textContent=authPageLabels[state.page];app.innerHTML=authPage(state.page);app.focus({preventScroll:true});}
  else if(state.page==='admin'){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels.admin;document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authAdminPage();app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(state.page==='librarian-home'){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels['librarian-home'];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authLibrarianHome();app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else if(authSession?.role==='Độc giả'&&(state.page==='reservations'||state.page==='fines')){document.body.classList.remove('auth-mode');document.getElementById('page-label').textContent=labels[state.page];document.querySelectorAll('.nav-link').forEach(link=>link.classList.toggle('active',link.dataset.page===state.page));syncCounts();app.innerHTML=authPersonalPage(state.page);app.focus({preventScroll:true});document.getElementById('sidebar').classList.remove('open');}
  else {document.body.classList.remove('auth-mode');authBaseRender();}
  const isSignedIn=!!authSession;
  document.getElementById('login-shortcut').hidden=isSignedIn;
  document.getElementById('logout-shortcut').hidden=!isSignedIn;
  if(state.page==='profile'){
    const roleField=app.querySelector('#profile-form select[name="role"]');
    if(roleField){const field=roleField.closest('.field');field?.remove();const hidden=document.createElement('input');hidden.type='hidden';hidden.name='role';hidden.value=authSession?.role||'Độc giả';app.querySelector('#profile-form').append(hidden);}
    const profileEmail=app.querySelector('#email');if(profileEmail)profileEmail.value=state.email||'minhanh@example.com';
    const profileEmailText=app.querySelector('.profile-identity small');if(profileEmailText)profileEmailText.textContent=state.email||'minhanh@example.com';
    if(authSession)app.querySelector('[data-auth]')?.remove();
  }
  authSyncChrome();
};

function authSyncChrome(){
  const role=authSession?.role||'Độc giả',isAdmin=role==='Admin',isLibrarian=role==='Thủ thư',isStaff=isAdmin||isLibrarian;
  document.getElementById('reader-nav').hidden=isStaff;
  document.getElementById('librarian-nav').hidden=!isLibrarian;
  document.getElementById('admin-nav').hidden=!isAdmin;
  document.getElementById('nav-caption').textContent=isAdmin?'QUẢN TRỊ HỆ THỐNG':isLibrarian?'NGHIỆP VỤ THƯ VIỆN':'KHÔNG GIAN ĐỌC';
  document.querySelectorAll('#reader-nav [data-page]').forEach(link=>{link.hidden=!authSession&&authPrivatePages.has(link.dataset.page);});
  document.getElementById('global-search').closest('.global-search').hidden=isStaff;
  document.getElementById('sidebar-help').hidden=isStaff;
  const sidebarRole=document.querySelector('.profile-copy small');if(sidebarRole)sidebarRole.textContent=!authSession?'Chưa đăng nhập':role==='Admin'?'Quản trị thư viện':role==='Thủ thư'?'Nhân viên thư viện':'Thành viên thân thiết';
  const sidebarName=document.querySelector('.profile-copy strong');if(sidebarName)sidebarName.textContent=authSession?.name||'Khách';
  const initials=(authSession?.name||'K').trim().split(/\s+/).slice(-2).map(part=>part[0]).join('').toLocaleUpperCase('vi');
  document.querySelectorAll('.sidebar-profile .avatar,.top-avatar').forEach(avatar=>{avatar.textContent=initials||'K';});
  const topAvatar=document.querySelector('.top-avatar');if(topAvatar)topAvatar.setAttribute('aria-label',`Hồ sơ ${role}: ${state.name}`);
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
  if(!response.ok){const errors=payload.errors||{};const first=Object.entries(errors).find(([,messages])=>messages?.length);const error=new Error(first?.[1]?.[0]||payload.message||'Không thể xử lý yêu cầu.');error.field=first?.[0]||'email';throw error;}
  return payload;
}

document.addEventListener('submit',async event=>{
  if(event.target.id==='profile-form'){
    event.preventDefault();event.stopImmediatePropagation();
    const values=new FormData(event.target);state.name=String(values.get('name')||state.name).trim();state.email=String(values.get('email')||state.email).trim().toLowerCase();
    saveStore('may-name',state.name);
    if(authSession){authSession={...authSession,name:state.name,email:state.email};const storage=localStorage.getItem('may-session')?localStorage:sessionStorage;storage.setItem('may-session',JSON.stringify(authSession));authPersistAccountData();}
    render();toast('Đã cập nhật hồ sơ trình diễn.');return;
  }
  if(event.target.id!=='auth-form')return;
  event.preventDefault();event.stopImmediatePropagation();
  const form=event.target,mode=form.dataset.authMode,values=new FormData(form),email=String(values.get('email')||'').trim().toLowerCase();
  const setError=(name,message)=>{const node=form.querySelector(`[data-error-for="${name}"]`);if(node)node.textContent=message;};
  form.querySelectorAll('.auth-error').forEach(node=>node.textContent='');
  if(mode==='forgot'){if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){setError('email','Vui lòng nhập email hợp lệ.');return;}toast('Chức năng khôi phục mật khẩu sẽ được kết nối sau.');return;}
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
    const requested=sessionStorage.getItem('may-next-route');sessionStorage.removeItem('may-next-route');
    const destination=requested||(result.user.role==='Admin'?'admin':result.user.role==='Thủ thư'?'librarian-home':'home');
    toast(mode==='register'?'Tạo tài khoản thành công.':`Đăng nhập thành công · ${result.user.role}`);go(destination);
  }catch(error){setError(error.field==='password_confirmation'?'confirm':error.field||'email',error.message);}
},true);

function authSubmitReservation(){
  if(!authSession){toast('Vui lòng đăng nhập để gửi yêu cầu.');go('login');return;}
  const selected=state.cart.map(proFindBook).filter(Boolean);
  if(!selected.length){toast('Giỏ sách hiện không có sách hợp lệ.');render();return;}
  const now=Date.now(),created=new Date().toLocaleDateString('vi-VN');
  const records=selected.map((book,index)=>({id:`YC-${now+index}`,book:book.title,created,status:'Chờ duyệt',reader:state.name,email:authSession.email}));
  opData.reservations.unshift(...records);opSave('reservations');
  const key=`${authAccountKey(authSession.email)}-reservations`,mine=readStore(key,[]);saveStore(key,[...records,...(Array.isArray(mine)?mine:[])]);
  state.cart=[];saveStore('may-cart',state.cart);authPersistAccountData();syncCounts();
  toast(`Đã gửi ${records.length} yêu cầu mượn. Theo dõi tại mục Đặt trước.`);go('reservations');
}
window.submitReservation=authSubmitReservation;

document.addEventListener('click',event=>{
  const protectedAction=event.target.closest('[data-add-cart],[data-remove-cart],[data-favorite],[data-reader-book],[data-checkout],[data-unlock]');
  if(protectedAction&&!authSession){
    event.preventDefault();event.stopImmediatePropagation();
    const currentRoute=location.hash.slice(1);
    if(/^(book|reader)\/\d+$/.test(currentRoute)||labels[currentRoute])sessionStorage.setItem('may-next-route',currentRoute);
    toast('Vui lòng đăng nhập để sử dụng chức năng này.');go('login');return;
  }
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
    event.preventDefault();
    authPost(window.libraryAuthUrls.logout,new FormData()).then(()=>{
      authPersistAccountData();authSession=null;window.libraryUser=null;authActiveEmail=null;
      state.role='Độc giả';state.name='Khách';state.email='';state.cart=[];state.favorites=[];state.avatar='';
      saveStore('may-avatar','');saveStore('may-role',state.role);saveStore('may-cart',state.cart);saveStore('may-favorites',state.favorites);
      toast('Bạn đã đăng xuất.');go('home');
    }).catch(error=>toast(error.message||'Không thể đăng xuất.'));
  }
},true);

document.addEventListener('click',event=>{if(event.target.closest('[data-add-cart],[data-remove-cart],[data-favorite]'))authPersistAccountData();});
document.addEventListener('change',event=>{if(event.target.id==='avatar-file')setTimeout(authPersistAccountData,0);});

document.addEventListener('input',event=>{if(event.target.closest('#auth-form')){const error=event.target.closest('.field')?.querySelector('.auth-error');if(error)error.textContent='';}});

function authSyncRoute(){
  let route=location.hash.slice(1)||'home';
  const dynamic=route.match(/^(book|reader)\/(\d+)$/);
  if(dynamic?.[1]==='book'){
    if(!authSession){sessionStorage.setItem('may-next-route',`book/${Number(dynamic[2])}`);route='login';history.replaceState(null,'','#login');state.page='login';render();return;}
    proSyncRoute();return;
  }
  if(dynamic?.[1]==='reader')route='reader';
  if(authPrivatePages.has(route)&&!authSession){sessionStorage.setItem('may-next-route',route);route='login';}
  if(authAdminPages.has(route)&&authSession?.role!=='Admin')route=authSession?'home':'login';
  if(authLibrarianPages.has(route)&&authSession?.role==='Độc giả')route='home';
  if(route==='librarian-home'&&authSession?.role==='Admin')route='admin';
  state.page=route;render();
}
window.addEventListener('popstate',authSyncRoute);
window.addEventListener('hashchange',()=>{const route=location.hash.slice(1);if(/^(book|reader)\/\d+$/.test(route)||authPageLabels[route]||authPrivatePages.has(route)||authAdminPages.has(route))authSyncRoute();});

const routeAtAuthStart=location.hash.slice(1)||'home';
const routeAtAuthStartMatch=routeAtAuthStart.match(/^(book|reader)\/(\d+)$/);
if(routeAtAuthStartMatch?.[1]==='reader'&&!authSession){sessionStorage.setItem('may-next-route','reader');state.page='login';history.replaceState(null,'','#login');render();}
else if(routeAtAuthStartMatch?.[1]==='book'&&!authSession){sessionStorage.setItem('may-next-route',`book/${Number(routeAtAuthStartMatch[2])}`);state.page='login';history.replaceState(null,'','#login');render();}
else if(routeAtAuthStartMatch){proSyncRoute();}
else if(authPageLabels[routeAtAuthStart]){state.page=routeAtAuthStart;render();}
else if(authPrivatePages.has(routeAtAuthStart)&&!authSession){sessionStorage.setItem('may-next-route',routeAtAuthStart);state.page='login';history.replaceState(null,'','#login');render();}
else if(authAdminPages.has(routeAtAuthStart)&&authSession?.role!=='Admin'){state.page=authSession?'home':'login';history.replaceState(null,'','#'+state.page);render();}
else if(authLibrarianPages.has(routeAtAuthStart)&&authSession?.role==='Độc giả'){state.page='home';history.replaceState(null,'','#home');render();}
else if(routeAtAuthStart==='home'&&authSession?.role==='Admin'){state.page='admin';history.replaceState(null,'','#admin');render();}
else if(routeAtAuthStart==='home'&&authSession?.role==='Thủ thư'){state.page='librarian-home';history.replaceState(null,'','#librarian-home');render();}
else if(labels[routeAtAuthStart]){state.page=routeAtAuthStart;render();}
else render();
