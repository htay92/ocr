FROM python:3.11-slim-bookworm
# No platform pin: ONNX Runtime runs natively on arm64 (Apple Silicon) and x86_64.

# fonts-ipafont-gothic: Japanese font used only by the tests to render a sample receipt.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libglib2.0-0 libgomp1 curl \
        fonts-ipafont-gothic \
    && rm -rf /var/lib/apt/lists/*

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# NDLOCR-Lite source and its ONNX models (in the repository), at a pinned commit (1.3.x, 2026-09-24).
ARG NDLOCR_COMMIT=636d1cfeb1331f89f4048f416e49e23a09a714b5
ENV NDLOCR_DIR=/opt/ndlocr NDLOCR_COMMIT=${NDLOCR_COMMIT}
RUN mkdir -p /opt/ndlocr \
    && curl -fsSL https://github.com/ndl-lab/ndlocr-lite/archive/${NDLOCR_COMMIT}.tar.gz \
       | tar -xz --strip-components=2 -C /opt/ndlocr ndlocr-lite-${NDLOCR_COMMIT}/src

# Build context: engines/ (shared preprocessing, server and contract tests in common/).
WORKDIR /app
COPY ocr-ndl/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY common/preprocess.py common/server.py ocr-ndl/main.py ./

COPY common/pytest.ini .
COPY common/tests/ tests/
COPY ocr-ndl/tests/ tests/

EXPOSE 8000
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
