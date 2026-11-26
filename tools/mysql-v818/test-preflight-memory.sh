#!/usr/bin/env bash
set -euo pipefail

project=$(cd -- "$(dirname -- "$0")/../.." && pwd -P)
source "$project/deploy/vps/preflight-memory.sh"

expect() {
    local expected=$1 name=$2 ram=$3 swap=$4 page_bytes=$5
    local actual=reject
    if vps_memory_sufficient "$page_bytes" <(printf 'MemTotal: %s kB\nSwapTotal: %s kB\n' "$ram" "$swap"); then
        actual=accept
    fi
    if [[ "$actual" != "$expected" ]]; then
        printf 'FAIL %s: expected %s, got %s\n' "$name" "$expected" "$actual" >&2
        return 1
    fi
    printf 'PASS %s\n' "$name"
}

expect accept 'VPS RAM and nominal 1 GiB swap with 4 KiB pages' 917168 1048572 4096
expect accept '1 KiB above the 4 KiB page boundary' 917168 1048573 4096
expect reject '1 KiB below the 4 KiB page boundary' 917168 1048571 4096
expect accept 'entire nominal 1 GiB reported as usable' 917168 1048576 4096
expect accept 'exactly 800 MiB RAM and sufficient swap' 819200 1048572 4096
expect reject '1 KiB below the RAM floor with sufficient swap' 819199 1048572 4096
expect accept 'nominal 1 GiB swap with 16 KiB pages' 917168 1048560 16384
expect reject '1 KiB below the 16 KiB page boundary' 917168 1048559 16384
expect accept 'nominal 1 GiB swap with 64 KiB pages' 917168 1048512 65536
expect reject '1 KiB below the 64 KiB page boundary' 917168 1048511 65536
expect reject 'zero swap' 917168 0 4096
expect reject 'missing page size' 917168 1048576 ''
expect reject 'page size not aligned to KiB' 917168 1048576 4097
expect reject 'page size equal to the nominal swap' 917168 0 1073741824
