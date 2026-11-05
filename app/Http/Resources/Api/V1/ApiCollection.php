<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

final class ApiCollection extends ResourceCollection
{
    public function paginationInformation($request, $paginated, $default): array
    {
        // Sin URLs derivadas de parámetros desconocidos, HTML ni etiquetas de paginación.
        return ['meta' => ['current_page' => $paginated['current_page'], 'per_page' => $paginated['per_page'],
            'last_page' => $paginated['last_page'], 'total' => $paginated['total']]];
    }
}
