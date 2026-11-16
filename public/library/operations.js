/* Tra cứu nghiệp vụ theo trang từ máy chủ. */
const opLabels = {
  reservations: 'Đặt trước', payments: 'Thanh toán', loans: 'Phiếu mượn', returns: 'Trả sách', readers: 'Độc giả',
  authors: 'Tác giả', accounts: 'Tài khoản & vai trò', inventory: 'Kho sách', fines: 'Phạt & vi phạm'
};
Object.assign(labels, opLabels);

const opConfig = {
  reservations:{title:'Quản lý yêu cầu đặt trước',desc:'Duyệt yêu cầu, xác nhận khoản thu và bàn giao sách theo từng bước.',fields:[['reader','Tên độc giả','text'],['book','Tên sách','text'],['created','Ngày yêu cầu','text'],['status','Trạng thái','select',['Chờ duyệt','Đã duyệt','Đã hủy']]],cols:[['reader','Độc giả'],['book','Sách'],['created','Ngày yêu cầu'],['payment_method','Thanh toán'],['amount','Số tiền'],['expires_at','Hạn giữ'],['status','Trạng thái']]},
  payments:{title:'Khoản thanh toán',desc:'Kiểm tra khoản tiền mặt đã nhận hoặc chuyển khoản mô phỏng trước khi xác nhận.',fields:null,cols:[['reader','Độc giả'],['purpose','Nội dung'],['amount','Số tiền'],['method','Phương thức'],['status','Trạng thái']]},
  loans:{title:'Quản lý phiếu mượn',desc:'Theo dõi người mượn, ngày đến hạn và tình trạng từng phiếu.',fields:[['reader','Tên độc giả','text'],['book','Tên sách','text'],['borrowed','Ngày mượn','text'],['due','Ngày hẹn trả','text'],['status','Trạng thái','select',['Đang mượn','Quá hạn','Đã trả']]],cols:[['reader','Độc giả'],['book','Sách'],['borrowed','Ngày mượn'],['due','Hẹn trả'],['status','Trạng thái']]},
  returns:{title:'Nhận và xử lý trả sách',desc:'Xác nhận bản sách đã trả, số ngày trễ và khoản phạt phát sinh.',fields:null,cols:[['reader','Độc giả'],['book','Sách'],['due','Hẹn trả'],['returned','Ngày trả'],['lateDays','Trễ (ngày)'],['fine','Tiền phạt'],['status','Trạng thái']]},
  readers:{title:'Hồ sơ độc giả',desc:'Tìm kiếm và cập nhật thông tin thành viên thư viện.',fields:[['name','Họ tên','text'],['email','Email','email'],['phone','Số điện thoại','tel'],['status','Trạng thái','select',['Hoạt động','Cần chú ý','Tạm khóa']]],cols:[['name','Họ tên'],['email','Email'],['phone','Điện thoại'],['status','Trạng thái']]},
  authors:{title:'Danh mục tác giả',desc:'Quản lý thông tin tác giả và các đầu sách liên quan.',fields:[['name','Tên tác giả','text'],['country','Tiểu sử','text'],['books','Sách liên quan','text']],cols:[['name','Tác giả'],['country','Quốc gia'],['books','Sách liên quan']]},
  accounts:{title:'Tài khoản và phân quyền',desc:'Quản lý trạng thái tài khoản và vai trò trong thư viện.',fields:[['name','Tên hiển thị','text'],['email','Email','email'],['role','Vai trò','select',['Độc giả','Thủ thư','Admin']],['status','Trạng thái','select',['Hoạt động','Tạm khóa']]],cols:[['name','Tài khoản'],['email','Email'],['role','Vai trò'],['status','Trạng thái']]},
  inventory:{title:'Kho và bản sách',desc:'Theo dõi số bản theo đầu sách và số lượng khả dụng.',fields:[['id','Mã bản sách','text'],['book','Tên sách','text'],['shelf','Vị trí kệ','text'],['total','Tổng bản','number'],['available','Còn sẵn','number'],['status','Trạng thái','select',['Đang lưu hành','Đang mượn hết','Ngưng lưu hành']]],cols:[['book','Tên sách'],['total','Lưu hành'],['onLoan','Đang mượn'],['reserved','Đang giữ'],['available','Khả dụng'],['status','Trạng thái']]},
  fines:{title:'Phạt và vi phạm',desc:'Theo dõi lý do, số tiền và lịch sử xử lý vi phạm.',fields:[['reader','Độc giả','text'],['reason','Lý do','text'],['amount','Số tiền (đ)','number'],['created','Ngày ghi nhận','text'],['status','Trạng thái','select',['Chưa thanh toán','Đã thanh toán','Đang khiếu nại']]],cols:[['reader','Độc giả'],['reason','Lý do'],['amount','Số tiền'],['created','Ngày ghi nhận'],['status','Trạng thái']]}
};
const opData = Object.fromEntries(Object.keys(opConfig).map(key => [key, []]));
const opViews = new Map();
const opFilters = {
  reservations: [['','Tất cả'],['pending','Chờ duyệt'],['ready','Đang giữ'],['fulfilled','Đã giao'],['expired','Hết hạn'],['cancelled','Đã hủy']],
  payments: [['queue','Hàng chờ'],['all','Tất cả'],['pending','Chờ thanh toán'],['confirming','Chờ xác nhận'],['paid','Đã thu'],['refunded','Đã hoàn'],['expired','Hết hạn'],['cancelled','Đã hủy']],
  loans: [['','Tất cả'],['active','Đang mượn'],['due_today','Đến hạn hôm nay'],['overdue','Quá hạn'],['returned','Đã kết thúc'],['deposit_due','Chưa tất toán cọc']],
  returns: [['','Tất cả'],['on_time','Đúng hạn'],['late','Trễ hạn']],
  readers: [['','Tất cả'],['active','Đang hoạt động'],['locked','Tạm khóa']],
  accounts: [['','Mọi vai trò'],['staff','Nhân viên'],['Admin','Admin'],['Thủ thư','Thủ thư'],['Độc giả','Độc giả']],
  inventory: [['','Tất cả'],['available','Còn khả dụng'],['unavailable','Hết bản khả dụng']],
  fines: [['','Tất cả'],['unpaid','Chưa thanh toán'],['paid','Đã thanh toán'],['waived','Đã miễn']],
};
const opAccountStatuses = [['','Mọi trạng thái'],['active','Đang hoạt động'],['locked','Đã khóa']];
function opView(key){
  if(!opViews.has(key)){
    opViews.set(key,{q:'',filter:opFilters[key]?.[0]?.[0]||'',accountStatus:'',page:1,pages:1,total:0,loading:false,loaded:false,error:'',serial:0});
  }
  return opViews.get(key);
}
function opStaffPage(key){
  const role=window.libraryUser?.role;
  return Boolean(opConfig[key])&&['Admin','Thủ thư'].includes(role)&&(!['accounts','authors'].includes(key)||role==='Admin');
}
function opReset(){
  opViews.clear();
  Object.keys(opData).forEach(key=>{opData[key]=[];});
}
function opSetFilter(key,filter,accountStatus=''){
  const view=opView(key);
  view.filter=filter;
  if(key==='accounts')view.accountStatus=accountStatus;
  view.page=1;
  view.loaded=false;
}
async function opLoad(key,page=opView(key).page){
  if(!opStaffPage(key)||typeof authRequest!=='function')return;
  const identity=window.libraryUser;
  const view=opView(key);
  const serial=++view.serial;
  view.page=page;
  view.loading=true;
  view.error='';
  opData[key]=[];
  if(state.page===key)opRefreshRows();
  const current=()=>opViews.get(key)===view&&window.libraryUser===identity&&view.serial===serial;
  const params=new URLSearchParams({page:String(page),per_page:'8'});
  if(view.q)params.set('q',view.q);
  if(view.filter)params.set(key==='accounts'?'role':'status',view.filter);
  if(key==='accounts'&&view.accountStatus)params.set('status',view.accountStatus);
  if(key==='loans')params.set('summary','1');
  try{
    const payload=await authRequest('/staff/'+key+'?'+params.toString());
    if(!current())return;
    if(page>Number(payload.last_page||1)){
      await opLoad(key,Number(payload.last_page||1));
      return;
    }
    opData[key]=(payload.data||[]).map(row=>authMapStaffRow(key,row));
    Object.assign(view,{page:Number(payload.current_page)||1,pages:Number(payload.last_page)||1,total:Number(payload.total)||0});
  }catch(error){
    if(current())view.error=error.message;
  }finally{
    if(current()){
      view.loading=false;
      view.loaded=true;
      if(state.page===key)opRefreshRows();
    }
  }
}
function opCash(value){return new Intl.NumberFormat('vi-VN').format(Number(value)||0)+'đ';}
function opStatus(value){const kind=String(value).includes('Chờ')||String(value).includes('Quá hạn')||String(value).includes('Chú ý')||String(value).includes('Chưa')?'warn':String(value).includes('Đã trả')||String(value).includes('thanh toán')?'done':'';return `<span class="status-pill ${kind}">${escapeHTML(value||'—')}</span>`;}
function opPaymentStatus(row){return ({pending:row.method==='cash'?'Chờ thu tiền mặt':'Chờ báo chuyển khoản mô phỏng',confirming:'Cần kiểm tra chuyển khoản mô phỏng',paid:'Đã xác nhận thanh toán',cancelled:'Đã hủy',expired:'Đã hết hạn',refunded:'Đã hoàn phí',failed:'Thất bại'})[row.raw_status]||'Chưa rõ trạng thái';}
function opRows(key){
  const conf=opConfig[key],view=opView(key),visible=opData[key]||[];
  const pages=view.pages, page=view.page;
  if(view.loading||!view.loaded)return {table:'<p class="empty-state" role="status">Đang tải dữ liệu…</p>',pagination:'',count:null};
  if(view.error)return {table:'<div class="empty-state" role="alert"><p>'+escapeHTML(view.error)+'</p><button class="secondary-button" data-op-retry>Thử lại</button></div>',pagination:'',count:null};
  const canEditRecord=['authors','readers','accounts','inventory'].includes(key);
  const canDeleteRecord=['authors','readers'].includes(key);
  const body=visible.map(row=>`<tr><td>${escapeHTML(row.id)}</td>${conf.cols.map(([field])=>`<td>${field==='status'?opStatus(key==='payments'?opPaymentStatus(row):row[field]):field==='amount'||field==='fine'?opCash(row[field]):field==='purpose'?escapeHTML(`${row.purpose==='vip'?'Gói VIP · 1 tháng':row.purpose==='reservation'?'Đặt trước':row.purpose==='fine'?'Thu phạt':row.purpose||'Khoản thu'}${row.reference?` · ${row.reference}`:''}`):field==='method'?escapeHTML(row.method==='bank_transfer'?'Chuyển khoản mô phỏng':row.method==='cash'?'Tiền mặt':row[field]??'—'):escapeHTML(row[field]??'—')}</td>`).join('')}<td class="op-actions">${key==='payments'&&window.libraryFeatureApi?.capabilities.payments&&((row.method==='cash'&&row.raw_status==='pending')||(row.method==='bank_transfer'&&row.raw_status==='confirming'))?`<button class="secondary-button" data-op-confirm-ledger="${escapeHTML(row.id)}">Xác nhận đã thu</button>`:''}${key==='inventory'&&['Admin','Thủ thư'].includes(window.libraryUser?.role)&&row.book_id?`<a class="link-button" href="/staff/books/${encodeURIComponent(row.book_id)}/epub/upload">Tải EPUB</a>`:''}${key==='reservations'&&row.status==='Chờ duyệt'?`<button class="secondary-button" data-op-approve="${row.id}">Duyệt</button>`:''}${key==='reservations'&&window.libraryFeatureApi?.capabilities.reservationWorkflow&&['Cần kiểm tra chuyển khoản mô phỏng','Chờ thu tiền mặt'].includes(row.status)?`<button class="secondary-button" data-op-confirm-payment="${row.id}">Xác nhận đã thu</button>`:''}${key==='reservations'&&window.libraryFeatureApi?.capabilities.reservationWorkflow&&row.status==='Thanh toán thành công'?`<button class="secondary-button" data-op-handover="${row.id}">Giao sách</button>`:''}${key==='reservations'&&row.raw_status==='ready'&&row.payment_status==null&&['Đã giữ','Chưa rõ thanh toán'].includes(row.status)?`<button class="secondary-button" data-op-fulfill="${row.id}">Giao sách</button>`:''}${key==='loans'&&(row.raw_status==='active'||['Đang mượn','Quá hạn'].includes(row.status))?`<button class="secondary-button" data-op-return="${row.id}">Nhận trả</button>`:''}${key==='returns'&&row.status==='Chờ nhận'?`<button class="secondary-button" data-op-process-return="${row.id}">Xác nhận trả</button>`:''}${canEditRecord?`<button class="icon-button" data-op-edit="${key}:${key==='inventory'?(row.book_id??row.id):row.id}" aria-label="Sửa">✎</button>`:''}${canDeleteRecord?`<button class="icon-button" data-op-delete="${key}:${row.id}" aria-label="Xóa">×</button>`:''}${opExtraActions(key,row)}</td></tr>`).join('');
  const head=`<tr><th>Mã</th>${conf.cols.map(([,label])=>`<th>${label}</th>`).join('')}<th>Thao tác</th></tr>`;
  const emptyMessage=key==='reservations'
    ?'<div class="empty-state"><strong>Chưa có yêu cầu đặt trước</strong><p>Các yêu cầu sẽ xuất hiện ở đây khi độc giả gửi yêu cầu đặt sách.</p></div>'
    :key==='payments'
      ?'<div class="empty-state"><strong>Chưa có khoản thanh toán</strong><p>Các khoản chờ xác nhận sẽ xuất hiện tại đây.</p></div>'
    :'<div class="empty-state"><strong>Chưa có dữ liệu phù hợp</strong><p>Thử đổi từ khóa hoặc thêm bản ghi mới.</p></div>';
  const table=visible.length?`<div class="ops-table-wrap"><table class="ops-table"><thead>${head}</thead><tbody>${body}</tbody></table></div>`:emptyMessage;
  const pagination=pages>1?`<div class="ops-pages"><button class="filter-chip" data-op-page="${Math.max(1,page-1)}" ${page===1?'disabled':''}>← Trước</button><span>Trang ${page} / ${pages}</span><button class="filter-chip" data-op-page="${Math.min(pages,page+1)}" ${page===pages?'disabled':''}>Sau →</button></div>`:'';
  return {table,pagination,count:view.total};
}
function opSelectFilter(id,label,items,selected){
  return '<label class="ops-filter"><span>'+label+'</span><select id="'+id+'">'+items.map(([value,option])=>
    '<option value="'+escapeHTML(value)+'"'+(value===selected?' selected':'')+'>'+escapeHTML(option)+'</option>').join('')+'</select></label>';
}
function renderOpPage(key){
  const conf=opConfig[key],result=opRows(key),view=opView(key);
  const isAdmin=window.libraryUser?.role==='Admin';
  const isAdminFines=isAdmin&&key==='fines';
  const title=isAdminFines?'Phạt & miễn phạt':conf.title;
  const description=isAdminFines?'Tra cứu phiếu phạt và ghi nhận miễn các khoản chưa thu.':conf.desc;
  const eyebrow=isAdmin?'QUẢN TRỊ THƯ VIỆN':'VẬN HÀNH THƯ VIỆN';
  const filters=opFilters[key]||[];
  const filter=filters.length?opSelectFilter('ops-status',key==='accounts'?'Vai trò':'Bộ lọc',filters,view.filter):'';
  const accountStatus=key==='accounts'?opSelectFilter('ops-account-status','Trạng thái',opAccountStatuses,view.accountStatus):'';
  const placeholder=key==='loans'?'Mã phiếu, độc giả hoặc sách':'Tìm theo mã hoặc từ khóa';
  const action = key === 'inventory'
    ? '<button class="primary-button" data-add-book>+ Thêm sách</button>'
    : key === 'returns'
      ? '<button class="primary-button" data-page-link="loans">Chọn phiếu cần nhận trả</button>'
      : conf.fields && key !== 'reservations' && !isAdminFines
        ? '<button class="primary-button" data-op-add="'+key+'">'+(key==='fines'?'+ Lập phiếu phạt':'+ Thêm mới')+'</button>' : '';
  return `<div class="eyebrow">${eyebrow}</div><div class="heading-row"><div><h1 class="page-title">${title}</h1><p class="subheading">${description}</p></div>${action}</div><form class="ops-toolbar" id="ops-search-form"><label class="global-search ops-search"><span aria-hidden="true">${icon('search')}</span><input type="search" id="ops-search" aria-label="Tìm bản ghi" value="${escapeHTML(view.q)}" placeholder="${placeholder}" maxlength="100" autocomplete="off"></label>${filter}${accountStatus}<button type="submit" class="secondary-button">Tìm</button><span class="results-label" role="status">${result.count===null?'':result.count+' kết quả'}</span></form><section class="list-card" id="ops-list">${result.table}</section>${result.pagination}`;
}
const coreRender = render;
render = function(){
  if(!opLabels[state.page]){coreRender();return;}
  document.getElementById('page-label').textContent=labels[state.page];
  document.querySelectorAll('.nav-link').forEach(a=>a.classList.toggle('active',a.dataset.page===state.page));
  syncCounts(); app.innerHTML=renderOpPage(state.page); app.focus({preventScroll:true});
  document.getElementById('sidebar').classList.remove('open');syncSidebar();
  const view=opView(state.page);
  if(!view.loaded&&!view.loading)opLoad(state.page);
};
function opRefreshRows(){
  const host=document.getElementById('ops-list');
  if(!host||!opConfig[state.page])return;
  const rows=opRows(state.page);
  host.innerHTML=rows.table;
  const pagination=document.querySelector('.ops-pages');
  if(rows.pagination){
    if(pagination) pagination.outerHTML=rows.pagination;
    else host.insertAdjacentHTML('afterend',rows.pagination);
  }else pagination?.remove();
  const count=document.querySelector('.ops-toolbar .results-label');
  if(count) count.textContent=rows.count===null?'':rows.count+' kết quả';
}
document.addEventListener('submit',event=>{
  if(event.target.id!=='ops-search-form'||!opStaffPage(state.page))return;
  event.preventDefault();
  opView(state.page).q=event.target.querySelector('#ops-search').value.trim();
  opLoad(state.page,1);
});
document.addEventListener('change',event=>{
  if(!['ops-status','ops-account-status'].includes(event.target.id)||!opStaffPage(state.page))return;
  const view=opView(state.page);
  if(event.target.id==='ops-account-status')view.accountStatus=event.target.value;
  else view.filter=event.target.value;
  view.q=event.target.form.querySelector('#ops-search').value.trim();
  opLoad(state.page,1);
});
document.addEventListener('click',event=>{
  const button=event.target.closest('[data-op-page],[data-op-retry]');
  if(!button||!opStaffPage(state.page))return;
  opLoad(state.page,Number(button.dataset.opPage)||opView(state.page).page);
});

function opExtraActions(key, row) {
  const role = window.libraryUser?.role;
  if (!['Admin', 'Thủ thư'].includes(role)) return '';
  const id = encodeURIComponent(row.id);
  const receipt = (url, title = 'In phiếu') =>
    '<a class="link-button" href="' + url + '" target="_blank" rel="noopener">' + title + '</a>';
  if (key === 'loans') {
    const labels = {payment_due: 'Thanh toán phiếu mượn', refund_due: 'Hoàn cọc', ready_to_settle: 'Tất toán cọc'};
    const action = row.moneySummary?.can_settle
      ? '<button class="secondary-button" type="button" data-op-settle="' + id + '">' +
        (labels[row.moneySummary.status] || 'Đối soát cọc') + '</button>'
      : '';
    return action + '<button class="link-button" data-staff-loan="' + id + '">Xem phiếu</button>' + receipt('/receipts/loans/' + id);
  }
  if (key === 'returns' && row.loan_id) {
    return '<button class="link-button" data-staff-loan="' + encodeURIComponent(row.loan_id) + '">Xem phiếu mượn</button>';
  }
  if (key === 'fines') {
    const isAdmin = role === 'Admin';
    const action = row.raw_status === 'unpaid'
      ? '<button class="secondary-button" type="button" ' + (isAdmin ? 'data-op-waive' : 'data-op-pay') +
        '="' + id + '">' + (isAdmin ? 'Miễn phạt' : 'Đã thu') + '</button>' : '';
    return action + receipt('/staff/receipts/fines/' + id, isAdmin ? 'Xem phiếu' : 'In phiếu');
  }
  if (key === 'payments') {
    if (['paid', 'refunded'].includes(row.raw_status)) return receipt('/staff/receipts/payments/' + id, 'In biên nhận');
    if (row.purpose === 'fine' && ['pending', 'confirming'].includes(row.raw_status)) {
      return '<button class="secondary-button" type="button" data-op-cancel-payment="' + id + '">Hủy giao dịch</button>';
    }
  }
  return '';
}
