<?php

return [
    // La API móvil nunca autentica mediante cookies de los guards de sesión.
    'routes' => false,
    'stateful' => [],
    'guard' => [],
    'expiration' => 43200,
    'token_prefix' => '',
    'middleware' => [],
];
