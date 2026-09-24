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
from datetime import datetime, timedelta, timezone
from xml.sax.saxutils import escape

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ads import ADS, TAIL, PHONE

REPO = "/workspace/demontazhnye_raboty"
# Даты в фиде Авито читает по Москве, а сборка может идти на сервере в UTC.
MSK = timezone(timedelta(hours=3))


def now_msk():
    return datetime.now(MSK)


SITE = "https://xn--d1aofccc0h.xn--p1ai"      # подноль.рф в punycode: надёжнее для внешних систем
# Телефон в объявлениях. ВАЖНО: Авито принимает только номер, привязанный
# к аккаунту. Сейчас в аккаунте 79877771162, а этот номер заказчик дал как
# актуальный 16.09.2026 — перед снятием паузы его нужно добавить в аккаунт
# Авито, иначе выгрузка не примет объявления.
PHONE_RAW = "+79877788894"

# Пауза показов. Пока True, в фид не попадает ни одно рабочее объявление:
# автозагрузка держит Авито в соответствии с файлом, и всё, чего в файле нет,
# уходит с публикации. Вернуть показы = поставить False, пересобрать фид
# и дождаться ближайшей выгрузки (она идёт раз в час).
#
# Совсем пустой файл не годится — валидатор Авито считает его несоответствующим
# формату, а выгрузка с ошибкой ничего не меняет. Поэтому в фиде остаётся одна
# заглушка с собственным id и датой начала через год: файл валидный, а
# публиковать по нему нечего — и заглушка не всплывёт сама, если про паузу
# забудут.
#
# Через <DateEnd> снять объявления нельзя, проверено 16.09.2026: дата в прошлом
# даёт «Прошла дата окончания размещения» и объявление просто пропускается,
# дата в будущем — «Без изменений», срок размещения у живого объявления
# не меняется. Ручки «остановить» в API тоже нет.
PAUSED = False
PAUSE_STUB_ID = "pauza-zaglushka"

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
# У этой категории свой список допустимых значений — он НЕ совпадает со
# «Снос и демонтаж»: диапазоны пишутся через длинное тире «–», а «6 лет»
# опыта здесь нет вовсе. Значения ниже подобраны по валидатору Авито
# (autoload.avito.ru/api/v2/public/xml_checker), другие он отвергает.
GARBAGE_SAME_DAY = "Да"                 # выезд в день заказа
GARBAGE_MIN_ORDER = 6900                # минимальная сумма заказа, ₽ (контейнер 8 м³)
GARBAGE_PERFORMERS = "2–5"              # исполнителей в команде: «1» или «2–5»
GARBAGE_LEGAL_ENTITIES = "Да"           # работаем с юрлицами и ИП
GARBAGE_EXPERIENCE = "1–3 года"         # из «Меньше года» / «1–3 года» / «10 лет и больше»

# Отделка фасадов — ещё одна категория со своим набором полей.
# Значения взяты из справочника автозагрузки (см. комментарий к SERVICE_SUBTYPE):
# GET /web/1/autoload/user-docs/category/77204/fields.
FACADE_ADS = {"otdelka-fasadov"}
FACADE_SUBTYPE = "Фасадные работы"
FACADE_WORK = ["Монтаж", "Отделочные работы", "Ремонт", "Утепление"]
FACADE_TYPE = ["Вентилируемые", "«Мокрый фасад»"]
FACADE_MATERIALS = ["Виниловый сайдинг", "Металлический сайдинг", "Фасадные панели",
                    "Клинкерная плитка", "Натуральный камень", "Искусственный камень"]
FACADE_FINISHING = ["Штукатурные", "Облицовочные", "Покраска", "Грунтование", "Шпаклевание"]
FACADE_REPAIR = ["Косметический", "Ремонт трещин"]
FACADE_INSULATORS = ["Минеральная вата", "Пенополистирол", "Пеноплекс"]
FACADE_MATERIAL_PURCHASE = "Возможна"      # Возможна | Нет
FACADE_PAYMENT = ["Поэтапная", "Постоплата"]
FACADE_WORK_WITH = ["Физические лица", "ИП", "ООО"]

# Уборка снега с крыш — «Ремонт и отделка» → «Высотные работы»: там, а не в
# «Уборке», лежат объявления «Очистка крыш от снега и сосулек» у конкурентов.
# Обязательные поля подобраны по валидатору Авито; SNOW_SPECIALTY — значение
# поля «Чем вы занимаетесь» из справочника автозагрузки (узел 66892).
SNOW_ADS = {"uborka-snega-krysha"}
SNOW_SERVICE_TYPE = "Ремонт и отделка"
SNOW_SUBTYPE = "Высотные работы"
SNOW_SPECIALTY = []                 # заполняется из справочника, см. выше

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


def facade_ad(ad, start, imgs):
    """Объявление в категории «Фасадные работы» — свой набор полей."""
    return f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>
    <Category>{CATEGORY}</Category>
    <ServiceType>{SERVICE_TYPE}</ServiceType>
    <ServiceSubtype>{FACADE_SUBTYPE}</ServiceSubtype>
    <Title>{escape(ad['title'])}</Title>
    <Description><![CDATA[{description(ad)}]]></Description>
    <Price>{ad['price']}</Price>
    <Images>{imgs}
    </Images>
    <Address>Москва</Address>
    <ContactPhone>{PHONE_RAW}</ContactPhone>
    <ManagerName>Под Ноль</ManagerName>
    {options("FacadeWork", FACADE_WORK)}
    {options("FacadeType", FACADE_TYPE)}
    {options("FacadeMaterials", FACADE_MATERIALS)}
    {options("FinishingServices", FACADE_FINISHING)}
    {options("TypesOfRepair", FACADE_REPAIR)}
    {options("FacadeInsulators", FACADE_INSULATORS)}
    <MaterialPurchase>{FACADE_MATERIAL_PURCHASE}</MaterialPurchase>
    <WorkExperience>{WORK_EXPERIENCE}</WorkExperience>
    {options("TeamSize", [TEAM_SIZE])}
    <Guarantee>{GUARANTEE}</Guarantee>
    <WorkWithContract>{WORK_WITH_CONTRACT}</WorkWithContract>
    {options("WorkWith", FACADE_WORK_WITH)}
    {options("Payment", FACADE_PAYMENT)}
    <FreeConsultation>Есть</FreeConsultation>
    {options("WorkDays", WORK_DAYS)}
    <WorkTimeFrom>08:00</WorkTimeFrom>
    <WorkTimeTo>22:00</WorkTimeTo>
  </Ad>"""


def snow_ad(ad, start, imgs):
    """Объявление в категории «Высотные работы» — свой набор полей."""
    return f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>
    <Category>{CATEGORY}</Category>
    <ServiceType>{SNOW_SERVICE_TYPE}</ServiceType>
    <ServiceSubtype>{SNOW_SUBTYPE}</ServiceSubtype>
    <Title>{escape(ad['title'])}</Title>
    <Description><![CDATA[{description(ad)}]]></Description>
    <Price>{ad['price']}</Price>
    <Images>{imgs}
    </Images>
    <Address>Москва</Address>
    <ContactPhone>{PHONE_RAW}</ContactPhone>
    <ManagerName>Под Ноль</ManagerName>
    {options("Specialty", SNOW_SPECIALTY)}
    <MaterialPurchase>Возможна</MaterialPurchase>
    <WorkExperience>{WORK_EXPERIENCE}</WorkExperience>
    {options("TeamSize", [TEAM_SIZE])}
    <Guarantee>{GUARANTEE}</Guarantee>
    <WorkWithContract>{WORK_WITH_CONTRACT}</WorkWithContract>
    {options("WorkDays", WORK_DAYS)}
    <WorkTimeFrom>08:00</WorkTimeFrom>
    <WorkTimeTo>22:00</WorkTimeTo>
  </Ad>"""


def garbage_ad(ad, start, imgs):
    """Объявление в категории «Вывоз мусора и вторсырья» — свой набор полей."""
    return f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>
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
    <SameDayPickup>{GARBAGE_SAME_DAY}</SameDayPickup>
    <MinimumOrderAmount>{GARBAGE_MIN_ORDER}</MinimumOrderAmount>
    <PerformersOnTheTeam>{GARBAGE_PERFORMERS}</PerformersOnTheTeam>
    <WorkWithLegalEntities>{GARBAGE_LEGAL_ENTITIES}</WorkWithLegalEntities>
    <WorkExperience>{GARBAGE_EXPERIENCE}</WorkExperience>
  </Ad>"""


def description(ad):
    """Полный текст объявления: лид + подробности + хвост.

    Общий хвост написан под демонтаж (подъезд, лифт, талоны на мусор) — у
    объявлений других услуг он свой, в ключе tail.
    """
    tail = ad.get("tail", TAIL)
    return f"{ad['lead']}\n\n{ad['body'].strip()}\n{tail.rstrip()}"


def build_xml(ads):
    # Дата начала — чуть в прошлом: если поставить её вперёд, выгрузка
    # отвечает «Не наступила дата начала размещения» и ждёт следующего круга.
    shift = timedelta(days=365) if PAUSED else timedelta(minutes=-10)
    start = (now_msk() + shift).strftime("%Y-%m-%dT%H:%M:%S")
    out = ['<?xml version="1.0" encoding="UTF-8"?>',
           '<Ads formatVersion="3" target="Avito.ru">']
    for ad in ads:
        # Авито не перечитывает картинку, если адрес не изменился: подменённый
        # по тому же пути файл в объявление не попадёт, выгрузка отчитается
        # «Без изменений». Поэтому у переснятых картинок в имени есть ревизия.
        rev = ad.get("img_rev")
        suffix = f"-r{rev}" if rev else ""
        imgs = "".join(
            f'\n      <Image url="{SITE}/avito/img/{ad["id"]}-{n}{suffix}.jpg"/>'
            for n in (1, 2))
        objects = OBJECTS_BY_AD.get(ad["id"], OBJECTS_FLAT)
        if ad["id"] in GARBAGE_ADS:
            out.append(garbage_ad(ad, start, imgs))
            continue
        if ad["id"] in FACADE_ADS:
            out.append(facade_ad(ad, start, imgs))
            continue
        if ad["id"] in SNOW_ADS:
            out.append(snow_ad(ad, start, imgs))
            continue
        out.append(f"""  <Ad>
    <Id>{ad['id']}</Id>
    <DateBegin>{start}</DateBegin>
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
        f"Всего: {len(ads)}. Составлено {now_msk():%d.%m.%Y}.",
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
    feed = [dict(ready[0], id=PAUSE_STUB_ID)] if PAUSED else ready

    os.makedirs(f"{REPO}/avito", exist_ok=True)
    open(f"{REPO}/avito/avito.xml", "w", encoding="utf-8").write(build_xml(feed))
    open(f"{REPO}/avito/OBYAVLENIYA.md", "w", encoding="utf-8").write(build_md(ADS))

    if PAUSED:
        print("avito.xml: ПАУЗА — только заглушка, показов нет")
    else:
        print(f"avito.xml: {len(ready)} объявлений с ценой")
    print(f"OBYAVLENIYA.md: все {len(ADS)}, из них {len(ADS) - len(ready)} без цены")


if __name__ == "__main__":
    main()
