FROM python:3.11-slim-bookworm

# fonts-ipafont-gothic: Japanese font used only by the tests to render a sample receipt.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libgl1 libglib2.0-0 libgomp1 curl \
        fonts-ipafont-gothic \
    && rm -rf /var/lib/apt/lists/*

ENV PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK=True \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app
# Build context: engines/ (shared preprocessing, server and contract tests in common/).
COPY ocr-paddle/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Download the PP-OCRv5 models at build time, not on the first request.
# Kept before the code COPY so code changes don't download them again.
RUN python -c "from paddleocr import PaddleOCR; PaddleOCR(lang='japan', ocr_version='PP-OCRv5', \
    use_doc_orientation_classify=False, use_doc_unwarping=False, use_textline_orientation=True)"

COPY common/preprocess.py common/server.py ocr-paddle/main.py ./

COPY common/pytest.ini .
COPY common/tests/ tests/
COPY ocr-paddle/tests/ tests/

EXPOSE 8000
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
