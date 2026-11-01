#!/usr/bin/env python3
"""
Кампания Яндекс Директа «Под Ноль — Поиск — Демонтаж» данными + создание через API v5.

Запуск:
  python3 scripts/direct_campaign.py            — проверка: длины текстов, лимиты, сводка (ничего не отправляет)
  python3 scripts/direct_campaign.py --create   — создать кампанию в Директе и сразу ОСТАНОВИТЬ её
                                                   (объявления уходят на модерацию, деньги не тратятся)
  python3 scripts/direct_campaign.py --resume ID — включить показы (только с согласия владельца на бюджет)

Токен — переменная окружения PODNOL_YANDEX_TOKEN (OAuth-приложение «Под Ноль», ClientID 8c1caf76…).
Только поиск, Москва, 08–22 без выходных. Стратегия — максимум кликов с недельным
бюджетом и потолком цены клика: пока счётчик не накопил конверсий, автостратегии учиться не на чем —
после 10+ целей «lead» в неделю перейти на оплату за конверсии.
Цен в объявлениях нет намеренно: сайт их не показывает, а Директ требует подтверждать цену на странице.
"""

import json, os, sys, urllib.request, urllib.error

SITE = "https://xn--d1aofccc0h.xn--p1ai/"          # подноль.рф (в ссылках Директ требует punycode)
UTM = "utm_source=yandex&utm_medium=cpc&utm_campaign={campaign_id}&utm_content={ad_id}&utm_term={keyword}"

CAMPAIGN = dict(
    name="Под Ноль — Поиск — Демонтаж (Москва)",
    regions=[213],                 # Москва; 1 — Москва и область
    weekly_rub=7000,               # ≈ 1 000 ₽/день — тест, менять только с согласия владельца
    bid_ceiling_rub=150,           # потолок цены клика
    hours=(8, 22),                 # показы 08:00–22:00, как работает бригада
    metrika=113983360,             # счётчик подноль.рф; цели lead / phone / messenger
)

# Общие для всех групп минус-слова: самоделки, работа, документы, чужие ниши и то, чего не делаем
NEGATIVE = """
своими руками самостоятельно сам самому как чем видео фото схема инструкция инструмент перфоратор купить аренда прокат
бесплатно вакансия вакансии работа требуется требуются подработка обучение курсы резюме
гост снип сп норматив расценка расценки фер гэсн образец скачать акт договор смета-образец
разрешение согласование согласовать узаконить бти мжи жилинспекция штраф закон можно ли
несущая несущей несущих несущую
дом дома дача дачи дачный сарай баня бани гараж забор здание зданий цех склад ангар фундамент
металлоконструкций металлоконструкции станок станков лифт лифта эскалатор
авто автомобиль машины бампер колес шин фар двигателя магнитолы
кондиционер кондиционера котел котла газ газовой счетчик счетчиков
асфальт бордюр рельсов мост моста
спб петербург питер екатеринбург казань новосибирск краснодар нижний
""".split()

CALLOUTS = ["Смета по фото за 1 час", "Цена в договоре", "Вывоз мусора с талонами",
            "Защитим лифт и подъезд", "Без выходных с 8 до 22"]

SITELINKS = [
    dict(title="Квартира под ноль", href=SITE + "#services", desc="Снимаем всё до бетона, вывозим мусор, сдаём чистую коробку"),
    dict(title="Как работаем", href=SITE + "#process", desc="Замер, смета, договор, демонтаж, вывоз и приёмка объекта"),
    dict(title="Ответы на вопросы", href=SITE + "#faq", desc="Сроки, шум, соседи, мусор и оплата — коротко и честно"),
    dict(title="Рассчитать стоимость", href=SITE + "#contacts", desc="Пришлите фото — пришлём смету в течение часа"),
]

# Группа = одна услуга: свои фразы и свои объявления под эти фразы.
# Тексты: Title ≤ 56, Title2 ≤ 30, Text ≤ 81 символа; путь ссылки ≤ 20.
GROUPS = [
    dict(name="Квартира под ключ", path="demontazh-kvartiry", phrases=[
        "демонтаж квартиры", "демонтаж квартиры под ключ", "демонтаж квартиры москва",
        "демонтаж квартиры перед ремонтом", "демонтаж квартиры цена", "демонтаж старого ремонта",
        "демонтажные работы в квартире", "демонтаж в квартире", "снос старого ремонта в квартире",
        "демонтаж квартиры с вывозом мусора", "квартира под ноль демонтаж",
    ], ads=[
        dict(t1="Демонтаж квартиры под ключ в Москве", t2="С вывозом мусора",
             text="Снимем всё до бетона за 2–5 дней. Смета по фото за 1 час, цена в договоре."),
        dict(t1="Демонтаж квартиры перед ремонтом", t2="Смета по фото за час",
             text="Стены, полы, плитка, сантехника. Защитим лифт, вывезем мусор с талонами."),
    ]),
    dict(name="Новостройка", path="novostroyka", phrases=[
        "демонтаж в новостройке", "демонтаж отделки застройщика", "демонтаж отделки в новостройке",
        "демонтаж отделки от застройщика", "демонтаж в новой квартире",
    ], ads=[
        dict(t1="Демонтаж отделки застройщика в новостройке", t2="Под вашу отделку",
             text="Снимем стяжку, короба, перегородки от застройщика. Смета по фото за 1 час."),
    ]),
    dict(name="Стены и перегородки", path="steny", phrases=[
        "демонтаж стен", "демонтаж стен в квартире", "демонтаж перегородок", "демонтаж перегородки в квартире",
        "снос стены в квартире", "снос перегородки", "демонтаж кирпичной стены", "демонтаж кирпичной перегородки",
        "демонтаж гипсокартонной перегородки", "демонтаж стены из пеноблока", "снести стену в квартире",
    ], ads=[
        dict(t1="Демонтаж стен и перегородок в квартире", t2="Кирпич, блок, гипсокартон",
             text="Сносим перегородки любой толщины, режем проёмы. Мусор вывозим в тот же день."),
    ]),
    dict(name="Стяжка и полы", path="styazhka", phrases=[
        "демонтаж стяжки", "демонтаж стяжки пола", "снять стяжку пола", "демонтаж пола в квартире",
        "демонтаж бетонной стяжки", "демонтаж паркета", "демонтаж ламината", "демонтаж полов",
    ], ads=[
        dict(t1="Демонтаж стяжки и полов в квартире", t2="До плиты перекрытия",
             text="Снимаем стяжку любой толщины, паркет, ламинат. Пылесосы, вывоз мусора."),
    ]),
    dict(name="Плитка и санузел", path="plitka", phrases=[
        "демонтаж плитки", "демонтаж плитки в ванной", "демонтаж кафеля", "демонтаж плитки со стен",
        "демонтаж плитки с пола", "демонтаж санузла", "демонтаж ванной комнаты", "демонтаж сантехкабины",
        "снять плитку в ванной",
    ], ads=[
        dict(t1="Демонтаж плитки и санузла под ремонт", t2="Вместе со старым клеем",
             text="Снимем кафель, стяжку, сантехнику и короба. Сдадим санузел в бетоне."),
    ]),
    dict(name="Штукатурка и потолки", path="shtukaturka", phrases=[
        "демонтаж штукатурки", "снять штукатурку со стен", "сбить штукатурку", "демонтаж потолка",
        "демонтаж подвесного потолка", "демонтаж потолка армстронг",
    ], ads=[
        dict(t1="Снятие штукатурки и демонтаж потолков", t2="До кирпича или бетона",
             text="Сбиваем штукатурку, снимаем подвесные потолки с каркасом. Вывоз мусора."),
    ]),
    dict(name="Окна и двери", path="okna-dveri", phrases=[
        "демонтаж окон", "демонтаж окон в квартире", "демонтаж балконного блока", "демонтаж дверей",
        "демонтаж межкомнатных дверей", "демонтаж входной двери", "демонтаж старых окон",
    ], ads=[
        dict(t1="Демонтаж окон, дверей и балконных блоков", t2="Вместе с коробками",
             text="Аккуратно снимем окна и двери, вывезем. Выезд в день обращения по Москве."),
    ]),
    dict(name="Офисы", path="ofis", phrases=[
        "демонтаж офиса", "демонтаж офисных перегородок", "демонтаж фальшпола", "демонтаж офисного помещения",
        "демонтаж коммерческого помещения", "демонтаж торгового помещения", "демонтаж стеклянных перегородок",
    ], ads=[
        dict(t1="Демонтаж офисов и коммерческих помещений", t2="Ночью и в выходные",
             text="Перегородки, фальшполы, потолки, инженерия. Договор, закрывающие документы."),
    ]),
]


def check():
    """Офлайн-проверка лимитов Директа. Возвращает список ошибок."""
    err = []
    lim = lambda s, n: len(s) <= n
    for g in GROUPS:
        if len(g["path"]) > 20:
            err.append(f"{g['name']}: путь длиннее 20")
        for p in g["phrases"]:
            if len(p.split()) > 7:
                err.append(f"фраза длиннее 7 слов: {p}")
        for a in g["ads"]:
            for k, n in (("t1", 56), ("t2", 30), ("text", 81)):
                if not lim(a[k], n):
                    err.append(f"{g['name']}: {k} {len(a[k])}>{n}: {a[k]}")
                if any(len(w) > 23 for w in a[k].split()):
                    err.append(f"{g['name']}: слово длиннее 23 в {k}")
    if sum(len(s["title"]) for s in SITELINKS) > 66:
        err.append("быстрые ссылки: заголовки вместе длиннее 66")
    for s in SITELINKS:
        if len(s["title"]) > 30 or len(s["desc"]) > 60:
            err.append(f"быстрая ссылка: {s['title']} ({len(s['title'])}/{len(s['desc'])})")
    for c in CALLOUTS:
        if len(c) > 25:
            err.append(f"уточнение длиннее 25: {c}")
    words = [w.lower() for g in GROUPS for p in g["phrases"] for w in p.split()]
    clash = sorted(set(words) & set(w.lower() for w in NEGATIVE))
    if clash:
        err.append(f"минус-слова режут свои фразы: {clash}")
    return err


def api(service, method, params):
    token = os.environ.get("PODNOL_YANDEX_TOKEN", "")
    if not token:
        sys.exit("нет PODNOL_YANDEX_TOKEN в окружении")
    req = urllib.request.Request(
        f"https://api.direct.yandex.com/json/v5/{service}",
        data=json.dumps({"method": method, "params": params}).encode(),
        headers={"Authorization": "Bearer " + token, "Accept-Language": "ru",
                 "Content-Type": "application/json; charset=utf-8"})
    with urllib.request.urlopen(req, timeout=60) as r:
        data = json.loads(r.read())
    if "error" in data:
        e = data["error"]
        sys.exit(f"{service}.{method}: {e.get('error_string')} — {e.get('error_detail')}")
    return data["result"]


def ids(result, key):
    out = []
    for r in result[key]:
        if r.get("Errors"):
            sys.exit(f"ошибка: {r['Errors']}")
        out.append(r["Id"])
    return out


def schedule():
    a, b = CAMPAIGN["hours"]
    return [str(d) + "," + ",".join("100" if a <= h < b else "0" for h in range(24)) for d in range(1, 8)]


def create():
    import datetime
    callouts = ids(api("adextensions", "add", {"AdExtensions": [{"Callout": {"CalloutText": c}} for c in CALLOUTS]}), "AddResults")
    links = ids(api("sitelinks", "add", {"SitelinksSets": [{"Sitelinks": [
        {"Title": s["title"], "Href": s["href"], "Description": s["desc"]} for s in SITELINKS]}]}), "AddResults")[0]
    micro = 1_000_000
    camp = ids(api("campaigns", "add", {"Campaigns": [{
        "Name": CAMPAIGN["name"],
        "StartDate": datetime.date.today().isoformat(),
        "NegativeKeywords": {"Items": NEGATIVE},
        "TimeTargeting": {"Schedule": {"Items": schedule()}, "ConsiderWorkingWeekends": "YES"},
        "TextCampaign": {
            "CounterIds": {"Items": [CAMPAIGN["metrika"]]},
            "BiddingStrategy": {
                "Search": {"BiddingStrategyType": "WB_MAXIMUM_CLICKS", "WbMaximumClicks": {
                    "WeeklySpendLimit": CAMPAIGN["weekly_rub"] * micro, "BidCeiling": CAMPAIGN["bid_ceiling_rub"] * micro}},
                "Network": {"BiddingStrategyType": "SERVING_OFF"}},
            "Settings": [{"Option": "ADD_METRICA_TAG", "Value": "YES"}, {"Option": "ENABLE_SITE_MONITORING", "Value": "YES"}],
        }}]}), "AddResults")[0]
    # сразу останавливаем: модерация пройдёт, но показов и списаний не будет до --resume
    api("campaigns", "suspend", {"SelectionCriteria": {"Ids": [camp]}})
    all_ads = []
    for g in GROUPS:
        gid = ids(api("adgroups", "add", {"AdGroups": [{"Name": g["name"], "CampaignId": camp, "RegionIds": CAMPAIGN["regions"]}]}), "AddResults")[0]
        href = SITE + "?" + UTM
        all_ads += ids(api("ads", "add", {"Ads": [{"AdGroupId": gid, "TextAd": {
            "Title": a["t1"], "Title2": a["t2"], "Text": a["text"], "Href": href, "Mobile": "NO",
            "DisplayUrlPath": g["path"], "SitelinkSetId": links, "AdExtensionIds": callouts}} for a in g["ads"]]}), "AddResults")
        ids(api("keywords", "add", {"Keywords": [{"Keyword": p, "AdGroupId": gid} for p in g["phrases"]]}), "AddResults")
        print(f"  группа «{g['name']}»: {len(g['ads'])} объявл., {len(g['phrases'])} фраз")
    api("ads", "moderate", {"SelectionCriteria": {"Ids": all_ads}})
    print(f"кампания {camp} создана и ОСТАНОВЛЕНА, {len(all_ads)} объявлений на модерации. Включить: --resume {camp}")


if __name__ == "__main__":
    errs = check()
    if errs:
        print("\n".join("✗ " + e for e in errs))
        sys.exit(1)
    nph = sum(len(g["phrases"]) for g in GROUPS)
    nad = sum(len(g["ads"]) for g in GROUPS)
    print(f"✓ {len(GROUPS)} групп, {nph} фраз, {nad} объявлений, {len(NEGATIVE)} минус-слов — лимиты Директа соблюдены")
    print(f"  бюджет {CAMPAIGN['weekly_rub']} ₽/нед, потолок клика {CAMPAIGN['bid_ceiling_rub']} ₽, регион {CAMPAIGN['regions']}, часы {CAMPAIGN['hours']}")
    if "--create" in sys.argv:
        create()
    elif "--resume" in sys.argv:
        cid = int(sys.argv[sys.argv.index("--resume") + 1])
        api("campaigns", "resume", {"SelectionCriteria": {"Ids": [cid]}})
        print(f"кампания {cid} включена")
