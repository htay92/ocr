"""The OCR container contract (spec 3.2), shared by every engine container.

POST /ocr     multipart: file, preprocess=true, lang=ja, return_image=false
GET  /health  {"engine": "...", "version": "...", "ready": bool}

Errors always have the body {"code": "...", "message": "..."}:
400 invalid_image, 413 too_large, 500 engine_error, 503 not_ready.

An engine only supplies how to load its model and how to read one prepared
(BGR) image; preprocessing, line order, boxes and errors are the same for all
engines, so their results can be compared fairly (spec 3.6).
"""

import base64
import threading
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass
from typing import Any, Callable, Iterable

import cv2
import numpy as np
from fastapi import FastAPI, File, Form, Request, UploadFile
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from preprocess import ImageError, order_points, prepare

# text, confidence 0..1, polygon points (any order)
RawLine = tuple[str, float, Any]


@dataclass
class Engine:
    name: str
    version: str
    load: Callable[[], Any]
    recognize: Callable[[Any, np.ndarray], Iterable[RawLine]]


def create_app(engine: Engine) -> FastAPI:
    # One recognition at a time: the models are not thread-safe, and the CPU is shared anyway.
    lock = threading.Lock()

    def load_model() -> None:
        try:
            app.state.model = engine.load()
        except Exception as exc:  # reported by /health, never crashes the server
            app.state.error = f"{type(exc).__name__}: {exc}"

    @asynccontextmanager
    async def lifespan(_app: FastAPI):
        threading.Thread(target=load_model, daemon=True).start()
        yield

    app = FastAPI(title=f"ocr-{engine.name}", lifespan=lifespan)
    app.state.engine = engine
    app.state.model = None
    app.state.error = None
    app.state.load_model = load_model

    @app.exception_handler(ImageError)
    def image_error(_request: Request, exc: ImageError) -> JSONResponse:
        return error(exc.status, exc.code, exc.message)

    @app.exception_handler(RequestValidationError)
    def validation_error(_request: Request, exc: RequestValidationError) -> JSONResponse:
        return error(400, "invalid_image", f"Invalid request: {exc.errors()[0]['msg']}")

    @app.get("/health")
    def health():
        body = {"engine": engine.name, "version": engine.version, "ready": app.state.model is not None}
        if body["ready"]:
            return body
        message = app.state.error or "Model is still loading"
        return JSONResponse(status_code=503, content={**body, "code": "not_ready", "message": message})

    @app.post("/ocr")
    def ocr(
        file: UploadFile = File(...),
        preprocess: bool = Form(True),
        lang: str = Form("ja"),
        return_image: bool = Form(False),
    ):
        started = time.perf_counter()
        model = app.state.model
        if model is None:
            return error(503, "not_ready", app.state.error or "Model is still loading")

        prepared = prepare(file.file.read(), preprocess=preprocess)

        try:
            with lock:
                raw = list(engine.recognize(model, prepared.image))
        except Exception as exc:
            return error(500, "engine_error", f"{type(exc).__name__}: {exc}")

        return {
            "engine": engine.name,
            "engine_version": engine.version,
            "elapsed_ms": round((time.perf_counter() - started) * 1000),
            "image": {"width": prepared.width, "height": prepared.height, "rotation": prepared.rotation},
            "lines": to_lines(raw, prepared.width, prepared.height),
            "preprocessed_image": encode(prepared.image) if return_image else None,
        }

    return app


def error(status: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"code": code, "message": message})


def to_lines(raw: Iterable[RawLine], width: int, height: int) -> list[dict]:
    """Contract lines: four points clockwise from top-left, inside the image, in reading order."""
    lines = []
    for text, score, points in raw:
        if not text.strip():
            continue
        corners = order_points(points)
        corners[:, 0] = corners[:, 0].clip(0, width - 1)
        corners[:, 1] = corners[:, 1].clip(0, height - 1)
        lines.append({
            "text": text,
            "confidence": round(min(max(float(score), 0.0), 1.0), 3),
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
