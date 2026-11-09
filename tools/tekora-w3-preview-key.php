<?php

// Identical keys for native GET forms (%20 or +) and links, without accepting arrays.
function tekoraMarketplacePreviewKey(string $uri): ?string
{
    $parts = parse_url($uri);
    if ($parts === false || ! str_starts_with($parts['path'] ?? '', '/')) {
        return null;
    }
    parse_str($parts['query'] ?? '', $query);
    foreach ($query as $key => $value) {
        if (! is_string($value)) {
            return null;
        }
        if ($value === '' && in_array($key, ['q', 'category', 'modality'], true)) {
            unset($query[$key]);
        }
    }
    ksort($query);

    return $parts['path'].($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}
