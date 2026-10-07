"""PaddleOCR engine container implementing the OCR contract (spec 3.2).

POST /ocr     multipart: file, preprocess=true, lang=ja, return_image=false
GET  /health  {"engine": "paddle", "version": "...", "ready": bool}

Errors always have the body {"code": "...", "message": "..."}:
400 invalid_image, 413 too_large, 500 engine_error, 503 not_ready.
"""

import base64
import os
import threading
import time
from contextlib import asynccontextmanager
from importlib.metadata import version

import cv2
import numpy as np
from fastapi import FastAPI, File, Form, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from preprocess import ImageError, order_points, prepare

ENGINE = "paddle"
OCR_VERSION = "PP-OCRv5"
VERSION = f"{version('paddleocr')} / {OCR_VERSION}"
DET_LIMIT = int(os.environ.get("PADDLE_DET_LIMIT", "960"))

state = {"ocr": None, "error": None}
# One prediction at a time: the predictor is not thread-safe, and the CPU is shared anyway.
predict_lock = threading.Lock()


def build_engine():
    from paddleocr import PaddleOCR

    # oneDNN is off: Paddle 3.x's oneDNN executor fails on the PP-OCR models.
    # Detection runs on a copy capped at DET_LIMIT px; recognition still reads
    # full-size crops (104 s -> 48 s on a phone photo under amd64 emulation).
    # The japan model also reads English, so lang is only a hint for now.
    return PaddleOCR(
        lang="japan",
        ocr_version=OCR_VERSION,
        use_doc_orientation_classify=False,
        use_doc_unwarping=False,
        use_textline_orientation=True,
        enable_mkldnn=False,
        text_det_limit_side_len=DET_LIMIT,
        text_det_limit_type="max",
    )


def load_model():
    try:
        state["ocr"] = build_engine()
    except Exception as exc:  # reported by /health, never crashes the server
        state["error"] = str(exc)


@asynccontextmanager
async def lifespan(_app: FastAPI):
    threading.Thread(target=load_model, daemon=True).start()
    yield


app = FastAPI(title="ocr-paddle", lifespan=lifespan)


def error(status: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"code": code, "message": message})


@app.exception_handler(ImageError)
def image_error(_request: Request, exc: ImageError) -> JSONResponse:
    return error(exc.status, exc.code, exc.message)


@app.exception_handler(RequestValidationError)
def validation_error(_request: Request, exc: RequestValidationError) -> JSONResponse:
    return error(400, "invalid_image", f"Invalid request: {exc.errors()[0]['msg']}")


@app.get("/health")
def health():
    body = {"engine": ENGINE, "version": VERSION, "ready": state["ocr"] is not None}
    if body["ready"]:
        return body
    message = state["error"] or "Model is still loading"
    return JSONResponse(status_code=503, content={**body, "code": "not_ready", "message": message})


@app.post("/ocr")
def ocr(
    file: UploadFile = File(...),
    preprocess: bool = Form(True),
    lang: str = Form("ja"),
    return_image: bool = Form(False),
):
    started = time.perf_counter()
    if state["ocr"] is None:
        return error(503, "not_ready", state["error"] or "Model is still loading")

    prepared = prepare(file.file.read(), preprocess=preprocess)

    try:
        with predict_lock:
            results = state["ocr"].predict(prepared.image)
    except Exception as exc:
        return error(500, "engine_error", f"{type(exc).__name__}: {exc}")

    return {
        "engine": ENGINE,
        "engine_version": VERSION,
        "elapsed_ms": round((time.perf_counter() - started) * 1000),
        "image": {"width": prepared.width, "height": prepared.height, "rotation": prepared.rotation},
        "lines": to_lines(results, prepared.width, prepared.height),
        "preprocessed_image": encode(prepared.image) if return_image else None,
    }


def to_lines(results, width: int, height: int) -> list[dict]:
    lines = []
    for res in results:
        for text, score, poly in zip(res["rec_texts"], res["rec_scores"], res["rec_polys"]):
            if not text.strip():
                continue
            corners = order_points(poly)
            corners[:, 0] = corners[:, 0].clip(0, width - 1)
            corners[:, 1] = corners[:, 1].clip(0, height - 1)
            lines.append({
                "text": text,
                "confidence": round(float(score), 3),
                "bbox": [[int(round(x)), int(round(y))] for x, y in corners],
            })
    return reading_order(lines)


def reading_order(lines: list[dict]) -> list[dict]:
    """Top to bottom; lines whose centres are within half a line height share a row, left to right."""
    if not lines:
        return lines

    def centre_y(line):
        return sum(y for _, y in line["bbox"]) / 4

    def line_height(line):
        ys = [y for _, y in line["bbox"]]
        return max(ys) - min(ys)

    tolerance = float(np.median([line_height(line) for line in lines])) / 2
    rows: list[list[dict]] = []
    for line in sorted(lines, key=centre_y):
        if rows and abs(centre_y(line) - centre_y(rows[-1][0])) <= tolerance:
            rows[-1].append(line)
        else:
            rows.append([line])
    return [line for row in rows for line in sorted(row, key=lambda l: l["bbox"][0][0])]


def encode(image: np.ndarray) -> str:
    ok, buffer = cv2.imencode(".jpg", image, [cv2.IMWRITE_JPEG_QUALITY, 85])
    return base64.b64encode(buffer.tobytes()).decode()
