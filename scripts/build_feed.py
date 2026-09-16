# -*- coding: utf-8 -*-
"""
Собирает из каталога ADS:
  • avito.xml — фид для Автозагрузки Авито (картинки берутся с подноль.рф);
  • OBYAVLENIYA.md — те же объявления текстом, чтобы можно было
    опубликовать руками, не дожидаясь автозагрузки.

Объявления с pending=True (нет цены от Арсения) в фид не попадают:
Авито обязательно требует цену. В текстовый файл попадают с пометкой.
"""
import os, sys
from datetime import datetime, timedelta
from xml.sax.saxutils import escape

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ads import ADS, TAIL, PHONE

REPO = "/workspace/demontazhnye_raboty"
SITE = "https://xn--d1aofccc0h.xn--p1ai"      # подноль.рф в punycode: надёжнее для внешних систем
# Телефон аккаунта Авито (Тихонов Никита) — заявки должны идти туда,
# где их реально принимают. На сайте подноль.рф номер другой, временный.
PHONE_RAW = "+79877771162"

# Пауза показов. Пока True, у каждого объявления проставляется <DateEnd> в
# прошлом — Авито снимает объявление с публикации, но не удаляет: заголовки,
# тексты, картинки и id остаются, включить обратно = поставить False,
# перегенерировать фид и запустить выгрузку.
PAUSED = True

# Раздел Авито, куда идут объявления. Названия сверяются с кабинетом —
# если Авито ругнётся на валидации, править здесь.
# Значения ниже — из официального шаблона Авито «Снос и демонтаж»
# (avito.ru/autoload/documentation/templates/77203). Менять только по нему:
# любое своё написание Авито отвергает с кодом 1073.
CATEGORY = "Предложение услуг"
SERVICE_TYPE = "Строительство"
SERVICE_SUBTYPE = "Снос и демонтаж"

# Обязательные параметры категории.
WORK_EXPERIENCE = "6 лет"          # из списка: Меньше года, 1 год … 10 лет и больше
TEAM_SIZE = "2-4 человека"         # 1 человек | 2-4 человека | 5-20 человек | больше 20
GUARANTEE = "Есть"                 # Есть | Нет
WORK_WITH_CONTRACT = "Да"
WORKING_METHOD = ["С помощью ручных инструментов", "С помощью техники"]
WORK_DAYS = ["пн.", "вт.", "ср.", "чт.", "пт.", "сб.", "вс."]

# Что именно демонтируем — набор зависит от услуги (несколько значений через «|»).
OBJECTS_FLAT = ["Отделка", "Перегородка", "Стена"]
OBJECTS_FULL = ["Отделка", "Перегородка", "Стена", "Перекрытия"]
OBJECTS_COMMERCIAL = ["Отделка", "Перегородка", "Стена", "Складские и промышленные объекты"]

# Вывоз мусора Авито не принимает в «Снос и демонтаж» — для него отдельная
# категория со своим набором параметров (модерация вернула объявление с прямым
# указанием, куда переносить).
GARBAGE_ADS = {"vyvoz-konteyner"}
GARBAGE_SERVICE_TYPE = "Вывоз мусора и вторсырья"

# Какие объекты указывать каждому объявлению.
OBJECTS_BY_AD = {
    "ofis": OBJECTS_COMMERCIAL,
    "ofisnye-peregorodki": OBJECTS_COMMERCIAL,
    "kvartira-pod-klyuch": OBJECTS_FULL,
    "beton": OBJECTS_FULL,
}


def options(tag, values):
    """Несколько значений одного параметра: Авито ждёт вложенные <Option>."""
    inner = "".join(f"<Option>{v}</Option>" for v in values)
    return f"<{tag}>{inner}</{tag}>"


def date_end():
    """На паузе — дата окончания в прошлом, иначе тега нет вовсе."""
    if not PAUSED:
        return ""
    past = (datetime.now() - timedelta(days=1)).strftime("%Y-%m-%dT%H:%M:%S")
    return f"\n    <DateEnd>{past}</DateEnd>"


def garbage_ad(ad, start, imgs):
    """Объявление в категории «Вывоз мусора и вторсырья» — свой набор полей."""
    return f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>{date_end()}
    <Category>{CATEGORY}</Category>
    <ServiceType>{GARBAGE_SERVICE_TYPE}</ServiceType>
    <Title>{escape(ad['title'])}</Title>
    <Description><![CDATA[{description(ad)}]]></Description>
    <Price>{ad['price']}</Price>
    <Images>{imgs}
    </Images>
    <Address>Москва</Address>
    <ContactPhone>{PHONE_RAW}</ContactPhone>
    <ManagerName>Под Ноль</ManagerName>
  </Ad>"""


def description(ad):
    """Полный текст объявления: лид + подробности + общий хвост."""
    return f"{ad['lead']}\n\n{ad['body'].strip()}\n{TAIL.rstrip()}"


def build_xml(ads):
    shift = timedelta(days=-2) if PAUSED else timedelta(minutes=5)
    start = (datetime.now() + shift).strftime("%Y-%m-%dT%H:%M:%S")
    out = ['<?xml version="1.0" encoding="UTF-8"?>',
           '<Ads formatVersion="3" target="Avito.ru">']
    for ad in ads:
        imgs = "".join(
            f'\n      <Image url="{SITE}/avito/img/{ad["id"]}-{n}.jpg"/>'
            for n in (1, 2))
        objects = OBJECTS_BY_AD.get(ad["id"], OBJECTS_FLAT)
        if ad["id"] in GARBAGE_ADS:
            out.append(garbage_ad(ad, start, imgs))
            continue
        out.append(f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>{date_end()}
    <Category>{CATEGORY}</Category>
    <ServiceType>{SERVICE_TYPE}</ServiceType>
    <ServiceSubtype>{SERVICE_SUBTYPE}</ServiceSubtype>
    <Title>{escape(ad['title'])}</Title>
    <Description><![CDATA[{description(ad)}]]></Description>
    <Price>{ad['price']}</Price>
    <Images>{imgs}
    </Images>
    <Address>Москва</Address>
    <ContactPhone>{PHONE_RAW}</ContactPhone>
    <ManagerName>Под Ноль</ManagerName>
    {options("DismantlingObject", objects)}
    {options("WorkingMethod", WORKING_METHOD)}
    <WorkExperience>{WORK_EXPERIENCE}</WorkExperience>
    <TeamSize>{TEAM_SIZE}</TeamSize>
    <Guarantee>{GUARANTEE}</Guarantee>
    <WorkWithContract>{WORK_WITH_CONTRACT}</WorkWithContract>
    {options("WorkDays", WORK_DAYS)}
    <WorkTimeFrom>08:00</WorkTimeFrom>
    <WorkTimeTo>22:00</WorkTimeTo>
  </Ad>""")
    out.append("</Ads>")
    return "\n".join(out) + "\n"


def build_md(ads):
    lines = [
        "# Объявления «Под Ноль» для Авито",
        "",
        f"Всего: {len(ads)}. Составлено {datetime.now():%d.%m.%Y}.",
        "",
        "Цены — «от», выставлены чуть ниже верхних объявлений в выдаче Москвы.",
        "Картинки лежат в `avito/img/`: `-1` — обложка на фото, `-2` — карточка условий.",
        "",
        "Как публиковать руками: скопировать заголовок, цену и описание,",
        "загрузить обе картинки, категория — «Предложение услуг» →",
        "«Строительство и ремонт» → «Снос и демонтаж».",
        "",
        "---",
        "",
    ]
    for i, ad in enumerate(ads, 1):
        price = (f"от {ad['price']:,} {ad['unit']}".replace(",", " ")
                 if ad.get("price") else "ЖДЁМ ЦЕНУ ОТ АРСЕНИЯ")
        lines += [
            f"## {i}. {ad['title']}",
            "",
            f"**Цена:** {price}  ",
            f"**Картинки:** `{ad['id']}-1.jpg`, `{ad['id']}-2.jpg`",
            "",
            "```",
            description(ad),
            "```",
            "",
            "---",
            "",
        ]
    return "\n".join(lines)


def main():
    ready = [a for a in ADS if not a.get("pending")]

    os.makedirs(f"{REPO}/avito", exist_ok=True)
    open(f"{REPO}/avito/avito.xml", "w", encoding="utf-8").write(build_xml(ready))
    open(f"{REPO}/avito/OBYAVLENIYA.md", "w", encoding="utf-8").write(build_md(ADS))

    print(f"avito.xml: {len(ready)} объявлений с ценой")
    print(f"OBYAVLENIYA.md: все {len(ADS)}, из них {len(ADS) - len(ready)} без цены")


if __name__ == "__main__":
    main()
