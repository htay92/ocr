"""NDLOCR-Lite-specific checks; the contract tests are shared (engines/common/tests)."""

import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw, ImageFont

import main
from conftest import FONT, to_bytes


def test_version_names_the_pinned_commit():
    assert main.app.state.engine.name == "ndl"
    assert main.VERSION.startswith("ndlocr-lite ")


def test_softmax_keeps_probabilities_and_normalises_logits():
    probs = np.array([[0.7, 0.2, 0.1]])
    assert main.softmax(probs) is probs
    assert main.softmax(np.array([[2.0, 1.0, 0.0]])).sum() == pytest.approx(1.0)


@pytest.fixture(scope="module")
def client():
    if main.app.state.model is None:
        main.app.state.load_model()
    assert main.app.state.error is None, main.app.state.error
    return TestClient(main.app)


def test_confidence_is_the_recognition_probability(client):
    image = Image.new("RGB", (900, 200), "white")
    ImageDraw.Draw(image).text((60, 60), "合計 ¥1,280", fill="black", font=ImageFont.truetype(FONT, 60))

    lines = client.post("/ocr", files={"file": ("x.png", to_bytes(image))}).json()["lines"]

    assert any("合計" in line["text"] for line in lines)
    assert all(0.5 < line["confidence"] <= 1.0 for line in lines)


def test_reads_a_vertical_line_as_one_line(client):
    image = Image.new("RGB", (300, 900), "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.truetype(FONT, 60)
    for i, char in enumerate("領収書在中"):
        draw.text((120, 80 + i * 140), char, fill="black", font=font)

    lines = client.post("/ocr", files={"file": ("v.png", to_bytes(image))}, data={"preprocess": "false"}).json()["lines"]

    assert len(lines) == 1
    assert lines[0]["text"] == "領収書在中"
