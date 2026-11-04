docs-site/
├── .vitepress/
│   └── config.mts
├── index.md                     ← home (= README resumido)
├── guia/
│   ├── instalacao.md            ← instalacao + requisitos
│   ├── primeiros-passos.md      ← "primeira rota em 5 min"
│   ├── sintaxe-rotas.md         ← #[Get], #[Post], params
│   ├── helpers.md               ← body(), query(), http()
│   ├── banco-pdo.md             ← PDO + transações
│   ├── auth-jwt.md              ← login, cookie, /me
│   ├── upload.md                ← multipart
│   ├── openapi.md               ← Swagger automático
│   └── cache.md                 ← TTL, quando usar
├── deploy/
│   ├── docker.md                ← docker-compose + scale
│   ├── vps.md                   ← systemd + nginx
│   └── https.md                 ← certbot
├── comparacoes/
│   ├── vs-laravel.md
│   ├── vs-fastapi.md
│   └── vs-slim.md
├── arquitetura/
│   ├── como-funciona.md         ← Rust + libphp
│   ├── performance.md           ← benchmarks
│   └── quando-nao-usar.md
└── faq.md