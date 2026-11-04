# Primeiros passos

## Pré-requisito

Você já rodou o `install.sh` e tem o servidor rodando:

\`\`\`bash
cargo run --release
# 📋 N rotas, X schemas, 1 schemes
# 🚀 Servidor ouvindo em http://localhost:7600
# ✅ PHP worker pronto
\`\`\`

## Sua primeira rota (30 segundos)

Edite `api/routes.php` e adicione:

\`\`\`php
#[Get('/ola')]
function ola(): string
{
return json_response(['msg' => 'Olá do Performatic PHP!']);
}
\`\`\`

Reinicie o servidor (Ctrl+C e `cargo run --release` de novo) e teste:

\`\`\`bash
curl http://localhost:7600/ola
# {"msg":"Olá do Performatic PHP!"}
\`\`\`

**Você acabou de criar sua primeira rota.** Sem classes, sem controllers, sem `$this`.

## Adicionando um parâmetro

\`\`\`php
#[Get('/usuarios/{id}')]
function buscar_usuario(int $id): string
{
return http(200, ['id' => $id, 'nome' => 'Alice']);
}
\`\`\`

\`\`\`bash
curl http://localhost:7600/usuarios/42
# {"id":42,"nome":"Alice"}
\`\`\`

O `$id` chega **já como `int`** — sem `(int)` manual.

## Próximo passo

→ [Sintaxe de rotas](/guia/sintaxe-rotas)