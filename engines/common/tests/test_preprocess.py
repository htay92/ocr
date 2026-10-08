"""Spec 3.6 preprocessing steps, without the OCR model."""

import cv2
import numpy as np
import pytest
from PIL import Image

from conftest import receipt_image, to_bytes
from preprocess import ImageError, crop_document, deskew, load, order_points, prepare, resize, skew_angle


def photo_of(page: Image.Image, angle: float) -> np.ndarray:
    """The page rotated by `angle` degrees and lying on a dark table, like a phone photo."""
    page = np.asarray(page.convert("RGB"))[:, :, ::-1]
    background = np.full((1500, 1500, 3), 40, np.uint8)
    y, x = (1500 - page.shape[0]) // 2, (1500 - page.shape[1]) // 2
    background[y:y + page.shape[0], x:x + page.shape[1]] = page
    matrix = cv2.getRotationMatrix2D((750, 750), angle, 1.0)
    return cv2.warpAffine(background, matrix, (1500, 1500), borderValue=(40, 40, 40))


def test_skew_angle_measures_text_rotation():
    page = np.asarray(receipt_image())[:, :, ::-1]
    matrix = cv2.getRotationMatrix2D((450, 350), 8, 1.0)
    rotated = cv2.warpAffine(page, matrix, (900, 700), borderValue=(255, 255, 255))

    assert skew_angle(page) == pytest.approx(0, abs=0.5)
    assert skew_angle(rotated) == pytest.approx(8, abs=1.0)


def test_photo_is_cropped_to_the_page_and_straightened():
    photo = photo_of(receipt_image(), angle=8)

    result = prepare(to_bytes(Image.fromarray(photo[:, :, ::-1])))

    # The dark table is cut away: the result has the page's proportions, not the square photo's.
    assert result.width / result.height == pytest.approx(900 / 700, rel=0.08)
    assert skew_angle(result.image) == pytest.approx(0, abs=1.0)


def test_tilted_scan_is_deskewed():
    # A scan: white all the way to the edges, so there is no outline to crop.
    page = np.asarray(receipt_image())[:, :, ::-1]
    matrix = cv2.getRotationMatrix2D((450, 350), -6, 1.0)
    tilted = cv2.warpAffine(page, matrix, (900, 700), borderValue=(255, 255, 255))

    assert skew_angle(deskew(tilted)) == pytest.approx(0, abs=1.0)


def test_table_border_on_a_scan_is_not_mistaken_for_the_page():
    # An invoice scan: white page, with a large ruled table in the lower half.
    page = np.asarray(receipt_image(size=(1100, 1600)))[:, :, ::-1].copy()
    cv2.rectangle(page, (40, 700), (1060, 1500), (0, 0, 0), 3)

    assert crop_document(page).shape == page.shape


def test_scan_without_outline_is_not_cropped():
    page = np.asarray(receipt_image())[:, :, ::-1]

    assert crop_document(page).shape == page.shape


def test_small_image_is_upscaled_and_huge_one_downscaled():
    assert min(resize(np.zeros((300, 200, 3), np.uint8)).shape[:2]) == 720
    assert max(resize(np.zeros((6000, 3000, 3), np.uint8)).shape[:2]) == 4000


def test_exif_rotation_is_applied_and_reported():
    image = receipt_image()
    exif = Image.Exif()
    exif[0x0112] = 6  # stored sideways: rotate 90 degrees clockwise to view
    rotated = image.rotate(90, expand=True)

    pixels, rotation = load(to_bytes(rotated, "JPEG", exif=exif))

    assert rotation == 90
    assert pixels.shape[:2] == (700, 900)


def test_transparent_background_becomes_white():
    transparent = Image.new("RGBA", (50, 50), (0, 0, 0, 0))

    pixels, _ = load(to_bytes(transparent))

    assert pixels.min() == 255


def test_single_page_pdf_is_rendered_at_300_dpi():
    pdf = to_bytes(receipt_image(), "PDF", resolution=72)

    pixels, _ = load(pdf)

    height, width = pixels.shape[:2]
    assert height == pytest.approx(700 * 300 / 72, abs=1)
    assert width == pytest.approx(900 * 300 / 72, abs=1)


@pytest.mark.parametrize("data, status, code", [
    (b"", 400, "invalid_image"),
    (b"hello, not an image", 400, "invalid_image"),
    (b"\x00" * (20 * 1024 * 1024 + 1), 413, "too_large"),
])
def test_bad_input_raises_contract_errors(data, status, code):
    with pytest.raises(ImageError) as error:
        load(data)

    assert (error.value.status, error.value.code) == (status, code)


def test_image_over_8000_px_is_too_large():
    with pytest.raises(ImageError) as error:
        load(to_bytes(Image.new("L", (8001, 10)), "PNG"))

    assert error.value.code == "too_large"


def test_multi_page_pdf_is_rejected():
    first, second = receipt_image(), receipt_image()
    buffer = to_bytes(first, "PDF", save_all=True, append_images=[second])

    with pytest.raises(ImageError) as error:
        load(buffer)

    assert error.value.code == "invalid_image"
    assert "2 pages" in error.value.message


def test_order_points_is_clockwise_from_top_left():
    shuffled = [[100, 90], [10, 12], [12, 95], [98, 8]]

    assert order_points(shuffled).tolist() == [[10, 12], [98, 8], [100, 90], [12, 95]]
