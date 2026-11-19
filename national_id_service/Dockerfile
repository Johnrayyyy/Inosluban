FROM python:3.13-slim

COPY national_id_service/requirements-national-id.txt /tmp/requirements-national-id.txt

RUN apt-get update && apt-get install -y --no-install-recommends \
    libgl1 \
    libglib2.0-0 \
    libgomp1 \
    && python -m pip install --no-cache-dir -r /tmp/requirements-national-id.txt \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY national_id_service /app/national_id_service

ENV NATIONAL_ID_SERVICE_URL=http://127.0.0.1:8000

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=3).read()"

CMD ["uvicorn", "national_id_service.app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]
