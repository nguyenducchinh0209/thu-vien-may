(() => {
  const wrapper = document.getElementById('notification-wrapper');
  const toggle = document.getElementById('notification-toggle');
  const panel = document.getElementById('notifications-panel');
  const list = document.getElementById('notifications-list');
  const badge = toggle?.querySelector('.notification-count');
  const dot = toggle?.querySelector('i');
  const readAll = document.getElementById('notification-read-all');
  if (!wrapper || !toggle || !panel || !list || !badge || !dot || !readAll) return;

  const allowedRoutes = new Set(['admin','librarian-home','reservations','payments','fines','profile','shelf']);
  let open = false;
  let unreadCount = 0;
  let currentIds = new Set();
  let pendingIdentity = null;

  function render(payload = []) {
    const notifications = Array.isArray(payload) ? payload : [];
    badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
    badge.hidden = unreadCount === 0;
    dot.hidden = true;
    readAll.hidden = unreadCount === 0;
    toggle.setAttribute('aria-label', unreadCount ? `Thông báo, ${unreadCount} chưa đọc` : 'Thông báo');
    currentIds = new Set(notifications.map(item => String(item.id)));

    if (!notifications.length) {
      list.innerHTML = '<p class="notification-empty">Bạn chưa có thông báo nào.</p>';
      return;
    }

    list.innerHTML = notifications.map(item => {
      const data = item.data || {};
      const unread = !item.read_at;
      const date = item.created_at ? new Date(item.created_at).toLocaleString('vi-VN', {dateStyle:'short',timeStyle:'short'}) : '';
      const route = allowedRoutes.has(data.route) ? data.route : '';
      return `<button class="notification-item ${unread?'unread':'read'}" type="button" data-notification-id="${authEscape(item.id)}" data-notification-route="${route}"><i aria-hidden="true"></i><span class="notification-copy"><strong>${authEscape(data.title || 'Thông báo')}</strong><span>${authEscape(data.message || '')}</span><small>${authEscape(date)}</small></span></button>`;
    }).join('');
  }

  async function refresh() {
    const identity = authSession;
    if (!identity?.id) {
      clear();
      return;
    }
    if (pendingIdentity === identity) return;
    pendingIdentity = identity;
    try {
      const result = await authRequest('/my/notifications');
      if (authSession !== identity) return;
      unreadCount = Number(result.unread_count) || 0;
      render(result.data || []);
    } catch (error) {
      if (authSession !== identity) return;
      console.error('Không tải được thông báo:', error);
      if (!list.children.length) list.innerHTML = '<p class="notification-empty">Chưa tải được thông báo. Hãy thử lại sau.</p>';
    } finally {
      if (pendingIdentity === identity) pendingIdentity = null;
    }
  }

  function clear() {
    setOpen(false);
    unreadCount = 0;
    render([]);
  }

  function setOpen(value) {
    open = value;
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
  }

  window.libraryNotifications = {refresh, clear};
  refresh();
  window.setInterval(refresh, 20000);
  window.addEventListener('focus', refresh);

  document.addEventListener('click', async event => {
    if (event.target.closest('#notification-toggle')) {
      event.preventDefault();
      if (!authSession?.id) {
        setOpen(false);
        toast('Đăng nhập để xem thông báo của bạn.');
        go('login');
        return;
      }
      setOpen(!open);
      if (open) await refresh();
      return;
    }

    if (event.target.closest('#notification-read-all')) {
      event.preventDefault();
      const identity = authSession;
      try {
        await authRequest('/my/notifications/read-all', {method:'POST',body:{}});
        if (authSession !== identity) return;
        unreadCount = 0;
        await refresh();
      } catch (error) { if (authSession === identity) toast(error.message); }
      return;
    }

    const item = event.target.closest('[data-notification-id]');
    if (item && wrapper.contains(item)) {
      event.preventDefault();
      const identity = authSession;
      const id = item.dataset.notificationId;
      const route = item.dataset.notificationRoute;
      if (currentIds.has(id)) {
        try { await authRequest(`/my/notifications/${encodeURIComponent(id)}/read`, {method:'POST',body:{}}); }
        catch (error) { if (authSession === identity) toast(error.message); }
      }
      if (authSession !== identity) return;
      setOpen(false);
      await refresh();
      if (authSession === identity && route && allowedRoutes.has(route)) go(route);
      return;
    }

    if (open && !wrapper.contains(event.target)) setOpen(false);
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && open) {
      setOpen(false);
      toggle.focus();
    }
  });
})();
