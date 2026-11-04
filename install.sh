#!/usr/bin/env bash
set -euo pipefail

GREEN='\033[0;32m'; YELLOW='\033[0;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { printf "${GREEN}✓${NC} %s\n" "$*"; }
warn()  { printf "${YELLOW}⚠${NC} %s\n" "$*"; }
error() { printf "${RED}✗${NC} %s\n" "$*" >&2; }

if command -v apt-get &>/dev/null; then
    info "Detectado apt (Debian/Ubuntu)"
    sudo apt-get update -qq
    sudo apt-get install -y -qq \
        php-dev php8.5-cli php8.5-curl php8.5-pgsql php8.5-mysql \
        libclang-dev build-essential pkg-config curl git unzip
elif command -v dnf &>/dev/null; then
    info "Detectado dnf (Fedora)"
    sudo dnf install -y -q php-devel php-curl php-pgsql php-mysql \
        clang-devel gcc pkg-config curl git unzip
elif command -v pacman &>/dev/null; then
    info "Detectado pacman (Arch)"
    sudo pacman -Sy --noconfirm --quiet php php-pgsql clang gcc pkg-config curl git unzip
else
    error "Distro não suportada. Instale manualmente: php-dev, libclang, build-essential"
    exit 1
fi

if ! command -v cargo &>/dev/null; then
    warn "Rust não encontrado. Instalando via rustup..."
    curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable
    source "$HOME/.cargo/env"
fi
info "Rust $(rustc --version | awk '{print $2}')"

[ -f .env ] || { cp .env.example .env; warn ".env criado — edite as credenciais"; }

mkdir -p storage && chmod 755 storage
info "storage/ pronto"

info "Compilando (primeira vez: 2-5 min)..."
cargo build --release

echo ""
printf "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
printf "${GREEN}  ✓ Performatic PHP instalado${NC}\n"
printf "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
echo ""
echo "Rodar:  ./target/release/php-server"
echo "Docs:   http://localhost:7600/docs"
echo "Ping:   http://localhost:7600/ping"
echo ""