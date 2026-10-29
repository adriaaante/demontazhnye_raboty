/* CRM «Под Ноль» — одностраничное приложение без сборки.
   Экраны по адресу после #: #/ (заявки), #/lead/ID, #/calls (только разработчик), #/team, #/stats, #/me.
   Все данные — через api.php; при ответе 401 показываем вход по PIN,
   при must_change — обязательную смену временного PIN. */
(function () {
  "use strict";

  var app = document.getElementById("app");
  var S = { me: null, dict: null, users: [], tab: "open", q: "", unread: 0, timer: null };
  var LOST = "lost";

  // ---------- утилиты ----------
  // бейдж «Сделано в FutureFlow» (futureflow-site/futureflow-badge): тёмная пилюля — градиент читается на светлом фоне CRM
  var FFBADGE = '<a class="ffmark" href="https://futureflow.ru" target="_blank" rel="noopener" aria-label="Сделано в FutureFlow">' +
    '<svg width="20" height="20" viewBox="0 0 32 32" fill="none" stroke="url(#ffGrad)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<defs><linearGradient id="ffGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c8dcf4"/><stop offset=".5" stop-color="#fff"/><stop offset="1" stop-color="#f5a623"/></linearGradient></defs>' +
    '<rect x="8" y="8" width="16" height="16" rx="2.5"/><path d="M12 8V4M16 8V4M20 8V4 M12 28V24M16 28V24M20 28V24 M8 12H4M8 16H4M8 20H4 M28 12H24M28 16H24M28 20H24"/></svg>' +
    '<span>Сделано в&nbsp;<b>FutureFlow</b></span></a>';
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function api(action, body, query) {
    var url = "api.php?a=" + action + (query || "");
    var opt = body ? { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "crm" }, body: JSON.stringify(body) } : {};
    opt.credentials = "same-origin";
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: "Сервер не ответил" }; }).then(function (d) {
        if (r.status === 401 && d.error === "auth") { S.me = null; showPin(); throw new Error("auth"); }
        if (d.error === "must_change") { S.me && (S.me.must_change = true); showChangePin(true); throw new Error("must_change"); }
        if (!d.ok) throw new Error(d.error || "Ошибка");
        return d;
      });
    });
  }
  function toast(msg) {
    var t = document.getElementById("toast");
    t.textContent = msg; t.classList.add("on");
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove("on"); }, 2600);
  }
  function fail(e) { if (e && e.message !== "auth" && e.message !== "must_change") toast(e.message); }
  function fmtPhone(p) {
    if (!p) return "";
    var d = String(p);
    return d.length === 11 ? "+7 (" + d.substr(1, 3) + ") " + d.substr(4, 3) + "-" + d.substr(7, 2) + "-" + d.substr(9, 2) : d;
  }
  function ago(ts) {
    var s = Math.floor(Date.now() / 1000) - ts;
    if (s < 60) return "только что";
    if (s < 3600) return Math.floor(s / 60) + " мин назад";
    if (s < 86400) return Math.floor(s / 3600) + " ч назад";
    var d = new Date(ts * 1000), now = new Date();
    var y = new Date(now); y.setDate(now.getDate() - 1);
    var hm = d.toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
    if (d.toDateString() === y.toDateString()) return "вчера " + hm;
    return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" }) + " " + hm;
  }
  function when(ts) { return new Date(ts * 1000).toLocaleString("ru-RU", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }); }
  function mins(m) {
    if (m == null) return "—";
    if (m < 60) return Math.round(m) + " мин";
    if (m < 1440) return (m / 60).toFixed(1).replace(".", ",") + " ч";
    return (m / 1440).toFixed(1).replace(".", ",") + " дн";
  }
  function money(n) { return n ? Number(n).toLocaleString("ru-RU") + " ₽" : "—"; }
  function stChip(s) { return '<span class="st st-' + s + '">' + esc(S.dict.statuses[s] || s) + "</span>"; }
  var SRC = { site: "Сайт", avito: "Авито", manual: "Звонок/вручную" };
  function userName(id) {
    for (var i = 0; i < S.users.length; i++) if (+S.users[i].id === +id) return S.users[i].name;
    return id ? "сотрудник №" + id : "";
  }
  var LOGO = '<svg viewBox="0 0 40 40" aria-hidden="true"><rect width="40" height="40" fill="#E8641B"/><ellipse cx="20" cy="20" rx="8.5" ry="11" fill="none" stroke="#fff" stroke-width="3.4"/><path d="M9 33 31 7" stroke="#fff" stroke-width="3.4"/></svg>';

  // ---------- вход по PIN ----------
  function pinPad(title, hint, onDone) {
    var val = "";
    app.innerHTML =
      '<div class="pin"><div class="box">' +
      '<div class="brand">' + LOGO + "<span>Под Ноль <small>CRM</small></span></div>" +
      "<div>" + esc(title) + "</div>" +
      '<div class="cells" id="cells">' + "<i></i><i></i><i></i><i></i><i></i><i></i>" + "</div>" +
      '<div class="pinmsg" id="pinmsg">' + esc(hint || "") + "</div>" +
      '<div class="pad" id="pad">' +
      [1, 2, 3, 4, 5, 6, 7, 8, 9].map(function (n) { return '<button type="button" data-k="' + n + '">' + n + "</button>"; }).join("") +
      '<button type="button" class="ghost" data-k="c">Сброс</button><button type="button" data-k="0">0</button><button type="button" class="ghost" data-k="b" aria-label="Стереть">⌫</button>' +
      "</div>" + FFBADGE + "</div></div>";
    var cells = document.getElementById("cells"), msg = document.getElementById("pinmsg"), busy = false;
    function draw() { [].forEach.call(cells.children, function (c, i) { c.classList.toggle("on", i < val.length); }); }
    function press(k) {
      if (busy) return;
      if (k === "b") val = val.slice(0, -1);
      else if (k === "c") val = "";
      else if (val.length < 6) val += k;
      draw();
      if (val.length === 6) {
        busy = true;
        // вход автоматически после шестой цифры
        onDone(val, function (err) {
          busy = false; val = ""; draw();
          if (err) { msg.textContent = err; cells.classList.remove("err"); void cells.offsetWidth; cells.classList.add("err"); }
        });
      }
    }
    document.getElementById("pad").onclick = function (e) { var b = e.target.closest("button"); if (b) press(b.getAttribute("data-k")); };
    document.onkeydown = function (e) {
      if (/^\d$/.test(e.key)) press(e.key);
      else if (e.key === "Backspace") press("b");
      else if (e.key === "Escape") press("c");
    };
  }
  function showPin() {
    stopPoll();
    pinPad("Введите PIN", "", function (pin, done) {
      api("login", { pin: pin }).then(function (d) {
        document.onkeydown = null;
        S.me = d.user;
        if (S.me.must_change) showChangePin(true); else boot();
      }).catch(function (e) { done(e.message); });
    });
  }
  function showChangePin(forced) {
    stopPoll();
    var first = null;
    function ask() {
      pinPad(forced ? "Задайте свой PIN — временный нужно сменить" : "Новый PIN", "6 цифр, не одинаковые и не подряд", function (pin, done) {
        first = pin; done();
        pinPad("Повторите новый PIN", "", function (pin2, done2) {
          if (pin2 !== first) { done2("PIN не совпали, начните заново"); setTimeout(ask, 1200); return; }
          api("my_pin", { pin: pin2 }).then(function () {
            document.onkeydown = null;
            if (S.me) S.me.must_change = false;
            toast("PIN сохранён");
            boot();
          }).catch(function (e) { done2(e.message); setTimeout(ask, 1600); });
        });
      });
    }
    ask();
  }

  // ---------- каркас ----------
  // личная настройка в браузере (порядок истории и т.п.); без хранилища — значение по умолчанию
  function getPref(k, def) { try { return localStorage.getItem("pn_" + k) || def; } catch (e) { return def; } }
  function setPref(k, v) { try { localStorage.setItem("pn_" + k, v); } catch (e) { /* приватный режим — просто не запомним */ } }
  function shell(active, html) {
    var head = S.me.role !== "manager";
    app.innerHTML =
      '<header class="top"><div class="in"><a class="brand" href="#/">' + LOGO + "<span>Под Ноль <small>CRM</small></span></a>" +
      '<nav class="nav">' +
      '<a href="#/" class="' + (active === "leads" ? "on" : "") + '">Заявки' + (S.unread ? '<span class="cnt">' + S.unread + "</span>" : "") + "</a>" +
      (S.me.role === "dev" ? '<a href="#/calls" class="' + (active === "calls" ? "on" : "") + '">Звонки</a>' : "") +
      (head ? '<a href="#/stats" class="' + (active === "stats" ? "on" : "") + '">Сводка</a>' : "") +
      (head ? '<a href="#/team" class="' + (active === "team" ? "on" : "") + '">Команда</a>' : "") +
      '<a href="#/me" class="me' + (active === "me" ? " on" : "") + '" aria-label="Профиль"><span class="mename">' + esc(S.me.name) + '</span><span class="meini" aria-hidden="true">' + esc(S.me.name.charAt(0).toUpperCase()) + "</span></a>" +
      "</nav></div></header><main class=\"wrap\">" + html + '<footer class="foot">' + FFBADGE + "</footer></main>";
  }
  function boot() {
    api("me").then(function (d) {
      S.me = d.user; S.dict = d.dict; S.users = d.users;
      if (S.me.must_change) return showChangePin(true);
      route();
      startPoll();
    }).catch(fail);
  }
  function route() {
    if (!S.me || !S.dict) return;
    var h = location.hash.replace(/^#\/?/, "");
    var m = h.match(/^lead\/(\d+)/);
    if (m) return viewLead(+m[1]);
    if (h === "calls") return viewCalls();
    if (h === "team") return viewTeam();
    if (h === "stats") return viewStats(30);
    if (h === "me") return viewMe();
    return viewLeads();
  }
  window.addEventListener("hashchange", route);
  function startPoll() {
    stopPoll();
    S.timer = setInterval(function () {
      if (document.hidden) return;
      var onList = !/^#\/(lead|calls|team|stats|me)/.test(location.hash);
      api("leads", null, "&tab=" + S.tab + "&q=" + encodeURIComponent(S.q)).then(function (d) {
        setUnread(+d.counts.unread || 0);
        if (onList) drawList(d);
      }).catch(function () {});
    }, 30000);
  }
  function stopPoll() { if (S.timer) clearInterval(S.timer); S.timer = null; }
  function setUnread(n) {
    S.unread = n;
    document.title = (n ? "(" + n + ") " : "") + "CRM · Под Ноль";
    var a = document.querySelector('.nav a[href="#/"]');
    if (a) a.innerHTML = "Заявки" + (n ? '<span class="cnt">' + n + "</span>" : "");
  }

  // ---------- список заявок ----------
  var TABS = [["new", "Новые"], ["open", "В работе"], ["mine", "Мои"], ["closed", "Закрытые"], ["all", "Все"]];
  function viewLeads() {
    shell("leads",
      '<div class="row" style="margin-bottom:12px"><input type="search" id="q" placeholder="Поиск: имя, телефон, услуга, № заявки" value="' + esc(S.q) + '" style="flex:1;min-width:200px"></div>' +
      '<div class="tabs" id="tabs"></div><div class="list" id="list"><div class="empty">Загружаю…</div></div>' +
      '<button class="btn fab" id="add" type="button">+ Заявка</button>');
    var t;
    document.getElementById("q").oninput = function (e) { clearTimeout(t); t = setTimeout(function () { S.q = e.target.value.trim(); loadList(); }, 300); };
    document.getElementById("add").onclick = newLeadModal;
    loadList();
  }
  function loadList() {
    api("leads", null, "&tab=" + S.tab + "&q=" + encodeURIComponent(S.q)).then(drawList).catch(fail);
  }
  function drawList(d) {
    var tabs = document.getElementById("tabs"), list = document.getElementById("list");
    if (!tabs || !list) return;
    var c = d.counts || {};
    var cnt = { new: c.new, open: c.open, mine: c.mine, closed: c.closed, all: c.all_ };
    tabs.innerHTML = TABS.map(function (t) {
      return '<button type="button" data-t="' + t[0] + '" class="' + (S.tab === t[0] ? "on" : "") + '">' + t[1] + '<span class="n">' + (cnt[t[0]] || 0) + "</span></button>";
    }).join("");
    tabs.onclick = function (e) { var b = e.target.closest("button"); if (b) { S.tab = b.getAttribute("data-t"); loadList(); } };
    setUnread(+c.unread || 0);
    if (!d.leads.length) { list.innerHTML = '<div class="empty">' + (S.q ? "Ничего не нашлось" : "Здесь пока пусто") + "</div>"; return; }
    var now = Date.now() / 1000;
    list.innerHTML = d.leads.map(function (l) {
      var who = l.client_name || fmtPhone(l.client_phone) || "Клиент без имени";
      var late = l.status === "new" && !l.first_touch_at && now - l.created_at > 1800;
      return '<a class="item' + (+l.unread ? " unread" : "") + '" href="#/lead/' + l.id + '"><span class="bar"></span>' +
        "<div><div class=\"who\">" + (+l.unread ? '<span class="dot" title="Есть новое"></span>' : "") + esc(who) + " " + stChip(l.status) + (+l.amount ? '<span class="amt">' + money(l.amount) + "</span>" : "") + "</div>" +
        '<div class="src">' + esc(SRC[l.source] || l.source) + (l.title ? " · " + esc(l.title) : "") + "</div>" +
        '<div class="what">' + esc(l.last_text || l.message || "") + "</div></div>" +
        '<div class="side"><span class="' + (late ? "late" : "") + '">' + ago(l.updated_at) + "</span>" +
        "<span>№" + l.id + "</span>" + (l.assignee_id ? "<span>" + esc(userName(l.assignee_id)) + "</span>" : '<span class="late">без ответственного</span>') + "</div></a>";
    }).join("");
  }
  function newLeadModal() {
    var m = document.createElement("div");
    m.className = "modal";
    m.innerHTML = '<form class="card" id="nf"><h2 style="margin:0">Новая заявка</h2>' +
      '<p class="muted small" style="margin:0">Для обращений по телефону, в WhatsApp или лично — чтобы они тоже были в истории.</p>' +
      '<div class="grid2"><label class="f">Имя<input type="text" id="n_name" autocomplete="off"></label>' +
      '<label class="f">Телефон<input type="tel" id="n_phone" inputmode="tel" autocomplete="off"></label></div>' +
      '<label class="f">Откуда<select id="n_ch"><option>Звонок</option><option>WhatsApp</option><option>Telegram</option><option>Рекомендация</option><option>Другое</option></select></label>' +
      '<label class="f">Что нужно<input type="text" id="n_title" placeholder="Например: демонтаж плитки, 12 м²"></label>' +
      '<label class="f">Подробности<textarea id="n_msg"></textarea></label>' +
      '<div class="row" style="justify-content:flex-end"><button type="button" class="btn ghost" id="n_cancel">Отмена</button><button class="btn">Создать</button></div></form>';
    document.body.appendChild(m);
    function close() { m.remove(); }
    m.onclick = function (e) { if (e.target === m) close(); };
    document.getElementById("n_cancel").onclick = close;
    document.getElementById("nf").onsubmit = function (e) {
      e.preventDefault();
      api("lead_create", {
        name: v("n_name"), phone: v("n_phone"), title: v("n_title"), message: v("n_msg"), channel: v("n_ch"),
      }).then(function (d) { close(); location.hash = "#/lead/" + d.id; }).catch(fail);
    };
    document.getElementById("n_name").focus();
  }
  function v(id) { return document.getElementById(id).value.trim(); }

  // ---------- карточка заявки ----------
  var EV = {
    created: ["+", "Заявка"], in: ["А", "Клиент написал в Авито"], out: ["↩", "Ответ в Авито"],
    status: ["→", "Статус"], comment: ["✎", "Комментарий"], assign: ["@", "Ответственный"], edit: ["✎", "Изменены данные"],
  };
  function viewLead(id) {
    shell("leads", '<div class="empty">Загружаю…</div>');
    api("lead", null, "&id=" + id).then(function (d) { drawLead(d); }).catch(function (e) { fail(e); });
  }
  function drawLead(d) {
    var l = d.lead, c = d.client;
    var created = d.events.length ? d.events[0].meta || {} : {};
    var title = c.name || fmtPhone(c.phone) || "Клиент без имени";
    var lastSt = null;
    d.events.forEach(function (e) { if (e.type === "status") lastSt = e; });
    var lastAsg = null;
    d.events.forEach(function (e) { if (e.type === "assign") lastAsg = e; });
    var info = '<dl class="kv">' +
      "<dt>Источник</dt><dd>" + esc(SRC[l.source] || l.source) + (created.channel ? " · " + esc(created.channel) : "") + "</dd>" +
      (l.title ? "<dt>" + (l.source === "avito" ? "Объявление" : "Что нужно") + "</dt><dd>" + esc(l.title) + (created.price ? " — " + esc(created.price) : "") + "</dd>" : "") +
      (l.page ? "<dt>Страница</dt><dd>" + esc(l.page) + "</dd>" : "") +
      (created.chat ? '<dt>Чат</dt><dd><a href="' + esc(created.chat) + '" target="_blank" rel="noopener">Открыть в Авито</a></dd>' : "") +
      (l.item_url ? '<dt>Ссылка</dt><dd><a href="' + esc(l.item_url) + '" target="_blank" rel="noopener">Объявление</a></dd>' : "") +
      "<dt>Создана</dt><dd>" + when(l.created_at) + (l.first_touch_at ? " · первая реакция через " + mins((l.first_touch_at - l.created_at) / 60) : ' · <b style="color:var(--bad)">реакции ещё не было</b>') + "</dd>" +
      (l.status === LOST && l.reason ? "<dt>Причина отказа</dt><dd>" + esc(l.reason) + "</dd>" : "") +
      (l.amount ? "<dt>Сумма</dt><dd>" + money(l.amount) + "</dd>" : "") +
      "</dl>";
    var evs = d.events.map(function (e) {
      return { t: e.created_at, html: evHtml(e) };
    }).concat((d.calls || []).map(function (k) {
      return { t: k.started_at, html: callEvHtml(k) };
    })).sort(function (a, b) { return a.t - b.t; }).map(function (x) { return x.html; });
    function evHtml(e) {
      var k = EV[e.type] || ["•", e.type], m = e.meta || {}, head = k[1];
      if (e.type === "status") head = "Статус: " + esc(S.dict.statuses[m.from] || m.from) + " → " + esc(S.dict.statuses[m.to] || m.to) + (m.reason ? " · " + esc(m.reason) : "") + (m.amount ? " · " + money(m.amount) : "");
      if (e.type === "assign") head = "Ответственный: " + (m.to ? esc(userName(m.to)) : "снят");
      if (e.type === "created") head = "Заявка " + (m.source === "avito" ? "из Авито" : m.source === "site" ? "с сайта" + (m.form ? " · " + esc(m.form) : "") : "заведена вручную");
      var who = e.user_name ? esc(e.user_name) : (e.type === "in" || e.type === "created" ? "клиент" : e.type === "out" ? "из Авито" : "система");
      var body = e.text ? (e.type === "in" || e.type === "out" || e.type === "created" ? '<div class="bubble">' + esc(e.text) + "</div>" : '<div class="txt">' + esc(e.text) + "</div>") : "";
      return '<div class="ev ' + e.type + '"><span class="ic">' + k[0] + "</span><div><div><b>" + head + "</b></div>" +
        '<div class="meta">' + who + " · " + when(e.created_at) + "</div>" + body + "</div></div>";
    }
    var newFirst = getPref("tl", "new") === "new";
    function tlHtml() { return (newFirst ? evs.slice().reverse() : evs).join(""); }
    function tlLabel() { return newFirst ? "Сначала новые" : "Сначала старые"; }
    var other = d.other.length ? "<h2>Другие обращения клиента</h2><div class=\"list\">" + d.other.map(function (o) {
      return '<a class="item" href="#/lead/' + o.id + '"><span class="bar"></span><div><div class="who">№' + o.id + " " + stChip(o.status) + '</div><div class="src">' + esc(SRC[o.source] || o.source) + (o.title ? " · " + esc(o.title) : "") + '</div></div><div class="side">' + ago(o.created_at) + "</div></a>";
    }).join("") + "</div>" : "";

    shell("leads",
      '<a href="#/" class="small">← Все заявки</a>' +
      '<div class="head" style="margin-top:8px"><div><h1 style="margin-bottom:4px">' + esc(title) + ' <span class="muted small">№' + l.id + "</span></h1>" +
      (c.phone ? '<a class="phone" href="tel:+' + esc(c.phone) + '">' + esc(fmtPhone(c.phone)) + "</a>" : '<span class="muted">телефона нет</span>') + "</div>" +
      '<button type="button" class="btn ghost" id="edit">Изменить данные</button></div>' +
      '<div class="card" style="margin-top:14px">' + info + "</div>" +
      '<div class="state">' +
      '<div class="srow"><div><div class="slabel">Статус</div><div class="sval">' + stChip(l.status) +
      (l.status === LOST && l.reason ? ' <span class="muted small">' + esc(l.reason) + "</span>" : "") +
      (l.amount ? ' <span class="muted small">' + money(l.amount) + "</span>" : "") + "</div>" +
      '<div class="swho">' + (lastSt ? "изменил(а) " + esc(lastSt.user_name || "система") + " · " + ago(lastSt.created_at) : "статус не меняли") + "</div></div>" +
      '<button type="button" class="btn" id="chst">Изменить статус</button></div>' +
      '<div class="srow"><div><div class="slabel">Ответственный</div><div class="sval">' + (l.assignee_id ? "<b>" + esc(userName(l.assignee_id)) + "</b>" : '<span class="muted">не назначен</span>') + "</div>" +
      (lastAsg ? '<div class="swho">назначил(а) ' + esc(lastAsg.user_name || "") + " · " + ago(lastAsg.created_at) + "</div>" : "") + "</div>" +
      '<button type="button" class="btn ghost" id="chasg">' + (l.assignee_id ? "Сменить" : "Назначить") + "</button></div>" +
      "</div>" +
      "<h2>Комментарий</h2>" +
      '<form id="cf" class="cform"><textarea id="ctext" placeholder="Что обсудили, о чём договорились, когда перезвонить"></textarea><button class="btn">Добавить комментарий</button></form>' +
      '<div class="h2row"><h2>История</h2><button type="button" class="sortbtn" id="tlsort" aria-label="Порядок истории">' + tlLabel() + ' <span aria-hidden="true">⇅</span></button></div>' +
      '<div class="card timeline" id="tl">' + tlHtml() + "</div>" + other);

    wirePlayers(document.getElementById("tl"));
    document.getElementById("tlsort").onclick = function () {
      newFirst = !newFirst; setPref("tl", newFirst ? "new" : "old");
      document.getElementById("tl").innerHTML = tlHtml();
      this.firstChild.nodeValue = tlLabel() + " ";
    };
    document.getElementById("chst").onclick = function () { statusModal(l); };
    document.getElementById("chasg").onclick = function () { assignModal(l); };
    document.getElementById("cf").onsubmit = function (e) {
      e.preventDefault();
      var t = v("ctext");
      if (!t) return;
      api("lead_comment", { id: l.id, text: t }).then(function () { toast("Комментарий добавлен"); viewLead(l.id); }).catch(fail);
    };
    document.getElementById("edit").onclick = function () { editModal(l, c); };
  }
  var ST_HINT = {
    new: "С клиентом ещё не связывались",
    contact: "Был разговор или переписка",
    visit: "Договорились о выезде на объект",
    estimate: "Клиент знает цену",
    deal: "Договорились о работе",
    done: "Работа выполнена и сдана",
    lost: "Клиент не будет заказывать",
  };
  function statusModal(l) {
    var keys = Object.keys(S.dict.statuses);
    modal("<h2>Изменить статус</h2>" +
      '<form id="sf" class="stack">' +
      '<fieldset class="roles stlist">' + keys.map(function (k) {
        var cur = k === l.status;
        return '<label class="rolecard' + (cur ? " cur" : "") + '"><input type="radio" name="st" value="' + k + '"' + (cur ? " disabled" : "") + ">" +
          "<span><b>" + esc(S.dict.statuses[k]) + (cur ? ' <em>сейчас</em>' : "") + "</b><small>" + esc(ST_HINT[k] || "") + "</small></span></label>";
      }).join("") + "</fieldset>" +
      '<div id="sx" class="stack" hidden>' +
      '<fieldset class="roles" id="sx_r" hidden><legend>Причина отказа — обязательно</legend>' +
      S.dict.reasons.map(function (r) { return '<label class="rolecard tight"><input type="radio" name="rs" value="' + esc(r) + '"><span><b>' + esc(r) + "</b></span></label>"; }).join("") + "</fieldset>" +
      '<label class="f" id="sx_a" hidden>Сумма, ₽<input type="number" id="sx_amount" inputmode="numeric" min="0" step="100" value="' + (l.amount || "") + '" placeholder="Если известна"></label>' +
      '<label class="f">Комментарий<textarea id="sx_comment" placeholder="Что сказал клиент, о чём договорились"></textarea></label></div>' +
      '<div class="actions"><button type="button" class="btn ghost" data-close>Отмена</button><button class="btn" id="sx_ok" disabled>Выберите статус</button></div></form>',
      function (m, close) {
        var form = m.querySelector("#sf"), ok = m.querySelector("#sx_ok");
        function sel() { var r = form.querySelector('input[name="st"]:checked'); return r ? r.value : ""; }
        function upd() {
          var s = sel(), reason = form.querySelector('input[name="rs"]:checked');
          m.querySelector("#sx").hidden = !s;
          m.querySelector("#sx_r").hidden = s !== LOST;
          m.querySelector("#sx_a").hidden = ["estimate", "deal", "done"].indexOf(s) < 0;
          ok.disabled = !s || (s === LOST && !reason);
          ok.textContent = !s ? "Выберите статус" : s === LOST && !reason ? "Укажите причину" : S.dict.statuses[l.status] + " → " + S.dict.statuses[s];
        }
        form.addEventListener("change", upd);
        form.onsubmit = function (e) {
          e.preventDefault();
          var s = sel(), r = form.querySelector('input[name="rs"]:checked');
          if (!s || (s === LOST && !r)) return;
          ok.disabled = true;
          api("lead_status", { id: l.id, from: l.status, status: s, reason: r ? r.value : "", amount: m.querySelector("#sx_amount").value, comment: v("sx_comment") })
            .then(function () { close(); toast("Статус: " + S.dict.statuses[s]); viewLead(l.id); })
            .catch(function (er) { ok.disabled = false; fail(er); });
        };
      });
  }
  function assignModal(l) {
    modal("<h2>Ответственный</h2>" +
      '<form id="af" class="stack"><fieldset class="roles">' +
      S.users.map(function (u) {
        var cur = +u.id === +l.assignee_id;
        return '<label class="rolecard tight' + (cur ? " cur" : "") + '"><input type="radio" name="as" value="' + u.id + '"' + (cur ? " checked" : "") + "><span><b>" + esc(u.name) + (cur ? " <em>сейчас</em>" : "") + "</b><small>" + esc(S.dict.roles[u.role]) + "</small></span></label>";
      }).join("") + "</fieldset>" +
      '<div class="actions">' + (l.assignee_id ? '<button type="button" class="btn ghost" id="a_none">Снять</button>' : "") +
      '<span style="flex:1"></span><button type="button" class="btn ghost" data-close>Отмена</button><button class="btn">Назначить</button></div></form>',
      function (m, close) {
        function save(uid) {
          if ((uid || null) === (l.assignee_id ? +l.assignee_id : null)) { close(); return; }
          api("lead_assign", { id: l.id, user_id: uid }).then(function () { close(); toast(uid ? "Ответственный: " + userName(uid) : "Ответственный снят"); viewLead(l.id); }).catch(fail);
        }
        m.querySelector("#af").onsubmit = function (e) {
          e.preventDefault();
          var r = m.querySelector('input[name="as"]:checked');
          if (r) save(+r.value);
        };
        var n = m.querySelector("#a_none");
        if (n) n.onclick = function () { save(null); };
      });
  }
  function editModal(l, c) {
    var m = document.createElement("div");
    m.className = "modal";
    m.innerHTML = '<form class="card" id="ef"><h2 style="margin:0">Данные клиента</h2>' +
      '<label class="f">Имя<input type="text" id="e_name" value="' + esc(c.name || "") + '"></label>' +
      '<label class="f">Телефон<input type="tel" id="e_phone" inputmode="tel" value="' + esc(c.phone ? fmtPhone(c.phone) : "") + '"></label>' +
      '<label class="f">Что нужно / объявление<input type="text" id="e_title" value="' + esc(l.title || "") + '"></label>' +
      '<div class="row" style="justify-content:flex-end"><button type="button" class="btn ghost" id="e_cancel">Отмена</button><button class="btn">Сохранить</button></div></form>';
    document.body.appendChild(m);
    function close() { m.remove(); }
    m.onclick = function (e) { if (e.target === m) close(); };
    document.getElementById("e_cancel").onclick = close;
    document.getElementById("ef").onsubmit = function (e) {
      e.preventDefault();
      api("lead_edit", { id: l.id, name: v("e_name"), phone: v("e_phone"), title: v("e_title") })
        .then(function () { close(); toast("Сохранено"); viewLead(l.id); }).catch(fail);
    };
  }

  // ---------- звонки (пока только разработчик) ----------
  function dur(sec) { sec = +sec || 0; return Math.floor(sec / 60) + ":" + ("0" + sec % 60).slice(-2); }
  // кнопка «Прослушать»: плеер появляется по нажатию — запись скачивается с Авито только когда её хотят послушать
  function playBtn(id) {
    return '<div class="cplay" data-call="' + id + '"><button type="button" class="btn ghost sm" data-play>▶ Прослушать</button></div>';
  }
  function callEvHtml(k) {
    var talk = +k.talk_sec > 0;
    return '<div class="ev call' + (talk ? "" : " missed") + '"><span class="ic">☎</span><div><div><b>' +
      (talk ? "Звонок через Авито · " + dur(k.talk_sec) : "Пропущенный звонок через Авито") + "</b></div>" +
      '<div class="meta">клиент · ' + when(k.started_at) + (k.item_title ? " · " + esc(k.item_title) : "") + "</div>" +
      (talk ? playBtn(k.id) : "") + "</div></div>";
  }
  var SPEEDS = [1, 1.25, 1.5, 2];
  function wirePlayers(root) {
    if (!root) return;
    root.addEventListener("click", function (e) {
      var box = e.target.closest(".cplay");
      if (!box) return;
      var id = box.getAttribute("data-call");
      if (e.target.closest("[data-play]")) {
        // одновременно играет одна запись
        [].forEach.call(document.querySelectorAll(".cplay audio"), function (a) { a.pause(); });
        var src = "api.php?a=call_record&id=" + id;
        box.innerHTML = '<audio controls preload="auto" src="' + src + '"></audio>' +
          '<div class="cctl"><span class="speeds">' + SPEEDS.map(function (s) { return '<button type="button" data-sp="' + s + '"' + (s === 1 ? ' class="on"' : "") + ">" + String(s).replace(".", ",") + "×</button>"; }).join("") + "</span>" +
          '<a href="' + src + '&dl=1" class="small">Скачать</a></div>';
        var au = box.querySelector("audio");
        au.addEventListener("error", function () { box.innerHTML = '<span class="muted small">Записи разговора нет — Авито её не отдал</span>'; });
        au.addEventListener("play", function () { [].forEach.call(document.querySelectorAll(".cplay audio"), function (a) { if (a !== au) a.pause(); }); });
        au.play().catch(function () {});
      }
      var sp = e.target.closest("[data-sp]");
      if (sp) {
        box.querySelector("audio").playbackRate = +sp.getAttribute("data-sp");
        [].forEach.call(box.querySelectorAll("[data-sp]"), function (b) { b.classList.toggle("on", b === sp); });
      }
    });
  }
  var CTABS = [["all", "Все"], ["record", "С разговором"], ["missed", "Пропущенные"], ["nolead", "Без заявки"]];
  var CS = { f: "all", q: "" };
  function viewCalls() {
    if (S.me.role !== "dev") { location.hash = "#/"; return; }
    shell("calls",
      '<div class="head" style="align-items:center;margin-bottom:12px"><h1 style="margin:0">Звонки</h1>' +
      '<button type="button" class="btn ghost" id="csync">Обновить из Авито</button></div>' +
      '<div class="row" style="margin-bottom:12px"><input type="search" id="cq" placeholder="Поиск: телефон, имя, объявление" value="' + esc(CS.q) + '" style="flex:1;min-width:200px"></div>' +
      '<div class="tabs" id="ctabs"></div><div id="csum" class="note" style="margin:0 0 10px"></div><div class="list" id="clist"><div class="empty">Загружаю…</div></div>' +
      '<p class="note">Раздел видит только разработчик. Звонки приходят из Авито при сверке раз в 30 минут — если у объявлений включён подменный номер.</p>');
    var t;
    document.getElementById("cq").oninput = function (e) { clearTimeout(t); t = setTimeout(function () { CS.q = e.target.value.trim(); loadCalls(); }, 300); };
    document.getElementById("csync").onclick = function () {
      var b = this; b.disabled = true; b.textContent = "Сверяю…";
      api("calls_sync", {}).then(function (r) { toast(r.calls_new ? "Новых звонков: " + r.calls_new : "Новых звонков нет"); loadCalls(); })
        .catch(fail).then(function () { b.disabled = false; b.textContent = "Обновить из Авито"; });
    };
    wirePlayers(document.getElementById("clist"));
    loadCalls();
  }
  function loadCalls() {
    api("calls", null, "&f=" + CS.f + "&q=" + encodeURIComponent(CS.q)).then(drawCalls).catch(fail);
  }
  function drawCalls(d) {
    var tabs = document.getElementById("ctabs"), list = document.getElementById("clist");
    if (!tabs || !list) return;
    var c = d.counts || {}, cnt = { all: c.all_, record: c.record, missed: c.missed, nolead: c.nolead };
    tabs.innerHTML = CTABS.map(function (x) {
      return '<button type="button" data-f="' + x[0] + '" class="' + (CS.f === x[0] ? "on" : "") + '">' + x[1] + '<span class="n">' + (+cnt[x[0]] || 0) + "</span></button>";
    }).join("");
    tabs.onclick = function (e) { var b = e.target.closest("button"); if (b) { CS.f = b.getAttribute("data-f"); loadCalls(); } };
    document.getElementById("csum").textContent = (+c.talk ? "Всего разговоров: " + mins(c.talk / 60) + " · " : "") +
      (d.synced_at ? "сверка с Авито " + ago(d.synced_at) : "сверки с Авито ещё не было");
    if (!d.calls.length) {
      list.innerHTML = '<div class="empty">' + (CS.q || CS.f !== "all" ? "Ничего не нашлось" :
        "Звонков пока нет.<br><span class=\"small\">Авито записывает разговоры, только когда у объявлений включён подменный номер, — тогда звонки появятся здесь сами.</span>") + "</div>";
      return;
    }
    list.innerHTML = d.calls.map(function (k) {
      var talk = +k.talk_sec > 0, who = k.client_name || fmtPhone(k.client_phone) || "Номер скрыт";
      return '<div class="item call' + (talk ? "" : " missed") + '"><span class="bar"></span><div>' +
        '<div class="who">' + esc(who) + (k.client_name && k.client_phone ? ' <span class="muted small">' + esc(fmtPhone(k.client_phone)) + "</span>" : "") +
        '<span class="amt">' + (talk ? dur(k.talk_sec) : "пропущен") + "</span></div>" +
        '<div class="src">Авито' + (k.item_title ? " · " + esc(k.item_title) : "") + "</div>" +
        (talk ? playBtn(k.id) : "") + "</div>" +
        '<div class="side"><span>' + ago(k.started_at) + "</span>" +
        (k.lead_id ? '<a href="#/lead/' + k.lead_id + '">заявка №' + k.lead_id + "</a>" :
          k.client_phone ? '<button type="button" class="link" data-mk="' + esc(k.client_phone) + '" data-it="' + esc(k.item_title || "") + '">Создать заявку</button>' : "") +
        (k.client_phone ? '<a href="tel:+' + esc(k.client_phone) + '">Перезвонить</a>' : "") + "</div></div>";
    }).join("");
    list.onclick = function (e) {
      var b = e.target.closest("[data-mk]");
      if (!b) return;
      api("lead_create", { phone: b.getAttribute("data-mk"), title: b.getAttribute("data-it"), channel: "Звонок через Авито", message: "" })
        .then(function (r) { location.hash = "#/lead/" + r.id; }).catch(fail);
    };
  }

  // ---------- команда ----------
  // общий каркас окна: закрытие по фону, Esc и кнопке «Отмена»
  function modal(html, onReady) {
    var m = document.createElement("div");
    m.className = "modal";
    m.innerHTML = '<div class="card dialog" role="dialog" aria-modal="true">' + html + "</div>";
    document.body.appendChild(m);
    function close() { document.removeEventListener("keydown", onKey); m.remove(); }
    function onKey(e) { if (e.key === "Escape") close(); }
    document.addEventListener("keydown", onKey);
    m.addEventListener("click", function (e) { if (e.target === m || e.target.closest("[data-close]")) close(); });
    var f = m.querySelector("input, button.btn");
    if (f) f.focus();
    if (onReady) onReady(m, close);
    return close;
  }
  var ROLE_HINT = {
    manager: "Работает с заявками: статусы, комментарии, звонки",
    head: "Заявки, сводка и команда — кроме разработчиков",
    dev: "Полный доступ, в том числе к разработчикам",
  };
  function viewTeam() {
    if (S.me.role === "manager") { location.hash = "#/"; return; }
    shell("team", '<div class="empty">Загружаю…</div>');
    api("users").then(function (d) {
      var roles = S.dict.roles;
      shell("team",
        '<div class="row" style="justify-content:space-between;margin-bottom:14px"><h1 style="margin:0">Команда</h1>' +
        '<button type="button" class="btn" id="addUser">Добавить сотрудника</button></div>' +
        '<div class="people" id="people">' +
        d.users.map(function (u) {
          var ini = u.name.split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join("").toUpperCase();
          return '<div class="person"><span class="ava ava-' + u.role + '" aria-hidden="true">' + esc(ini) + '</span><div class="pinfo"><div class="nm">' + esc(u.name) + (+u.id === +S.me.id ? ' <span class="you">вы</span>' : "") + "</div>" +
            '<div class="meta"><span class="role role-' + u.role + '">' + esc(roles[u.role]) + '</span><span class="sep">·</span>' + (u.last_login ? "входил " + ago(u.last_login) : "ещё не входил") + "</div></div>" +
            (u.can_manage ? '<div class="pact"><button type="button" class="link" data-pin="' + u.id + '" data-name="' + esc(u.name) + '">Сменить PIN</button>' +
              '<button type="button" class="link danger" data-del="' + u.id + '" data-name="' + esc(u.name) + '">Удалить</button></div>' : "") + "</div>";
        }).join("") + "</div>");
      document.getElementById("addUser").onclick = addUserModal;
      document.getElementById("people").onclick = function (e) {
        var b = e.target.closest("button");
        if (!b) return;
        var id = +(b.getAttribute("data-del") || b.getAttribute("data-pin")), name = b.getAttribute("data-name");
        if (b.hasAttribute("data-del")) deleteUserModal(id, name); else resetPinModal(id, name);
      };
    }).catch(fail);
  }
  function addUserModal() {
    var roles = S.dict.roles;
    var allowed = ["manager", "head", "dev"].filter(function (r) { return S.me.role === "dev" || r !== "dev"; });
    modal('<h2>Новый сотрудник</h2>' +
      '<form id="uf" class="stack">' +
      '<label class="f">Имя<input type="text" id="u_name" autocomplete="off" required placeholder="Например: Ольга"></label>' +
      '<fieldset class="roles"><legend>Роль</legend>' +
      allowed.map(function (r) {
        return '<label class="rolecard"><input type="radio" name="u_role" value="' + r + '"' + (r === "manager" ? " checked" : "") + '><span><b>' + esc(roles[r]) + "</b><small>" + esc(ROLE_HINT[r]) + "</small></span></label>";
      }).join("") + "</fieldset>" +
      '<label class="f">Начальный PIN<input type="text" id="u_pin" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="6 цифр или оставьте пустым"></label>' +
      '<p class="hint">Пустое поле — PIN придумаем сами. При первом входе сотрудник заменит его на свой.</p>' +
      '<div class="actions"><button type="button" class="btn ghost" data-close>Отмена</button><button class="btn">Добавить</button></div></form>',
      function (m, close) {
        m.querySelector("#uf").onsubmit = function (e) {
          e.preventDefault();
          var name = v("u_name"), role = m.querySelector('input[name="u_role"]:checked').value;
          api("user_create", { name: name, role: role, pin: v("u_pin") })
            .then(function (r) { close(); pinModal("PIN для входа: " + name, r.pin); }).catch(fail);
        };
      });
  }
  function resetPinModal(id, name) {
    modal("<h2>Новый PIN — " + esc(name) + "</h2>" +
      '<form id="pf" class="stack"><label class="f">Временный PIN<input type="text" id="p_pin" inputmode="numeric" maxlength="6" autocomplete="off" placeholder="6 цифр или оставьте пустым"></label>' +
      '<p class="hint">Старый PIN перестанет работать сразу, вход на всех устройствах сбросится. При входе сотрудник задаст свой.</p>' +
      '<div class="actions"><button type="button" class="btn ghost" data-close>Отмена</button><button class="btn">Сменить</button></div></form>',
      function (m, close) {
        m.querySelector("#pf").onsubmit = function (e) {
          e.preventDefault();
          api("user_pin_reset", { id: id, pin: v("p_pin") }).then(function (r) { close(); pinModal("Новый PIN — " + name, r.pin); }).catch(fail);
        };
      });
  }
  function deleteUserModal(id, name) {
    modal("<h2>Удалить сотрудника?</h2><p style=\"margin:0\"><b>" + esc(name) + "</b></p>" +
      '<p class="hint" style="margin:0">Доступ пропадёт сразу. Его открытые заявки останутся без ответственного, история действий сохранится.</p>' +
      '<div class="actions"><button type="button" class="btn ghost" data-close>Отмена</button><button type="button" class="btn danger-solid" id="d_ok">Удалить</button></div>',
      function (m, close) {
        m.querySelector("#d_ok").onclick = function () {
          api("user_delete", { id: id }).then(function () { close(); toast("Сотрудник удалён"); refreshTeam(); }).catch(fail);
        };
      });
  }
  function refreshTeam() { api("me").then(function (d) { S.users = d.users; viewTeam(); }).catch(fail); }
  function pinModal(title, pin) {
    modal("<h2>" + esc(title) + "</h2>" +
      '<div class="bigpin">' + esc(pin.slice(0, 3) + " " + pin.slice(3)) + "</div>" +
      '<p class="hint" style="margin:0">Передайте PIN лично — больше он нигде не показывается.</p>' +
      '<div class="actions"><button type="button" class="btn" data-close>Готово</button></div>');
    refreshTeam();
  }

  // ---------- сводка ----------
  function bars(obj, labels) {
    var keys = Object.keys(obj || {});
    if (!keys.length) return '<p class="muted">Нет данных</p>';
    var max = Math.max.apply(null, keys.map(function (k) { return obj[k]; }));
    return keys.map(function (k) {
      return '<div class="hb"><span>' + esc(labels ? labels[k] || k : k) + '</span><div class="t"><i style="width:' + (obj[k] / max * 100) + '%"></i></div><b>' + obj[k] + "</b></div>";
    }).join("");
  }
  function pct(n, of) { return of ? Math.round(n / of * 100) + " %" : "—"; }
  function tile(label, value, sub, tone) {
    return '<div class="tile' + (tone ? " " + tone : "") + '"><span class="tl">' + label + "</span><b>" + value + "</b>" + (sub ? '<span class="ts">' + sub + "</span>" : "") + "</div>";
  }
  // ячейка с подписью: на телефоне таблица становится карточками, подпись видна рядом с числом
  function td(label, value) { return '<td class="n" data-l="' + label + '">' + value + "</td>"; }
  function cell(label, value, sub, tone) {
    return '<div class="sc' + (tone ? " " + tone : "") + '"><span class="tl">' + label + "</span><b>" + value + '</b><span class="ts">' + sub + "</span></div>";
  }
  function viewStats(days) {
    if (S.me.role === "manager") { location.hash = "#/"; return; }
    shell("stats", '<div class="empty">Считаю…</div>');
    api("stats", null, "&days=" + days).then(function (d) {
      var roles = S.dict.roles, st = S.dict.statuses, T = d.total;
      var share = function (n) { return T ? (n / T * 100) + "%" : "0"; };
      var funnel = Object.keys(d.funnel).map(function (k) {
        var n = d.funnel[k];
        return '<div class="fs"><span class="fn">' + esc(st[k]) + '</span><div class="ft"><i style="width:' + share(n) + '"></i></div><b>' + n + '</b><span class="fp">' + pct(n, T) + "</span></div>";
      }).join("");
      var titles = Object.keys(d.by_title), tt = { total: 0, won: 0 };
      titles.forEach(function (k) { tt.total += d.by_title[k].total; tt.won += d.by_title[k].won; });
      var pt = { leads: 0, status: 0, comment: 0, won: 0, lost: 0 };
      d.people.forEach(function (p) { Object.keys(pt).forEach(function (k) { pt[k] += p[k]; }); });

      shell("stats",
        '<div class="head" style="align-items:center;margin-bottom:12px"><h1 style="margin:0">Сводка</h1><div class="tabs" id="per" style="margin:0">' +
        [7, 30, 90, 0].map(function (n) { return '<button type="button" data-d="' + n + '" class="' + (n === days ? "on" : "") + '">' + (n ? n + " дн" : "Всё время") + "</button>"; }).join("") + "</div></div>" +
        '<div class="tiles">' +
        tile("Заявок", T, days ? "за " + days + " дн" : "за всё время") +
        tile("В работе", d.open, pct(d.open, T) + " от всех", "blue") +
        tile("Договор", d.won, pct(d.won, T) + " конверсия", "ok") +
        tile("Отказ", d.lost, pct(d.lost, T) + " от всех", "bad") +
        "</div>" +
        '<div class="card result"><div class="rbar">' +
        (d.open ? '<i class="r-open" style="width:' + share(d.open) + '"></i>' : "") +
        (d.won ? '<i class="r-won" style="width:' + share(d.won) + '"></i>' : "") +
        (d.lost ? '<i class="r-lost" style="width:' + share(d.lost) + '"></i>' : "") + "</div>" +
        '<div class="rleg"><span><i class="r-open"></i>В работе ' + d.open + '</span><span><i class="r-won"></i>Договор и выполнено ' + d.won + '</span><span><i class="r-lost"></i>Отказ ' + d.lost + "</span></div></div>" +
        '<div class="strip">' +
        cell("Первая реакция", mins(d.touch_median), "медиана") +
        cell("Без реакции", d.late, "новые дольше 30 мин", d.late ? "bad" : "") +
        cell("В сметах", d.pipeline ? money(d.pipeline) : "—", "ждут решения") +
        cell("Договоры", d.revenue ? money(d.revenue) : "—", "на сумму", d.revenue ? "ok" : "") +
        "</div>" +
        "<h2>Воронка</h2><div class=\"card\">" + (T ? funnel : '<p class="muted">Нет данных</p>') +
        '<p class="note">Сколько заявок дошли хотя бы до этой стадии — включая тех, кто потом отказался.</p></div>' +
        '<div class="grid2"><div><h2>Откуда</h2><div class="card">' + bars(d.by_source, SRC) + "</div></div>" +
        "<div><h2>Причины отказов</h2><div class=\"card\">" + bars(d.reasons) + "</div></div></div>" +
        "<h2>Услуги и объявления</h2><div class=\"tbl\"><table><tr class=\"h\"><th>Что</th><th class=\"n\">Заявок</th><th class=\"n\">Договор</th><th class=\"n\">Конверсия</th></tr>" +
        titles.map(function (k) { var r = d.by_title[k]; return "<tr><td>" + esc(k) + "</td>" + td("Заявок", r.total) + td("Договор", r.won) + td("Конверсия", pct(r.won, r.total)) + "</tr>"; }).join("") +
        '<tr class="sum"><td>Итого</td>' + td("Заявок", tt.total) + td("Договор", tt.won) + td("Конверсия", pct(tt.won, tt.total)) + "</tr></table></div>" +
        "<h2>Работа сотрудников</h2><div class=\"tbl\"><table><tr class=\"h\"><th>Сотрудник</th><th class=\"n\">Заявок</th><th class=\"n\">Реакция</th><th class=\"n\">Смен статуса</th><th class=\"n\">Комментариев</th><th class=\"n\">Договор</th><th class=\"n\">Отказ</th></tr>" +
        d.people.map(function (p) {
          return "<tr><td>" + esc(p.name) + ' <span class="muted small">' + esc(roles[p.role]) + "</span></td>" + td("Заявок", p.leads) + td("Реакция", mins(p.touch_median)) + td("Смен статуса", p.status) + td("Комментариев", p.comment) + td("Договор", p.won) + td("Отказ", p.lost) + "</tr>";
        }).join("") +
        '<tr class="sum"><td>Итого</td>' + td("Заявок", pt.leads) + td("Реакция", mins(d.touch_median)) + td("Смен статуса", pt.status) + td("Комментариев", pt.comment) + td("Договор", pt.won) + td("Отказ", pt.lost) + "</tr></table></div>" +
        '<p class="note">«Заявок» — где сотрудник ответственный. «Реакция» — медиана времени до первого действия по заявке.</p>');
      document.getElementById("per").onclick = function (e) { var b = e.target.closest("button"); if (b) viewStats(+b.getAttribute("data-d")); };
    }).catch(fail);
  }

  // ---------- профиль ----------
  function viewMe() {
    shell("me",
      "<h1>" + esc(S.me.name) + '</h1><p class="muted">' + esc(S.dict.roles[S.me.role]) + "</p>" +
      '<div class="row"><button type="button" class="btn ghost" id="chpin">Сменить PIN</button><button type="button" class="btn danger" id="out">Выйти</button></div>');
    document.getElementById("chpin").onclick = function () { showChangePin(false); };
    document.getElementById("out").onclick = function () {
      api("logout", {}).then(function () { S.me = null; showPin(); }).catch(fail);
    };
  }

  boot();
})();
