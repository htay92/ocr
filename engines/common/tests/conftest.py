import json
from io import BytesIO
from pathlib import Path

import jsonschema
import pytest
from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf"
SCHEMA = json.loads((Path(__file__).parent / "contract_schema.json").read_text())


def assert_schema(instance, definition: str) -> None:
    jsonschema.validate(instance, {**SCHEMA, "$ref": f"#/$defs/{definition}"})


RECEIPT_LINES = [
    "ローソン 敷津西二丁目店",
    "2026年10月7日(水) 12:34",
    "おにぎり 鮭        ¥150",
    "お茶              ¥130",
    "合計            ¥1,280",
    "登録番号 T1234567890123",
]


def receipt_image(lines=RECEIPT_LINES, size=(900, 700)) -> Image.Image:
    """A clean printed receipt, rendered with a Japanese font."""
    image = Image.new("RGB", size, "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.truetype(FONT, 40)
    for i, text in enumerate(lines):
        draw.text((60, 60 + i * 95), text, fill="black", font=font)
    return image


def to_bytes(image: Image.Image, fmt: str = "PNG", **kwargs) -> bytes:
    buffer = BytesIO()
    image.save(buffer, fmt, **kwargs)
    return buffer.getvalue()


@pytest.fixture
def receipt_png() -> bytes:
    return to_bytes(receipt_image())
