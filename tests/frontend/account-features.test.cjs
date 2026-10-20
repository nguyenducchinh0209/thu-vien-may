const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {webcrypto} = require('node:crypto');
const apiSource = fs.readFileSync(path.join(__dirname,'../../public/library/feature-api.js'),'utf8');
const uiSource = fs.readFileSync(path.join(__dirname,'../../public/library/account-features.js'),'utf8');
const authSource = fs.readFileSync(path.join(__dirname,'../../public/library/auth-admin.js'),'utf8');
const tick = () => new Promise(resolve=>setImmediate(resolve));
const future = () => new Date(Date.now()+3600000).toISOString();
const past = () => new Date(Date.now()-3600000).toISOString();
function harness(config={}, transport=async()=>{throw new Error('Unexpected network call');}) {
  const events = new Map(), elements = new Map();
  function element() {
    return {innerHTML:'',hidden:true,dataset:{},value:'',contains:()=>false,matches:()=>false,setAttribute(){},focus(){},querySelector:()=>null,addEventListener(){},append(){}};
  }
  ['account-menu','account-toggle','account-dropdown','library-support'].forEach(id=>elements.set(id,element()));
  const document = {activeElement:null,body:element(),getElementById:id=>elements.get(id)||null,querySelector:selector=>selector.includes('csrf-token')?{content:'test-csrf'}:null,createElement:element,addEventListener(name,handler){if(!events.has(name))events.set(name,[]);events.get(name).push(handler);}};
  const context = vm.createContext({URL,AbortController,setTimeout,clearTimeout,queueMicrotask,crypto:webcrypto,location:{origin:'http://localhost'},document,fetch:transport,libraryFeatureConfig:config,matchMedia:()=>({matches:false}),navigator:{clipboard:{writeText:async()=>{}}},escapeHTML:value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])),state:{page:'profile',avatar:''},authSession:{id:1,name:'Reader',email:'reader@example.test',role:'Độc giả'},authServerData:{profile:{gender:null}},render(){},toast(){},authRequest:async()=>({profile:{gender:null}})});
  context.window=context;
  vm.runInContext(apiSource,context);
  vm.runInContext(uiSource,context);
  const feature=vm.runInContext('accountFeatures',context);
  const fire = (selector,data={}) => events.get('click').forEach(handler=>handler({target:{closest:key=>key===selector?{dataset:data}:null}}));
  return {context,feature,events,elements,fire,api:context.libraryFeatureApi,rules:context.LibraryFeatureRules};
}
const response = data => ({ok:true,json:async()=>data});
function quote(purpose='reservation') {
  return {id:'q-1',purpose,reference_id:'7',currency:'VND',total_amount:10000,expires_at:future(),methods:['bank_transfer','cash'],items:[{title:'Book <script>',quantity:2,unit_amount:5000,line_amount:10000}],...(purpose==='reservation'?{reservation:{id:7,status:'ready',expires_at:future(),payment_status:'unpaid'}}:{})};
}
test('all optional features fail closed without sending requests',async()=>{
  let calls=0;const {api}=harness({},async()=>{calls++;});
  for(const action of [()=>api.membership(),()=>api.plans(),()=>api.quote('vip',1),()=>api.createPayment('q','cash','key'),()=>api.payment(1),()=>api.reportTransfer(1),()=>api.chat('hello',[]),()=>api.submitCartReservation('cash'),()=>api.reservationAction(7,'approve'),()=>api.reservations()])await assert.rejects(action(),{name:'FeatureUnavailable'});
  assert.equal(calls,0);assert.equal(api.capabilities.gender,false);
});
test('enabled contracts send same-origin credentials, CSRF and idempotency key',async()=>{
  const calls=[];const {api}=harness({capabilities:{payments:true},endpoints:{payments:'/test-payments'}},async(url,options)=>{calls.push({url,options});return response({payment:{id:1}});});
  await api.createPayment('quote-1','cash','stable-key');
  assert.equal(calls[0].url,'http://localhost/test-payments');assert.equal(calls[0].options.credentials,'same-origin');
  assert.equal(calls[0].options.headers['X-CSRF-TOKEN'],'test-csrf');
  assert.deepEqual(JSON.parse(calls[0].options.body),{quote_id:'quote-1',method:'cash',idempotency_key:'stable-key'});
});
test('transfer report uses the payment-specific API route with same-origin CSRF',async()=>{
  const calls=[];const {api}=harness({capabilities:{payments:true}},async(url,options)=>{calls.push({url,options});return response({payment:{id:'p-1',status:'confirming'}});});
  await api.reportTransfer('p-1');
  assert.equal(calls[0].url,'http://localhost/my/payments/p-1/transfer');assert.equal(calls[0].options.method,'POST');
  assert.equal(calls[0].options.headers['X-CSRF-TOKEN'],'test-csrf');assert.deepEqual(JSON.parse(calls[0].options.body),{});
});
test('reservation detail fetch is an owner-scoped read-only GET through the feature API',async()=>{
  const calls=[];const {api}=harness({capabilities:{reservationWorkflow:true}},async(url,options)=>{calls.push({url,options});return response({reservation:{id:7,status:'pending'},payment:null,loan:null});});
  const result=await api.reservation('7');
  assert.equal(calls[0].url,'http://localhost/my/reservations/7');assert.equal(calls[0].options.method,'GET');
  assert.equal(result.reservation.id,7);
});
test('foreign endpoints, invalid JSON and server errors are surfaced',async()=>{
  const foreign=harness({capabilities:{chat:true},endpoints:{chat:'https://other.test/chat'}});
  await assert.rejects(foreign.api.chat('hello',[]),/Địa chỉ/);
  const invalid=harness({capabilities:{chat:true}},async()=>({ok:true,json:async()=>{throw new Error('bad json');}}));
  await assert.rejects(invalid.api.chat('hello',[]),/không hợp lệ/);
  const failed=harness({capabilities:{chat:true}},async()=>({ok:false,json:async()=>({message:'Busy'})}));
  await assert.rejects(failed.api.chat('hello',[]),/Busy/);
});
test('reservation eligibility rejects missing payment state, expiry and all non-ready states',()=>{
  const {rules}=harness(),ready={status:'ready',expires_at:future(),payment_status:'unpaid'};
  assert.equal(rules.reservationPayable(ready),true);
  for(const status of ['pending','cancelled','fulfilled','expired'])assert.equal(rules.reservationPayable({...ready,status}),false);
  for(const row of [{...ready,expires_at:past()},{...ready,expires_at:null},{...ready,payment_status:'paid'},{...ready,payment_status:undefined}])assert.equal(rules.reservationPayable(row),false);
});
test('reservation list links resume existing payment but never starts a second payment',()=>{
  const h=harness({capabilities:{payments:true,reservationWorkflow:true}});
  const row={id:7,status:'ready',expires_at:future(),payment_status:'unpaid'};
  assert.match(h.feature.paymentAction(row),/Thanh toán/);
  for(const status of ['pending','confirming','paid'])assert.match(h.feature.paymentAction({...row,payment_status:status}),/Xem thanh toán/);
  for(const changed of [{...row,status:'fulfilled'},{...row,expires_at:past()},{...row,payment_status:null}])assert.equal(h.feature.paymentAction(changed),'');
});
test('reservation detail shows every state without creating payments and keeps paid receipt and loan snapshots',async()=>{
  const fixtures=[
    {reservation:{id:7,status:'pending',quantity:1,book:{title:'A book'}},payment:null,loan:null},
    {reservation:{id:7,status:'ready',quantity:1,expires_at:future(),payment_status:'unpaid',book:{title:'A book'}},payment:null,loan:null},
    {reservation:{id:8,status:'ready',quantity:1,expires_at:future(),payment_status:null,amount:null,book:{title:'Legacy book'}},payment:null,loan:null},
    {reservation:{id:7,status:'expired',quantity:1,payment_status:'unpaid',book:{title:'A book'}},payment:null,loan:null},
    {reservation:{id:7,status:'fulfilled',quantity:1,payment_status:'paid',book:{title:'A book'}},payment:{id:'pay-7',status:'paid',method:'cash',amount:5000,confirmed_at:future()},loan:{id:22,status:'active',issued_at:future(),due_at:future(),rental_fee_total:5000,rental_fee_received:5000}},
  ];
  for(const fixture of fixtures){
    const calls=[];const h=harness({capabilities:{reservationWorkflow:true,payments:true}},async(url,options)=>{calls.push({url,options});return response(fixture);});
    h.feature.setRoute(`reservation/${fixture.reservation.id}`);h.context.state.page='reservation-detail';h.feature.enter();await tick();
    const html=h.feature.view();
    assert.equal(calls.length,1);assert.equal(calls[0].url,`http://localhost/my/reservations/${fixture.reservation.id}`);assert.equal(calls[0].options.method,'GET');
    assert.doesNotMatch(html,/data-payment-create/);
    if(fixture.reservation.status==='pending'){
      assert.match(html,/đang chờ nhân viên thư viện duyệt/);assert.doesNotMatch(html,/checkout\/reservation\/7/);
    } else if(fixture.reservation.status==='ready'&&fixture.reservation.payment_status==null){
      assert.match(html,/Tạo phiếu thanh toán/);assert.match(html,/phí hiện tại của sách sẽ được áp dụng/);assert.match(html,/hạn giữ sách không đổi/);
      assert.doesNotMatch(html,/Số tiền/);
    } else if(fixture.reservation.status==='ready'){
      assert.match(html,/checkout\/reservation\/7/);
    } else if(fixture.reservation.status==='expired'){
      assert.match(html,/Đã hết hạn/);assert.doesNotMatch(html,/checkout\/reservation\/7/);
    } else {
      assert.match(html,/Biên nhận thanh toán/);assert.match(html,/pay-7/);assert.match(html,/Phiếu mượn đã liên kết/);assert.match(html,/22/);
    }
  }
});
test('failed reservation detail fetch shows one error and only retries after an explicit request',async()=>{
  let calls=0;
  const h=harness({capabilities:{reservationWorkflow:true}},async()=>{
    calls++;
    return calls===1
      ? {ok:false,json:async()=>({message:'Reservation unavailable'})}
      : response({reservation:{id:7,status:'pending',book:{title:'A book'}},payment:null,loan:null});
  });
  h.feature.init();h.feature.setRoute('reservation/7');h.context.state.page='reservation-detail';h.feature.enter();await tick();
  assert.match(h.feature.view(),/Reservation unavailable/);
  h.feature.enter();await tick();
  assert.equal(calls,1);
  h.fire('[data-feature-retry]',{featureRetry:'reservation'});await tick();await tick();
  assert.equal(calls,2);
  assert.match(h.feature.view(),/A book/);
});
test('fine payment action only appears for unpaid fines and fee checkout resumes existing ledger payment',async()=>{
  const quoteData={...quote('fine'),reference_id:'52',items:[{title:'Phí vi phạm',quantity:1,unit_amount:25000,line_amount:25000}],total_amount:25000};
  assert.match(harness({capabilities:{payments:true}}).feature.finePaymentAction({id:52,raw_status:'unpaid'}),/checkout\/fine\/52/);
  assert.equal(harness().feature.finePaymentAction({id:52,raw_status:'unpaid'}),'');
  assert.equal(harness({capabilities:{payments:true}}).feature.finePaymentAction({id:52,raw_status:'paid'}),'');

  const existing={id:'fine-pay',quote_id:quoteData.id,method:'bank_transfer',status:'pending',transfer:{simulated:true,bank_name:'Demo Bank'}};
  const calls=[];const h=harness({capabilities:{payments:true}},async(url,options)=>{calls.push({url,options});return response({quote:quoteData,payment:existing});});
  h.feature.setRoute('checkout/fine/52');h.context.state.page='checkout';h.feature.enter();await tick();
  assert.match(h.feature.view(),/Thanh toán khoản phạt/);assert.match(h.feature.view(),/Demo Bank/);assert.doesNotMatch(h.feature.view(),/data-payment-create/);
  assert.equal(calls.length,1);assert.equal(calls[0].url,'http://localhost/my/payment-quotes');
  assert.deepEqual(JSON.parse(calls[0].options.body),{purpose:'fine',reference_id:'52'});
});
test('confirmed fine payment refreshes the server fine list before leaving checkout',async()=>{
  const fineQuote={...quote('fine'),reference_id:'52'};
  const pending={id:'fine-pay',quote_id:fineQuote.id,method:'cash',status:'pending'};
  let fineListRefreshes=0;
  const h=harness({capabilities:{payments:true}},async(url)=>url.endsWith('/my/payment-quotes')
    ? response({quote:fineQuote,payment:pending})
    : response({payment:{...pending,status:'paid'}}));
  h.context.authRefreshReaderData=async()=>{fineListRefreshes++;};
  h.feature.init();h.feature.setRoute('checkout/fine/52');h.context.state.page='checkout';h.feature.enter();await tick();
  h.fire('[data-payment-check]');await tick();await tick();
  assert.equal(fineListRefreshes,1);
});
test('reader list keeps a detail link for every reservation and offers payment only for unpaid fees',()=>{
  const recordsStart=authSource.indexOf('function authReaderRecords(kind){');
  const pageStart=authSource.indexOf('function authPersonalPage(kind){',recordsStart);
  const pageEnd=authSource.indexOf('\nfunction authLibrarianHome',pageStart);
  assert.notEqual(recordsStart,-1);assert.notEqual(pageStart,-1);assert.notEqual(pageEnd,-1);
  const source=authSource.slice(recordsStart,pageEnd);
  const context=vm.createContext({
    authServerData:{reservations:[
      {id:1,book:'Pending title',created:'2026-10-20',status:'Chờ duyệt',raw_status:'pending'},
      {id:2,book:'Expired title',created:'2026-10-19',status:'Đã hết hạn',raw_status:'expired'},
      {id:3,book:'Completed title',created:'2026-10-18',status:'Đã giao',raw_status:'fulfilled'},
    ],fines:[
      {id:51,reason:'Late fee',created:'2026-10-20',amount:25000,status:'Chưa thanh toán',raw_status:'unpaid',payment_status:null},
      {id:52,reason:'Paid fee',created:'2026-10-19',amount:5000,status:'Đã thanh toán',raw_status:'paid',payment_status:'paid'},
    ]},
    authSession:{email:'reader@example.test'},
    authAccountKey:value=>value,
    readStore:()=>[],
    authEscape:value=>String(value),
    fmtPrice:value=>`${value}đ`,
    accountFeatures:{
      reservationViewAction:row=>`<a data-page-link="reservation/${row.id}">Xem chi tiết</a>`,
      paymentAction:()=>'',
      finePaymentAction:row=>row.raw_status==='unpaid'?`<a data-page-link="checkout/fine/${row.id}">Thanh toán khoản phạt</a>`:'',
    },
    window:{libraryFeatureApi:{capabilities:{reservationWorkflow:true}}},
  });
  vm.runInContext(`${source}; globalThis.renderReaderPage=authPersonalPage;`,context);
  const reservations=vm.runInContext("renderReaderPage('reservations')",context);
  for(const id of [1,2,3])assert.match(reservations,new RegExp(`data-page-link="reservation/${id}"`));
  const fines=vm.runInContext("renderReaderPage('fines')",context);
  assert.match(fines,/data-page-link="checkout\/fine\/51"/);
  assert.doesNotMatch(fines,/data-page-link="checkout\/fine\/52"/);
});
test('missing or expired membership never becomes free or VIP implicitly',()=>{
  const {rules}=harness();assert.equal(rules.membershipLabel(null),'Chưa có thông tin');assert.equal(rules.membershipLabel({tier:'free',status:'active'}),'Miễn phí');
  assert.equal(rules.membershipLabel({tier:'free',status:'expired'}),'Chưa có thông tin');
  assert.equal(rules.membershipLabel({tier:'vip',status:'active',expires_at:future()}),'VIP');
  assert.equal(rules.membershipLabel({tier:'vip',status:'active',expires_at:past()}),'Chưa có thông tin');
  assert.equal(rules.paymentStatus('success'),'unknown');
});
test('profile screens escape data and disable unsupported gender without fake statistics',()=>{
  const {context,feature}=harness();context.authSession.name='<img onerror=alert(1)>';
  let html=feature.view();assert.match(html,/&lt;img/);assert.match(html,/Chưa cập nhật/);assert.doesNotMatch(html,/1.280|24<\/strong>/);
  context.state.page='profile-edit';html=feature.view();assert.match(html,/name="gender" disabled/);assert.match(html,/Không muốn chia sẻ/);assert.match(html,/data-page-link="profile"/);
});
test('avatar fallback uses initials, supported gender is editable, and unknown amounts are never zero',()=>{
  const h=harness({capabilities:{gender:true}});h.feature.init();
  assert.doesNotMatch(h.elements.get('account-menu').innerHTML,/src="http:\/\/localhost\//);
  h.context.state.page='profile-edit';assert.match(h.feature.view(),/name="gender" >/);
  for(const value of [null,undefined,'',false,'oops',NaN,Infinity,-1])assert.equal(h.rules.isAmount(value),false);
  for(const value of [0,5000,'5000.00'])assert.equal(h.rules.isAmount(value),true);
});
test('server avatar URLs render in the account profile and account menu',()=>{
  const h=harness();h.context.state.avatar='/storage/avatars/reader.webp';
  assert.match(h.feature.view(),/src="http:\/\/localhost\/storage\/avatars\/reader\.webp"/);
  h.feature.init();assert.match(h.elements.get('account-menu').innerHTML,/src="http:\/\/localhost\/storage\/avatars\/reader\.webp"/);
});
test('staff menus retain profile and logout without offering VIP registration',()=>{
  const h=harness();h.feature.init();
  for(const role of ['Admin','Thủ thư']){h.context.authSession.role=role;h.feature.syncMenu();const html=h.elements.get('account-menu').innerHTML;assert.match(html,/Sửa thông tin cá nhân/);assert.match(html,/data-auth-logout/);assert.doesNotMatch(html,/Đăng ký VIP|Xem gói VIP/);}
});
test('VIP plans come only from valid server data and link to the selected checkout',async()=>{
  const h=harness({capabilities:{plans:true}},async()=>response({plans:[{id:'month',name:'VIP Month',price:100000,duration_label:'1 tháng',benefits:['A benefit']}]}));
  h.feature.init();h.context.state.page='vip';h.feature.enter();await tick();assert.match(h.feature.view(),/checkout\/vip\/month/);assert.match(h.feature.view(),/A benefit/);
  const invalid=harness({capabilities:{plans:true}},async()=>response({plans:[{id:'month',name:'Incomplete',price:null,duration_label:'1 tháng',benefits:[]}]}));
  invalid.feature.init();invalid.context.state.page='vip';invalid.feature.enter();await tick();assert.match(invalid.feature.view(),/chưa đầy đủ/);assert.doesNotMatch(invalid.feature.view(),/checkout\/vip/);
});
test('disabled VIP and checkout have no invented prices, QR or success',async()=>{
  const {context,feature}=harness();feature.init();context.state.page='vip';feature.enter();assert.match(feature.view(),/Đăng ký VIP<\/button>/);assert.doesNotMatch(feature.view(),/₫/);
  context.state.page='checkout';feature.setRoute('checkout/reservation/7');feature.enter();await tick();
  const html=feature.view();assert.match(html,/đang chờ dịch vụ xác nhận/);assert.doesNotMatch(html,/data-payment-create|payment-qr|Thanh toán thành công/);
});
test('direct checkout refuses ineligible and expired server quotes',async()=>{
  for(const change of [q=>q.reservation.status='pending',q=>q.reservation.payment_status='paid',q=>q.expires_at=past(),q=>q.reservation.expires_at=past(),q=>q.reference_id='99']){
    const q=quote();change(q);const {context,feature}=harness({capabilities:{payments:true,reservationWorkflow:true}},async()=>response({quote:q}));
    feature.init();context.state.page='checkout';feature.setRoute('checkout/reservation/7');feature.enter();await tick();
    assert.match(feature.view(),/không còn đủ điều kiện/);assert.doesNotMatch(feature.view(),/data-payment-create/);
  }
});

test('reservation workflow remains disabled even if generic payments are enabled',async()=>{
  let calls=0;const h=harness({capabilities:{payments:true}},async()=>{calls++;return response({quote:quote()});});
  h.feature.init();h.context.state.page='checkout';h.feature.setRoute('checkout/reservation/7');h.feature.enter();await tick();
  assert.equal(calls,0);assert.match(h.feature.view(),/đang chờ dịch vụ xác nhận/);
  assert.equal(h.feature.paymentAction({id:7,status:'ready',expires_at:future(),payment_status:'unpaid'}),'');
});
test('cash stays pending, repeated clicks create one payment, rechecking accepts only server-confirmed status',async()=>{
  let creates=0,checks=0;let release;
  const pending = new Promise(resolve=>{release=resolve;});
  const h=harness({capabilities:{payments:true,reservationWorkflow:true}},async(url,options)=>{
    if(url.endsWith('payment-quotes'))return response({quote:quote()});
    if(options.method==='POST'){creates++;await pending;return response({payment:{id:'p-1',quote_id:'q-1',method:'cash',status:'pending'}});}
    checks++;return response({payment:{id:'p-1',quote_id:'q-1',method:'cash',status:'paid'}});
  });
  h.feature.init();h.context.state.page='checkout';h.feature.setRoute('checkout/reservation/7');h.feature.enter();await tick();
  assert.match(h.feature.view(),/Book &lt;script&gt;/);
  h.fire('[data-payment-create]');h.fire('[data-payment-create]');assert.equal(creates,1);release();await tick();
  assert.match(h.feature.view(),/Chờ thanh toán/);assert.doesNotMatch(h.feature.view(),/Thanh toán thành công/);
  h.fire('[data-payment-check]');await tick();assert.equal(checks,1);assert.match(h.feature.view(),/Thanh toán thành công/);
});
test('paid VIP refreshes membership; cash registration alone never activates VIP',async()=>{
  let memberships=0,status='pending';
  const h=harness({capabilities:{payments:true,membership:true}},async(url)=>{
    if(url.endsWith('payment-quotes'))return response({quote:quote('vip')});
    if(url.endsWith('membership')){memberships++;return response({membership:{tier:'vip',status:'active',expires_at:future()}});}
    return response({payment:{id:'p-1',quote_id:'q-1',method:'cash',status}});
  });
  h.feature.init();h.context.state.page='checkout';h.feature.setRoute('checkout/vip/7');h.feature.enter();await tick();h.fire('[data-payment-create]');await tick();assert.equal(memberships,0);
  status='paid';h.fire('[data-payment-check]');await tick();assert.equal(memberships,1);assert.match(h.elements.get('account-menu').innerHTML,/Xem gói VIP/);
});
test('payment outcomes render from the API and demo transfer details never imply real payment or QR',async()=>{
  for(const [status,label] of Object.entries({pending:'Chờ thanh toán',confirming:'Đang xác nhận',failed:'Thanh toán thất bại',cancelled:'Giao dịch đã hủy',expired:'Đã hết hạn',weird:'Chưa xác định được trạng thái'})){
    const h=harness({capabilities:{payments:true,reservationWorkflow:true}},async(url)=>response(url.endsWith('payment-quotes')?{quote:quote()}:{payment:{id:'p-1',quote_id:'q-1',method:'bank_transfer',status,transfer:{simulated:true,qr_url:'javascript:alert(1)',bank_name:'API Bank',content:'<reference>'}}}));
    h.feature.init();h.context.state.page='checkout';h.feature.setRoute('checkout/reservation/7');h.feature.enter();await tick();h.fire('[data-payment-create]');await tick();
    assert.ok(h.feature.view().includes(label));assert.doesNotMatch(h.feature.view(),/Thanh toán thành công|src="javascript:/);
    if(['pending','confirming'].includes(status)){assert.match(h.feature.view(),/API Bank/);assert.match(h.feature.view(),/&lt;reference&gt;/);assert.match(h.feature.view(),/Mô phỏng – không chuyển tiền thật/);assert.doesNotMatch(h.feature.view(),/payment-qr|Chuyển khoản QR/);}
  }
});
test('reservation and VIP checkout resume existing payments and report demo transfers through the payment endpoint',async()=>{
  for(const purpose of ['reservation','vip']){
    const q=quote(purpose);if(q.reservation)q.reservation.payment_status='pending';
    const existing={id:'p-1',quote_id:q.id,method:'bank_transfer',status:'pending',transfer:{simulated:true,bank_name:'Demo Bank',account_name:'Demo account',account_number:'DEMO-1',content:'DEMO-p-1'}};
    const calls=[];let memberships=0;
    const h=harness({capabilities:{payments:true,reservationWorkflow:true,membership:true}},async(url,options)=>{
      calls.push({url,options});
      if(url.endsWith('payment-quotes'))return response({quote:q,payment:existing});
      if(url.endsWith('/transfer'))return response({payment:{...existing,status:'confirming'}});
      if(url.endsWith('/membership')){memberships++;return response({membership:{tier:'vip',status:'active',expires_at:future()}});}
      return response({payment:{...existing,status:'paid'}});
    });
    h.feature.init();h.context.state.page='checkout';h.feature.setRoute(`checkout/${purpose}/7`);h.feature.enter();await tick();
    assert.match(h.feature.view(),/Chờ thanh toán/);assert.match(h.feature.view(),/Demo Bank/);assert.doesNotMatch(h.feature.view(),/data-payment-create/);
    h.fire('[data-payment-report]');await tick();
    assert.equal(calls.filter(call=>call.url.endsWith('/transfer')).length,1);assert.match(h.feature.view(),/Đang xác nhận/);
    h.fire('[data-payment-check]');await tick();assert.match(h.feature.view(),/Thanh toán thành công/);
    assert.equal(calls.filter(call=>call.url.endsWith('/transfer')).length,1);
    assert.equal(memberships,purpose==='vip'?1:0);
  }
});
test('leaving checkout reloads paid VIP quote while same-page retries retain their request key',async()=>{
  let quoteCalls=0;
  const h=harness({capabilities:{payments:true,membership:true}},async url=>{
    if(url.endsWith('payment-quotes')){
      quoteCalls++;
      const q=quote('vip');
      return response({quote:q,payment:quoteCalls===1?{id:'paid-1',quote_id:q.id,method:'cash',status:'paid'}:null});
    }
    return response({membership:{tier:'vip',status:'active',expires_at:future()}});
  });
  h.feature.init();h.context.state.page='checkout';h.feature.setRoute('checkout/vip/7');h.feature.enter();await tick();
  assert.equal(quoteCalls,1);assert.doesNotMatch(h.feature.view(),/data-payment-create/);
  h.feature.setRoute('profile');h.context.state.page='profile';
  h.feature.setRoute('checkout/vip/7');h.context.state.page='checkout';h.feature.enter();await tick();
  assert.equal(quoteCalls,2);assert.match(h.feature.view(),/data-payment-create/);
});
test('retrying payment after an uncertain network failure reuses the idempotency key',async()=>{
  const keys=[];const h=harness({capabilities:{payments:true,reservationWorkflow:true}},async(url,options)=>{
    if(url.endsWith('payment-quotes'))return response({quote:quote()});
    keys.push(JSON.parse(options.body).idempotency_key);if(keys.length===1)throw new Error('Network lost');
    return response({payment:{id:'p-1',quote_id:'q-1',method:'bank_transfer',status:'pending'}});
  });
  h.feature.init();h.context.state.page='checkout';h.feature.setRoute('checkout/reservation/7');h.feature.enter();await tick();
  h.fire('[data-payment-create]');await tick();h.fire('[data-payment-create]');await tick();assert.equal(keys.length,2);assert.equal(keys[0],keys[1]);
});
test('chat sends once, escapes assistant content, links books, and ignores late replies after logout',async()=>{
  let calls=0,release;const waiting=new Promise(resolve=>{release=resolve;});
  const h=harness({capabilities:{chat:true}},async()=>{calls++;await waiting;return response({answer:'Try <b>this</b>',books:[{id:3,title:'A book'}]});});
  h.feature.init();h.fire('[data-chat-suggest]',{chatSuggest:'Find books'});h.fire('[data-chat-suggest]',{chatSuggest:'Find books'});assert.equal(calls,1);
  release();await tick();assert.match(h.elements.get('library-support').innerHTML,/Try &lt;b&gt;/);assert.match(h.elements.get('library-support').innerHTML,/book\/3/);
  h.feature.reset(null);assert.doesNotMatch(h.elements.get('library-support').innerHTML,/Find books|Try &lt;b&gt;/);
  let later;const h2=harness({capabilities:{chat:true}},()=>new Promise(resolve=>{later=resolve;}));h2.feature.init();h2.fire('[data-chat-suggest]',{chatSuggest:'Private draft'});h2.feature.reset(null);later(response({answer:'Late answer'}));await tick();assert.doesNotMatch(h2.elements.get('library-support').innerHTML,/Private draft|Late answer/);
});
test('failed chat offers retry without duplicating the user message',async()=>{
  let calls=0;const h=harness({capabilities:{chat:true}},async()=>{calls++;if(calls===1)throw new Error('Offline');return response({answer:'Available again'});});
  h.feature.init();h.fire('[data-chat-suggest]',{chatSuggest:'How to borrow?'});await tick();assert.match(h.elements.get('library-support').innerHTML,/Offline/);
  h.fire('[data-chat-retry]');await tick();const html=h.elements.get('library-support').innerHTML;assert.match(html,/Available again/);assert.equal((html.match(/How to borrow\?/g)||[]).length,1);
});
