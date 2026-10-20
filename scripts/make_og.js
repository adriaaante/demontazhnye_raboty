const { chromium } = require('playwright');
const R = process.argv[2], out = process.argv[3];
const cards = [
  ['og-main.jpg', 'shell.jpg', 'Демонтаж под ноль<br>в Москве', 'Квартиры · офисы · вывоз мусора с талонами'],
  ['og-fasad.jpg', 'fasad.jpg', 'Отделка фасадов<br>под ключ', 'Штукатурка · покраска · утепление · свои леса'],
  ['og-sneg.jpg', 'sneg.jpg', 'Уборка снега<br>с крыш', 'Сброс снега · сосульки и наледь · на страховке'],
];
(async () => {
  const b = await chromium.launch();
  for (const [name, img, title, sub] of cards) {
    const p = await b.newPage({ viewport: { width: 1200, height: 630 } });
    const html = (`<style>
@font-face{font-family:M;src:url(file://${R}/assets/fonts/montserrat-cyrillic.woff2);font-weight:100 900;unicode-range:U+0400-04FF}
@font-face{font-family:M;src:url(file://${R}/assets/fonts/montserrat-latin.woff2);font-weight:100 900}
html,body{margin:0;width:1200px;height:630px;font-family:M;background:#17181A;color:#fff;overflow:hidden}
.ph{position:absolute;inset:0 0 0 520px;background:url(file://${R}/assets/img/${img}) center/cover}
.ph:after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,#17181A 0,rgba(23,24,26,0) 40%)}
.t{position:absolute;left:64px;top:64px;bottom:64px;width:600px;display:flex;flex-direction:column}
.br{display:flex;align-items:center;gap:14px;font-weight:700;font-size:26px}
.br svg{width:44px;height:44px}
h1{font-size:54px;line-height:1.06;font-weight:700;margin:auto 0 22px;letter-spacing:-.02em}
p{font-size:22px;color:#C9C7C2;margin:0 0 30px;font-weight:500}
.d{font-size:20px;font-weight:600;color:#E8641B;letter-spacing:.02em}
</style><div class="ph"></div><div class="t"><div class="br"><svg viewBox="0 0 40 40"><rect width="40" height="40" fill="#E8641B"/><ellipse cx="20" cy="20" rx="8.5" ry="11" fill="none" stroke="#fff" stroke-width="3.4"/><path d="M9 33 31 7" stroke="#fff" stroke-width="3.4"/></svg>Под Ноль</div>
<h1>${title}</h1><p>${sub}</p><div class="d">подноль.рф · +7 (987) 778-88-94</div></div>`);
    require('fs').writeFileSync(R + '/.og-tmp.html', html); await p.goto('file://' + R + '/.og-tmp.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: `${out}/${name}`, type: 'jpeg', quality: 86 }); await p.close();
  }
  require('fs').unlinkSync(R + '/.og-tmp.html'); await b.close();
})();
