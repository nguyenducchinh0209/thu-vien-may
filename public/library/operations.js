/* Các trang nghiệp vụ của bản frontend demo. Dữ liệu được lưu cục bộ trên trình duyệt. */
const opLabels = {
  reservations: 'Đặt trước', loans: 'Phiếu mượn', returns: 'Trả sách', readers: 'Độc giả',
  authors: 'Tác giả', accounts: 'Tài khoản & vai trò', inventory: 'Kho sách', fines: 'Phạt & vi phạm'
};
Object.assign(labels, opLabels);

const opSeed = {
  reservations: [
    {id:1, reader:'Nguyễn Hà My', book:'Nhà giả kim', created:'12/10/2026', status:'Chờ duyệt'},
    {id:2, reader:'Lê Phương', book:'Khu vườn bí mật', created:'13/10/2026', status:'Chờ duyệt'},
    {id:3, reader:'Trần Hoàng Nam', book:'Rừng Na Uy', created:'10/10/2026', status:'Đã duyệt'}
  ],
  loans: [
    {id:1001, reader:'Minh Anh', book:'Đi tìm lẽ sống', borrowed:'08/10/2026', due:'18/10/2026', status:'Đang mượn'},
    {id:1002, reader:'Nguyễn Hà My', book:'Nhà giả kim', borrowed:'10/10/2026', due:'20/10/2026', status:'Đang mượn'},
    {id:1003, reader:'Trần Hoàng Nam', book:'Những ngày thơ ấu', borrowed:'25/09/2026', due:'09/10/2026', status:'Quá hạn'}
  ],
  returns: [{id:1003, reader:'Trần Hoàng Nam', book:'Những ngày thơ ấu', due:'09/10/2026', returned:'', lateDays:0, fine:0, status:'Chờ nhận'}],
  readers: [
    {id:1, name:'Minh Anh', email:'minhanh@example.com', phone:'0901234567', status:'Hoạt động'},
    {id:2, name:'Nguyễn Hà My', email:'hamy@example.com', phone:'0912345678', status:'Hoạt động'},
    {id:3, name:'Trần Hoàng Nam', email:'nam@example.com', phone:'0987654321', status:'Cần chú ý'}
  ],
  authors: [
    {id:1, name:'Viktor E. Frankl', country:'Áo', books:'Đi tìm lẽ sống'},
    {id:2, name:'Paulo Coelho', country:'Brazil', books:'Nhà giả kim'},
    {id:3, name:'Nguyên Hồng', country:'Việt Nam', books:'Những ngày thơ ấu'}
  ],
  accounts: [
    {id:1, name:'Minh Anh', email:'minhanh@example.com', role:'Độc giả', status:'Hoạt động'},
    {id:2, name:'Lê Thu Hà', email:'ha.thu@library.vn', role:'Thủ thư', status:'Hoạt động'},
    {id:3, name:'Quản trị viên', email:'admin@library.vn', role:'Admin', status:'Hoạt động'}
  ],
  inventory: [
    {id:'TV-001', book:'Đi tìm lẽ sống', shelf:'A-01', total:8, available:5, status:'Đang lưu hành'},
    {id:'TV-002', book:'Nhà giả kim', shelf:'B-03', total:6, available:2, status:'Đang lưu hành'},
    {id:'TV-003', book:'Những ngày thơ ấu', shelf:'C-02', total:4, available:0, status:'Đang mượn hết'}
  ],
  fines: [
    {id:1, reader:'Trần Hoàng Nam', reason:'Trả trễ 5 ngày', amount:25000, created:'14/10/2026', status:'Chưa thanh toán'},
    {id:2, reader:'Nguyễn Hà My', reason:'Rách nhẹ bìa sách', amount:15000, created:'02/10/2026', status:'Đã thanh toán'}
  ]
};
const opConfig = {
  reservations:{title:'Quản lý yêu cầu đặt trước',desc:'Duyệt yêu cầu, xác nhận khoản thu và bàn giao sách theo từng bước.',fields:[['reader','Tên độc giả','text'],['book','Tên sách','text'],['created','Ngày yêu cầu','text'],['status','Trạng thái','select',['Chờ duyệt','Đã duyệt','Đã hủy']]],cols:[['reader','Độc giả'],['book','Sách'],['created','Ngày yêu cầu'],['payment_method','Thanh toán'],['amount','Số tiền'],['expires_at','Hạn giữ'],['status','Trạng thái']]},
  loans:{title:'Quản lý phiếu mượn',desc:'Theo dõi người mượn, ngày đến hạn và tình trạng từng phiếu.',fields:[['reader','Tên độc giả','text'],['book','Tên sách','text'],['borrowed','Ngày mượn','text'],['due','Ngày hẹn trả','text'],['status','Trạng thái','select',['Đang mượn','Quá hạn','Đã trả']]],cols:[['reader','Độc giả'],['book','Sách'],['borrowed','Ngày mượn'],['due','Hẹn trả'],['status','Trạng thái']]},
  returns:{title:'Nhận và xử lý trả sách',desc:'Xác nhận bản sách đã trả, số ngày trễ và khoản phạt phát sinh.',fields:null,cols:[['reader','Độc giả'],['book','Sách'],['due','Hẹn trả'],['returned','Ngày trả'],['lateDays','Trễ (ngày)'],['fine','Tiền phạt'],['status','Trạng thái']]},
  readers:{title:'Hồ sơ độc giả',desc:'Tìm kiếm và cập nhật thông tin thành viên thư viện.',fields:[['name','Họ tên','text'],['email','Email','email'],['phone','Số điện thoại','tel'],['status','Trạng thái','select',['Hoạt động','Cần chú ý','Tạm khóa']]],cols:[['name','Họ tên'],['email','Email'],['phone','Điện thoại'],['status','Trạng thái']]},
  authors:{title:'Danh mục tác giả',desc:'Quản lý thông tin tác giả và các đầu sách liên quan.',fields:[['name','Tên tác giả','text'],['country','Tiểu sử','text'],['books','Sách liên quan','text']],cols:[['name','Tác giả'],['country','Quốc gia'],['books','Sách liên quan']]},
  accounts:{title:'Tài khoản và phân quyền',desc:'Quản lý trạng thái tài khoản và vai trò trong thư viện.',fields:[['name','Tên hiển thị','text'],['email','Email','email'],['role','Vai trò','select',['Độc giả','Thủ thư','Admin']],['status','Trạng thái','select',['Hoạt động','Tạm khóa']]],cols:[['name','Tài khoản'],['email','Email'],['role','Vai trò'],['status','Trạng thái']]},
  inventory:{title:'Kho và bản sách',desc:'Theo dõi mã bản sách, vị trí kệ và số lượng sẵn có.',fields:[['id','Mã bản sách','text'],['book','Tên sách','text'],['shelf','Vị trí kệ','text'],['total','Tổng bản','number'],['available','Còn sẵn','number'],['status','Trạng thái','select',['Đang lưu hành','Đang mượn hết','Ngưng lưu hành']]],cols:[['id','Mã bản'],['book','Tên sách'],['shelf','Vị trí kệ'],['total','Tổng bản'],['available','Còn sẵn'],['status','Trạng thái']]},
  fines:{title:'Phạt và vi phạm',desc:'Theo dõi lý do, số tiền và lịch sử xử lý vi phạm.',fields:[['reader','Độc giả','text'],['reason','Lý do','text'],['amount','Số tiền (đ)','number'],['created','Ngày ghi nhận','text'],['status','Trạng thái','select',['Chưa thanh toán','Đã thanh toán','Đang khiếu nại']]],cols:[['reader','Độc giả'],['reason','Lý do'],['amount','Số tiền'],['created','Ngày ghi nhận'],['status','Trạng thái']]}
};
const opData = {};
let opQuery = '', opPage = 1;
for (const key of Object.keys(opSeed)) {
  try { const stored = JSON.parse(localStorage.getItem(`may-${key}`)); opData[key] = Array.isArray(stored) ? stored : opSeed[key].map(x=>({...x})); }
  catch { opData[key] = opSeed[key].map(x=>({...x})); }
}
function opSave(key){localStorage.setItem(`may-${key}`,JSON.stringify(opData[key]));}
function opSyncReservation(row,remove=false){
  if(!row?.email)return;
  const accountKey=`may-account-${String(row.email).trim().toLowerCase().replace(/[^a-z0-9@._-]/g,'_')}-reservations`;
  try{const records=JSON.parse(localStorage.getItem(accountKey)||'[]');if(!Array.isArray(records))return;const updated=remove?records.filter(item=>String(item.id)!==String(row.id)):records.map(item=>String(item.id)===String(row.id)?{...item,status:row.status}:item);localStorage.setItem(accountKey,JSON.stringify(updated));}catch{}
}
function opCash(value){return new Intl.NumberFormat('vi-VN').format(Number(value)||0)+'đ';}
function opStatus(value){const kind=String(value).includes('Chờ')||String(value).includes('Quá hạn')||String(value).includes('Chú ý')||String(value).includes('Chưa')?'warn':String(value).includes('Đã trả')||String(value).includes('thanh toán')?'done':'';return `<span class="status-pill ${kind}">${escapeHTML(value||'—')}</span>`;}
function opRows(key){
  const conf=opConfig[key], rows=opData[key]||[];
  const filtered=rows.filter(row=>Object.values(row).join(' ').toLocaleLowerCase('vi').includes(opQuery.toLocaleLowerCase('vi')));
  const pages=Math.max(1,Math.ceil(filtered.length/8));opPage=Math.min(opPage,pages);
  const visible=filtered.slice((opPage-1)*8,opPage*8);
  const canEditRecord=['authors','readers','accounts','inventory'].includes(key);
  const canDeleteRecord=['authors','readers'].includes(key);
  const body=visible.map(row=>`<tr><td>${escapeHTML(row.id)}</td>${conf.cols.map(([field])=>`<td>${field==='status'?opStatus(row[field]):field==='amount'||field==='fine'?opCash(row[field]):escapeHTML(row[field]??'—')}</td>`).join('')}<td class="op-actions">${key==='reservations'&&row.status==='Chờ duyệt'?`<button class="secondary-button" data-op-approve="${row.id}">Duyệt</button>`:''}${key==='reservations'&&window.libraryFeatureApi?.capabilities.reservationWorkflow&&['Cần kiểm tra chuyển khoản','Chờ thu tiền mặt'].includes(row.status)?`<button class="secondary-button" data-op-confirm-payment="${row.id}">Xác nhận đã thu</button>`:''}${key==='reservations'&&window.libraryFeatureApi?.capabilities.reservationWorkflow&&row.status==='Thanh toán thành công'?`<button class="secondary-button" data-op-handover="${row.id}">Giao sách</button>`:''}${key==='reservations'&&(!window.libraryFeatureApi?.capabilities.reservationWorkflow)&&row.status==='Đã giữ'?`<button class="secondary-button" data-op-fulfill="${row.id}">Giao sách</button>`:''}${key==='loans'&&['Đang mượn','Quá hạn'].includes(row.status)?`<button class="secondary-button" data-op-return="${row.id}">Nhận trả</button>`:''}${key==='returns'&&row.status==='Chờ nhận'?`<button class="secondary-button" data-op-process-return="${row.id}">Xác nhận trả</button>`:''}${key==='fines'&&row.status==='Chưa thanh toán'?`<button class="secondary-button" data-op-pay="${row.id}">Đã thu</button>`:''}${canEditRecord?`<button class="icon-button" data-op-edit="${key}:${key==='inventory'?(row.book_id??row.id):row.id}" aria-label="Sửa">✎</button>`:''}${canDeleteRecord?`<button class="icon-button" data-op-delete="${key}:${row.id}" aria-label="Xóa">×</button>`:''}</td></tr>`).join('');
  const head=`<tr><th>Mã</th>${conf.cols.map(([,label])=>`<th>${label}</th>`).join('')}<th>Thao tác</th></tr>`;
  const emptyMessage=key==='reservations'
    ?'<div class="empty-state"><strong>Chưa có yêu cầu đặt trước</strong><p>Các yêu cầu sẽ xuất hiện ở đây khi độc giả gửi yêu cầu đặt sách.</p></div>'
    :'<div class="empty-state"><strong>Chưa có dữ liệu phù hợp</strong><p>Thử đổi từ khóa hoặc thêm bản ghi mới.</p></div>';
  const table=filtered.length?`<div class="ops-table-wrap"><table class="ops-table"><thead>${head}</thead><tbody>${body}</tbody></table></div>`:emptyMessage;
  const pagination=pages>1?`<div class="ops-pages"><button class="filter-chip" data-op-page="${Math.max(1,opPage-1)}" ${opPage===1?'disabled':''}>← Trước</button><span>Trang ${opPage} / ${pages}</span><button class="filter-chip" data-op-page="${Math.min(pages,opPage+1)}" ${opPage===pages?'disabled':''}>Sau →</button></div>`:'';
  return {table,pagination,count:filtered.length};
}
function renderOpPage(key){
  const conf=opConfig[key], result=opRows(key);
  const canAdd=conf.fields&&!['reservations','fines'].includes(key);
  return `<div class="eyebrow">VẬN HÀNH THƯ VIỆN</div><div class="heading-row"><div><h1 class="page-title">${conf.title}</h1><p class="subheading">${conf.desc}</p></div>${canAdd?`<button class="primary-button" data-op-add="${key}">+ Thêm mới</button>`:''}</div><div class="ops-toolbar"><label class="global-search ops-search"><span>${icon('search')}</span><input id="ops-search" value="${escapeHTML(opQuery)}" placeholder="Tìm trong danh sách…" autocomplete="off"></label><span class="results-label">${result.count} kết quả</span></div><section class="list-card" id="ops-list">${result.table}</section>${result.pagination}`;
}
const coreRender = render;
render = function(){
  if(!opLabels[state.page]){coreRender();return;}
  document.getElementById('page-label').textContent=labels[state.page];
  document.querySelectorAll('.nav-link').forEach(a=>a.classList.toggle('active',a.dataset.page===state.page));
  syncCounts(); app.innerHTML=renderOpPage(state.page); app.focus({preventScroll:true});
  document.getElementById('sidebar').classList.remove('open');syncSidebar();
};
function opRefreshRows(){const host=document.getElementById('ops-list');if(!host)return;const rows=opRows(state.page);host.innerHTML=rows.table;const p=document.querySelector('.ops-pages');if(rows.pagination){if(p)p.outerHTML=rows.pagination;else host.insertAdjacentHTML('afterend',rows.pagination)}else if(p)p.remove();const count=document.querySelector('.ops-toolbar .results-label');if(count)count.textContent=`${rows.count} kết quả`;}
function opForm(key,id){
  const conf=opConfig[key], old=(opData[key]||[]).find(x=>String(x.id)===String(id));
  const inputs=conf.fields.map(([field,label,type,options])=>`<div class="field"><label for="op-${field}">${label}</label>${options?`<select id="op-${field}" name="${field}">${options.map(v=>`<option ${old?.[field]===v?'selected':''}>${v}</option>`).join('')}</select>`:`<input id="op-${field}" name="${field}" type="${type}" value="${escapeHTML(old?.[field]??'')}" ${type==='number'?'min="0"':''} required>`}</div>`).join('');
  showModal(`<form class="login-form" id="op-form" data-op-key="${key}" data-op-id="${old?escapeHTML(old.id):''}"><div class="eyebrow">VẬN HÀNH THƯ VIỆN</div><h2 id="modal-title">${old?'Cập nhật':'Thêm'} ${conf.title.toLocaleLowerCase('vi')}</h2><div class="ops-form-grid">${inputs}</div><button class="primary-button" type="submit">${old?'Lưu thay đổi':'Thêm bản ghi'}</button></form>`);
}
function opReturnForm(id){
  const row=opData.returns.find(x=>String(x.id)===String(id));
  if(!row)return;
  showModal(`<form class="login-form" id="return-form" data-return-id="${escapeHTML(row.id)}"><div class="eyebrow">NHẬN TRẢ SÁCH</div><h2 id="modal-title">Xác nhận sách đã trả</h2><p>${escapeHTML(row.reader)} · ${escapeHTML(row.book)}</p><div class="field"><label for="late-days">Số ngày trả trễ</label><input id="late-days" name="lateDays" type="number" min="0" value="0" required></div><p class="modal-note">Mức phạt mẫu: 5.000đ mỗi ngày trễ. Backend sẽ tính phí theo quy định thực tế.</p><button class="primary-button" type="submit">Xác nhận trả sách</button></form>`);
}
document.addEventListener('input',e=>{if(e.target.id==='ops-search'){opQuery=e.target.value;opPage=1;opRefreshRows();}});
document.addEventListener('click',e=>{
  const page=e.target.closest('[data-op-page]');if(page){opPage=Number(page.dataset.opPage);opRefreshRows();return;}
  if(window.libraryUser)return;
  const add=e.target.closest('[data-op-add]');if(add){if(add.dataset.opAdd==='inventory')return;opForm(add.dataset.opAdd);return;}
  const edit=e.target.closest('[data-op-edit]');if(edit){const [key,id]=edit.dataset.opEdit.split(':');if(key==='inventory')return;opForm(key,id);return;}
  const del=e.target.closest('[data-op-delete]');if(del){const [key,id]=del.dataset.opDelete.split(':');if(key==='inventory')return;const row=opData[key].find(x=>String(x.id)===id);if(row&&confirm(`Xóa bản ghi “${row.name||row.book||row.reader||row.id}”?`)){opData[key]=opData[key].filter(x=>String(x.id)!==id);opSave(key);if(key==='reservations')opSyncReservation(row,true);render();toast('Đã xóa bản ghi.');}return;}
  const approve=e.target.closest('[data-op-approve]');if(approve){const row=opData.reservations.find(x=>String(x.id)===approve.dataset.opApprove);if(row){row.status='Đã duyệt';opSave('reservations');opSyncReservation(row);const next=Math.max(1000,...opData.loans.map(x=>Number(x.id)||0))+1;opData.loans.unshift({id:next,reader:row.reader,book:row.book,borrowed:new Date().toLocaleDateString('vi-VN'),due:new Date(Date.now()+14*86400000).toLocaleDateString('vi-VN'),status:'Đang mượn'});opSave('loans');toast('Đã duyệt và tạo phiếu mượn.');render();}return;}
  const ret=e.target.closest('[data-op-return]');if(ret){const loan=opData.loans.find(x=>String(x.id)===ret.dataset.opReturn);if(loan){const id=loan.id;if(!opData.returns.some(x=>String(x.id)===String(id)&&x.status==='Chờ nhận')){opData.returns.unshift({id,reader:loan.reader,book:loan.book,due:loan.due,returned:'',lateDays:0,fine:0,status:'Chờ nhận'});loan.status='Chờ trả';opSave('returns');opSave('loans');}state.page='returns';history.replaceState(null,'','#returns');render();toast('Đã chuyển phiếu sang danh sách chờ nhận trả.');}return;}
  const process=e.target.closest('[data-op-process-return]');if(process){opReturnForm(process.dataset.opProcessReturn);return;}
  const pay=e.target.closest('[data-op-pay]');if(pay){const fine=opData.fines.find(x=>String(x.id)===pay.dataset.opPay);if(fine){fine.status='Đã thanh toán';opSave('fines');opRefreshRows();toast('Đã cập nhật trạng thái khoản phạt.');}return;}
});
document.addEventListener('submit',e=>{
  if(window.libraryUser)return;
  if(e.target.id==='return-form'){e.preventDefault();const form=e.target,row=opData.returns.find(x=>String(x.id)===form.dataset.returnId);if(!row)return;const days=Math.max(0,Number(new FormData(form).get('lateDays'))||0);row.lateDays=days;row.fine=days*5000;row.returned=new Date().toLocaleDateString('vi-VN');row.status='Đã nhận trả';const loan=opData.loans.find(x=>String(x.id)===String(row.id));if(loan)loan.status='Đã trả';if(days)opData.fines.unshift({id:Date.now(),reader:row.reader,reason:`Trả trễ ${days} ngày`,amount:row.fine,created:row.returned,status:'Chưa thanh toán'});opSave('returns');opSave('loans');opSave('fines');closeModal();render();toast(days?`Đã nhận sách và ghi nhận phạt ${opCash(row.fine)}.`:'Đã xác nhận trả sách đúng hạn.');return;}
  if(e.target.id!=='op-form')return;e.preventDefault();const form=e.target,key=form.dataset.opKey,id=form.dataset.opId,fd=new FormData(form),conf=opConfig[key];
  const record={};for(const [field] of conf.fields){let value=String(fd.get(field)||'').trim();if(field==='total'||field==='available'||field==='amount')value=Number(value)||0;record[field]=value;}
  if(id){opData[key]=opData[key].map(old=>String(old.id)===id?{...old,...record}:old);}else{const ident=key==='inventory'?(record.id||`TV-${String(Date.now()).slice(-5)}`):Math.max(0,...opData[key].map(x=>Number(x.id)||0))+1;record.id=ident;opData[key].unshift(record);}
  opSave(key);if(key==='reservations'&&id){const updated=opData.reservations.find(row=>String(row.id)===id);opSyncReservation(updated);}closeModal();render();toast(id?'Đã cập nhật bản ghi.':'Đã thêm bản ghi.');
});

// app.js chạy trước nên sẽ đưa các hash nghiệp vụ chưa biết về trang chủ.
// Render lại sau khi các trang nghiệp vụ đã được đăng ký.
const initialOpPage=location.hash.slice(1);
if(opLabels[initialOpPage]){state.page=initialOpPage;render();}
