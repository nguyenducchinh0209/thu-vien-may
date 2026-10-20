/* Account screens share the existing auth router and render lifecycle. */
const accountFeatures = (() => {
  'use strict';
  if (typeof labels !== 'undefined') labels['reservation-detail'] = 'Chi tiết đặt trước';
  const api = window.libraryFeatureApi, rules = window.LibraryFeatureRules;
  const genderLabels = {'':'Chưa cập nhật', male:'Nam', female:'Nữ', other:'Khác', prefer_not_to_say:'Không muốn chia sẻ'};
  let epoch = 0, identity = null, membership = null, membershipLoading = false;
  let plans = null, plansError = '', plansLoading = false, checkout = null, route = null;
  let reservationRecord = null, reservationLoading = false, reservationError = '';
  let menuOpen = false, closeTimer, chatOpen = false, chatBusy = false, chatError = '', lastMessage = '';
  let messages = [], chatController = null, avatarUrl = null;
  const esc = value => escapeHTML(String(value ?? ''));
  const date = value => value && Number.isFinite(Date.parse(value)) ? new Date(value).toLocaleString('vi-VN') : 'Chưa cập nhật';
  const money = value => rules.isAmount(value) ? `${Number(value).toLocaleString('vi-VN')} ₫` : 'Chưa có thông tin';
  const button = (label, page, kind='secondary-button') => `<button class="${kind}" data-page-link="${esc(page)}">${label}</button>`;
  const unavailable = (message, retry='') => `<div class="feature-notice" role="status"><span aria-hidden="true">✳</span><div><strong>Chưa thể sử dụng lúc này</strong><p>${esc(message)}</p>${retry ? `<button class="link-button" data-feature-retry="${retry}">Thử lại</button>` : ''}</div></div>`;
  const heading = (overline, title, description, action='') => `<div class="eyebrow">${overline}</div><div class="heading-row"><div><h1 class="page-title">${title}</h1><p class="subheading">${description}</p></div>${action}</div>`;
  function safeImage(value) {
    if (!value || typeof value !== 'string') return '';
    try { const url = new URL(value, location.origin); return ['http:','https:'].includes(url.protocol) ? esc(url.href) : ''; } catch { return ''; }
  }
  function avatar(className='profile-big-avatar') {
    const src = safeImage(state.avatar || '');
    return `<span class="${className}">${src ? `<img src="${src}" alt="Ảnh đại diện">` : esc((authSession?.name || 'K').split(/\s+/).slice(-2).map(part=>part[0]).join(''))}</span>`;
  }
  function reset(session) {
    if (identity === (session?.id ?? null)) return;
    identity = session?.id ?? null; epoch++; membership = null; membershipLoading = false;
    plans = null; plansLoading = false; plansError = ''; checkout = null;
    reservationRecord = null; reservationLoading = false; reservationError = '';
    chatController?.abort(); chatController = null; messages = []; chatBusy = false; chatError = ''; lastMessage = '';
    revokeAvatar(); setMenu(false); renderChat();
  }
  function revokeAvatar() { if (avatarUrl) URL.revokeObjectURL(avatarUrl); avatarUrl = null; }
  async function refreshMembership() {
    if (!authSession || membershipLoading || !api.capabilities.membership) return;
    membershipLoading = true;
    const current = epoch;
    try { const result = await api.membership(); if (current === epoch) membership = result.membership || null; }
    catch { if (current === epoch) membership = null; }
    finally {
      if (current === epoch) {
        membershipLoading = false; syncMenu();
        if (['profile','vip'].includes(state.page)) render();
      }
    }
  }
  function resolve(value) {
    const reservation = /^reservation\/([a-zA-Z0-9_-]+)$/.exec(value);
    if (reservation) return {page:'reservation-detail', id:reservation[1]};
    const match = /^checkout\/(reservation|vip|fine)\/([a-zA-Z0-9_-]+)$/.exec(value);
    if (match) return {page:'checkout', purpose:match[1], referenceId:match[2]};
    if (['profile','profile-edit','vip','help'].includes(value)) return {page:value};
    return null;
  }
  function setRoute(value) {
    const next = resolve(value);
    if (route?.page === 'checkout' && (!next || next.page !== 'checkout' || next.purpose !== route.purpose || next.referenceId !== route.referenceId)) checkout = null;
    if (route?.page === 'reservation-detail' && (!next || next.page !== 'reservation-detail' || next.id !== route.id)) {
      reservationRecord = null; reservationLoading = false; reservationError = '';
    }
    route = next;
  }
  function enter() {
    if (state.page !== 'profile-edit') revokeAvatar();
    setMenu(false);
    if (state.page === 'vip' && plans === null && !plansLoading && !plansError && api.capabilities.plans) loadPlans();
    if (state.page === 'reservation-detail' && route?.page === 'reservation-detail' && !reservationLoading && !reservationError && String(reservationRecord?.reservation?.id) !== String(route.id)) loadReservation(route.id);
    if (state.page === 'checkout' && route?.page === 'checkout' && (route.purpose === 'vip' || (route.purpose === 'fine' && api.capabilities.payments) || api.capabilities.reservationWorkflow)) {
      const key = `${epoch}/${route.purpose}/${route.referenceId}`;
      if (checkout?.key !== key) {
        checkout = {key, purpose:route.purpose, referenceId:route.referenceId, loading:true, error:'', quote:null, payment:null, busy:false, method:'', requestKey:crypto.randomUUID()};
        loadQuote(checkout);
      }
    }
  }
  async function loadPlans() {
    if (plansLoading) return;
    plansLoading = true; plansError = ''; const current = epoch;
    try {
      const result = await api.plans();
      if (!Array.isArray(result.plans) || !result.plans.every(plan=>/^[a-zA-Z0-9_-]+$/.test(String(plan.id)) && typeof plan.name === 'string' && rules.isAmount(plan.price) && typeof plan.duration_label === 'string' && Array.isArray(plan.benefits))) throw new Error('Thông tin gói thành viên chưa đầy đủ. Vui lòng thử lại sau.');
      if (current === epoch) plans = result.plans;
    }
    catch (error) { if (current === epoch) plansError = error.message; }
    finally { if (current === epoch) { plansLoading = false; if (state.page === 'vip') render(); } }
  }
  async function loadReservation(id) {
    reservationLoading = true; reservationError = ''; reservationRecord = null;
    const current = epoch;
    try {
      const result = await api.reservation(id);
      if (!result?.reservation || String(result.reservation.id) !== String(id)) throw new Error('Không tìm thấy yêu cầu đặt trước này.');
      if (current === epoch && route?.page === 'reservation-detail' && String(route.id) === String(id)) reservationRecord = result;
    } catch (error) {
      if (current === epoch && route?.page === 'reservation-detail' && String(route.id) === String(id)) reservationError = error.message;
    } finally {
      if (current === epoch && route?.page === 'reservation-detail' && String(route.id) === String(id)) {
        reservationLoading = false;
        if (state.page === 'reservation-detail') render();
      }
    }
  }
  function validQuote(q, target, payment=null) {
    if (!q?.id || q.purpose !== target.purpose || String(q.reference_id) !== target.referenceId || q.currency !== 'VND' || !rules.isAmount(q.total_amount) || !Array.isArray(q.items) || !q.items.length || !(Date.parse(q.expires_at) > Date.now())) return false;
    if (!q.items.every(item => typeof item.title === 'string' && Number.isInteger(item.quantity) && item.quantity > 0 && rules.isAmount(item.unit_amount) && rules.isAmount(item.line_amount))) return false;
    if (target.purpose === 'reservation') {
      const resumable = payment && ['pending','confirming','paid'].includes(payment.status)
        && q.reservation?.status === 'ready'
        && Number.isFinite(Date.parse(q.reservation.expires_at))
        && Date.parse(q.reservation.expires_at) > Date.now();
      if ((!rules.reservationPayable(q.reservation) && !resumable) || String(q.reservation.id) !== target.referenceId) return false;
    }
    return Array.isArray(q.methods) && q.methods.some(method=>['bank_transfer','cash'].includes(method));
  }
  function validPayment(payment, quote, expectedId=null) {
    return !!payment?.id && String(payment.quote_id) === String(quote.id)
      && quote.methods.includes(payment.method) && typeof payment.status === 'string' && payment.status.length > 0
      && (!expectedId || String(payment.id) === String(expectedId));
  }
  function reservationPaymentLink(row) {
    if (!row || row.status !== 'ready' || !Number.isFinite(Date.parse(row.expires_at)) || Date.parse(row.expires_at) <= Date.now()) return '';
    if (rules.reservationPayable(row)) return button('Thanh toán','checkout/reservation/'+encodeURIComponent(row.id),'secondary-button reservation-payment');
    if (['pending','confirming','paid'].includes(row.payment_status)) return button('Xem thanh toán','checkout/reservation/'+encodeURIComponent(row.id),'secondary-button reservation-payment');
    return '';
  }
  function reservationViewLink(row) {
    return row?.id ? button('Xem chi tiết',`reservation/${encodeURIComponent(row.id)}`,'secondary-button reservation-detail-link') : '';
  }
  function finePaymentLink(row) {
    if (!api.capabilities.payments || !row?.id) return '';
    if (row.raw_status === 'unpaid' && !['pending','confirming','paid'].includes(row.payment_status)) return button('Thanh toán khoản phạt',`checkout/fine/${encodeURIComponent(row.id)}`,'secondary-button fine-payment-link');
    if (['pending','confirming'].includes(row.payment_status)) return button('Xem thanh toán',`checkout/fine/${encodeURIComponent(row.id)}`,'secondary-button fine-payment-link');
    return '';
  }
  function reservationDetailView() {
    const id = route?.id;
    const intro = heading('TÀI KHOẢN ĐỘC GIẢ','Chi tiết yêu cầu đặt trước','Theo dõi trạng thái xử lý và khoản thanh toán của yêu cầu này.',button('← Quay lại','reservations'));
    if (!id) return intro + unavailable('Liên kết yêu cầu đặt trước không hợp lệ.');
    if (reservationLoading) return intro + '<p class="feature-loading" role="status">Đang tải yêu cầu đặt trước…</p>';
    if (!reservationRecord) return intro + unavailable(reservationError || 'Chưa tải được thông tin yêu cầu.', 'reservation');
    const {reservation:r,payment,loan}=reservationRecord;
    const statuses={pending:'Chờ duyệt',ready:'Đã duyệt, đang giữ sách',expired:'Đã hết hạn',fulfilled:'Đã giao sách',cancelled:'Đã hủy'};
    const paymentStatuses={unpaid:'Chưa thanh toán',pending:'Chờ xác nhận thanh toán',confirming:'Đang chờ xác nhận chuyển khoản',paid:'Đã thanh toán'};
    const legacyReadyWithoutPayment=r.status==='ready'&&r.payment_status==null&&!payment;
    const detail=[['Mã yêu cầu',r.id],['Sách',r.book?.title],['Mã sách',r.book?.book_code],['Số lượng',r.quantity],['Trạng thái',statuses[r.status]||r.status],['Thanh toán',paymentStatuses[r.payment_status]||(legacyReadyWithoutPayment?'Chưa thiết lập':r.payment_status)],['Ngày duyệt',r.ready_at?date(r.ready_at):''],['Hạn giữ sách',r.expires_at?date(r.expires_at):''],['Số tiền',r.amount==null?'':money(r.amount)]]
      .filter(([,value])=>value!==null&&value!==undefined&&value!=='')
      .map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`).join('');
    const statusMessage=r.status==='pending'?'Yêu cầu đang chờ nhân viên thư viện duyệt.':legacyReadyWithoutPayment?'Phiếu chưa có báo giá. Khi tạo phiếu thanh toán, phí hiện tại của sách sẽ được áp dụng; hạn giữ sách không đổi.':r.status==='ready'&&r.payment_status==='unpaid'?'Yêu cầu đã được duyệt. Thanh toán chỉ khả dụng trong thời hạn giữ sách.':r.status==='ready'&&['pending','confirming'].includes(payment?.status)?'Đã ghi nhận giao dịch; đang chờ nhân viên thư viện xác nhận.':'';
    const payAction=r.status==='ready'&&api.capabilities.reservationWorkflow&&api.capabilities.payments&&Number.isFinite(Date.parse(r.expires_at))&&Date.parse(r.expires_at)>Date.now()
      ? payment&&['pending','confirming'].includes(payment.status)
        ? button('Xem thanh toán',`checkout/reservation/${encodeURIComponent(r.id)}`,'secondary-button reservation-payment')
        : !payment&&(r.payment_status==='unpaid'||legacyReadyWithoutPayment)
          ? button(legacyReadyWithoutPayment?'Tạo phiếu thanh toán':'Thanh toán',`checkout/reservation/${encodeURIComponent(r.id)}`,'primary-button reservation-payment')
          : ''
      : '';
    const receipt=payment?.status==='paid'?`<section class="profile-card reservation-receipt"><h2>Biên nhận thanh toán</h2><dl class="profile-details">${[['Mã giao dịch',payment.id],['Trạng thái','Đã xác nhận thanh toán'],['Phương thức',payment.method==='cash'?'Tiền mặt':payment.method==='bank_transfer'?'Chuyển khoản mô phỏng':'Khác'],['Số tiền',money(payment.amount)],['Thời điểm xác nhận',payment.confirmed_at?date(payment.confirmed_at):'']].map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>${payment.method==='bank_transfer'&&payment.transfer?.simulated===true?'<p class="fine-print">Thông tin chuyển khoản này là mô phỏng; không chuyển tiền thật.</p>':''}</section>`:'';
    const linkedLoan=loan?`<section class="profile-card reservation-loan"><h2>Phiếu mượn đã liên kết</h2><dl class="profile-details">${[['Mã phiếu',loan.id],['Trạng thái',loan.status],['Ngày nhận',loan.issued_at?date(loan.issued_at):''],['Hạn trả',loan.due_at?date(loan.due_at):''],['Phí mượn',loan.rental_fee_total==null?'':money(loan.rental_fee_total)],['Đã thu',loan.rental_fee_received==null?'':money(loan.rental_fee_received)]].filter(([,value])=>value!==null&&value!==undefined&&value!=='').map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl></section>`:'';
    return intro + `<section class="profile-card reservation-detail-card"><div class="eyebrow">YÊU CẦU ĐẶT TRƯỚC</div><h2>${esc(r.book?.title||'Sách')}</h2><dl class="profile-details">${detail}</dl>${statusMessage?`<p class="fine-print">${esc(statusMessage)}</p>`:''}${payAction}</section>${receipt}${linkedLoan}`;
  }
  async function loadQuote(target) {
    target.loading = true; target.error = '';
    try {
      const result = await api.quote(target.purpose, target.referenceId);
      if (!validQuote(result.quote, target, result.payment || null)) throw new Error('Yêu cầu không còn đủ điều kiện thanh toán hoặc báo giá chưa hợp lệ.');
      if (result.payment && !validPayment(result.payment, result.quote)) throw new Error('Thông tin giao dịch chưa hợp lệ. Vui lòng kiểm tra lại.');
      target.quote = result.quote;
      target.payment = result.payment || null;
      target.method = target.payment?.method || ['cash','bank_transfer'].find(method=>result.quote.methods.includes(method)) || '';
      if (target.purpose === 'vip' && target.payment?.status === 'paid') await refreshMembership();
    } catch (error) { target.error = error.message; }
    finally { target.loading = false; if (checkout === target && state.page === 'checkout') render(); }
  }
  function profile(edit=false) {
    const p = authServerData.profile;
    const title = edit ? 'Chỉnh sửa hồ sơ' : 'Hồ sơ cá nhân';
    const identityCard = `<aside class="profile-card account-identity"><div class="profile-identity">${avatar()}<strong>${esc(authSession?.name)}</strong><small>${esc(authSession?.email)}</small><span class="membership-badge">${esc(authSession?.role === 'Độc giả' ? rules.membershipLabel(membership) : authSession?.role)}</span>${rules.membershipLabel(membership) === 'VIP' ? `<small>Hiệu lực đến ${date(membership.expires_at)}</small>` : ''}</div></aside>`;
    if (!p) return heading('TÀI KHOẢN CỦA BẠN', title, 'Thông tin cá nhân trong không gian đọc của bạn.') + (authServerData.profileLoading ? '<p class="feature-loading" role="status">Đang tải hồ sơ…</p>' : unavailable(authServerData.profileError || 'Chưa tải được hồ sơ. Vui lòng thử lại.', 'profile'));
    const field = (name, label, value, type='text', attrs='') => `<div class="field"><label for="profile-${name}">${label}</label><input id="profile-${name}" name="${name}" type="${type}" value="${esc(value)}" ${attrs}></div>`;
    const details = edit ? `<form id="profile-form">${field('name','Họ và tên',authSession.name,'text','required minlength="2" maxlength="100"')}${field('email','Email',authSession.email,'email','required maxlength="255"')}${field('phone','Số điện thoại',p.phone || '','tel','maxlength="20"')}${field('birthday','Ngày sinh',p.date_of_birth || '','date')}
      <div class="field"><label for="profile-gender">Giới tính</label><select id="profile-gender" name="gender" ${api.capabilities.gender ? '' : 'disabled aria-describedby="gender-note"'}>${Object.entries(genderLabels).map(([value,label])=>`<option value="${value}" ${(p.gender || '') === value ? 'selected' : ''}>${label}</option>`).join('')}</select>${api.capabilities.gender ? '' : '<small id="gender-note">Tạm thời chưa thể cập nhật giới tính.</small>'}</div>
      ${field('address','Địa chỉ',p.address || '','text','maxlength="5000"')}<div class="field full"><label for="avatar-file">Ảnh đại diện</label><div class="avatar-upload"><img class="avatar-preview" id="avatar-preview" alt="Xem trước ảnh đại diện" ${state.avatar ? `src="${safeImage(state.avatar)}"` : 'hidden'}><input id="avatar-file" name="avatar" type="file" accept="image/png,image/jpeg,image/webp"></div><small>Ảnh PNG, JPEG hoặc WebP, tối đa 2 MB.</small></div><p class="feature-form-error" id="profile-error" role="alert"></p><div class="form-actions"><button class="secondary-button" type="button" data-page-link="profile">Hủy</button><button class="primary-button" type="submit">Lưu thay đổi</button></div></form>` : `<dl class="profile-details">${[['Họ và tên',authSession.name],['Email',authSession.email],['Số điện thoại',p.phone],['Ngày sinh',p.date_of_birth ? new Date(`${p.date_of_birth}T00:00:00`).toLocaleDateString('vi-VN') : ''],['Giới tính',genderLabels[p.gender || ''] || 'Chưa cập nhật'],['Địa chỉ',p.address],['Vai trò',authSession.role]].map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value || 'Chưa cập nhật')}</dd></div>`).join('')}</dl>`;
    return heading('TÀI KHOẢN CỦA BẠN', title, edit ? 'Chăm chút thông tin để thư viện hỗ trợ bạn tốt hơn.' : 'Một góc nhỏ dành riêng cho bạn.', edit ? '' : button('Chỉnh sửa hồ sơ ↗','profile-edit','primary-button')) + `<div class="profile-layout feature-profile">${identityCard}<section class="profile-card form-card"><h2>Thông tin cá nhân</h2>${details}</section></div>`;
  }
  function vip() {
    const current = rules.membershipLabel(membership);
    const intro = heading('THÀNH VIÊN THƯ VIỆN MÂY','Đọc nhiều hơn, khám phá xa hơn.','Khám phá gói VIP và những quyền lợi dành cho hành trình đọc của bạn.');
    const status = `<div class="vip-intro"><span class="vip-symbol" aria-hidden="true">✦</span><div><small>GÓI TÀI KHOẢN HIỆN TẠI</small><h2>${esc(current)}</h2><p>${current === 'VIP' ? `Hiệu lực đến ${date(membership.expires_at)}` : 'Thông tin gói và quyền lợi được xác nhận trước khi đăng ký.'}</p></div></div>`;
    if (plansLoading) return intro + status + '<p class="feature-loading" role="status">Đang tải các gói thành viên…</p>';
    if (!plans?.length) return intro + status + unavailable(plansError || 'Các gói VIP sẽ sớm được cập nhật. Bạn vẫn có thể khám phá danh mục sách.', api.capabilities.plans ? 'plans' : '') + '<button class="primary-button" disabled>Đăng ký VIP</button>';
    return intro + status + `<div class="vip-plans">${plans.map(plan=>`<article class="profile-card vip-plan"><span class="membership-badge">VIP</span><h2>${esc(plan.name)}</h2><p class="vip-price">${money(plan.price)} <small>/ ${esc(plan.duration_label)}</small></p><ul>${(Array.isArray(plan.benefits) ? plan.benefits : []).map(item=>`<li>${esc(item)}</li>`).join('')}</ul>${button('Chọn gói này →',`checkout/vip/${encodeURIComponent(plan.id)}`,'primary-button')}</article>`).join('')}</div>`;
  }
  function paymentView() {
    const c = checkout;
    const purposeTitle = c?.purpose === 'vip' ? 'Đăng ký thành viên VIP' : c?.purpose === 'fine' ? 'Thanh toán khoản phạt' : 'Thanh toán phí mượn sách';
    const backPage = c?.purpose === 'vip' ? 'vip' : c?.purpose === 'fine' ? 'fines' : 'reservations';
    const intro = heading('THANH TOÁN', purposeTitle,'Kiểm tra thông tin trước khi chọn hình thức thanh toán.',button('← Quay lại',backPage));
    if (route?.page !== 'checkout') return intro + unavailable('Vui lòng chọn một khoản cần thanh toán để tiếp tục.');
    if (route.purpose === 'reservation' && !api.capabilities.reservationWorkflow) return intro + unavailable('Quy trình thanh toán đặt trước đang chờ dịch vụ xác nhận của thư viện.');
    if (route.purpose === 'fine' && !api.capabilities.payments) return intro + unavailable('Thanh toán khoản phạt đang chờ dịch vụ xác nhận của thư viện.');
    if (!c || c.loading) return intro + '<p class="feature-loading" role="status">Đang lấy báo giá…</p>';
    if (!c.quote) return intro + unavailable(c.error || 'Chức năng thanh toán đang được hoàn thiện.', api.capabilities.payments ? 'quote' : '');
    const q = c.quote, payment = c.payment, status = payment ? rules.paymentStatus(payment.status) : 'none';
    const expired = Date.parse(q.expires_at) <= Date.now() || (q.reservation && Date.parse(q.reservation.expires_at) <= Date.now());
    const labels = {pending:'Chờ thanh toán',confirming:'Đang xác nhận',paid:'Thanh toán thành công',failed:'Thanh toán thất bại',cancelled:'Giao dịch đã hủy',expired:'Đã hết hạn',unknown:'Chưa xác định được trạng thái'};
    const selected = payment?.method || c.method;
    const qr = payment?.transfer;
    let transaction = '';
    if (payment) {
      const paymentMessage = status === 'paid'
        ? (c.purpose === 'vip' ? 'Thư viện đã xác nhận. Quyền lợi VIP được tải từ hồ sơ thành viên.' : c.purpose === 'fine' ? 'Thư viện đã xác nhận khoản phạt đã được thanh toán.' : 'Phí mượn đã được xác nhận. Vui lòng nhận sách tại thư viện trong thời hạn giữ.')
        : status === 'confirming'
          ? 'Đã báo giao dịch. Đang chờ nhân viên thư viện xác nhận.'
          : selected === 'cash'
            ? 'Thanh toán trực tiếp tại thư viện. Giao dịch chỉ hoàn tất sau khi nhân viên xác nhận đã thu tiền.'
            : qr?.simulated === true
              ? 'Thông tin bên dưới chỉ dùng để mô phỏng; không chuyển tiền thật.'
              : 'Chưa có thông tin giao dịch mô phỏng. Không chuyển tiền thật.';
      transaction = `<section class="payment-result ${status === 'paid' ? 'is-paid' : ''}" role="status"><strong>${labels[status]}</strong><p>${paymentMessage}</p><small>Mã giao dịch: ${esc(payment.id)}</small></section>`;
      if (selected === 'bank_transfer' && ['pending','confirming'].includes(status) && !expired) transaction += qr?.simulated === true ? `<div class="transfer-details"><p><strong>Mô phỏng – không chuyển tiền thật.</strong> Thư viện sẽ xác nhận giao dịch thủ công.</p><dl>${[['Ngân hàng mô phỏng',qr.bank_name],['Chủ tài khoản mô phỏng',qr.account_name],['Số tài khoản mô phỏng',qr.account_number],['Nội dung mô phỏng',qr.content]].map(([label,value])=>`<div><dt>${label}</dt><dd>${esc(value || 'Chưa có thông tin')}${value ? `<button class="link-button" data-copy-value="${esc(value)}">Sao chép</button>` : ''}</dd></div>`).join('')}</dl></div>` : unavailable('Chưa nhận được thông tin giao dịch mô phỏng. Không chuyển tiền thật.');
      if (['pending','confirming','unknown'].includes(status)) transaction += `<button class="secondary-button" data-payment-check ${c.busy ? 'disabled' : ''}>${c.busy ? 'Đang kiểm tra…' : 'Kiểm tra trạng thái'}</button>`;
    }
    const reportTransfer = selected === 'bank_transfer' && payment && status === 'pending' && !expired && api.capabilities.payments ? `<button class="secondary-button" data-payment-report ${c.busy ? 'disabled' : ''}>Tôi đã chuyển khoản mô phỏng</button>` : '';
    return intro + `<div class="checkout-layout"><section class="profile-card checkout-main"><h2>${c.purpose === 'vip' ? 'Gói thành viên của bạn' : c.purpose === 'fine' ? 'Khoản phạt của bạn' : 'Thông tin yêu cầu'}</h2><p class="checkout-reference">Mã ${c.purpose === 'vip' ? 'gói' : c.purpose === 'fine' ? 'khoản phạt' : 'yêu cầu'}: ${esc(c.referenceId)}</p>${q.reservation ? `<p>Hạn nhận sách: <strong>${date(q.reservation.expires_at)}</strong></p>` : ''}<div class="checkout-items">${q.items.map(item=>`<article><div><strong>${esc(item.title)}</strong><small>${item.quantity} × ${money(item.unit_amount)}</small></div><b>${money(item.line_amount)}</b></article>`).join('')}</div><h2>Hình thức thanh toán</h2><div class="payment-methods">${[['bank_transfer','Chuyển khoản mô phỏng','Không chuyển tiền thật. Giao dịch cần thư viện xác nhận thủ công.'],['cash','Tiền mặt tại thư viện','Thanh toán trực tiếp; nhân viên xác nhận đã thu tiền.']].filter(([method])=>q.methods.includes(method)).map(([method,label,description])=>`<label class="payment-method"><input type="radio" name="payment_method" value="${method}" ${selected === method ? 'checked' : ''} ${payment || c.busy || expired ? 'disabled' : ''}><span><strong>${label}</strong><small>${description}</small></span></label>`).join('')}</div>${transaction}${reportTransfer}</section><aside class="summary-card checkout-summary"><h3>Tóm tắt thanh toán</h3><div class="summary-line summary-total"><span>Tổng cộng</span><strong>${money(q.total_amount)}</strong></div><p class="fine-print">Báo giá có hiệu lực đến ${date(q.expires_at)}.</p>${expired && !payment ? unavailable('Báo giá hoặc thời hạn thanh toán đã hết. Vui lòng quay lại danh sách để kiểm tra.') : ''}${!payment ? `<button class="primary-button" data-payment-create ${c.busy || expired || !selected ? 'disabled' : ''}>${c.busy ? 'Đang xử lý…' : selected === 'cash' ? 'Chọn trả tại thư viện' : 'Tạo giao dịch mô phỏng →'}</button>` : ''}<p class="feature-form-error" role="alert">${esc(c.error)}</p>${c.purpose === 'reservation' ? '<p class="fine-print">Thanh toán không thay thế bước nhân viên xác nhận giao sách.</p>' : ''}</aside></div>`;
  }
  async function updatePayment(check=false) {
    const c = checkout;
    if (!c || c.busy || !c.quote || state.page !== 'checkout' || route?.page !== 'checkout') return;
    if (!check && !validQuote(c.quote,c)) { c.error = 'Báo giá hoặc yêu cầu đã hết hiệu lực. Vui lòng kiểm tra lại.'; render(); return; }
    c.busy = true; c.error = ''; render();
    try {
      const result = check ? await api.payment(c.payment.id) : await api.createPayment(c.quote.id,c.method,c.requestKey);
      const payment = result.payment;
      if (!validPayment(payment,c.quote,check ? c.payment.id : null)) throw new Error('Thông tin giao dịch chưa hợp lệ. Vui lòng kiểm tra lại.');
      if (checkout !== c) return;
      c.payment = payment;
      if (payment.status === 'paid' && c.purpose === 'vip') await refreshMembership();
      if (payment.status === 'paid' && c.purpose === 'fine' && typeof window.authRefreshReaderData === 'function') await window.authRefreshReaderData();
    } catch (error) { if (checkout === c) c.error = error.message; }
    finally { c.busy = false; if (checkout === c && state.page === 'checkout') render(); }
  }
  async function reportTransfer() {
    const c=checkout;if(!c||c.busy||!c.payment||c.payment.method!=='bank_transfer'||c.payment.status!=='pending'||!api.capabilities.payments)return;
    c.busy=true;c.error='';render();
    try{const result=await api.reportTransfer(c.payment.id);if(!validPayment(result.payment,c.quote,c.payment.id)||!['confirming','paid'].includes(result.payment.status))throw new Error('Chưa nhận được trạng thái xác nhận từ thư viện.');c.payment=result.payment;if(result.payment.status==='paid'&&c.purpose==='vip')await refreshMembership();if(result.payment.status==='paid'&&c.purpose==='fine'&&typeof window.authRefreshReaderData==='function')await window.authRefreshReaderData();}
    catch(error){c.error=error.message;}
    finally{c.busy=false;if(checkout===c&&state.page==='checkout')render();}
  }
  function help() {
    return heading('THƯ VIỆN LUÔN Ở ĐÂY','Bạn cần hỗ trợ?','Một vài hướng dẫn để bắt đầu hành trình đọc.') + `<section class="profile-card help-guide"><h2>Đặt và nhận sách</h2><ol><li>Tìm sách trong danh mục, thêm vào giỏ và gửi yêu cầu đặt trước.</li><li>Theo dõi mục Đặt trước; nhân viên sẽ duyệt và giữ sách.</li><li>Khi được duyệt, kiểm tra hạn nhận sách và hình thức thanh toán khả dụng.</li><li>Đến thư viện nhận sách. Theo dõi hạn trả trong Kệ sách của tôi.</li></ol><h2>Quản lý tài khoản</h2><p>Mở menu avatar ở góc phải để xem hồ sơ, sửa thông tin hoặc đăng xuất.</p><div class="feature-actions">${button('Khám phá sách','catalog','primary-button')}${button('Xem đặt trước','reservations')}</div></section>`;
  }
  function view() {
    if (state.page === 'profile' || state.page === 'profile-edit') return profile(state.page === 'profile-edit');
    if (state.page === 'vip') return vip();
    if (state.page === 'checkout') return paymentView();
    if (state.page === 'reservation-detail') return reservationDetailView();
    return help();
  }
  function setMenu(open, focus=false) {
    clearTimeout(closeTimer); menuOpen = open;
    const menu = document.getElementById('account-menu'), toggle = document.getElementById('account-toggle');
    if (menu) menu.hidden = !open;
    toggle?.setAttribute('aria-expanded',String(open));
    if (open && focus) menu?.querySelector('button,a')?.focus();
  }
  function syncMenu() {
    const menu = document.getElementById('account-menu');
    if (!menu) return;
    const label = rules.membershipLabel(membership);
    const signed = authSession ? `<div class="account-menu-identity">${avatar('account-menu-avatar')}<div><strong>${esc(authSession.name)}</strong><small>${esc(authSession.email)}</small></div></div><div class="account-menu-plan"><span>Gói tài khoản</span><b class="membership-badge">${esc(label)}</b>${label === 'VIP' ? `<small>Đến ${date(membership.expires_at)}</small>` : ''}</div>${button('Hồ sơ cá nhân','profile','account-menu-link')}${button('Sửa thông tin cá nhân','profile-edit','account-menu-link')}${authSession.role === 'Độc giả' ? button(label === 'VIP' ? '✦ Xem gói VIP' : '✦ Đăng ký VIP','vip','account-menu-link account-vip-link') : ''}<button class="account-menu-link account-logout" data-auth-logout>Đăng xuất</button>` : `<div class="account-menu-guest"><strong>Chào bạn đến với Mây</strong><p>Đăng nhập để quản lý sách và hồ sơ của bạn.</p></div>${button('Đăng nhập','login','account-menu-link')}${button('Tạo tài khoản','register','account-menu-link')}`;
    if (menu.innerHTML !== signed) menu.innerHTML = signed;
    menu.hidden = !menuOpen;
  }
  function renderChat() {
    let host = document.getElementById('library-support');
    if (!host) { host = document.createElement('div'); host.id = 'library-support'; document.body.append(host); }
    const active = host.contains(document.activeElement), draft = host.querySelector('textarea')?.value || '';
    host.innerHTML = `<button class="support-launcher" data-chat-toggle aria-controls="support-panel" aria-expanded="${chatOpen}" aria-label="${chatOpen ? 'Đóng' : 'Mở'} trợ lý thư viện">${chatOpen ? '×' : '<span aria-hidden="true">✳</span> <span>Hỏi Mây</span>'}</button><section class="support-panel" id="support-panel" aria-label="Trợ lý thư viện Mây" ${chatOpen ? '' : 'hidden'}><header><div><strong>Trợ lý Mây</strong><small>Đồng hành cùng hành trình đọc</small></div><button class="icon-button" data-chat-close aria-label="Đóng chat">×</button></header><div class="support-messages" role="log" aria-live="polite" aria-relevant="additions"><div class="support-welcome"><span>✳</span><h2>Mây có thể giúp gì cho bạn?</h2><p>Tìm sách và khám phá cách sử dụng thư viện.</p></div>${!api.capabilities.chat ? '<p class="support-unavailable">Trợ lý đang được hoàn thiện. Bạn có thể xem hướng dẫn hoặc tự tìm sách bên dưới.</p>' : ''}${messages.map(message=>`<div class="support-message ${message.role === 'user' ? 'from-user' : 'from-assistant'}"><small>${message.role === 'user' ? 'Bạn' : 'Trợ lý Mây'}</small><p>${esc(message.content)}</p>${(message.books || []).filter(book=>/^\d+$/.test(String(book.id))).map(book=>button(esc(book.title),`book/${book.id}`,'support-book')).join('')}</div>`).join('')}${chatBusy ? '<p class="support-thinking" role="status">Mây đang trả lời…</p>' : ''}${chatError ? `<div class="support-error" role="alert">${esc(chatError)} <button class="link-button" data-chat-retry>Thử lại</button></div>` : ''}</div><div class="support-quick-links">${button('Hướng dẫn','help','link-button')}${button('Tìm sách','catalog','link-button')}</div>${api.capabilities.chat && messages.length === 0 ? '<div class="support-suggestions"><button data-chat-suggest="Tôi muốn tìm một cuốn sách hay">Gợi ý sách cho tôi</button><button data-chat-suggest="Hướng dẫn cách đặt và nhận sách">Cách đặt và nhận sách</button></div>' : ''}<form id="support-form"><label class="sr-only" for="support-input">Câu hỏi cho trợ lý</label><textarea id="support-input" maxlength="2000" rows="2" placeholder="Hỏi Mây về sách và thư viện…" ${!api.capabilities.chat || chatBusy ? 'disabled' : ''}>${esc(draft)}</textarea><button class="primary-button" type="submit" ${!api.capabilities.chat || chatBusy ? 'disabled' : ''}>Gửi</button></form></section>`;
    const log = host.querySelector('.support-messages'); if (log) log.scrollTop = log.scrollHeight;
    if (active && chatOpen && !chatBusy && api.capabilities.chat) host.querySelector('textarea')?.focus();
  }
  async function sendChat(text, retry=false) {
    const message = String(text || '').trim();
    if (!message || message.length > 2000 || chatBusy || !api.capabilities.chat) return;
    chatBusy = true; chatError = ''; lastMessage = message;
    const history = messages.map(({role,content})=>({role,content}));
    if (!retry) messages.push({role:'user',content:message});
    const current = epoch;
    chatController = new AbortController(); renderChat();
    try {
      const result = await api.chat(message,retry ? history.slice(0,-1) : history,{signal:chatController.signal});
      if (current !== epoch) return;
      if (typeof result.answer !== 'string' || !result.answer.trim()) throw new Error('Chưa nhận được câu trả lời. Vui lòng thử lại.');
      messages.push({role:'assistant',content:result.answer,books:Array.isArray(result.books) ? result.books : []});
    } catch (error) { if (current === epoch) chatError = error.message; }
    finally { if (current === epoch) { chatBusy = false; chatController = null; renderChat(); } }
  }
  async function retry(target) {
    if (target === 'plans') { await loadPlans(); return; }
    if (target === 'quote' && checkout && !checkout.loading) { loadQuote(checkout); render(); return; }
    if (target === 'reservation' && route?.page === 'reservation-detail' && !reservationLoading) { await loadReservation(route.id); return; }
    if (target === 'profile') {
      if(authServerData.profileLoading)return;
      const current = epoch;
      authServerData.profileLoading=true;authServerData.profileError='';render();
      try { const result = await authRequest('/my/profile'); if (current === epoch) { authServerData.profile = result.profile; state.avatar = result.profile?.avatar_url || ''; } }
      catch (error) { if(current===epoch)authServerData.profileError=error.message; }
      finally { if(current===epoch){authServerData.profileLoading=false;if(['profile','profile-edit'].includes(state.page))render();} }
    }
  }
  function init() {
    reset(authSession); syncMenu(); renderChat();
    const wrap = document.getElementById('account-dropdown'), toggle = document.getElementById('account-toggle');
    wrap.addEventListener('pointerenter',event=>{if(event.pointerType === 'mouse') setMenu(true);});
    wrap.addEventListener('pointerleave',event=>{if(event.pointerType === 'mouse') closeTimer = setTimeout(()=>{if(!wrap.contains(document.activeElement)) setMenu(false);},160);});
    wrap.addEventListener('focusout',()=>{queueMicrotask(()=>{if(!wrap.contains(document.activeElement) && !wrap.matches(':hover')) setMenu(false);});});
    toggle.addEventListener('click',event=>setMenu(event.detail === 0 || matchMedia('(hover: none)').matches ? !menuOpen : true));
    toggle.addEventListener('keydown',event=>{if(event.key === 'ArrowDown'){event.preventDefault();setMenu(true,true);}});
    document.addEventListener('keydown',event=>{
      if (event.key === 'Escape') { if (menuOpen) {setMenu(false);toggle.focus();} else if(chatOpen){chatOpen=false;renderChat();document.querySelector('[data-chat-toggle]')?.focus();} }
    });
    document.addEventListener('click',event=>{
      if(!wrap.contains(event.target)) setMenu(false);
      if(event.target.closest('#account-menu button')) setMenu(false);
      if(event.target.closest('#library-support [data-page-link]')){chatOpen=false;renderChat();}
      if(event.target.closest('[data-chat-toggle],[data-chat-close]')) {chatOpen=!chatOpen;renderChat();if(chatOpen)document.querySelector('#support-input:not(:disabled)')?.focus();}
      const retryButton=event.target.closest('[data-feature-retry]');if(retryButton)retry(retryButton.dataset.featureRetry);
      if(event.target.closest('[data-payment-create]'))updatePayment();
      if(event.target.closest('[data-payment-check]'))updatePayment(true);
      if(event.target.closest('[data-payment-report]'))reportTransfer();
      const copy=event.target.closest('[data-copy-value]');if(copy)navigator.clipboard.writeText(copy.dataset.copyValue).then(()=>toast('Đã sao chép.')).catch(()=>toast('Chưa sao chép được. Bạn có thể chọn và sao chép nội dung.'));
      const suggestion=event.target.closest('[data-chat-suggest]');if(suggestion)sendChat(suggestion.dataset.chatSuggest);
      if(event.target.closest('[data-chat-retry]'))sendChat(lastMessage,true);
    });
    document.addEventListener('change',event=>{
      if(event.target.name === 'payment_method' && checkout && !checkout.busy && !checkout.payment){checkout.method=event.target.value;render();}
      if(event.target.id === 'avatar-file') {
        const file=event.target.files?.[0],preview=document.getElementById('avatar-preview');revokeAvatar();
        if(!file) {if(preview){preview.hidden=!state.avatar;if(state.avatar)preview.src=state.avatar;}return;}
        if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>2*1024*1024){toast('Chọn ảnh PNG, JPEG hoặc WebP không quá 2 MB.');event.target.value='';if(preview){preview.hidden=!state.avatar;if(state.avatar)preview.src=state.avatar;}return;}
        avatarUrl=URL.createObjectURL(file);if(preview){preview.src=avatarUrl;preview.hidden=false;}
      }
    });
    document.addEventListener('submit',event=>{if(event.target.id==='support-form'){event.preventDefault();const input=event.target.querySelector('textarea'),text=input.value;input.value='';sendChat(text);}});
    document.addEventListener('input',event=>{if(event.target.closest('#profile-form'))event.target.closest('form').dataset.dirty='true';});
  }
  return {reset, refreshMembership, resolve, setRoute, enter, view, syncMenu, init, revokeAvatar,
    handles:page=>['profile','profile-edit','vip','checkout','help','reservation-detail'].includes(page),
    paymentAction:row=>api.capabilities.reservationWorkflow&&api.capabilities.payments ? reservationPaymentLink(row) : '',
    reservationViewAction:reservationViewLink,
    finePaymentAction:finePaymentLink,
  };
})();
