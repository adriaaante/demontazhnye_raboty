# -*- coding: utf-8 -*-
"""Рендер картинок для объявлений Авито: обложка на фото + карточка условий."""
import base64, json, os, sys
sys.path.insert(0, os.path.dirname(__file__))
from ads import ADS, PHONE

HERE = os.path.dirname(os.path.abspath(__file__))
FONTS = "/workspace/demontazhnye_raboty/assets/fonts"
PHOTO = os.path.join(HERE, "photo")
OUT = os.path.join(HERE, "covers")
os.makedirs(OUT, exist_ok=True)


def b64(path, mime):
    with open(path, "rb") as f:
        return f"data:{mime};base64," + base64.b64encode(f.read()).decode()


CYR = b64(f"{FONTS}/montserrat-cyrillic.woff2", "font/woff2")
LAT = b64(f"{FONTS}/montserrat-latin.woff2", "font/woff2")

# Логотип «Под Ноль» — тот же знак, что на сайте и в фавиконке
LOGO = """<svg viewBox="0 0 40 40" class="mark">
  <rect width="40" height="40" fill="#E8641B"/>
  <ellipse cx="20" cy="20" rx="8.5" ry="11" fill="none" stroke="#fff" stroke-width="3.4"/>
  <path d="M9 33 31 7" stroke="#fff" stroke-width="3.4"/>
</svg>"""

CSS = f"""
@font-face {{ font-family: M; src: url("{CYR}") format("woff2");
  font-weight: 300 700; unicode-range: U+0400-045F,U+0490-0491,U+2116; }}
@font-face {{ font-family: M; src: url("{LAT}") format("woff2");
  font-weight: 300 700; unicode-range: U+0000-00FF,U+2000-206F,U+20BD,U+2122; }}
* {{ margin:0; padding:0; box-sizing:border-box; }}
body {{ font-family: M, Arial, sans-serif; background:#111; }}

.card {{ width:1200px; height:900px; position:relative; overflow:hidden; }}

/* ---------- обложка: фото + информация ---------- */
.cover .bg {{ position:absolute; inset:0; background-size:cover; background-position:center; }}
.cover .shade {{ position:absolute; inset:0;
  background: linear-gradient(to bottom, rgba(0,0,0,.55) 0%, rgba(0,0,0,0) 26%,
              rgba(0,0,0,0) 40%, rgba(10,10,10,.93) 78%, #0A0A0A 100%); }}

.top {{ position:absolute; top:34px; left:38px; right:38px;
  display:flex; align-items:center; justify-content:space-between; }}
.logo {{ display:flex; align-items:center; gap:14px; }}
.mark {{ width:52px; height:52px; display:block; }}
.logo b {{ color:#fff; font-size:23px; font-weight:700;
  letter-spacing:.14em; text-transform:uppercase; }}
.badge {{ background:#E8641B; color:#fff; font-size:19px; font-weight:700;
  letter-spacing:.1em; text-transform:uppercase; padding:13px 22px; }}

.bottom {{ position:absolute; left:38px; right:38px; bottom:34px; }}
.price {{ display:flex; align-items:baseline; gap:16px; margin-bottom:6px; }}
.price .from {{ color:#E8641B; font-size:34px; font-weight:700;
  text-transform:uppercase; letter-spacing:.08em; }}
.price .num {{ color:#fff; font-size:104px; font-weight:700; line-height:1;
  letter-spacing:-.02em; }}
.price .unit {{ color:#fff; font-size:40px; font-weight:600; }}
.price .ask {{ color:#fff; font-size:64px; font-weight:700; line-height:1.05; }}

.title {{ color:#fff; font-size:38px; font-weight:600; line-height:1.2;
  margin-bottom: 22px; max-width: 900px; }}

.perks {{ display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; }}
.perk {{ border:2px solid rgba(255,255,255,.42); color:#fff; font-size:21px;
  font-weight:600; padding:10px 18px; }}
.perk.hot {{ border-color:#E8641B; background:#E8641B; }}

.cta {{ border-top:2px solid rgba(255,255,255,.22); padding-top:18px;
  color:#fff; font-size:27px; font-weight:600; letter-spacing:.02em; }}
.cta span {{ color:#FFB07A; }}

/* ---------- карточка условий ---------- */
.terms {{ background:#141414; padding:56px 60px; display:flex;
  flex-direction:column; justify-content:space-between; }}
.terms .head {{ display:flex; align-items:center; justify-content:space-between; }}
.terms h1 {{ color:#fff; font-size:46px; font-weight:700; line-height:1.15;
  margin:26px 0 0; }}
.terms .big {{ display:flex; align-items:baseline; gap:18px; margin:18px 0 6px; }}
.terms .big .num {{ color:#E8641B; font-size:132px; font-weight:700; line-height:.95; }}
.terms .big .unit {{ color:#fff; font-size:46px; font-weight:600; }}
.terms .big .from {{ color:#E8641B; font-size:40px; font-weight:700;
  text-transform:uppercase; letter-spacing:.08em; }}
.terms .big .ask {{ color:#E8641B; font-size:76px; font-weight:700; line-height:1.05; }}
.terms .note {{ color:#9A9A9A; font-size:24px; line-height:1.45; max-width:1000px; }}

.rows {{ display:flex; flex-direction:column; gap:16px; margin:12px 0; }}
.row {{ display:flex; align-items:center; gap:20px; }}
.row .ic {{ width:52px; height:52px; background:#E8641B; flex-shrink:0;
  display:grid; place-items:center; color:#fff; font-size:27px; font-weight:700; }}
.row .tx {{ color:#fff; font-size:30px; font-weight:600; line-height:1.25; }}
.row .tx i {{ display:block; color:#9A9A9A; font-size:23px; font-weight:400;
  font-style:normal; margin-top:3px; }}

/* Длинный заголовок переносится на две строки, и без поджатия нижняя строка
   «Ответим за 15 минут» упирается в край карточки. */
.terms.long h1 {{ font-size:40px; margin-top:20px; }}
.terms.long .big {{ margin:10px 0 2px; }}
.terms.long .big .num {{ font-size:110px; }}
.terms.long .note {{ font-size:22px; }}
.terms.long .rows {{ gap:11px; margin:8px 0; }}
.terms .foot {{ border-top:2px solid #2C2C2C; padding-top:22px;
  display:flex; align-items:center; justify-content:space-between; }}
.terms .foot .call {{ color:#fff; font-size:30px; font-weight:700; }}
.terms .foot .call i {{ display:block; color:#E8641B; font-size:23px;
  font-weight:600; font-style:normal; margin-top:4px; letter-spacing:.06em;
  text-transform:uppercase; }}
.terms .foot .site {{ color:#7A7A7A; font-size:23px; letter-spacing:.1em;
  text-transform:uppercase; }}
"""


def price_html(ad, cls=""):
    """Крупный блок цены. Если цены пока нет — зовём обсудить."""
    if ad.get("price"):
        num = f"{ad['price']:,}".replace(",", " ")
        return (f'<span class="from">от</span><span class="num">{num}</span>'
                f'<span class="unit">{ad["unit"]}</span>')
    return '<span class="ask">Цена по объёму<br>считаем бесплатно</span>'


# Плашки и строки условий по умолчанию написаны под демонтаж. Объявление
# другой услуги (снег, фасады) задаёт свои через ключи perks / card_rows —
# иначе на карточке про снег окажется «вывоз мусора с талонами».
PERKS = ["Договоримся по цене", "Скидка на объём", "Вывоз мусора", "Договор"]
CARD_ROWS = [
    ("%", "Скидки на объём и комплекс", "Чем больше работ берём — тем ниже цена за метр"),
    ("₽", "Договоримся по цене", "Назовите свою — если она в рынке, согласуем"),
    ("1ч", "Смета по фото за час", "Пришлите фото помещения — посчитаем бесплатно"),
    ("✓", "Фиксируем цену в договоре", "В процессе не растёт, вывоз мусора с талонами"),
]


def perks_html(ad):
    perks = ad.get("perks", PERKS)
    return "".join(
        f'<div class="perk{" hot" if i == 0 else ""}">{p}</div>' for i, p in enumerate(perks))


def rows_html(ad):
    return "".join(
        f'<div class="row"><div class="ic">{ic}</div><div class="tx">{title}'
        f'<i>{sub}</i></div></div>'
        for ic, title, sub in ad.get("card_rows", CARD_ROWS))


def build(ad):
    photo = None
    for ext in (".png", ".jpg"):
        p = os.path.join(PHOTO, ad["photo"] + ext)
        if os.path.exists(p):
            photo = b64(p, "image/png" if ext == ".png" else "image/jpeg")
            break
    if not photo:
        raise SystemExit(f"нет фото для {ad['id']}: {ad['photo']}")

    cover = f"""<div class="card cover">
  <div class="bg" style="background-image:url('{photo}')"></div>
  <div class="shade"></div>
  <div class="top">
    <div class="logo">{LOGO}<b>Под Ноль</b></div>
    <div class="badge">{ad['badge']}</div>
  </div>
  <div class="bottom">
    <div class="price">{price_html(ad)}</div>
    <div class="title">{ad['title']}</div>
    <div class="perks">{perks_html(ad)}</div>
    <div class="cta">Пишите или звоните — <span>ответим за 15 минут</span>,
      смета по фото за час</div>
  </div>
</div>"""

    long = " long" if len(ad["title"]) > 36 else ""
    terms = f"""<div class="card terms{long}">
  <div>
    <div class="head">
      <div class="logo">{LOGO}<b>Под Ноль</b></div>
      <div class="badge">{ad['badge']}</div>
    </div>
    <h1>{ad['title']}</h1>
    <div class="big">{price_html(ad)}</div>
    <div class="note">Цена минимальная, итог — по вашему объёму.
      Всегда обсуждаем: на большой объём и комплекс работ даём скидку.</div>
  </div>

  <div class="rows">{rows_html(ad)}</div>

  <div class="foot">
    <div class="call">Пишите в сообщения или звоните<i>Ответим за 15 минут</i></div>
    <div class="site">подноль.рф</div>
  </div>
</div>"""
    return cover, terms


def main():
    cards = []
    for ad in ADS:
        c, t = build(ad)
        cards.append((f"{ad['id']}-1", c))
        cards.append((f"{ad['id']}-2", t))

    html = ("<style>" + CSS + "</style>" +
            "".join(f'<div id="{i}">{h}</div>' for i, h in cards))
    path = os.path.join(HERE, "cards.html")
    open(path, "w", encoding="utf-8").write(html)
    open(os.path.join(HERE, "cards.json"), "w").write(
        json.dumps([i for i, _ in cards], ensure_ascii=False))
    print(f"карточек: {len(cards)} → {path}")


if __name__ == "__main__":
    main()
