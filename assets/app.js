(function () {
  'use strict';

  // ---------- Vehicle drawings (top-down, front at the top) ----------
  // Seat coordinates are percentages of the drawing; the first seat is the driver (not bookable).
  const DRAWINGS = {
    buggy: {
      viewBox: '0 0 200 290',
      svg:
        '<rect class="vb-tyre" x="8" y="40" width="24" height="56" rx="8"/>' +
        '<rect class="vb-tyre" x="168" y="40" width="24" height="56" rx="8"/>' +
        '<rect class="vb-tyre" x="2" y="186" width="32" height="70" rx="10"/>' +
        '<rect class="vb-tyre" x="166" y="186" width="32" height="70" rx="10"/>' +
        '<path class="vb-body" d="M62 12 H138 Q164 12 166 40 L174 248 Q174 278 146 278 H54 Q26 278 26 248 L34 40 Q36 12 62 12Z"/>' +
        '<circle class="vb-light" cx="68" cy="24" r="6"/><circle class="vb-light" cx="132" cy="24" r="6"/>' +
        '<path class="vb-line" d="M42 84 Q100 70 158 84 M42 160 Q100 150 158 160 M46 84 L48 160 M154 84 L152 160 M70 248 H130 M74 260 H126"/>',
      seats: [[32, 40], [68, 40], [22, 70], [50, 70], [78, 70]],
    },
    hilux: {
      viewBox: '0 0 200 400',
      svg:
        '<rect class="vb-tyre" x="8" y="50" width="24" height="62" rx="8"/>' +
        '<rect class="vb-tyre" x="168" y="50" width="24" height="62" rx="8"/>' +
        '<rect class="vb-tyre" x="8" y="286" width="24" height="62" rx="8"/>' +
        '<rect class="vb-tyre" x="168" y="286" width="24" height="62" rx="8"/>' +
        '<path class="vb-body" d="M54 8 H146 Q174 8 176 40 V378 Q176 394 160 394 H40 Q24 394 24 378 V40 Q26 8 54 8Z"/>' +
        '<circle class="vb-light" cx="62" cy="18" r="6"/><circle class="vb-light" cx="138" cy="18" r="6"/>' +
        '<path class="vb-glass" d="M40 58 Q100 46 160 58 L154 82 Q100 72 46 82Z"/>' +
        '<path class="vb-line" d="M34 200 H166"/>' +
        '<rect class="vb-line" x="38" y="210" width="124" height="170" rx="8"/>' +
        '<path class="vb-line" d="M62 228 V362 M100 228 V362 M138 228 V362" stroke-dasharray="2 10"/>',
      seats: [[32, 29], [68, 29], [24, 42], [50, 42], [76, 42], [24, 71], [50, 71], [76, 71]],
    },
  };

  const API = 'api.php';
  const money = (cents, opts) => {
    const whole = cents % 100 === 0;
    return new Intl.NumberFormat('en-US', {
      style: 'currency', currency: 'BRL',
      minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2,
    }).format(cents / 100).replace('R$', 'R$ ') + (opts && opts.suffix ? opts.suffix : '');
  };

  const $ = (id) => document.getElementById(id);
  const S = {
    me: null, csrf: '', types: {},
    tab: 'rides',
    date: isoDate(addDays(new Date(), 1)),
    stripStart: isoDate(addDays(new Date(), -2)),
    day: null, calendar: {}, balances: null,
    sheet: null, // 'join' | 'guest' | null
    confirm: null, // { kind, id } for inline confirmations
    editingPrice: null, payingId: null,
    lastRiderIds: null, lastDate: null,
    dragging: false,
  };

  // ---------- Small helpers ----------
  function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function isoDate(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function parseIso(s) { return new Date(s + 'T00:00:00'); }
  function dayDiff(s) { return Math.round((parseIso(s) - parseIso(isoDate(new Date()))) / 86400000); }
  function fmt(s, o) { return parseIso(s).toLocaleDateString('en-GB', o); }
  function relLabel(s) {
    const d = dayDiff(s);
    return d === 0 ? 'Today' : d === 1 ? 'Tomorrow' : d === -1 ? 'Yesterday' : null;
  }
  function initials(name) {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  function hueFor(id) { return (Number(id) * 137 + 17) % 360; }

  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    if (attrs) {
      for (const k in attrs) {
        const v = attrs[k];
        if (v == null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'style') el.style.cssText = v;
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'html') el.innerHTML = v;
        else el.setAttribute(k, v === true ? '' : v);
      }
    }
    for (const c of children.flat()) {
      if (c == null || c === false) continue;
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    }
    return el;
  }
  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    svg.appendChild(use);
    return svg;
  }
  function avatar(name, hueId, extraClass) {
    return h('span', { class: 'avatar' + (extraClass ? ' ' + extraClass : ''), style: '--hue:' + hueFor(hueId), 'aria-hidden': 'true' }, initials(name));
  }

  let toastTimer = null;
  function toast(text, isError) {
    const t = $('toast');
    t.textContent = text;
    t.className = 'toast' + (isError ? ' is-error' : '');
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, isError ? 5000 : 2600);
  }

  // ---------- API ----------
  async function request(method, params, body) {
    const url = API + '?' + new URLSearchParams(Object.assign({ _: Date.now() }, params || {}));
    const res = await fetch(url, {
      method,
      cache: 'no-store',
      credentials: 'same-origin',
      headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
    let data;
    try { data = await res.json(); } catch (e) { throw new Error('The server sent an unexpected reply (' + res.status + ').'); }
    if (!res.ok) {
      if (data.gate || data.reload) { location.reload(); return new Promise(() => {}); }
      if (data.login && S.me) { S.me = null; showAuth(); }
      const err = new Error(data.error || 'Request failed');
      err.status = res.status;
      throw err;
    }
    return data;
  }
  const get = (action, params) => request('GET', Object.assign({ action }, params));
  const post = (action, data) => request('POST', null, Object.assign({ action }, data));

  async function withBusy(btn, fn) {
    if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
    try { return await fn(); }
    finally { if (btn && btn.isConnected) { btn.disabled = false; btn.classList.remove('is-busy'); } }
  }

  // ---------- Auth ----------
  let authMode = 'login';
  function setAuthMode(mode) {
    authMode = mode;
    $('authView').dataset.mode = mode;
    $('tabLogin').setAttribute('aria-selected', String(mode === 'login'));
    $('tabRegister').setAttribute('aria-selected', String(mode === 'register'));
    $('authSubmit').textContent = mode === 'login' ? 'Sign in' : 'Create account';
    $('authForm').password.autocomplete = mode === 'login' ? 'current-password' : 'new-password';
    $('authMsg').textContent = '';
  }
  function showAuth() {
    document.body.classList.remove('is-loading');
    $('appView').hidden = true;
    $('authView').hidden = false;
    setAuthMode(authMode);
    $('authForm').username.focus();
  }
  document.querySelectorAll('[data-auth-mode]').forEach((b) => b.addEventListener('click', () => setAuthMode(b.dataset.authMode)));
  $('authForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const msg = $('authMsg');
    msg.className = 'form-msg';
    msg.textContent = '';
    const payload = { username: f.username.value.trim(), password: f.password.value };
    if (authMode === 'register') payload.name = f.name.value.trim();
    if (!payload.username || !payload.password || (authMode === 'register' && !payload.name)) {
      msg.className = 'form-msg is-error';
      msg.textContent = authMode === 'register' ? 'Fill in your name, a username and a password.' : 'Enter your username and password.';
      return;
    }
    try {
      const me = await withBusy($('authSubmit'), () => post(authMode, payload));
      applyMe(me);
      f.reset();
      showApp();
    } catch (err) {
      msg.className = 'form-msg is-error';
      msg.textContent = err.message;
    }
  });

  function applyMe(me) {
    S.me = me.user;
    S.csrf = me.csrf;
    S.types = me.types;
  }

  $('logoutBtn').addEventListener('click', async () => {
    try {
      await post('logout');
      const me = await get('me');
      applyMe(me);
    } catch (e) { /* fall through to the sign-in screen */ }
    S.me = null;
    S.day = null;
    showAuth();
  });

  // ---------- App shell ----------
  function showApp() {
    document.body.classList.remove('is-loading');
    $('authView').hidden = true;
    $('appView').hidden = false;
    $('meName').textContent = S.me.name;
    const av = $('meAvatar');
    av.textContent = initials(S.me.name);
    av.style.setProperty('--hue', hueFor(S.me.id));
    setTab(S.tab);
  }

  function setTab(tab) {
    S.tab = tab;
    document.querySelectorAll('.tab').forEach((t) => {
      if (t.dataset.tab === tab) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
    });
    $('ridesView').hidden = tab !== 'rides';
    $('moneyView').hidden = tab !== 'money';
    if (tab === 'rides') { renderStrip(); loadDay(); loadCalendar(); }
    else loadBalances();
    window.scrollTo({ top: 0 });
  }
  document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => setTab(t.dataset.tab)));

  // ---------- Date strip ----------
  const STRIP_DAYS = 14;
  function stripDates() {
    const start = parseIso(S.stripStart);
    return Array.from({ length: STRIP_DAYS }, (_, i) => isoDate(addDays(start, i)));
  }
  function renderStrip() {
    const ol = $('dateStrip');
    ol.innerHTML = '';
    for (const d of stripDates()) {
      const info = S.calendar[d];
      const dots = [];
      if (info) {
        const n = Math.min(info.riders, 5);
        for (let i = 0; i < n; i++) {
          let cls = '';
          if (i === 0 && info.mine) cls = 'is-mine';
          else if (info.vehicles && info.paid === info.vehicles && i === n - 1) cls = 'is-paid';
          dots.push(h('i', { class: cls }));
        }
      }
      const diff = dayDiff(d);
      const label = fmt(d, { weekday: 'long', day: 'numeric', month: 'long' }) + (info ? ', ' + info.riders + ' riding' : '');
      ol.appendChild(h('li', null, h('button', {
        type: 'button',
        class: 'dchip' + (diff === 0 ? ' is-today' : '') + (diff < 0 ? ' is-past' : ''),
        'aria-current': d === S.date ? 'date' : null,
        'aria-label': label,
        onclick: () => selectDate(d),
      },
      h('span', { class: 'dchip-dow' }, fmt(d, { weekday: 'short' })),
      h('span', { class: 'dchip-num' }, fmt(d, { day: 'numeric' })),
      h('span', { class: 'dchip-dots' }, dots))));
    }
    const cur = ol.querySelector('[aria-current="date"]');
    if (cur) cur.scrollIntoView({ block: 'nearest', inline: 'center' });
    $('datePicker').value = S.date;
  }
  async function loadCalendar() {
    const dates = stripDates();
    try {
      const res = await get('calendar', { from: dates[0], to: dates[dates.length - 1] });
      S.calendar = Object.assign(S.calendar, res.days);
      renderStrip();
    } catch (e) { /* the strip still works without counts */ }
  }
  function selectDate(d) {
    if (!d) return;
    S.date = d;
    const dates = stripDates();
    if (d < dates[0] || d > dates[dates.length - 1]) S.stripStart = isoDate(addDays(parseIso(d), -3));
    S.sheet = null; S.confirm = null; S.editingPrice = null; S.payingId = null;
    renderStrip();
    loadDay();
    loadCalendar();
  }
  $('prevDays').addEventListener('click', () => { S.stripStart = isoDate(addDays(parseIso(S.stripStart), -7)); renderStrip(); loadCalendar(); });
  $('nextDays').addEventListener('click', () => { S.stripStart = isoDate(addDays(parseIso(S.stripStart), 7)); renderStrip(); loadCalendar(); });
  $('datePicker').addEventListener('change', (e) => selectDate(e.target.value));

  // ---------- Day ----------
  async function loadDay() {
    const date = S.date;
    try {
      const day = await get('state', { date });
      if (date === S.date) applyDay(day);
    } catch (e) {
      setDayMsg('Could not load this day: ' + e.message, true);
    }
  }
  function applyDay(day) {
    if (day.date !== S.date) return;
    const prev = S.lastDate === day.date ? S.lastRiderIds : null;
    S.day = day;
    S.lastDate = day.date;
    S.lastRiderIds = new Set(day.riders.map((r) => r.id));
    S.calendar[day.date] = {
      riders: day.riders.length,
      mine: day.riders.some((r) => r.mine && !r.guestOf),
      vehicles: day.vehicles.length,
      paid: day.vehicles.filter((v) => v.paidBy).length,
    };
    renderDay(prev);
    renderStrip();
  }
  async function act(action, data, btn, okText) {
    try {
      const day = await withBusy(btn, () => post(action, Object.assign({ date: S.date }, data)));
      S.confirm = null; S.editingPrice = null; S.payingId = null;
      applyDay(day);
      if (okText) toast(okText);
      return true;
    } catch (e) {
      toast(e.message, true);
      if (e.status === 409 || e.status === 404) loadDay();
      return false;
    }
  }
  function setDayMsg(text, isError) {
    const m = $('dayMsg');
    m.textContent = text || '';
    m.className = 'form-msg' + (isError ? ' is-error' : '');
  }

  // Lays riders into the seats of the given vehicle list, in booking order.
  function seatPlan(vehicleTypes, riders) {
    const plan = [];
    let idx = 0;
    vehicleTypes.forEach((type, v) => {
      const seats = DRAWINGS[type].seats.slice(1);
      plan[v] = seats.map(() => (idx < riders.length ? { rider: riders[idx], flat: idx++ } : { rider: null, flat: idx++ }));
    });
    return { plan, overflow: riders.slice(Math.min(idx, riders.length)) };
  }

  function renderDay(prevIds) {
    const day = S.day;
    if (!day) return;
    const rel = relLabel(day.date);
    $('dayTitle').textContent = fmt(day.date, { weekday: 'long', day: 'numeric', month: 'short' });

    const sub = $('daySub');
    sub.innerHTML = '';
    if (rel) sub.appendChild(h('span', { class: 'pill' }, rel));
    if (day.locked) {
      sub.appendChild(h('span', { class: 'pill is-locked' }, icon('lock'), 'Seats locked'));
    } else {
      sub.appendChild(h('span', null, 'Seat changes close ' + fmt(isoDate(addDays(parseIso(day.date), -1)), { weekday: 'short' }) + ' 22:00'));
    }

    // Cost
    const sm = day.summary;
    tickNumber($('perPerson'), sm.riders ? sm.perPersonCents : 0, sm.riders ? null : '—');
    const meta = $('costMeta');
    meta.innerHTML = '';
    if (!sm.riders) {
      meta.append('Nobody has joined yet.');
    } else {
      meta.append(money(sm.totalCents) + ' for ' + sm.riders + (sm.riders === 1 ? ' rider' : ' riders'));
      if (!day.vehicles.length) meta.append(h('span', { class: 'est' }, ' · estimate from the proposal'));
      else if (sm.paidCents < sm.totalCents) meta.append(h('span', { class: 'est' }, ' · ' + money(sm.paidCents) + ' paid so far'));
      else meta.append(h('span', { class: 'est' }, ' · all paid'));
    }

    renderFleet(prevIds);
    renderRiders();
    renderActionBar();
  }

  function renderFleet(prevIds) {
    const day = S.day;
    const fleet = $('fleet');
    const actions = $('stageActions');
    fleet.innerHTML = '';
    actions.innerHTML = '';
    const booked = day.vehicles.length > 0;
    const types = booked ? day.vehicles.map((v) => v.type) : day.proposal;

    if (!booked && day.proposal.length) {
      const names = day.proposal.map((t) => S.types[t].label);
      actions.append(
        h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: (e) => act('book_proposal', {}, e.currentTarget, 'Fleet booked') },
          icon('check'), 'Book ' + (names.length > 1 ? 'this fleet' : 'the ' + names[0].toLowerCase())));
    }
    if (booked || day.riders.length) {
      actions.append(
        h('button', { type: 'button', class: 'btn btn-quiet btn-sm', onclick: (e) => act('add_vehicle', { type: 'buggy' }, e.currentTarget) }, icon('plus'), 'Buggy'),
        h('button', { type: 'button', class: 'btn btn-quiet btn-sm', onclick: (e) => act('add_vehicle', { type: 'hilux' }, e.currentTarget) }, icon('plus'), 'Hilux'));
    }

    if (!types.length) {
      fleet.appendChild(h('div', { class: 'fleet-empty' },
        h('strong', null, 'No convoy yet'),
        'Join this day and the right vehicles appear here: a buggy for up to 4, a hilux for up to 7.'));
      $('overflowNote').hidden = true;
      return;
    }

    const { plan, overflow } = seatPlan(types, day.riders);
    const canDrag = !day.locked;
    const counts = {};
    types.forEach((type, i) => {
      counts[type] = (counts[type] || 0) + 1;
      const v = booked ? day.vehicles[i] : null;
      const draw = DRAWINGS[type];
      const seatsEl = [];
      const [dx, dy] = draw.seats[0];
      seatsEl.push(h('span', { class: 'seat is-driver', style: '--x:' + dx + '%;--y:' + dy + '%', title: 'Driver' }, icon('wheel')));
      plan[i].forEach((slot, s) => {
        const [x, y] = draw.seats[s + 1];
        const r = slot.rider;
        const attrs = { class: 'seat', style: '--x:' + x + '%;--y:' + y + '%', 'data-flat': slot.flat };
        if (r) {
          attrs.class += ' is-filled' + (r.mine ? ' is-mine' : '') + (r.guestOf ? ' is-guest' : '') + (canDrag ? ' can-drag' : '');
          attrs.class += prevIds && !prevIds.has(r.id) ? ' just-filled' : '';
          attrs.style += ';--hue:' + hueFor(r.userId);
          attrs['data-id'] = r.id;
          attrs.title = r.name + (r.guestOf ? ' (guest of ' + r.guestOf + ')' : '') + ' — ' + r.pickup;
          attrs['aria-label'] = 'Seat ' + (s + 1) + ': ' + r.name;
          seatsEl.push(h('span', attrs, initials(r.name)));
        } else {
          attrs['aria-label'] = 'Seat ' + (s + 1) + ': open';
          seatsEl.push(h('span', attrs));
        }
      });

      const body = h('div', { class: 'vehicle-body', role: 'group', 'aria-label': S.types[type].label + ' seats' });
      body.innerHTML = '<svg viewBox="' + draw.viewBox + '" aria-hidden="true">' + draw.svg + '</svg>';
      seatsEl.forEach((el) => body.appendChild(el));

      const filled = plan[i].filter((x) => x.rider).length;
      const state = !v ? 'proposed' : v.paidBy ? 'paid' : 'booked';
      const name = h('div', { class: 'vehicle-name' }, S.types[type].label + (types.filter((t) => t === type).length > 1 ? ' ' + counts[type] : ''),
        h('small', null, filled + '/' + S.types[type].seats));
      const metaEl = h('div', { class: 'vehicle-meta' }, name, statusChip(v), priceEl(v, type), vehicleActions(v));
      fleet.appendChild(h('article', { class: 'vehicle is-' + state, 'data-type': type, style: 'animation-delay:' + i * 70 + 'ms' }, body, metaEl));
    });

    const note = $('overflowNote');
    if (overflow.length) {
      note.hidden = false;
      note.innerHTML = '';
      note.append(
        h('span', null, h('strong', null, overflow.length + (overflow.length === 1 ? ' rider has' : ' riders have') + ' no seat: '),
          overflow.map((r) => r.name).join(', ')),
        h('button', { type: 'button', class: 'btn btn-sm btn-ghost', onclick: (e) => act('add_vehicle', { type: overflow.length <= 4 ? 'buggy' : 'hilux' }, e.currentTarget) },
          icon('plus'), 'Add ' + (overflow.length <= 4 ? 'a buggy' : 'a hilux')));
    } else {
      note.hidden = true;
    }
  }

  function statusChip(v) {
    if (!v) return h('span', { class: 'chip chip-proposed' }, 'Proposed');
    if (v.paidBy) return h('span', { class: 'chip chip-paid' }, icon('check'), 'Paid by ' + (v.paidBy.id === S.me.id ? 'you' : v.paidBy.name));
    return h('span', { class: 'chip chip-booked' }, 'Booked');
  }

  function priceEl(v, type) {
    if (!v) return h('span', { class: 'price' }, money(S.types[type].defaultPriceCents));
    const mayEdit = !v.paidBy || v.paidBy.id === S.me.id;
    if (S.editingPrice === v.id || S.payingId === v.id) {
      const paying = S.payingId === v.id;
      const input = h('input', { type: 'number', min: '0', step: '0.01', inputmode: 'decimal', value: (v.priceCents / 100).toString(), 'aria-label': 'Price in reais' });
      const form = h('form', {
        class: 'price-edit',
        onsubmit: (e) => {
          e.preventDefault();
          const btn = form.querySelector('button[type=submit]');
          if (paying) act('pay_vehicle', { id: v.id, price: input.value }, btn, 'Marked as paid by you');
          else act('update_vehicle', { id: v.id, price: input.value }, btn, 'Price saved');
        },
      }, h('span', null, 'R$'), input,
      h('button', { type: 'submit', class: 'btn btn-sm ' + (paying ? 'btn-teal' : 'btn-primary') }, paying ? 'I paid' : 'Save'),
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Cancel', onclick: () => { S.editingPrice = null; S.payingId = null; renderFleet(); } }, icon('x')));
      setTimeout(() => { input.focus(); input.select(); }, 0);
      return form;
    }
    if (!mayEdit) return h('span', { class: 'price' }, money(v.priceCents));
    return h('button', { type: 'button', class: 'price', title: 'Change the price', onclick: () => { S.editingPrice = v.id; S.payingId = null; renderFleet(); } }, money(v.priceCents));
  }

  function vehicleActions(v) {
    if (!v || S.editingPrice === v.id || S.payingId === v.id) return null;
    const wrap = h('div', { class: 'vehicle-actions' });
    if (v.paidBy) {
      if (v.paidBy.id === S.me.id) {
        wrap.append(h('button', { type: 'button', class: 'btn btn-quiet btn-sm', onclick: (e) => act('unpay_vehicle', { id: v.id }, e.currentTarget, 'Payment removed') }, icon('undo'), 'Undo payment'));
      }
      return wrap;
    }
    if (S.confirm && S.confirm.kind === 'vehicle' && S.confirm.id === v.id) {
      wrap.append(
        h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: (e) => act('remove_vehicle', { id: v.id }, e.currentTarget, 'Vehicle removed') }, 'Remove'),
        h('button', { type: 'button', class: 'btn btn-quiet btn-sm', onclick: () => { S.confirm = null; renderFleet(); } }, 'Keep'));
      return wrap;
    }
    const other = v.type === 'buggy' ? 'hilux' : 'buggy';
    wrap.append(
      h('button', { type: 'button', class: 'btn btn-teal btn-sm', onclick: () => { S.payingId = v.id; S.editingPrice = null; renderFleet(); } }, icon('check'), 'I paid'),
      h('button', { type: 'button', class: 'icon-btn', title: 'Switch to ' + S.types[other].label, 'aria-label': 'Switch to ' + S.types[other].label, onclick: (e) => act('update_vehicle', { id: v.id, type: other }, e.currentTarget) }, icon('swap')),
      h('button', { type: 'button', class: 'icon-btn', title: 'Remove', 'aria-label': 'Remove vehicle', onclick: () => { S.confirm = { kind: 'vehicle', id: v.id }; renderFleet(); } }, icon('trash')));
    return wrap;
  }

  function renderRiders() {
    const day = S.day;
    const ul = $('riderList');
    ul.innerHTML = '';
    $('riderCount').textContent = day.riders.length;
    if (!day.riders.length) {
      ul.appendChild(h('li', { class: 'rider-empty' }, 'No one yet. Be the first.'));
      return;
    }
    for (const r of day.riders) {
      let tail = null;
      if (r.mine && !day.locked) {
        if (S.confirm && S.confirm.kind === 'rider' && S.confirm.id === r.id) {
          tail = h('span', { class: 'confirm' },
            h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: (e) => act('cancel', { id: r.id }, e.currentTarget, r.guestOf ? 'Guest removed' : 'You left this day') }, r.guestOf ? 'Remove' : 'Leave'),
            h('button', { type: 'button', class: 'btn btn-quiet btn-sm', onclick: () => { S.confirm = null; renderRiders(); } }, 'Keep'));
        } else {
          tail = h('button', { type: 'button', class: 'icon-btn', 'aria-label': r.guestOf ? 'Remove guest ' + r.name : 'Leave this day', title: r.guestOf ? 'Remove guest' : 'Leave this day', onclick: () => { S.confirm = { kind: 'rider', id: r.id }; renderRiders(); } }, icon('x'));
        }
      }
      ul.appendChild(h('li', { class: 'rider' },
        avatar(r.name, r.userId),
        h('div', { class: 'rider-main' },
          h('span', { class: 'rider-name' }, r.name + (r.mine && !r.guestOf ? ' ' : ''), r.mine && !r.guestOf ? h('em', null, '(you)') : null, r.guestOf ? h('em', null, ' guest of ' + (r.mine ? 'you' : r.guestOf)) : null),
          h('span', { class: 'rider-pickup' }, icon('pin'), h('span', null, r.pickup))),
        tail));
    }
  }

  function renderActionBar() {
    const day = S.day;
    const main = $('actionMain');
    main.innerHTML = '';
    const mine = day.riders.find((r) => r.mine && !r.guestOf);
    const sheet = $('joinSheet');
    sheet.hidden = !S.sheet;
    main.hidden = !!S.sheet;
    if (S.sheet) return;

    const dayShort = fmt(day.date, { weekday: 'short', day: 'numeric', month: 'short' });
    if (!mine) {
      main.append(
        h('div', { class: 'actionbar-status' },
          h('strong', null, day.riders.length ? day.riders.length + ' riding' : 'Nobody riding yet'),
          h('span', null, day.riders.length ? '≈ ' + money(day.summary.perPersonCents) + ' each' : 'Start the convoy')),
        h('button', { type: 'button', class: 'btn btn-primary', onclick: () => openSheet('join') }, 'Join ' + dayShort));
    } else {
      const { plan } = seatPlan(day.vehicles.length ? day.vehicles.map((v) => v.type) : day.proposal, day.riders);
      let where = 'no seat yet';
      plan.forEach((seats, i) => seats.forEach((s, j) => {
        if (s.rider && s.rider.id === mine.id) {
          const t = day.vehicles.length ? day.vehicles[i].type : day.proposal[i];
          where = S.types[t].label + ', seat ' + (j + 1);
        }
      }));
      main.append(
        h('div', { class: 'actionbar-status' }, h('strong', null, 'You’re in'), h('span', null, where)),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => openSheet('guest') }, icon('user-plus'), 'Guest'));
    }
  }

  function openSheet(kind) {
    S.sheet = kind;
    const mine = S.day.riders.find((r) => r.mine && !r.guestOf);
    $('pickupInput').value = (mine && mine.pickup) || S.me.defaultPickup || '';
    $('guestInput').value = '';
    $('guestField').hidden = kind !== 'guest';
    $('joinSheetLabel').textContent = kind === 'guest' ? 'Guest’s pickup' : 'Pickup location';
    $('joinConfirm').textContent = kind === 'guest' ? 'Add guest' : 'Join ' + fmt(S.day.date, { weekday: 'short', day: 'numeric', month: 'short' });
    setDayMsg('');
    renderActionBar();
    (kind === 'guest' ? $('guestInput') : $('pickupInput')).focus();
  }
  function closeSheet() { S.sheet = null; renderActionBar(); }
  $('joinCancel').addEventListener('click', closeSheet);
  $('joinSheet').addEventListener('submit', async (e) => {
    e.preventDefault();
    const pickup = $('pickupInput').value.trim();
    if (!pickup) { setDayMsg('Where should the driver pick you up?', true); $('pickupInput').focus(); return; }
    if (S.sheet === 'guest') {
      const name = $('guestInput').value.trim();
      if (!name) { setDayMsg('Enter your guest’s name.', true); $('guestInput').focus(); return; }
      S.sheet = null;
      if (await act('add_guest', { name, pickup }, $('joinConfirm'), name + ' added — their share goes on your tab')) setDayMsg('');
      else S.sheet = 'guest';
    } else {
      S.sheet = null;
      if (await act('join', { pickup }, $('joinConfirm'), 'You’re in!')) { setDayMsg(''); S.me.defaultPickup = pickup; }
      else S.sheet = 'join';
    }
    renderActionBar();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (S.sheet) closeSheet();
    else if (S.confirm || S.editingPrice || S.payingId) { S.confirm = null; S.editingPrice = null; S.payingId = null; renderFleet(); renderRiders(); }
  });

  // Count-up/down for the per-person cost.
  function tickNumber(el, cents, fallback) {
    if (fallback) { el.textContent = fallback; el.dataset.v = ''; return; }
    const from = el.dataset.v ? Number(el.dataset.v) : cents;
    el.dataset.v = String(cents);
    if (from === cents || matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = money(cents); return; }
    const start = performance.now();
    const dur = 700;
    const step = (now) => {
      const t = Math.min(1, (now - start) / dur);
      const e = 1 - Math.pow(1 - t, 4);
      el.textContent = money(Math.round((from + (cents - from) * e) / 100) * 100);
      if (t < 1) requestAnimationFrame(step); else el.textContent = money(cents);
    };
    requestAnimationFrame(step);
  }

  // ---------- Drag seats (pointer events: mouse, touch and pen) ----------
  let drag = null;
  $('fleet').addEventListener('pointerdown', (e) => {
    const seat = e.target.closest('.seat.can-drag');
    if (!seat || e.button > 0) return;
    e.preventDefault();
    drag = { id: Number(seat.dataset.id), from: Number(seat.dataset.flat), seat, x: e.clientX, y: e.clientY, ghost: null, target: null };
    document.addEventListener('pointermove', onDragMove);
    document.addEventListener('pointerup', onDragEnd);
    document.addEventListener('pointercancel', onDragEnd);
  });
  function onDragMove(e) {
    if (!drag) return;
    if (!drag.ghost) {
      if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 6) return;
      drag.ghost = h('div', { class: 'drag-ghost', style: '--hue:' + drag.seat.style.getPropertyValue('--hue') }, drag.seat.textContent);
      document.body.appendChild(drag.ghost);
      drag.seat.classList.add('is-dragging');
      document.body.classList.add('is-dragging');
      S.dragging = true;
    }
    drag.ghost.style.left = e.clientX + 'px';
    drag.ghost.style.top = e.clientY + 'px';
    const under = document.elementFromPoint(e.clientX, e.clientY);
    const target = under && under.closest('.seat[data-flat]');
    document.querySelectorAll('.seat.is-drop').forEach((s) => s.classList.remove('is-drop'));
    drag.target = null;
    if (target && target !== drag.seat) { target.classList.add('is-drop'); drag.target = Number(target.dataset.flat); }
  }
  function onDragEnd() {
    document.removeEventListener('pointermove', onDragMove);
    document.removeEventListener('pointerup', onDragEnd);
    document.removeEventListener('pointercancel', onDragEnd);
    if (!drag) return;
    const d = drag;
    drag = null;
    S.dragging = false;
    document.body.classList.remove('is-dragging');
    if (d.ghost) d.ghost.remove();
    d.seat.classList.remove('is-dragging');
    document.querySelectorAll('.seat.is-drop').forEach((s) => s.classList.remove('is-drop'));
    if (d.target == null) return;
    const riders = S.day.riders.slice();
    const from = riders.findIndex((r) => r.id === d.id);
    if (from < 0) return;
    const [moved] = riders.splice(from, 1);
    riders.splice(Math.min(d.target, riders.length), 0, moved);
    S.day.riders = riders;
    renderFleet();
    renderRiders();
    act('reorder', { order: riders.map((r) => r.id) });
  }

  // ---------- Money ----------
  async function loadBalances() {
    try {
      S.balances = await get('balances');
      renderMoney();
    } catch (e) {
      toast('Could not load balances: ' + e.message, true);
    }
  }

  function renderMoney() {
    const b = S.balances;
    if (!b) return;
    const meRow = b.users.find((u) => u.id === S.me.id) || { net: 0, paid: 0, share: 0, sent: 0, received: 0 };
    const title = $('balanceTitle');
    title.innerHTML = '';
    if (meRow.net > 0) title.append('You’re owed ', h('span', { class: 'pos' }, money(meRow.net)));
    else if (meRow.net < 0) title.append('You owe ', h('span', { class: 'neg' }, money(-meRow.net)));
    else title.append('You’re all square');
    const metaParts = ['You paid ' + money(meRow.paid), 'your share ' + money(meRow.share)];
    if (meRow.sent) metaParts.push('repaid ' + money(meRow.sent));
    if (meRow.received) metaParts.push('received ' + money(meRow.received));
    let meta = metaParts.join(' · ') + '.';
    if (b.unsplit.length) meta += ' ' + b.unsplit.length + ' paid day' + (b.unsplit.length > 1 ? 's have' : ' has') + ' no riders yet, so ' + (b.unsplit.length > 1 ? 'they aren’t' : 'it isn’t') + ' split.';
    $('balanceMeta').textContent = meta;

    // Settle up
    const tl = $('transferList');
    tl.innerHTML = '';
    if (!b.transfers.length) {
      tl.appendChild(h('li', { class: 'transfers-empty' }, icon('check'), 'Everyone is square. Nothing to settle.'));
    }
    for (const t of b.transfers) {
      const fromMe = t.from === S.me.id;
      const toMe = t.to === S.me.id;
      let btn = null;
      if (fromMe || toMe) {
        btn = h('button', {
          type: 'button', class: 'btn btn-sm ' + (fromMe ? 'btn-primary' : 'btn-teal'),
          onclick: (e) => settle({ from: t.from, to: t.to, amount: (t.amountCents / 100).toFixed(2), note: '' }, e.currentTarget),
        }, icon('check'), fromMe ? 'I paid ' + t.toName : t.fromName + ' paid me');
      }
      tl.appendChild(h('li', { class: 'transfer' + (fromMe ? ' is-mine' : '') + (toMe ? ' is-to-me' : '') },
        avatar(t.fromName, t.from),
        h('span', { class: 'transfer-who' }, h('span', null, fromMe ? 'You' : t.fromName), icon('right'), h('span', null, toMe ? 'you' : t.toName)),
        h('span', { class: 'transfer-amt' }, money(t.amountCents)),
        btn));
    }

    // Record form selects
    const fromSel = $('settleFrom');
    const toSel = $('settleTo');
    const keepFrom = fromSel.value || String(S.me.id);
    const keepTo = toSel.value;
    fromSel.innerHTML = '';
    toSel.innerHTML = '';
    const people = b.users.slice().sort((x, y) => x.name.localeCompare(y.name));
    for (const u of people) {
      const label = u.id === S.me.id ? u.name + ' (you)' : u.name;
      fromSel.appendChild(h('option', { value: u.id }, label));
      toSel.appendChild(h('option', { value: u.id }, label));
    }
    fromSel.value = keepFrom;
    const firstOther = people.find((u) => u.id !== S.me.id);
    toSel.value = keepTo || (firstOther ? String(firstOther.id) : '');

    // Balances
    const bl = $('balanceList');
    bl.innerHTML = '';
    const maxAbs = Math.max(1, ...b.users.map((u) => Math.abs(u.net)));
    for (const u of b.users) {
      const cls = u.net > 0 ? 'pos' : u.net < 0 ? 'neg' : 'zero';
      const w = (Math.abs(u.net) / maxAbs) * 50;
      bl.appendChild(h('li', { class: 'bal' + (u.id === S.me.id ? ' is-me' : ''), title: 'Paid ' + money(u.paid) + ', share ' + money(u.share) },
        h('span', { class: 'bal-name' }, u.id === S.me.id ? 'You' : u.name),
        h('span', { class: 'bal-track', 'aria-hidden': 'true' }, u.net ? h('span', { class: 'bal-bar ' + cls, style: 'width:' + w + '%' }) : null),
        h('span', { class: 'bal-amt ' + cls }, u.net > 0 ? '+' + money(u.net) : u.net < 0 ? '−' + money(-u.net) : money(0))));
    }

    // History
    const hl = $('historyList');
    hl.innerHTML = '';
    if (!b.history.length) hl.appendChild(h('li', { class: 'hist-empty' }, 'Nothing yet. Paid vehicles and repayments show up here.'));
    for (const it of b.history) {
      if (it.kind === 'day') {
        const kinds = {};
        it.vehicles.forEach((v) => { kinds[v.type] = (kinds[v.type] || 0) + 1; });
        const what = Object.keys(kinds).map((k) => (kinds[k] > 1 ? kinds[k] + '× ' : '') + S.types[k].label).join(' + ');
        const payers = Array.from(new Set(it.vehicles.map((v) => v.paidBy))).join(', ');
        hl.appendChild(h('li', { class: 'hist' },
          h('span', { class: 'hist-dot' }, icon('car')),
          h('div', null,
            h('div', { class: 'hist-title' }, fmt(it.date, { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + what),
            h('div', { class: 'hist-sub' }, 'Paid by ' + payers + ' · split ' + it.riders + ' ways')),
          h('div', { class: 'hist-amt' }, money(it.totalCents), h('small', null, it.myShareCents ? 'you ' + money(it.myShareCents) : 'not on it'))));
      } else {
        const del = it.canDelete
          ? h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Delete this payment', title: 'Delete', onclick: (e) => deleteSettlement(it.id, e.currentTarget) }, icon('trash'))
          : null;
        hl.appendChild(h('li', { class: 'hist is-settle' },
          h('span', { class: 'hist-dot' }, icon('check')),
          h('div', null,
            h('div', { class: 'hist-title' }, it.from + ' paid ' + it.to),
            h('div', { class: 'hist-sub' }, fmt(it.date, { day: 'numeric', month: 'short' }) + (it.note ? ' · ' + it.note : ''))),
          h('div', { class: 'hist-amt' }, money(it.amountCents), del)));
      }
    }
  }

  async function settle(data, btn) {
    try {
      S.balances = await withBusy(btn, () => post('settle_add', data));
      renderMoney();
      toast('Payment recorded');
      return true;
    } catch (e) {
      toast(e.message, true);
      return false;
    }
  }
  async function deleteSettlement(id, btn) {
    try {
      S.balances = await withBusy(btn, () => post('settle_delete', { id }));
      renderMoney();
      toast('Payment deleted');
    } catch (e) { toast(e.message, true); }
  }
  $('settleForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = $('settleMsg');
    msg.textContent = '';
    const data = { from: Number($('settleFrom').value), to: Number($('settleTo').value), amount: $('settleAmount').value, note: $('settleNote').value.trim() };
    if (data.from === data.to) { msg.className = 'form-msg is-error'; msg.textContent = 'Pick two different people.'; return; }
    if (data.from !== S.me.id && data.to !== S.me.id) { msg.className = 'form-msg is-error'; msg.textContent = 'You can only record payments you sent or received.'; return; }
    if (!(Number(data.amount) > 0)) { msg.className = 'form-msg is-error'; msg.textContent = 'Enter an amount above zero.'; return; }
    if (await settle(data, e.submitter)) {
      $('settleAmount').value = '';
      $('settleNote').value = '';
      $('recordBox').open = false;
    }
  });

  // ---------- Live refresh ----------
  setInterval(() => {
    if (document.hidden || !S.me || S.dragging || S.sheet || S.confirm || S.editingPrice || S.payingId) return;
    if (S.tab === 'rides') loadDay(); else loadBalances();
  }, 20000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && S.me && S.tab === 'rides') { loadDay(); loadCalendar(); }
  });

  // ---------- Boot ----------
  (async function boot() {
    try {
      const me = await get('me');
      applyMe(me);
      if (S.me) showApp(); else showAuth();
    } catch (e) {
      document.body.classList.remove('is-loading');
      document.body.textContent = 'Could not reach the server: ' + e.message;
    }
  })();
})();
