FROM python:3.13-slim

COPY requirements-national-id.txt /tmp/requirements-national-id.txt

RUN apt-get update && apt-get install -y --no-install-recommends \
    libgl1 \
    libglib2.0-0 \
    libgomp1 \
    && python -m pip install --no-cache-dir -r /tmp/requirements-national-id.txt \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app/national_id_service

ENV PORT=8000

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python -c "import os, urllib.request; urllib.request.urlopen('http://127.0.0.1:' + os.getenv('PORT', '8000') + '/health', timeout=3).read()"

EXPOSE 8000

CMD ["sh", "-c", "exec uvicorn national_id_service.app:app --host 0.0.0.0 --port ${PORT:-8000} --workers 1"]
