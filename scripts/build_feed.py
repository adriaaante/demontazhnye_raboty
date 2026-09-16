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
PHONE_RAW = "+79093410785"

# Раздел Авито, куда идут объявления. Названия сверяются с кабинетом —
# если Авито ругнётся на валидации, править здесь.
CATEGORY = "Предложение услуг"
SERVICE_TYPE = "Строительство и ремонт"


def description(ad):
    """Полный текст объявления: лид + подробности + общий хвост."""
    return f"{ad['lead']}\n\n{ad['body'].strip()}\n{TAIL.rstrip()}"


def build_xml(ads):
    start = (datetime.now() + timedelta(minutes=30)).strftime("%Y-%m-%dT%H:%M:%S")
    out = ['<?xml version="1.0" encoding="UTF-8"?>',
           '<Ads formatVersion="3" target="Avito.ru">']
    for ad in ads:
        imgs = "".join(
            f'\n      <Image url="{SITE}/avito/img/{ad["id"]}-{n}.jpg"/>'
            for n in (1, 2))
        out.append(f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>
    <Category>{CATEGORY}</Category>
    <ServiceType>{SERVICE_TYPE}</ServiceType>
    <Title>{escape(ad['title'])}</Title>
    <Description><![CDATA[{description(ad)}]]></Description>
    <Price>{ad['price']}</Price>
    <Images>{imgs}
    </Images>
    <Address>Москва</Address>
    <ContactPhone>{PHONE_RAW}</ContactPhone>
    <ManagerName>Под Ноль</ManagerName>
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
