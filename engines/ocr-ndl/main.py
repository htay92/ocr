"""NDLOCR-Lite engine container implementing the OCR contract (spec 3.2, see server.py).

NDLOCR-Lite (National Diet Library, CC BY 4.0) runs ONNX models on the CPU:
DEIM layout/line detection, PARSeq line recognition (three models of growing
width, picked by the predicted character count) and reading order. Its own
pipeline function is used as is; the models are loaded once at start-up.

Confidence: NDLOCR-Lite reports only the detection score per line, so the
recognizer is wrapped to also keep the mean probability of the characters it
read; a line's confidence is that mean (the detection score when the line could
not be matched), so V-09 (low confidence) means the same as for PaddleOCR.
"""

import os
import sys
import tempfile
import threading
from types import SimpleNamespace

import cv2
import numpy as np

from server import Engine, create_app

NDL_DIR = os.environ.get("NDLOCR_DIR", "/opt/ndlocr")
NDL_COMMIT = os.environ.get("NDLOCR_COMMIT", "dev")
VERSION = f"ndlocr-lite {NDL_COMMIT[:7]}"

sys.path.insert(0, NDL_DIR)


class ScoredRecognizer:
    """A PARSeq recognizer that also records the mean character probability of each line image it reads."""

    def __init__(self, inner, scores: dict):
        self.inner = inner
        self.scores = scores
        self._lock = threading.Lock()

    def read(self, img: np.ndarray) -> str:
        if img is None or img.size == 0:
            return ""
        inner = self.inner
        logits = inner.session.run(inner.output_names, {inner.input_names[0]: inner.preprocess(img)})[0][0]
        probs = softmax(logits)
        indices = np.argmax(probs, axis=1)
        stop = np.where(indices == 0)[0]
        end = stop[0] if stop.size > 0 else len(indices)
        text = "".join(inner.charlist[i - 1] for i in indices[:end].tolist())
        score = float(probs[np.arange(end), indices[:end]].mean()) if end > 0 else 0.0
        with self._lock:
            self.scores[image_key(img)] = score  # the last (widest) model that read the line wins
        return text


def softmax(values: np.ndarray) -> np.ndarray:
    if np.all(values >= 0) and np.allclose(values.sum(axis=1), 1.0, atol=1e-3):
        return values  # already probabilities
    shifted = np.exp(values - values.max(axis=1, keepdims=True))
    return shifted / shifted.sum(axis=1, keepdims=True)


def image_key(img: np.ndarray) -> tuple:
    return img.__array_interface__["data"][0], img.shape


def load():
    import ocr as ndl

    model = os.path.join(NDL_DIR, "model")
    config = os.path.join(NDL_DIR, "config")
    args = SimpleNamespace(
        det_weights=os.path.join(model, "deim-s-1024x1024.onnx"),
        det_classes=os.path.join(config, "ndl.yaml"),
        det_score_threshold=0.2,
        det_conf_threshold=0.25,
        det_iou_threshold=0.2,
        rec_weights=os.path.join(model, "parseq-ndl-24x768-100-tiny-153epoch-tegaki3-r8data-202604.onnx"),
        rec_weights30=os.path.join(model, "parseq-ndl-24x256-30-tiny-189epoch-tegaki3-r8data-202604.onnx"),
        rec_weights50=os.path.join(model, "parseq-ndl-24x384-50-tiny-300epoch-tegaki3-r8data-202604.onnx"),
        rec_classes=os.path.join(config, "NDLmoji.yaml"),
        device="cpu",
        enable_tcy=False,
    )
    scores: dict = {}
    return SimpleNamespace(
        ndl=ndl,
        scores=scores,
        detector=ndl.get_detector(args),
        recognizer30=ScoredRecognizer(ndl.get_recognizer(args, weights_path=args.rec_weights30), scores),
        recognizer50=ScoredRecognizer(ndl.get_recognizer(args, weights_path=args.rec_weights50), scores),
        recognizer100=ScoredRecognizer(ndl.get_recognizer(args), scores),
    )


def recognize(engine, image: np.ndarray):
    rgb = np.ascontiguousarray(cv2.cvtColor(image, cv2.COLOR_BGR2RGB))  # NDLOCR-Lite reads RGB (PIL) images
    engine.scores.clear()
    with tempfile.TemporaryDirectory() as out:
        result = engine.ndl._run_ocr_on_image_array(
            engine.detector, engine.recognizer30, engine.recognizer50, engine.recognizer100,
            "page.jpg", rgb, out, save_viz=False,
        )
    for line in result["json_lines"]:
        xs = [x for x, _ in line["boundingBox"]]
        ys = [y for _, y in line["boundingBox"]]
        x1, y1, x2, y2 = min(xs), min(ys), max(xs), max(ys)
        crop = rgb[y1:y2, x1:x2, :]  # the same slice NDLOCR-Lite gave the recognizer
        score = engine.scores.get(image_key(crop), float(line["confidence"]))
        yield line["text"], score, [[x1, y1], [x2, y1], [x2, y2], [x1, y2]]


app = create_app(Engine(name="ndl", version=VERSION, load=load, recognize=recognize))
