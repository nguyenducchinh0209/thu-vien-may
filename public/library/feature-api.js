/* Optional frontend contracts. Configure before these deferred scripts load.
 * No feature is enabled until its backend contract is available. */
(function (root) {
  'use strict';
  class FeatureUnavailable extends Error {
    constructor() { super('Chức năng đang được hoàn thiện. Vui lòng quay lại sau.'); this.name = 'FeatureUnavailable'; }
  }
  function createLibraryFeatureApi(config = {}, transport = (...args) => fetch(...args)) {
    const capabilities = Object.freeze({membership:false, plans:false, payments:false, reservationWorkflow:false, chat:false, gender:false, ...config.capabilities});
    const endpoints = {membership:'/my/membership', plans:'/membership/plans', quote:'/my/payment-quotes', payments:'/my/payments', reservations:'/my/reservations', cartReservations:'/my/cart/reservations', staffReservations:'/staff/reservations', chat:'/support/chat', ...config.endpoints};
    async function request(capability, endpoint, {method='GET', body, signal} = {}) {
      if (!capabilities[capability]) throw new FeatureUnavailable();
      const url = new URL(endpoint, root.location.origin);
      if (url.origin !== root.location.origin) throw new Error('Địa chỉ dịch vụ không hợp lệ.');
      const headers = {Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'};
      if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-TOKEN'] = root.document?.querySelector('meta[name="csrf-token"]')?.content || '';
      }
      const controller = new AbortController();
      const abort = () => controller.abort();
      if (signal?.aborted) abort(); else signal?.addEventListener('abort', abort, {once:true});
      const timeout = setTimeout(abort, 20000);
      try {
        const response = await transport(url.href, {method, headers, credentials:'same-origin', signal:controller.signal, body:body === undefined ? undefined : JSON.stringify(body)});
        const data = await response.json().catch(() => { throw new Error('Dịch vụ trả về dữ liệu không hợp lệ.'); });
        if (!response.ok) throw new Error(data.message || 'Không thể xử lý yêu cầu. Vui lòng thử lại.');
        return data;
      } catch (error) {
        if (error.name === 'AbortError' && !signal?.aborted) throw new Error('Kết nối quá lâu. Vui lòng thử lại.');
        throw error;
      } finally {
        clearTimeout(timeout); signal?.removeEventListener('abort', abort);
      }
    }
    return Object.freeze({
      capabilities,
      membership: options => request('membership', endpoints.membership, options),
      plans: options => request('plans', endpoints.plans, options),
      quote: (purpose, referenceId, options={}) => request('payments', endpoints.quote, {...options, method:'POST', body:{purpose, reference_id:String(referenceId)}}),
      createPayment: (quoteId, method, key, options={}) => request('payments', endpoints.payments, {...options, method:'POST', body:{quote_id:quoteId, method, idempotency_key:key}}),
      payment: (id, options) => request('payments', `${endpoints.payments.replace(/\/$/, '')}/${encodeURIComponent(id)}`, options),
      submitCartReservation: (method, options={}) => request('reservationWorkflow', endpoints.cartReservations, {...options, method:'POST', body:{payment_method:method}}),
      reservationAction: (id, action, body={}, options={}) => {
        if (!['approve','report-transfer','confirm-payment','handover'].includes(action)) throw new Error('Thao tác đặt trước không hợp lệ.');
        const staff = action === 'approve' || action === 'confirm-payment' || action === 'handover';
        const base = staff ? endpoints.staffReservations : endpoints.reservations;
        const path = action === 'report-transfer' ? `${endpoints.reservations}/${encodeURIComponent(id)}/transfer` : `${base}/${encodeURIComponent(id)}/${action}`;
        return request('reservationWorkflow', path, {...options, method:'POST', body});
      },
      reservations: options => request('reservationWorkflow', endpoints.reservations, options),
      chat: (message, history, options={}) => request('chat', endpoints.chat, {...options, method:'POST', body:{message, history:history.slice(-8)}}),
    });
  }
  // Never infer membership, payment success or eligibility from a missing field.
  const isAmount = value => (typeof value === 'number' || (typeof value === 'string' && /^\d+(?:\.\d{1,2})?$/.test(value))) && Number.isFinite(Number(value)) && Number(value) >= 0;
  const rules = Object.freeze({
    isAmount,
    reservationPayable(row, now=Date.now()) {
      return !!row && row.status === 'ready' && Number.isFinite(Date.parse(row.expires_at)) && Date.parse(row.expires_at) > now && row.payment_status === 'unpaid';
    },
    membershipLabel(value) {
      if (value?.tier === 'vip' && value.status === 'active' && Date.parse(value.expires_at) > Date.now()) return 'VIP';
      if (value?.tier === 'free') return 'Miễn phí';
      return 'Chưa có thông tin';
    },
    paymentStatus(value) {
      return ['pending','confirming','paid','failed','cancelled','expired'].includes(value) ? value : 'unknown';
    },
  });
  root.createLibraryFeatureApi = createLibraryFeatureApi;
  root.LibraryFeatureRules = rules;
  root.LibraryFeatureUnavailable = FeatureUnavailable;
  root.libraryFeatureApi = createLibraryFeatureApi(root.libraryFeatureConfig);
})(globalThis);
