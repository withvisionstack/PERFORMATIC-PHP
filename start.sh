#!/bin/bash
N=${1:-4}
BASE_PORT=7600

for i in $(seq 0 $((N-1))); do
    PORT=$((BASE_PORT + i)) ./target/release/php-server &
    echo "worker $((i+1)) na porta $PORT (PID $!)"
done

wait