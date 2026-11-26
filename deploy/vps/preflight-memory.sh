#!/usr/bin/env bash

vps_memory_sufficient() {
    local page_bytes=$1
    local meminfo=$2

    awk -v page_bytes="$page_bytes" '
        /^MemTotal:/ { ram = $2 }
        /^SwapTotal:/ { swap = $2 }
        END {
            nominal_swap_kib = 1024 * 1024
            if (page_bytes !~ /^[0-9]+$/ || page_bytes < 1024 ||
                page_bytes % 1024 != 0 || page_bytes >= nominal_swap_kib * 1024) {
                exit 1
            }
            # Linux excludes the first page, which contains the swap header.
            minimum_swap_kib = nominal_swap_kib - page_bytes / 1024
            exit !(ram >= 800 * 1024 && swap >= minimum_swap_kib)
        }
    ' "$meminfo"
}
