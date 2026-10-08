"""Image loading and preprocessing for OCR engines (spec 3.6).

Steps, in order:
1. Read EXIF orientation and rotate; HEIC is decoded via pillow-heif.
   A single-page PDF is rendered at 300 dpi first.
2. Detect the document outline (largest four-sided contour) and apply a
   perspective transform. Skipped when there is no clear outline (scanner input).
3. Deskew by text angle, up to +-15 degrees.
4. Grayscale and CLAHE contrast enhancement for faded thermal paper.
5. Resize so the short side is at least 720 px and the long side at most 4,000 px.

Only step 1 runs when preprocessing is switched off.
"""

from dataclasses import dataclass
from io import BytesIO

import cv2
import numpy as np
import pypdfium2 as pdfium
from PIL import Image, ImageOps, UnidentifiedImageError
from pillow_heif import register_heif_opener

register_heif_opener()

MAX_BYTES = 20 * 1024 * 1024
MAX_SIDE = 8000
PDF_DPI = 300
MIN_SHORT_SIDE = 720
MAX_LONG_SIDE = 4000
MAX_SKEW = 15.0

# EXIF Orientation tag -> clockwise rotation applied to make the image upright.
EXIF_ROTATION = {3: 180, 4: 180, 5: 90, 6: 90, 7: 270, 8: 270}


class ImageError(Exception):
    """A contract error (spec 3.2): HTTP status, code and message."""

    def __init__(self, status: int, code: str, message: str):
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message


@dataclass
class Prepared:
    image: np.ndarray  # BGR, ready for the OCR engine
    rotation: int

    @property
    def width(self) -> int:
        return int(self.image.shape[1])

    @property
    def height(self) -> int:
        return int(self.image.shape[0])


def prepare(data: bytes, preprocess: bool = True) -> Prepared:
    image, rotation = load(data)
    if preprocess:
        image = crop_document(image)
        image = deskew(image)
        image = enhance(image)
        image = resize(image)
    return Prepared(image=image, rotation=rotation)


def load(data: bytes) -> tuple[np.ndarray, int]:
    if len(data) > MAX_BYTES:
        raise ImageError(413, "too_large", "File is over 20 MB")
    if not data:
        raise ImageError(400, "invalid_image", "Empty file")

    if data.startswith(b"%PDF"):
        pil, rotation = _render_pdf(data), 0
    else:
        try:
            pil = Image.open(BytesIO(data))
            _check_size(*pil.size)
            rotation = EXIF_ROTATION.get(pil.getexif().get(0x0112, 1), 0)
            pil = ImageOps.exif_transpose(pil)
            pil.load()
        except ImageError:
            raise
        except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError) as exc:
            raise ImageError(400, "invalid_image", f"Unreadable image: {exc}") from exc

    _check_size(*pil.size)
    return cv2.cvtColor(np.asarray(_flatten(pil)), cv2.COLOR_RGB2BGR), rotation


def _flatten(pil: Image.Image) -> Image.Image:
    """RGB on white: a transparent background would otherwise turn black."""
    if pil.mode == "P" and "transparency" in pil.info or pil.mode in ("RGBA", "LA", "PA"):
        rgba = pil.convert("RGBA")
        white = Image.new("RGBA", rgba.size, "white")
        return Image.alpha_composite(white, rgba).convert("RGB")
    return pil.convert("RGB")


def _render_pdf(data: bytes) -> Image.Image:
    try:
        pdf = pdfium.PdfDocument(data)
    except pdfium.PdfiumError as exc:
        raise ImageError(400, "invalid_image", f"Unreadable PDF: {exc}") from exc
    if len(pdf) != 1:
        raise ImageError(400, "invalid_image", f"PDF has {len(pdf)} pages; send one page per request")
    page = pdf[0]
    width, height = page.get_size()  # points (1/72 inch)
    _check_size(width * PDF_DPI / 72, height * PDF_DPI / 72)
    return page.render(scale=PDF_DPI / 72).to_pil()


def _check_size(width: float, height: float) -> None:
    if max(width, height) > MAX_SIDE:
        raise ImageError(413, "too_large", f"Image side is over {MAX_SIDE} px")


def crop_document(image: np.ndarray) -> np.ndarray:
    """Perspective-correct the page when a clear four-sided outline is found."""
    height, width = image.shape[:2]
    scale = 1000 / max(height, width)
    small = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA) if scale < 1 else image
    scale = min(scale, 1.0)

    gray = cv2.GaussianBlur(cv2.cvtColor(small, cv2.COLOR_BGR2GRAY), (5, 5), 0)
    edges = cv2.dilate(cv2.Canny(gray, 50, 150), np.ones((3, 3), np.uint8))
    contours, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    area = small.shape[0] * small.shape[1]
    for contour in sorted(contours, key=cv2.contourArea, reverse=True)[:5]:
        quad = cv2.approxPolyDP(contour, 0.02 * cv2.arcLength(contour, True), True)
        quad_area = cv2.contourArea(quad)
        # Too small: not the page. Almost the whole frame: a scan, nothing to crop.
        if len(quad) == 4 and 0.15 * area <= quad_area <= 0.95 * area and _on_background(gray, quad):
            return _warp(image, quad.reshape(4, 2) / scale)
    return image


def _on_background(gray: np.ndarray, quad: np.ndarray) -> bool:
    """True when the outline is a page lying on a darker surface.

    A table or frame printed on a scanned page has white paper around it,
    so it must not be mistaken for the page edge.
    """
    mask = np.zeros(gray.shape, np.uint8)
    cv2.fillPoly(mask, [quad.reshape(4, 2).astype(np.int32)], 255)
    inside = cv2.mean(gray, mask=mask)[0]
    outside = cv2.mean(gray, mask=cv2.bitwise_not(mask))[0]
    return inside - outside > 40


def _warp(image: np.ndarray, corners: np.ndarray) -> np.ndarray:
    tl, tr, br, bl = order_points(corners)
    width = int(max(np.linalg.norm(br - bl), np.linalg.norm(tr - tl)))
    height = int(max(np.linalg.norm(tr - br), np.linalg.norm(tl - bl)))
    target = np.array([[0, 0], [width - 1, 0], [width - 1, height - 1], [0, height - 1]], dtype="float32")
    matrix = cv2.getPerspectiveTransform(np.array([tl, tr, br, bl], dtype="float32"), target)
    return cv2.warpPerspective(image, matrix, (width, height), borderMode=cv2.BORDER_REPLICATE)


def order_points(points) -> np.ndarray:
    """Four points ordered clockwise from top-left."""
    points = np.asarray(points, dtype="float32")
    total = points.sum(axis=1)
    diff = np.diff(points, axis=1).ravel()  # y - x
    return np.array([
        points[np.argmin(total)],  # top-left
        points[np.argmin(diff)],   # top-right
        points[np.argmax(total)],  # bottom-right
        points[np.argmax(diff)],   # bottom-left
    ])


def skew_angle(image: np.ndarray) -> float:
    """Text angle in degrees (positive = counter-clockwise), 0 when unclear."""
    gray = image if image.ndim == 2 else cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)[1]
    # Join characters into text-line blobs, then take the median line angle.
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (max(15, gray.shape[1] // 40), 3))
    blobs = cv2.dilate(binary, kernel)
    contours, _ = cv2.findContours(blobs, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    angles = []
    for contour in contours:
        (_, _), (w, h), angle = cv2.minAreaRect(contour)
        if max(w, h) < gray.shape[1] * 0.1 or min(w, h) == 0 or max(w, h) / min(w, h) < 3:
            continue  # not a text line
        if w < h:
            angle -= 90
        angles.append(-angle)
    return float(np.median(angles)) if angles else 0.0


def deskew(image: np.ndarray) -> np.ndarray:
    angle = skew_angle(image)
    if abs(angle) < 0.3 or abs(angle) > MAX_SKEW:
        return image
    height, width = image.shape[:2]
    matrix = cv2.getRotationMatrix2D((width / 2, height / 2), -angle, 1.0)
    return cv2.warpAffine(image, matrix, (width, height), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)


def enhance(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
    return cv2.cvtColor(gray, cv2.COLOR_GRAY2BGR)


def resize(image: np.ndarray) -> np.ndarray:
    height, width = image.shape[:2]
    scale = 1.0
    if min(height, width) < MIN_SHORT_SIDE:
        scale = MIN_SHORT_SIDE / min(height, width)
    if max(height, width) * scale > MAX_LONG_SIDE:
        scale = MAX_LONG_SIDE / max(height, width)
    if scale == 1.0:
        return image
    interpolation = cv2.INTER_CUBIC if scale > 1 else cv2.INTER_AREA
    return cv2.resize(image, None, fx=scale, fy=scale, interpolation=interpolation)
