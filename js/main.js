(function () {
  "use strict";

  var header = document.getElementById("header");
  var burger = document.getElementById("burger");
  var nav = document.getElementById("nav");

  var fab = document.getElementById("fab");
  var fabToggle = document.getElementById("fab-toggle");

  function lockScroll(on) {
    document.body.style.overflow = on ? "hidden" : "";
    document.body.classList.toggle("is-locked", on);
  }

  function onScroll() {
    header.classList.toggle("is-scrolled", window.scrollY > 24);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  function closeMenu() {
    if (!burger || !header.classList.contains("is-menu-open")) return;
    header.classList.remove("is-menu-open");
    burger.classList.remove("is-open");
    burger.setAttribute("aria-expanded", "false");
    lockScroll(false);
  }

  if (burger) {
    burger.addEventListener("click", function () {
      var open = header.classList.toggle("is-menu-open");
      burger.classList.toggle("is-open", open);
      burger.setAttribute("aria-expanded", open ? "true" : "false");
      lockScroll(open);
    });
    nav.addEventListener("click", function (e) {
      if (e.target.closest("a")) closeMenu();
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && header.classList.contains("is-menu-open")) {
        burger.click();
      }
    });
  }

  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
  );
  document.querySelectorAll(".reveal").forEach(function (el) {
    observer.observe(el);
  });

  var lightbox = document.getElementById("lightbox");
  if (lightbox) {
    var lightboxImg = lightbox.querySelector("img");
    document.querySelectorAll(".project-gallery figure img").forEach(function (img) {
      img.parentElement.addEventListener("click", function () {
        lightboxImg.src = img.src;
        lightboxImg.alt = img.alt;
        lightbox.classList.add("is-open");
        document.body.style.overflow = "hidden";
      });
    });
    lightbox.addEventListener("click", function () {
      lightbox.classList.remove("is-open");
      lightboxImg.removeAttribute("src");
      document.body.style.overflow = "";
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && lightbox.classList.contains("is-open")) {
        lightbox.click();
      }
    });
  }

  // Отправка заявки: уходит на api/lead.php, оттуда — в Telegram.
  // Если обработчик недоступен (нет PHP, сеть отвалилась) — открываем
  // WhatsApp с готовым текстом, чтобы заявка не потерялась.
  function wireLeadForm(form) {
    if (!form) return;
    var submit = form.querySelector("button[type=submit]");
    var submitLabel = submit ? submit.textContent : "";

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (!form.reportValidity()) return;

      var msgField = form.elements.message;
      var message = msgField ? msgField.value.trim() : "";
      var srcField = form.elements.source;
      var source = srcField ? srcField.value.trim() : "";
      var text =
        "Заявка с сайта: демонтажные работы\n" +
        "Имя: " + form.elements.name.value.trim() + "\n" +
        "Телефон: " + form.elements.phone.value.trim() +
        (message ? "\nО проекте: " + message : "") +
        (source ? "\nОткуда: " + source : "");

      function done() {
        form.classList.add("is-done");
        if (submit) { submit.disabled = true; submit.textContent = submitLabel; }
      }

      function fallbackToWhatsApp() {
        window.open("https://wa.me/79877788894?text=" + encodeURIComponent(text), "_blank", "noopener");
        done();
      }

      var endpoint = form.getAttribute("data-endpoint");
      if (!endpoint || !window.fetch || !window.FormData) { fallbackToWhatsApp(); return; }

      var data = new FormData(form);
      data.append("page", location.pathname);
      if (submit) { submit.disabled = true; submit.textContent = "Отправляем…"; }

      fetch(endpoint, { method: "POST", body: data })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (json) {
          if (json && json.ok) done();
          else fallbackToWhatsApp();
        })
        .catch(fallbackToWhatsApp);
    });
  }

  wireLeadForm(document.getElementById("lead-form"));
  wireLeadForm(document.getElementById("callback-form"));

  // --- Плавающая кнопка связи (смартфоны) ---
  if (fab && fabToggle) {
    fabToggle.addEventListener("click", function () {
      var open = fab.classList.toggle("is-open");
      fabToggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    document.addEventListener("click", function (e) {
      if (fab.classList.contains("is-open") && !fab.contains(e.target)) closeFab();
    });

    fab.addEventListener("click", function (e) {
      if (e.target.closest(".fab__item")) closeFab();
    });
  }

  function closeFab() {
    if (!fab) return;
    fab.classList.remove("is-open");
    fabToggle.setAttribute("aria-expanded", "false");
  }

  // --- Модальное окно с формой ---
  var modal = document.getElementById("callback-modal");
  if (modal) {
    var lastFocused = null;
    var modalTitle = document.getElementById("callback-title");
    var modalSource = modal.querySelector("input[name=source]");
    var defaultTitle = modalTitle ? modalTitle.textContent : "";
    // на внутренних страницах title вида «Проект «Графит» — 47,6 м² | Void & Form»:
    // берём только имя страницы, на главной источник и так очевиден
    var pageName = document.title.indexOf("|") > -1
      ? document.title.split("|")[0].split("—")[0].trim()
      : "";

    // label — надпись на кнопке, по которой открыли окно: она же
    // становится заголовком и уходит в заявку как источник обращения
    function openModal(label) {
      lastFocused = document.activeElement;
      closeFab();
      if (modalTitle) modalTitle.textContent = label || defaultTitle;
      if (modalSource) {
        modalSource.value = (label || defaultTitle) + (pageName ? " · " + pageName : "");
      }
      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");
      lockScroll(true);
      // на телефоне не ставим фокус в поле: иначе сразу вылезает клавиатура
      // и закрывает собой окно. Фокус уходит на само окно — им же
      // ограничивается таб-обход, а клавиатура появится по тапу в поле.
      var box = modal.querySelector(".modal__box");
      var finePointer = window.matchMedia("(hover: hover) and (pointer: fine)").matches;
      var first = finePointer && modal.querySelector("input[type=text], input[type=tel]");
      if (first) first.focus();
      else if (box) box.focus();
    }

    function closeModal() {
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
      lockScroll(false);
      if (lastFocused) lastFocused.focus();
    }

    document.addEventListener("click", function (e) {
      var trigger = e.target.closest("[data-modal-open]");
      if (trigger) {
        // ссылка на #contacts остаётся в разметке как запасной путь без JS
        e.preventDefault();
        closeMenu();
        openModal(trigger.getAttribute("data-modal-open") || trigger.textContent.trim());
      } else if (e.target.closest("[data-modal-close]")) closeModal();
    });

    document.addEventListener("keydown", function (e) {
      if (!modal.classList.contains("is-open")) return;
      if (e.key === "Escape") { closeModal(); return; }
      if (e.key !== "Tab") return;
      // не выпускаем фокус за пределы окна, пока оно открыто
      var items = modal.querySelectorAll("a[href], button, input:not([type=hidden]), textarea");
      if (!items.length) return;
      var first = items[0], last = items[items.length - 1];
      // фокус на самом окне (так открывается на телефоне) — заводим его внутрь
      if ([].indexOf.call(items, document.activeElement) === -1) {
        e.preventDefault();
        (e.shiftKey ? last : first).focus();
      }
      else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }
})();
