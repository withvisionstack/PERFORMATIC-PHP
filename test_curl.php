<?php
require __DIR__ . '/api/http.php';

$key = '144219c65bcc4eafa4053208250211';
$urls = [
    'https://api.weatherapi.com/v1/current.json?' . http_build_query(['key' => $key, 'q' => 'Sao Paulo']),
    'https://api.weatherapi.com/v1/current.json?' . http_build_query(['key' => $key, 'q' => 'Curitiba']),
    'https://api.weatherapi.com/v1/current.json?' . http_build_query(['key' => $key, 'q' => 'Belo Horizonte']),
];

for ($i = 1; $i <= 3; $i++) {
    echo "Chamada $i:\n";
    $inicio = microtime(true);
    $respostas = http_parallel($urls, 5);
    $tempo = round((microtime(true) - $inicio) * 1000, 2);
    foreach ($respostas as $j => $r) {
        $len = $r === null ? 'NULL' : strlen($r);
        echo "  URL $j: $len bytes\n";
        if ($j === 0 && $r !== null && strlen($r) > 0) {
            $dados = json_decode($r, true);
            echo "  Sample: " . json_encode($dados['location'] ?? 'sem location') . "\n";
        }
    }
    echo "  Tempo: {$tempo}ms, Memória: " . memory_get_usage(true) . " bytes\n\n";
}
