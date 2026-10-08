FROM python:3.11-slim-bookworm
# Google Cloud Vision over REST: no model in the image. Optional (compose profile "cloud").

# fonts-ipafont-gothic: Japanese font used only by the tests to render a sample receipt.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libglib2.0-0 curl \
        fonts-ipafont-gothic \
    && rm -rf /var/lib/apt/lists/*

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# Build context: engines/ (shared preprocessing, server and contract tests in common/).
WORKDIR /app
COPY ocr-cloud/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY common/preprocess.py common/server.py ocr-cloud/main.py ./

COPY common/pytest.ini .
COPY common/tests/ tests/
COPY ocr-cloud/tests/ tests/

EXPOSE 8000
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
