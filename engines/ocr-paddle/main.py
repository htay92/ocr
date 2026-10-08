"""PaddleOCR engine container (PP-OCRv5) implementing the OCR contract (spec 3.2, see server.py)."""

import os
from importlib.metadata import version

from server import Engine, create_app

OCR_VERSION = "PP-OCRv5"
VERSION = f"{version('paddleocr')} / {OCR_VERSION}"
DET_LIMIT = int(os.environ.get("PADDLE_DET_LIMIT", "960"))


def load():
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


def recognize(ocr, image):
    for res in ocr.predict(image):
        yield from zip(res["rec_texts"], res["rec_scores"], res["rec_polys"])


app = create_app(Engine(name="paddle", version=VERSION, load=load, recognize=recognize))
