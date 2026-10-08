"""POST /ocr against the spec 3.2 contract, with the engine's real model (the same tests for every engine)."""

import base64

import pytest
from fastapi.testclient import TestClient
from PIL import Image

import main
from conftest import assert_schema, receipt_image, to_bytes


ENGINE = main.app.state.engine


@pytest.fixture(scope="module")
def client():
    if getattr(main, "SKIP_LIVE_TESTS", None):  # e.g. a cloud engine without its API key
        pytest.skip(main.SKIP_LIVE_TESTS)
    if main.app.state.model is None:
        main.app.state.load_model()
    assert main.app.state.error is None, main.app.state.error
    return TestClient(main.app)


def post(client, data: bytes, filename="receipt.png", **form):
    return client.post("/ocr", files={"file": (filename, data)}, data={k: str(v).lower() for k, v in form.items()})


def test_reads_a_receipt_in_the_contract_shape(client, receipt_png):
    response = post(client, receipt_png)

    assert response.status_code == 200
    body = response.json()
    assert_schema(body, "response")
    assert body["engine"] == ENGINE.name
    assert body["engine_version"] == ENGINE.version
    assert body["preprocessed_image"] is None

    text = "\n".join(line["text"] for line in body["lines"])
    assert "合計" in text
    assert "1,280" in text
    assert "T1234567890123" in text


def test_lines_are_in_reading_order_with_boxes_inside_the_image(client, receipt_png):
    body = post(client, receipt_png).json()
    width, height = body["image"]["width"], body["image"]["height"]

    tops = [line["bbox"][0][1] for line in body["lines"]]
    assert tops == sorted(tops) or all(b >= a - 20 for a, b in zip(tops, tops[1:]))
    assert body["lines"][0]["text"].startswith("ローソン")
    for line in body["lines"]:
        (tlx, tly), (trx, try_), (brx, bry), (blx, bly) = line["bbox"]
        assert tlx < trx and tly < bly  # clockwise from top-left
        assert all(0 <= x < width and 0 <= y < height for x, y in line["bbox"])


def test_returns_the_preprocessed_image_when_asked(client, receipt_png):
    body = post(client, receipt_png, return_image=True).json()

    assert base64.b64decode(body["preprocessed_image"])[:2] == b"\xff\xd8"  # JPEG


def test_reads_a_single_page_pdf(client):
    response = post(client, to_bytes(receipt_image(), "PDF", resolution=150), filename="receipt.pdf")

    assert response.status_code == 200
    assert any("合計" in line["text"] for line in response.json()["lines"])


@pytest.mark.parametrize("data, status, code", [
    (b"not an image", 400, "invalid_image"),
    (to_bytes(Image.new("L", (8001, 10)), "PNG"), 413, "too_large"),
    (to_bytes(receipt_image(), "PDF", save_all=True, append_images=[receipt_image()]), 400, "invalid_image"),
])
def test_bad_files_get_contract_errors(client, data, status, code):
    response = post(client, data)

    assert response.status_code == status
    assert_schema(response.json(), "error")
    assert response.json()["code"] == code


def test_missing_file_is_an_invalid_image(client):
    response = client.post("/ocr", data={"lang": "ja"})

    assert response.status_code == 400
    assert response.json()["code"] == "invalid_image"


def test_engine_error_is_reported(client, receipt_png, monkeypatch):
    def broken(_model, _image):
        raise RuntimeError("boom")

    monkeypatch.setattr(ENGINE, "recognize", broken)
    response = post(client, receipt_png)

    assert response.status_code == 500
    assert response.json() == {"code": "engine_error", "message": "RuntimeError: boom"}


def test_not_ready_while_the_model_loads(client, receipt_png, monkeypatch):
    monkeypatch.setattr(main.app.state, "model", None)
    response = post(client, receipt_png)

    assert response.status_code == 503
    assert response.json()["code"] == "not_ready"
