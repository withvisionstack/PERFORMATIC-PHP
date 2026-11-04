# ---------- Build stage ----------
FROM rust:1.83-bookworm AS builder

RUN apt-get update && apt-get install -y \
    php-dev php8.3-curl libclang-dev build-essential pkg-config \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY Cargo.toml Cargo.lock ./
COPY src ./src
COPY api ./api
COPY app ./app
COPY php.ini ./

RUN cargo build --release

# ---------- Runtime stage ----------
FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y \
    libphp8.3-embed ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=builder /app/target/release/php-server .
COPY --from=builder /app/api ./api
COPY --from=builder /app/app ./app
COPY --from=builder /app/php.ini .
COPY .env.example .env

RUN mkdir -p storage && chmod 755 storage

EXPOSE 7600
CMD ["./php-server"]
