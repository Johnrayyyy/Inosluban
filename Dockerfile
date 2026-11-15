FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY requirements-face.txt /tmp/requirements-face.txt

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libgl1 \
    libglib2.0-0 \
    libgomp1 \
    libpq-dev \
    python3 \
    python3-venv \
    unzip \
    && docker-php-ext-install curl pdo pdo_pgsql \
    && python3 -m venv /opt/face-venv \
    && /opt/face-venv/bin/pip install --no-cache-dir -r /tmp/requirements-face.txt \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . /var/www/html/

ENV FACE_VERIFIER_COMMAND=/opt/face-venv/bin/python

EXPOSE 80