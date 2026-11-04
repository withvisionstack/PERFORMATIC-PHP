<?php
/**
 * Helpers de banco de dados.
 * PDO com reconexão automática quando o servidor derruba a conexão.
 */

function db(): PDO
{
    if (!isset($GLOBALS['__pdo'])) {
        try {
            $GLOBALS['__pdo'] = new PDO(
                getenv('DB_DSN') ?: 'sqlite:/tmp/app.db',
                getenv('DB_USER') ?: '',
                getenv('DB_PASS') ?: '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 5,
                    // ATTR_PERSISTENT removido: $GLOBALS['__pdo'] já mantém a conexão viva
                ]
            );
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            throw $e;
        }
    }
    return $GLOBALS['__pdo'];
}

/**
 * Detecta conexão perdida por SQLSTATE ou mensagem.
 * SQLSTATE 08xxx = connection exception, 57P01 = admin shutdown.
 */
function db_connection_lost(PDOException $e): bool
{
    return in_array($e->errorInfo[0] ?? '', ['08000', '08003', '08006', '57P01'], true)
        || str_contains($e->getMessage(), 'server closed the connection')
        || str_contains($e->getMessage(), 'gone away');
}

/**
 * Executa SQL com retry automático em caso de conexão perdida.
 * Retorna o PDOStatement para fetch(), fetchAll(), rowCount(), etc.
 */
function db_exec(string $sql, array $params = []): PDOStatement
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        if (!db_connection_lost($e)) {
            throw $e;
        }
        // Conexão caiu: descarta e tenta uma vez
        $GLOBALS['__pdo'] = null;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

/**
 * Atalho para SELECT que retorna array diretamente.
 * Delega para db_exec (mesma lógica de retry).
 */
function db_query(string $sql, array $params = []): array
{
    return db_exec($sql, $params)->fetchAll();
}

/**
 * Executa um bloco dentro de transação, com rollback automático em exceção.
 *
 * Uso:
 *   return db_transaction(function (PDO $pdo) use ($dados) {
 *       $pdo->prepare('UPDATE contas SET saldo = saldo - ? WHERE id = ?')
 *           ->execute([$dados['valor'], $dados['from']]);
 *       $pdo->prepare('UPDATE contas SET saldo = saldo + ? WHERE id = ?')
 *           ->execute([$dados['valor'], $dados['to']]);
 *       return http(200, ['ok' => true]);
 *   });
 */
function db_transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}