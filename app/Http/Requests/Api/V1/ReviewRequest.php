<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ServiceReview;
use App\Services\MobileClientOperations;
use App\Support\ReviewInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use stdClass;

final class ReviewRequest extends ApiRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('api.v1.services.reviews.index', 'api.v1.providers.reviews.index')) {
            return true;
        }

        $actor = $this->user('sanctum');
        $scope = app(MobileClientOperations::class);
        $gate = Gate::forUser($actor);
        if ($this->routeIs('api.v1.reviews.store')) {
            $gate->authorize('create', [ServiceReview::class, $scope->reservation($actor, $this->resourceId('reservation'))]);
        } elseif ($this->routeIs('api.v1.reviews.show', 'api.v1.reviews.withdraw')) {
            $review = $scope->reviews($actor)->findOrFail($this->resourceId('review'));
            $gate->authorize($this->routeIs('api.v1.reviews.withdraw') ? 'withdraw' : 'view', $review);
            $this->attributes->set('mobile_review', $review);
        } else {
            $gate->authorize('viewAny', ServiceReview::class);
        }

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'api.v1.services.reviews.index', 'api.v1.providers.reviews.index', 'api.v1.reviews.index' => [
                'page' => ['sometimes', ...ReviewInput::pageRules()],
            ],
            // Web forms accept a numeric string. The API requires a JSON integer and the workflow validates the value and comment.
            'api.v1.reviews.store' => ['rating' => ['sometimes', 'integer:strict'], 'comment' => ['sometimes', 'nullable']],
            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator) {
            $body = $this->getContent();
            if ($this->isMethodSafe()) {
                if ($body !== '') {
                    $validator->errors()->add('request', 'Envía los parámetros de lectura en la consulta.');
                }

                return;
            }

            $requiresObject = $body !== '' || $this->routeIs('api.v1.reviews.store');
            if ($this->query() !== [] || ($requiresObject && (! $this->isJson() || ! json_decode($body) instanceof stdClass))) {
                $validator->errors()->add('request', 'Envía los datos en un objeto JSON en el cuerpo.');
            }
        });
    }

    public function resourceId(string $parameter): int
    {
        $id = filter_var($this->route($parameter), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }
}
