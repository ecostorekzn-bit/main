import argparse
import json
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph


GREEN = colors.HexColor("#173D2A")
ACCENT = colors.HexColor("#5EAC72")
LIGHT = colors.HexColor("#EDF6EF")
TEXT = colors.HexColor("#213128")
MUTED = colors.HexColor("#65736A")


def find_font(bold=False):
    candidates = [
        Path("C:/Windows/Fonts/arialbd.ttf" if bold else "C:/Windows/Fonts/arial.ttf"),
        Path("C:/Windows/Fonts/calibrib.ttf" if bold else "C:/Windows/Fonts/calibri.ttf"),
    ]
    for path in candidates:
        if path.exists():
            return path
    raise FileNotFoundError("Не найден шрифт с поддержкой кириллицы")


def register_fonts():
    pdfmetrics.registerFont(TTFont("Eco", str(find_font(False))))
    pdfmetrics.registerFont(TTFont("EcoBold", str(find_font(True))))


def money(value):
    return f"{int(round(value)):,}".replace(",", " ") + " ₽"


def paragraph(c, text, x, y, width, style):
    item = Paragraph(text, style)
    _, height = item.wrap(width, 100 * mm)
    item.drawOn(c, x, y - height)
    return height


def generate(data, output):
    register_fonts()
    page_w, page_h = landscape(A4)
    c = canvas.Canvas(str(output), pagesize=(page_w, page_h))
    title_style = ParagraphStyle(
        "title", fontName="EcoBold", fontSize=23, leading=27, textColor=GREEN
    )
    body_style = ParagraphStyle(
        "body", fontName="Eco", fontSize=10, leading=14, textColor=TEXT
    )
    center_style = ParagraphStyle(
        "center", fontName="Eco", fontSize=10, leading=13, alignment=TA_CENTER, textColor=TEXT
    )

    c.setFillColor(colors.white)
    c.rect(0, 0, page_w, page_h, fill=1, stroke=0)
    c.setFillColor(GREEN)
    c.rect(0, page_h - 36 * mm, page_w, 36 * mm, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("EcoBold", 16)
    c.drawString(15 * mm, page_h - 17 * mm, "ECO-STORE")
    c.setFont("Eco", 9)
    c.drawString(15 * mm, page_h - 24 * mm, "студия премиального озеленения")
    c.setFont("EcoBold", 22)
    c.drawRightString(page_w - 15 * mm, page_h - 18 * mm, "КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ")

    client = data.get("client_name") or "клиента"
    size = f"{data['input']['width_cm']:g}×{data['input']['height_cm']:g} см"
    title_height = paragraph(
        c,
        f"Панно из стабилизированного мха для {client}",
        15 * mm,
        page_h - 49 * mm,
        180 * mm,
        title_style,
    )
    paragraph(
        c,
        f"Размер {size}. Ниже три варианта наполнения в одинаковой комплектации. "
        "Можно выбрать подходящий по фактуре и бюджету.",
        15 * mm,
        page_h - 51 * mm - title_height,
        220 * mm,
        body_style,
    )

    variants = data["variants"]
    gap = 6 * mm
    card_w = (page_w - 30 * mm - 2 * gap) / 3
    card_y = 22 * mm
    card_h = 105 * mm
    descriptions = {
        "standard": "Спокойный фактурный вариант с мягкой стоимостью.",
        "plus": "Баланс объёма, выразительности и стоимости.",
        "premium": "Максимально объёмная и насыщенная композиция.",
    }
    for i, variant in enumerate(variants):
        x = 15 * mm + i * (card_w + gap)
        c.setFillColor(LIGHT if variant["key"] != "plus" else colors.HexColor("#E0F2E4"))
        c.roundRect(x, card_y, card_w, card_h, 5 * mm, fill=1, stroke=0)
        if variant["key"] == "plus":
            c.setFillColor(ACCENT)
            c.roundRect(x + 8 * mm, card_y + card_h - 13 * mm, 29 * mm, 7 * mm, 3 * mm, fill=1, stroke=0)
            c.setFillColor(colors.white)
            c.setFont("EcoBold", 7)
            c.drawCentredString(x + 22.5 * mm, card_y + card_h - 10.5 * mm, "РЕКОМЕНДУЕМ")
        c.setFillColor(GREEN)
        c.setFont("EcoBold", 17)
        c.drawString(x + 8 * mm, card_y + card_h - 26 * mm, variant["name"])
        c.setFont("EcoBold", 23)
        c.drawString(x + 8 * mm, card_y + card_h - 41 * mm, money(variant["total"]))
        paragraph(
            c,
            descriptions[variant["key"]],
            x + 8 * mm,
            card_y + card_h - 50 * mm,
            card_w - 16 * mm,
            center_style,
        )
        rows = [
            ("Композиция", variant["base"] + variant["plants"]),
            ("Рама", variant["frame"]),
            ("Подсветка", variant["lighting"]),
            ("Дополнения", variant["addons"]),
        ]
        row_y = card_y + 35 * mm
        c.setFont("Eco", 8.5)
        for label, value in rows:
            if not value:
                continue
            c.setFillColor(MUTED)
            c.drawString(x + 8 * mm, row_y, label)
            c.setFillColor(TEXT)
            c.drawRightString(x + card_w - 8 * mm, row_y, money(value))
            row_y -= 7 * mm
        c.setStrokeColor(colors.HexColor("#B9D5BF"))
        c.line(x + 8 * mm, card_y + 20 * mm, x + card_w - 8 * mm, card_y + 20 * mm)
        c.setFillColor(GREEN)
        c.setFont("EcoBold", 9)
        c.drawString(x + 8 * mm, card_y + 11 * mm, "Аванс 20%")
        c.drawRightString(x + card_w - 8 * mm, card_y + 11 * mm, money(variant["deposit"]))

    c.setFillColor(MUTED)
    c.setFont("Eco", 7.5)
    c.drawString(15 * mm, 10 * mm, "Предварительный расчёт. Итоговая стоимость фиксируется после подтверждения комплектации.")
    c.showPage()

    c.setFillColor(colors.white)
    c.rect(0, 0, page_w, page_h, fill=1, stroke=0)
    c.setFillColor(GREEN)
    c.rect(0, page_h - 29 * mm, page_w, 29 * mm, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("EcoBold", 18)
    c.drawString(15 * mm, page_h - 18 * mm, "Что входит в предложение")
    blocks = [
        ("Материал", "Натуральный стабилизированный мох собственного производства из Татарстана."),
        ("Гарантия", "5 лет на сохранность цвета и текстуры ягеля."),
        ("Оплата", "20% для запуска заказа, остаток после готовности и фото или видео перед отправкой."),
        ("Доставка", "Надёжная упаковка и отправка по России. Доставка оплачивается отдельно при получении."),
        ("Согласование", "Перед изготовлением подтверждаем выбранный вариант, размер, раму, подсветку и детали."),
        ("Следующий шаг", "Напишите менеджеру, какой вариант вам ближе: Стандарт, Стандарт+ или Премиум."),
    ]
    block_w = (page_w - 36 * mm) / 2
    block_h = 43 * mm
    for i, (heading, text) in enumerate(blocks):
        col, row = i % 2, i // 2
        x = 15 * mm + col * (block_w + 6 * mm)
        y = page_h - 43 * mm - row * (block_h + 6 * mm)
        c.setFillColor(LIGHT)
        c.roundRect(x, y - block_h, block_w, block_h, 4 * mm, fill=1, stroke=0)
        c.setFillColor(GREEN)
        c.setFont("EcoBold", 13)
        c.drawString(x + 7 * mm, y - 12 * mm, heading)
        paragraph(c, text, x + 7 * mm, y - 19 * mm, block_w - 14 * mm, body_style)
    c.setFillColor(GREEN)
    c.setFont("EcoBold", 11)
    c.drawString(15 * mm, 10 * mm, "Eco-Store  •  Казань  •  отправка по всей России")
    c.save()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    data = json.loads(Path(args.input).read_text(encoding="utf-8"))
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    generate(data, output)


if __name__ == "__main__":
    main()
