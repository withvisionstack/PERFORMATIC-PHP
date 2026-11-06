# ====================================================
# STAGE 1 — BUILDER
# ====================================================
FROM rust:1.99-bookworm AS builder

# PHP 8.5 via sury.org (sem composer do apt — vamos instalar o oficial)
RUN apt-get update && apt-get install -y \
    curl ca-certificates apt-transport-https lsb-release gnupg \
    && curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list \
    && apt-get update && apt-get install -y \
        php8.5-cli php8.5-dev php8.5-curl php8.5-pgsql php8.5-mbstring libphp8.5-embed \
        libclang-dev build-essential pkg-config unzip zip git \
    && rm -rf /var/lib/apt/lists/*

# Composer OFICIAL (não o do apt). Instalador sempre pega o mais recente.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

WORKDIR /app

# ====================================================
# STAGE 1 — BUILDER
# ====================================================

# ---- PHP deps via composer ----
# IMPORTANTE: o composer.json usa path repository "./framework",
# então a pasta precisa existir ANTES do composer install.
COPY composer.json composer.lock ./
COPY framework ./framework

# Variáveis que evitam o composer travar em container
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
    test -L /app/vendor/performatic/framework || test -d /app/vendor/performatic/framework; \
    echo "=== composer OK ==="

# ---- Rust + código PHP ----
COPY Cargo.toml Cargo.lock ./
COPY src ./src
COPY api ./api
COPY app ./app
COPY php.ini ./

RUN cargo build --release

# Diagnóstico
RUN echo "=== libphp ===" && ls -la /usr/lib/libphp*.so* 2>/dev/null

# ====================================================
# STAGE 2 — RUNTIME
# ====================================================
FROM debian:bookworm-slim

# Runtime
RUN apt-get update && apt-get install -y \
    curl ca-certificates apt-transport-https lsb-release gnupg \
    && curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list \
    && apt-get update && apt-get install -y \
        libphp8.5-embed php8.5-curl php8.5-pgsql php8.5-mbstring \
        libpq5 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Binário Rust (motor)
COPY --from=builder /app/target/release/php-server .

# Código PHP do usuário
COPY --from=builder /app/api ./api
COPY --from=builder /app/app ./app
COPY --from=builder /app/php.ini .

# Vendor do composer (já contém o framework via symlink ou cópia)
COPY --from=builder /app/vendor ./vendor
COPY --from=builder /app/composer.json .
COPY --from=builder /app/composer.lock .

# IMPORTANTE: o vendor/performatic/framework pode ser um symlink relativo
# para ../../framework/. Sem copiar framework/, o symlink quebra no runtime.
COPY --from=builder /app/framework ./framework

# Storage
RUN mkdir -p storage && chmod 755 storage

EXPOSE 7600
CMD ["./php-server"]