"""Google Cloud Vision mapping and errors, with a fake Vision API (no call to Google, no units used)."""

import httpx
import pytest
from fastapi.testclient import TestClient

import main

KEY = "AIza-test-key-never-shown"


def symbol(text, brk=None):
    return {"text": text, **({"property": {"detectedBreak": {"type": brk}}} if brk else {})}


def word(text, box, confidence, brk=None):
    symbols = [symbol(c) for c in text[:-1]] + [symbol(text[-1], brk)]
    (x1, y1), (x2, y2) = box
    return {"boundingBox": {"vertices": [{"x": x1, "y": y1}, {"x": x2, "y": y1}, {"x": x2, "y": y2}, {"x": x1, "y": y2}]},
            "confidence": confidence, "symbols": symbols}


VISION = {"responses": [{"fullTextAnnotation": {"pages": [{"blocks": [{"paragraphs": [{"words": [
    word("合計", ((60, 300), (140, 340)), 0.98, "SPACE"),
    word("¥1,280", ((160, 300), (300, 342)), 0.90, "EOL_SURE_SPACE"),
    word("ローソン", ((60, 60), (260, 100)), 0.99, "LINE_BREAK"),
]}]}]}]}}]}


@pytest.fixture
def vision(monkeypatch):
    """The engine with a fake Vision API; returns the list of requests it received."""
    requests = []

    def install(status=200, body=VISION):
        def handler(request: httpx.Request):
            requests.append(request)
            return httpx.Response(status, json=body)

        monkeypatch.setattr(main.app.state, "model", httpx.Client(transport=httpx.MockTransport(handler), headers={"x-goog-api-key": KEY}))
        return requests

    yield install
    main.app.state.model = None


def post(png: bytes):
    return TestClient(main.app).post("/ocr", files={"file": ("r.png", png)})


def test_maps_vision_words_to_contract_lines(vision, receipt_png):
    requests = vision()

    body = post(receipt_png).json()

    assert [line["text"] for line in body["lines"]] == ["ローソン", "合計 ¥1,280"]   # sorted top to bottom
    total = body["lines"][1]
    assert total["bbox"] == [[60, 300], [300, 300], [300, 342], [60, 342]]
    assert total["confidence"] == pytest.approx(0.94)
    assert body["engine"] == "cloud"

    sent = requests[0]
    assert sent.headers["x-goog-api-key"] == KEY and KEY not in str(sent.url)
    assert b"DOCUMENT_TEXT_DETECTION" in sent.content


def test_google_errors_become_engine_errors_without_the_key(vision, receipt_png):
    vision(403, {"error": {"code": 403, "status": "PERMISSION_DENIED", "message": "API key not valid."}})

    response = post(receipt_png)

    assert response.status_code == 500
    assert response.json()["code"] == "engine_error"
    assert "PERMISSION_DENIED" in response.json()["message"]
    assert KEY not in response.text


def test_an_error_inside_the_response_is_an_engine_error(vision, receipt_png):
    vision(200, {"responses": [{"error": {"code": 3, "message": "Bad image data."}}]})

    assert post(receipt_png).json()["message"].endswith("Bad image data.")


def test_no_text_gives_no_lines(vision, receipt_png):
    vision(200, {"responses": [{}]})

    assert post(receipt_png).json()["lines"] == []


def test_not_ready_without_a_key(monkeypatch):
    monkeypatch.delenv("GOOGLE_VISION_API_KEY", raising=False)
    main.app.state.load_model()

    response = TestClient(main.app).get("/health")

    assert response.status_code == 503
    assert response.json()["message"] == "RuntimeError: GOOGLE_VISION_API_KEY is not set"
    main.app.state.error = None


def test_a_large_page_is_sent_scaled_down_and_boxes_are_scaled_back(vision, monkeypatch):
    import base64

    import cv2
    import numpy as np

    monkeypatch.setattr(main, "MAX_SIDE", 1000)
    requests = vision()
    page = np.full((3000, 2000, 3), 255, np.uint8)

    lines = main.recognize(main.app.state.model, page)

    sent = cv2.imdecode(np.frombuffer(base64.b64decode(__import__("json").loads(requests[0].content)["requests"][0]["image"]["content"]), np.uint8), cv2.IMREAD_UNCHANGED)
    assert max(sent.shape[:2]) == 1000 and sent.ndim == 2           # scaled down, grayscale
    assert lines[0][2][0] == [60 * 3, 300 * 3]          # boxes back in the page's pixels
