# -*- coding: utf-8 -*-
"""
Страницы отдельных услуг: отделка фасадов и уборка снега с крыш.

Шапка, подвал, форма контактов, модалка и плавающая кнопка берутся из
index.html — поправили их на главной, перезапустили скрипт, и страницы
услуг подтянули изменения. Руками страницы услуг не править.

Запуск из корня репозитория: python3 scripts/build_pages.py
"""
import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = "https://xn--d1aofccc0h.xn--p1ai"
ORG = f"{SITE}/#org"


def between(text, start, end, include_end=True):
    """Кусок текста от маркера start до первого end после него."""
    i = text.index(start)
    j = text.index(end, i) + (len(end) if include_end else 0)
    return text[i:j]


INDEX = open(os.path.join(ROOT, "index.html"), encoding="utf-8").read()

# На странице услуги свои «Как работаем», «Вопросы» и «Контакты», а раздел
# «Услуги» и «Что вы получаете» есть только на главной — туда и ведём.
HEADER = between(INDEX, '<header class="header"', "</header>")
FOOTER = between(INDEX, '<footer class="footer">', "</footer>")
FAB = between(INDEX, "<!-- Быстрая связь", '<div class="modal"', include_end=False).rstrip()
MODAL = between(INDEX, '<div class="modal"', '<script src="js/main.js', include_end=False).rstrip()
CONTACTS = between(INDEX, '<section class="section section--soft" id="contacts">', "</section>")
SCRIPT = re.search(r'<script src="js/main\.js[^"]*" defer></script>', INDEX).group(0)


def to_home(chunk):
    return (chunk.replace('href="#services"', 'href="./#services"')
                 .replace('href="#value"', 'href="./#value"'))


def contacts_for(page):
    c = CONTACTS
    c = c.replace("Посчитаем ваш демонтаж сегодня", page["contacts_title"])
    c = c.replace('<label for="f-msg">Что демонтируем</label>',
                  f'<label for="f-msg">{page["msg_label"]}</label>')
    c = c.replace('placeholder="Квартира или офис, площадь, адрес"',
                  f'placeholder="{page["msg_placeholder"]}"')
    c = c.replace('value="Форма в разделе «Контакты»"',
                  f'value="Форма на странице «{page["short"]}»"')
    return c


def cards(items):
    out = []
    for i, it in enumerate(items, 1):
        out.append(f"""          <article class="service reveal">
            <span class="service__num">{i:02d}</span>
            <h3>{it['title']}</h3>
            <p>{it['text']}</p>
            <dl class="service__meta"><dt>{it['dt']}</dt><dd>{it['dd']}</dd></dl>
          </article>""")
    return "\n".join(out)


def process(steps):
    return "\n".join(f"""          <div class="process__row reveal">
            <span class="process__num">{i:02d}</span>
            <span class="process__name">{name}</span>
            <p class="process__text">{text}</p>
          </div>""" for i, (name, text) in enumerate(steps, 1))


def faq(items):
    return "\n".join(f"""          <details>
            <summary>{q}</summary>
            <p class="faq__a">{a}</p>
          </details>""" for q, a in items)


def jsonld(page):
    url = f"{SITE}/{page['slug']}"
    service = {
        "@context": "https://schema.org",
        "@type": "Service",
        "name": page["short"],
        "serviceType": page["service_type"],
        "description": page["description"],
        "url": url,
        "image": f"{SITE}/{page['image']}",
        "areaServed": "Москва и Московская область",
        "provider": {"@type": "ProfessionalService", "@id": ORG, "name": "Под Ноль",
                     "telephone": "+79877788894", "url": f"{SITE}/"},
        "offers": {"@type": "Offer", "priceCurrency": "RUB",
                   "priceSpecification": page["price_spec"]},
    }
    crumbs = {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Главная", "item": f"{SITE}/"},
            {"@type": "ListItem", "position": 2, "name": page["short"], "item": url},
        ],
    }
    faqpage = {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {"@type": "Question", "name": q,
             "acceptedAnswer": {"@type": "Answer", "text": re.sub(r"<[^>]+>", "", a)}}
            for q, a in page["faq"]
        ],
    }
    return "\n".join(
        '  <script type="application/ld+json">\n  '
        + json.dumps(block, ensure_ascii=False, indent=2).replace("\n", "\n  ")
        + "\n  </script>"
        for block in (service, crumbs, faqpage))


def render(page):
    url = f"{SITE}/{page['slug']}"
    esc = html.escape
    meta = "".join(f"\n          <span>{m}</span>" for m in page["hero_meta"])
    return f"""<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{esc(page['title'])}</title>
  <meta name="description" content="{esc(page['description'])}">
  <link rel="canonical" href="{url}">
  <meta name="robots" content="index, follow">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Под Ноль">
  <meta property="og:locale" content="ru_RU">
  <meta property="og:title" content="{esc(page['og_title'])}">
  <meta property="og:description" content="{esc(page['description'])}">
  <meta property="og:url" content="{url}">
  <meta property="og:image" content="{SITE}/{page['image']}">
  <meta name="twitter:card" content="summary_large_image">

  <link rel="icon" href="favicon.svg" type="image/svg+xml">

  <link rel="preload" href="assets/fonts/montserrat-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="css/style.css">
  <noscript><style>.reveal {{ opacity: 1; transform: none; }}</style></noscript>
  <link rel="preload" as="image" href="{page['image']}">

{jsonld(page)}
</head>
<body>

  {to_home(HEADER)}

  <main>
    <section class="hero">
      <div class="hero__text">
        <nav class="breadcrumbs" aria-label="Навигационная цепочка">
          <a href="./">Главная</a><span>/</span><span>{page['short']}</span>
        </nav>
        <span class="hero__eyebrow">{page['eyebrow']}</span>
        <h1 class="hero__title">{page['h1']}</h1>
        <p class="hero__sub">{page['sub']}</p>
        <div class="hero__cta">
          <a class="btn" href="#contacts" data-modal-open="{page['cta']}">{page['cta']}</a>
          <a class="btn btn--ghost" href="#prices">Цены</a>
        </div>
        <div class="hero__meta">{meta}
        </div>
      </div>
      <div class="hero__media">
        <img src="{page['image']}" alt="{esc(page['image_alt'])}" fetchpriority="high" width="1168" height="880">
      </div>
    </section>

    <section class="section section--soft" id="uslugi">
      <div class="container">
        <div class="section__head reveal">
          <div>
            <span class="section-label">Что делаем</span>
            <h2 class="section-title">{page['services_title']}</h2>
          </div>
        </div>
        <div class="services-grid">
{cards(page['services'])}
        </div>
      </div>
    </section>

    <section class="section" id="prices">
      <div class="container">
        <div class="section__head reveal">
          <div>
            <span class="section-label">Цены</span>
            <h2 class="section-title">{page['prices_title']}</h2>
          </div>
        </div>
        <div class="services-grid">
{cards(page['prices'])}
        </div>
        <div class="services-note reveal">
          <div class="services-note__text">
            <span class="section-label">Стоимость</span>
            <p>{page['price_note']}</p>
          </div>
          <a class="btn" href="#contacts" data-modal-open="{page['cta']}">{page['cta']}</a>
        </div>
      </div>
    </section>

    <section class="section section--dark" id="process">
      <div class="container">
        <div class="section__head reveal">
          <div>
            <span class="section-label">Как работаем</span>
            <h2 class="section-title">{page['process_title']}</h2>
          </div>
        </div>
        <div class="process">
{process(page['process'])}
        </div>
      </div>
    </section>

    <section class="section" id="faq">
      <div class="container">
        <div class="section__head reveal">
          <div>
            <span class="section-label">Вопросы и ответы</span>
            <h2 class="section-title">{page['faq_title']}</h2>
          </div>
        </div>
        <div class="faq reveal">
{faq(page['faq'])}
        </div>
      </div>
    </section>

    {contacts_for(page)}
  </main>

  {to_home(FOOTER)}

  {FAB}

  {MODAL}

  {SCRIPT}
</body>
</html>
"""


WA = '<a href="https://wa.me/79877788894" rel="noopener" target="_blank">+7&nbsp;(987)&nbsp;778-88-94</a>'

PAGES = [
    dict(
        slug="otdelka-fasadov.html",
        short="Отделка фасадов",
        title="Отделка фасадов в Москве и области — от 100 000 ₽ | Под Ноль",
        og_title="Отделка фасадов домов и зданий под ключ — Под Ноль",
        description="Отделка фасадов частных домов, коттеджей и зданий под ключ: штукатурка и мокрый фасад, покраска, утепление, облицовка. Свои леса, цена в договоре.",
        service_type="Отделка фасадов частных домов и зданий",
        price_spec={"@type": "PriceSpecification", "minPrice": 100000, "priceCurrency": "RUB"},
        image="assets/img/fasad.jpg",
        image_alt="Рабочие на лесах штукатурят фасад частного дома из газобетона — отделка фасада «Под Ноль»",
        eyebrow="Фасадные работы · Москва и область",
        h1="Отделка фасадов домов и зданий под ключ",
        sub="Штукатурим, утепляем, красим и облицовываем фасады частных домов, коттеджей и коммерческих зданий. Свои леса, закупка материалов, цена в договоре — в процессе не растёт.",
        cta="Рассчитать фасад",
        hero_meta=["Мокрый фасад", "Вентфасад", "Утепление", "Смета по фото за час"],
        services_title="Фасад под ключ — от основания до финишного слоя",
        services=[
            dict(title="Штукатурка и «мокрый фасад»",
                 text="Утепление минватой, пенополистиролом или пеноплексом, армирующий слой и декоративная штукатурка. Тёплые стены без швов и мостиков холода.",
                 dt="Результат", dd="тепло и ровные стены"),
            dict(title="Покраска фасада",
                 text="Очищаем и ремонтируем основание, заделываем трещины, грунтуем, шпаклюем и красим фасадными красками в нужный цвет.",
                 dt="Основание", dd="штукатурка, бетон, кирпич"),
            dict(title="Облицовка и вентфасад",
                 text="Фасадные панели, виниловый и металлический сайдинг, клинкерная плитка, натуральный и искусственный камень на подсистеме.",
                 dt="Материалы", dd="панели, сайдинг, клинкер"),
            dict(title="Ремонт фасада",
                 text="Заделка трещин, восстановление штукатурки и откосов, косметическое обновление старого фасада без полной переделки.",
                 dt="Когда нужен", dd="трещины и отслоения"),
        ],
        prices_title="Сколько стоит отделка фасада",
        prices=[
            dict(title="Площадь фасада",
                 text="Считаем по квадратным метрам стен за вычетом окон и дверей, а не «на глаз» по размеру дома.",
                 dt="Замер", dd="бесплатно"),
            dict(title="Система отделки",
                 text="Покраска по готовому основанию дешевле всего, «мокрый фасад» с утеплением — в середине, вентфасад на подсистеме — дороже.",
                 dt="Выбор", dd="подскажем на замере"),
            dict(title="Материалы",
                 text="Можем закупить всё сами по оптовым ценам или работать с вашими материалами — как удобнее.",
                 dt="Закупка", dd="наша или ваша"),
            dict(title="Этажность и основание",
                 text="Высота дома, количество лесов, состояние старой отделки — всё это влияет на объём подготовки.",
                 dt="Леса", dd="свои"),
        ],
        price_note="Отделка фасада — от 100 000 ₽ за объект. Пришлите фото дома и примерные размеры — за час назовём ориентир. Выезд на замер по Москве и области бесплатный, цену и срок фиксируем в договоре. Оплата поэтапно или после приёмки.",
        process_title="Пять шагов до готового фасада",
        process=[
            ("Фото и размеры", "Пришлите фото дома и примерные размеры — за час назовём ориентир по цене."),
            ("Замер и договор", "Бесплатно приедем, замерим фасад, предложим систему отделки и материалы. Цену и срок фиксируем в договоре."),
            ("Леса и подготовка", "Ставим свои леса, укрываем окна, отмостку и газон плёнкой, очищаем и грунтуем основание."),
            ("Отделка", "Утепление, армирование, штукатурка, покраска или облицовка — по выбранной системе."),
            ("Приёмка и уборка", "Снимаем леса, убираем и вывозим мусор, сдаём работу. Оплата — поэтапно или после приёмки."),
        ],
        faq_title="Что спрашивают про отделку фасада",
        faq=[
            ("Сколько стоит отделка фасада частного дома?",
             f"От 100 000 ₽ за объект. Итог зависит от площади фасада, системы отделки и материалов. Пришлите фото дома в WhatsApp на {WA} — за час назовём ориентир, замер бесплатный, цена фиксируется в договоре."),
            ("Сколько времени занимает отделка фасада?",
             "Фасад частного дома площадью 150–200 м² — от 10 дней. Штукатурные работы ведём при плюсовой температуре. Точный срок фиксируем в договоре вместе с ценой."),
            ("Что выбрать: мокрый фасад или вентилируемый?",
             "Мокрый фасад дешевле, выглядит монолитно и хорошо утепляет. Вентилируемый — дороже, облицовка крепится на подсистеме, он долговечнее и проще в ремонте. На замере посмотрим основание и подскажем, что подходит вашему дому."),
            ("Вы сами закупаете материалы?",
             "Да, можем закупить всё сами или работать с вашими материалами. Закупку и доставку согласуем заранее и фиксируем в смете."),
            ("Нужно ли арендовать леса отдельно?",
             "Нет, леса у нас свои — ставим и снимаем сами, отдельно ничего арендовать не нужно."),
        ],
        contacts_title="Посчитаем ваш фасад сегодня",
        msg_label="Что за дом",
        msg_placeholder="Этажность, площадь фасада, адрес",
    ),
    dict(
        slug="uborka-snega-s-kryshi.html",
        short="Уборка снега с крыш",
        title="Уборка снега с крыши в Москве — от 40 ₽/м² | Под Ноль",
        og_title="Уборка снега с крыш и сбивание сосулек — Под Ноль",
        description="Уборка снега с крыш частных домов, коттеджей и зданий в Москве и области: сброс снега, сбивание сосулек и наледи. Работаем на страховке. От 40 ₽/м².",
        service_type="Очистка кровли от снега и наледи",
        price_spec={"@type": "UnitPriceSpecification", "minPrice": 40, "priceCurrency": "RUB", "unitText": "м²"},
        image="assets/img/sneg.jpg",
        image_alt="Рабочие на страховке сбрасывают снег со скатной крыши частного дома — уборка снега с крыш «Под Ноль»",
        eyebrow="Очистка кровли от снега · Москва и область",
        h1="Уборка снега с крыш и сбивание сосулек",
        sub="Сбрасываем снег со скатных и плоских крыш, сбиваем сосульки и наледь, очищаем водостоки. Работаем на страховке и деревянными лопатами — покрытие остаётся целым. Выезжаем в день обращения.",
        cta="Рассчитать уборку",
        hero_meta=["Скатные крыши", "Плоские кровли", "Сосульки и наледь", "Договор на сезон"],
        services_title="Снег, наледь и сосульки — до того, как они упадут сами",
        services=[
            dict(title="Сброс снега с крыши",
                 text="Скатные и плоские кровли частных домов, коттеджей, складов и зданий. Ограждаем место, куда падает снег.",
                 dt="Цена", dd="от 40 ₽/м²"),
            dict(title="Сосульки и наледь",
                 text="Сбиваем по краю кровли, пока они не упали на людей, машины и посадки.",
                 dt="Цена", dd="за погонный метр"),
            dict(title="Водостоки и желоба",
                 text="Очищаем ото льда, чтобы талая вода уходила по трубам, а не разрывала их и не текла по фасаду.",
                 dt="Когда", dd="в оттепель"),
            dict(title="Навесы, гаражи, бани",
                 text="Козырьки, беседки и хозпостройки — всё, что может продавить тяжёлым мокрым снегом.",
                 dt="Цена", dd="по фото"),
        ],
        prices_title="Сколько стоит уборка снега с крыши",
        prices=[
            dict(title="Плоская кровля и пологие скаты",
                 text="Самый простой случай: безопасный доступ и снег сбрасывается без лишней страховки.",
                 dt="Цена", dd="от 40 ₽/м²"),
            dict(title="Крутые скаты и высота",
                 text="Нужны верёвки и страховочные пояса на всю бригаду — работа медленнее и дороже.",
                 dt="Цена", dd="по фото"),
            dict(title="Толстая наледь",
                 text="Слежавшийся снег со льдом снимаем аккуратно, чтобы не повредить покрытие.",
                 dt="Цена", dd="по фото"),
            dict(title="Договор на сезон",
                 text="Чистим по графику или по первому звонку всю зиму — со скидкой к разовому выезду.",
                 dt="Выгода", dd="скидка"),
        ],
        price_note="Уборка снега с крыши — от 40 ₽ за м². Сосульки и наледь по краю кровли считаем за погонный метр. Пришлите фото крыши и примерную площадь — назовём цену сразу, до выезда. На договор на сезон — скидка.",
        process_title="Пять шагов до безопасной крыши",
        process=[
            ("Фото крыши", "Пришлите фото и примерную площадь кровли — назовём цену сразу."),
            ("Выезд", "Приезжаем в день обращения, ограждаем место, куда будет падать снег."),
            ("Страховка", "Закрепляем верёвки, работаем в страховочных поясах и касках."),
            ("Очистка", "Сбрасываем снег, сбиваем наледь и сосульки, освобождаем водостоки."),
            ("Уборка двора", "По желанию убираем сброшенный снег со двора и сдаём работу."),
        ],
        faq_title="Что спрашивают про уборку снега с крыши",
        faq=[
            ("Сколько стоит уборка снега с крыши?",
             f"От 40 ₽ за м² на плоских кровлях и пологих скатах. Крутые скаты, высота и толстая наледь — дороже. Пришлите фото крыши в WhatsApp на {WA} — назовём цену до выезда."),
            ("Не повредите кровлю?",
             "Чистим деревянными и пластиковыми лопатами, металлом по кровле не скребём — покрытие, водостоки и снегозадержатели остаются целыми."),
            ("Когда пора чистить крышу?",
             "После сильных снегопадов и в оттепель, когда по краю кровли нарастают наледь и сосульки. Ждать, пока снег сойдёт сам, опасно: пласт срывается целиком и ломает водостоки, машины и деревья."),
            ("Можно заключить договор на всю зиму?",
             "Да. По договору на сезон чистим по графику или по первому звонку, цена ниже, чем за разовый выезд."),
            ("Работаете с юрлицами и управляющими компаниями?",
             "Да: договор, счёт, закрывающие документы. Чистим кровли жилых домов, складов, магазинов и офисных зданий."),
        ],
        contacts_title="Посчитаем вашу крышу сегодня",
        msg_label="Что за крыша",
        msg_placeholder="Тип и площадь кровли, адрес",
    ),
]


def main():
    for page in PAGES:
        path = os.path.join(ROOT, page["slug"])
        open(path, "w", encoding="utf-8").write(render(page))
        print("готово:", page["slug"])


if __name__ == "__main__":
    main()
