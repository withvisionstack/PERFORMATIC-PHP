# ====================================================
# STAGE 1 — BUILDER
# ====================================================
FROM rust:1.99-bookworm AS builder

RUN apt-get update && apt-get install -y \
    curl ca-certificates apt-transport-https lsb-release gnupg \
    && curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list \
    && apt-get update && apt-get install -y \
        php8.5-cli php8.5-dev php8.5-curl php8.5-pgsql php8.5-mbstring libphp8.5-embed \
        libclang-dev build-essential pkg-config unzip zip git \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

WORKDIR /app

# ---- PHP deps via composer (do Packagist) ----
COPY composer.json composer.lock ./

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN set -eux; \
    composer install \
        --no-dev \
        --optimize-autoloader \
        --no-progress \
        --no-scripts \
        --prefer-dist; \
    echo "=== /app/vendor ==="; \
    ls -la /app/vendor; \
    test -f /app/vendor/autoload.php; \
    test -d /app/vendor/withvisionstack/performatic-framework; \
    test -f /app/vendor/withvisionstack/performatic-framework/src/bootstrap.php; \
    echo "=== composer OK ==="

# ---- Rust + código PHP ----
COPY Cargo.toml Cargo.lock ./
COPY src ./src
COPY api ./api
COPY app ./app
COPY php.ini ./

RUN cargo build --release

RUN echo "=== libphp ===" && ls -la /usr/lib/libphp*.so* 2>/dev/null

# ====================================================
# STAGE 2 — RUNTIME
# ====================================================
FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y \
    curl ca-certificates apt-transport-https lsb-release gnupg \
    && curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list \
    && apt-get update && apt-get install -y \
        libphp8.5-embed php8.5-curl php8.5-pgsql php8.5-mbstring \
        libpq5 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=builder /app/target/release/php-server .
COPY --from=builder /app/api ./api
COPY --from=builder /app/app ./app
COPY --from=builder /app/php.ini .
COPY --from=builder /app/vendor ./vendor
COPY --from=builder /app/composer.json .
COPY --from=builder /app/composer.lock .

RUN mkdir -p storage && chmod 755 storage

EXPOSE 7600
CMD ["./php-server"]