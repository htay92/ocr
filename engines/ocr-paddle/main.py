"""PaddleOCR engine container (spec 3.2).

GET /health -> {"engine": "paddle", "version": "...", "ready": bool}
               503 {"code": "not_ready", ...} while the model is loading.
POST /ocr is added in Milestone 2.
"""

import os
import threading
from contextlib import asynccontextmanager
from importlib.metadata import version

from fastapi import FastAPI
from fastapi.responses import JSONResponse

ENGINE = "paddle"
OCR_VERSION = "PP-OCRv5"
VERSION = f"{version('paddleocr')} / {OCR_VERSION}"
DET_LIMIT = int(os.environ.get("PADDLE_DET_LIMIT", "960"))

state = {"ocr": None, "error": None}


def build_engine():
    from paddleocr import PaddleOCR

    # oneDNN is off: Paddle 3.x's oneDNN executor fails on the PP-OCR models.
    # Detection runs on a copy capped at DET_LIMIT px; recognition still reads
    # full-size crops (104 s -> 48 s on a phone photo under amd64 emulation).
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


@app.get("/health")
def health():
    body = {"engine": ENGINE, "version": VERSION, "ready": state["ocr"] is not None}
    if body["ready"]:
        return body
    message = state["error"] or "Model is still loading"
    return JSONResponse(status_code=503, content={**body, "code": "not_ready", "message": message})
