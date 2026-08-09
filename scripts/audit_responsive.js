/**
 * Адаптивный аудит: 5 страниц × 23 разрешения.
 * Ищет горизонтальную прокрутку, элементы за краем экрана, мелкий текст,
 * малые зоны нажатия, битые изображения, обрезанный текст и перекрытие шапкой.
 *
 * Запуск (из корня, локальный сервер должен быть поднят на :8765):
 *   python3 -m http.server 8765 &
 *   NODE_PATH=/opt/node22/lib/node_modules node scripts/audit_responsive.js
 */
const { chromium } = require('playwright');

const PAGES = [
  ['/', 'Главная'],
  ['/privacy.html', 'Политика'],
  ['/consent.html', 'Согласие'],
  ['/404.html', '404'],
];

const DEVICES = [
  // портрет — телефоны
  [320, 568, 'iPhone SE'],
  [360, 640, 'Android small'],
  [360, 800, 'Galaxy S'],
  [375, 667, 'iPhone 8'],
  [390, 844, 'iPhone 14'],
  [412, 915, 'Pixel 7'],
  [430, 932, 'iPhone 14 Pro Max'],
  // планшеты
  [600, 960, 'Планшет 600'],
  [768, 1024, 'iPad mini'],
  [810, 1080, 'iPad 10'],
  [834, 1194, 'iPad Pro 11'],
  [1024, 1366, 'iPad Pro 12.9'],
  // ноутбуки / десктоп
  [1280, 720, 'Ноутбук 720p'],
  [1366, 768, 'Ноутбук 768p'],
  [1440, 900, 'MacBook Air'],
  [1512, 982, 'MacBook 14'],
  [1600, 900, 'Десктоп 1600'],
  [1920, 1080, 'Full HD'],
  [2560, 1440, '2K'],
  [3440, 1440, 'Ultrawide'],
  // ландшафт
  [568, 320, 'SE ландшафт'],
  [844, 390, 'iPhone ландшафт'],
  [1024, 768, 'iPad ландшафт'],
];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage();
  const issues = [];
  const runtime = [];
  page.on('pageerror', e => runtime.push('JS ERROR: ' + e.message));
  page.on('response', r => { if (r.status() >= 400) runtime.push(`HTTP ${r.status()} ${r.url()}`); });

  for (const [path, pname] of PAGES) {
    await page.goto('http://localhost:8766' + path, { waitUntil: 'networkidle', timeout: 25000 });
    await page.evaluate(() => document.fonts.ready);
    await page.evaluate(() => document.querySelectorAll('.reveal').forEach(e => e.classList.add('is-visible')));

    for (const [w, h, dname] of DEVICES) {
      await page.setViewportSize({ width: w, height: h });
      await page.waitForTimeout(130);
      const isMobile = w < 900;

      const res = await page.evaluate((isMobile) => {
        const out = { overflow: 0, offscreen: [], tiny: [], smallTap: [], brokenImg: [], overlap: [], clipped: [] };
        const vw = document.documentElement.clientWidth;
        out.overflow = document.scrollingElement.scrollWidth - vw;

        const vis = el => {
          const s = getComputedStyle(el);
          return s.display !== 'none' && s.visibility !== 'hidden' && parseFloat(s.opacity) > 0.05;
        };
        const label = el => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' && el.className.trim()
          ? '.' + el.className.trim().split(/\s+/)[0] : '');

        // элементы, выходящие за правый край
        document.querySelectorAll('body *').forEach(el => {
          if (!vis(el)) return;
          const r = el.getBoundingClientRect();
          if (r.width < 1 || r.height < 1) return;
          if (r.right > vw + 1.5 || r.left < -1.5) out.offscreen.push(`${label(el)}[${Math.round(r.left)}..${Math.round(r.right)}]`);
        });

        // слишком мелкий текст
        document.querySelectorAll('p, span, a, li, dd, dt, summary, label, h1, h2, h3, div').forEach(el => {
          if (!vis(el)) return;
          const t = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join('');
          if (t.length < 2) return;
          const fs = parseFloat(getComputedStyle(el).fontSize);
          if (fs < 11) out.tiny.push(`${label(el)} ${fs.toFixed(1)}px "${t.slice(0, 22)}"`);
        });

        // тач-зоны на мобильных
        if (isMobile) {
          document.querySelectorAll('a, button, summary, input[type=checkbox]').forEach(el => {
            if (!vis(el)) return;
            const r = el.getBoundingClientRect();
            if (r.width < 1 || r.height < 1) return;
            // ссылки внутри абзацев не считаем — они инлайновые
            const inProse = el.closest('p, .faq__a, .form__consent, .text-page__body');
            if (inProse) return;
            if (r.height < 32 || r.width < 24) out.smallTap.push(`${label(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
          });
        }

        // битые изображения
        document.querySelectorAll('img[src]').forEach(img => {
          const s = img.getAttribute('src');
          if (!s) { out.brokenImg.push('(пустой src)'); return; }
          // lazy-картинки вне зоны видимости ещё не загружены — это не дефект
          const r = img.getBoundingClientRect();
          const inView = r.top < innerHeight * 2 && r.bottom > -innerHeight;
          if (inView && (!img.complete || img.naturalWidth === 0)) out.brokenImg.push(s);
        });

        // текст, обрезанный по высоте контейнера
        document.querySelectorAll('h1, h2, h3, p, .service, .project-card__body, .stat, .fact__value').forEach(el => {
          if (!vis(el)) return;
          if (el.scrollHeight > el.clientHeight + 2 && getComputedStyle(el).overflow === 'hidden')
            out.clipped.push(`${label(el)} ${el.clientHeight}<${el.scrollHeight}`);
        });

        // перекрытие шапкой первого значимого контента
        const header = document.querySelector('.header');
        if (header) {
          const hb = header.getBoundingClientRect();
          const first = document.querySelector('main h1, main .hero__title, main .breadcrumbs');
          if (first) {
            const fb = first.getBoundingClientRect();
            if (fb.top < hb.bottom - 1 && fb.bottom > hb.top + 1)
              out.overlap.push(`шапка перекрывает ${label(first)} (шапка до ${Math.round(hb.bottom)}, контент с ${Math.round(fb.top)})`);
          }
        }
        return out;
      }, isMobile);

      const uniq = a => [...new Set(a)];
      const tag = `${pname} @${w}x${h} (${dname})`;
      if (res.overflow > 1) issues.push(`${tag}: гориз. прокрутка +${res.overflow}px → ${uniq(res.offscreen).slice(0,3).join(', ')}`);
      else if (res.offscreen.length) issues.push(`${tag}: элемент за краем → ${uniq(res.offscreen).slice(0,3).join(', ')}`);
      if (res.tiny.length) issues.push(`${tag}: мелкий текст → ${uniq(res.tiny).slice(0,3).join(', ')}`);
      if (res.smallTap.length) issues.push(`${tag}: малые тач-зоны → ${uniq(res.smallTap).slice(0,4).join(', ')}`);
      if (res.brokenImg.length) issues.push(`${tag}: битые изображения → ${uniq(res.brokenImg).slice(0,3).join(', ')}`);
      if (res.clipped.length) issues.push(`${tag}: обрезан текст → ${uniq(res.clipped).slice(0,3).join(', ')}`);
      if (res.overlap.length) issues.push(`${tag}: ${res.overlap.join('; ')}`);
    }
  }

  console.log('=== АУДИТ: ' + PAGES.length + ' страниц × ' + DEVICES.length + ' разрешений = ' + (PAGES.length * DEVICES.length) + ' проверок');
  console.log(issues.length ? issues.join('\n') : '✓ проблем не найдено');
  console.log('--- рантайм:');
  console.log(runtime.length ? [...new Set(runtime)].join('\n') : '✓ ошибок JS и битых запросов нет');
  await browser.close();
})();
