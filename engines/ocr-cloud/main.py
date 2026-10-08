"""Google Cloud Vision engine container implementing the OCR contract (spec 3.2, see server.py).

Optional and off by default (NFR-09): receipts are sent to Google. The image
Vision receives is the same preprocessed page the local engines read, so boxes
are in preprocessed-image pixels and results can be compared fairly.

The API key comes from GOOGLE_VISION_API_KEY (compose reads it from ocr/.env)
and is sent in the x-goog-api-key header, so it never appears in a URL, a log
or an error message.
"""

import base64
import os

import cv2
import httpx
import numpy as np

from server import Engine, create_app

ENDPOINT = "https://vision.googleapis.com/v1/images:annotate"
FEATURE = "DOCUMENT_TEXT_DETECTION"
VERSION = f"google-vision v1 {FEATURE}"
LINE_ENDS = {"EOL_SURE_SPACE", "LINE_BREAK"}
# A full-size page is 3-4 MB as base64 and timed out on a slow upload link (10-30 KB/s). The page is sent
# as grayscale JPEG with the long side at most MAX_SIDE (~200 KB for a receipt photo); the boxes are scaled
# back to the preprocessed image.
MAX_SIDE = int(os.environ.get("VISION_MAX_SIDE", "1600"))
TIMEOUT = float(os.environ.get("VISION_TIMEOUT", "120"))
SPACES = {"SPACE", "SURE_SPACE"}

# The shared contract tests call the real engine; without a key they would fail, with one they cost units.
SKIP_LIVE_TESTS = None if os.environ.get("GOOGLE_VISION_API_KEY") else "GOOGLE_VISION_API_KEY is not set"


class VisionError(RuntimeError):
    pass


def load() -> httpx.Client:
    key = os.environ.get("GOOGLE_VISION_API_KEY", "").strip()
    if not key:
        raise RuntimeError("GOOGLE_VISION_API_KEY is not set")
    return httpx.Client(headers={"x-goog-api-key": key}, timeout=TIMEOUT)


def recognize(client: httpx.Client, image: np.ndarray):
    scale = min(1.0, MAX_SIDE / max(image.shape[:2]))
    sent = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA) if scale < 1.0 else image
    gray = sent if sent.ndim == 2 else cv2.cvtColor(sent, cv2.COLOR_BGR2GRAY)
    ok, jpeg = cv2.imencode(".jpg", gray, [cv2.IMWRITE_JPEG_QUALITY, 80])
    body = {"requests": [{
        "image": {"content": base64.b64encode(jpeg.tobytes()).decode()},
        "features": [{"type": FEATURE}],
        "imageContext": {"languageHints": ["ja", "en"]},
    }]}
    try:
        response = client.post(ENDPOINT, json=body)
    except httpx.HTTPError as exc:
        raise VisionError(f"Vision API unreachable: {type(exc).__name__}") from None

    data = response.json() if response.headers.get("content-type", "").startswith("application/json") else {}
    error = data.get("error") or ((data.get("responses") or [{}])[0].get("error"))
    if response.status_code >= 400 or error:
        error = error or {}
        raise VisionError(f"Vision API {response.status_code} {error.get('status', '')}: {error.get('message', response.reason_phrase)}".strip())

    annotation = (data.get("responses") or [{}])[0].get("fullTextAnnotation") or {}
    return [(text, score, [[x / scale, y / scale] for x, y in box]) for text, score, box in to_raw_lines(annotation)]


def to_raw_lines(annotation: dict):
    """fullTextAnnotation → (text, confidence, box) per printed line: words joined, a line ends at a line break."""
    for page in annotation.get("pages", []):
        for block in page.get("blocks", []):
            for paragraph in block.get("paragraphs", []):
                text, points, scores = "", [], []
                for word in paragraph.get("words", []):
                    points += [(v.get("x", 0), v.get("y", 0)) for v in word.get("boundingBox", {}).get("vertices", [])]
                    scores.append(float(word.get("confidence", 0.0)))
                    for symbol in word.get("symbols", []):
                        text += symbol.get("text", "")
                        brk = symbol.get("property", {}).get("detectedBreak", {}).get("type")
                        if brk in SPACES:
                            text += " "
                        elif brk in LINE_ENDS:
                            yield from _line(text, points, scores)
                            text, points, scores = "", [], []
                yield from _line(text, points, scores)


def _line(text: str, points: list, scores: list):
    text = text.strip()
    if text and points:
        xs, ys = [x for x, _ in points], [y for _, y in points]
        box = [[min(xs), min(ys)], [max(xs), min(ys)], [max(xs), max(ys)], [min(xs), max(ys)]]
        yield text, sum(scores) / len(scores), box


app = create_app(Engine(name="cloud", version=VERSION, load=load, recognize=recognize))
