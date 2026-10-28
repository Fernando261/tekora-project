<?php

namespace App\Http\Requests;

use App\Enums\ServiceStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

class ServiceStatusRequest extends ServiceRequest
{
    public function authorize(): bool
    {
        $ability = $this->input('status') === ServiceStatus::Published->value ? 'publish' : 'pause';

        return $this->user('web')?->can($ability, $this->route('service')) ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(ServiceStatus::class)]] + self::protectedFields();
    }

    public function after(): array
    {
        return [function (ValidationValidator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->input('status') !== ServiceStatus::Published->value) {
                return;
            }

            // Volver a comprobar validez al republicar, incluida una categoría que pudo desactivarse.
            $details = Validator::make($this->route('service')->only(array_keys(self::detailRules())), self::detailRules());
            if ($details->fails()) {
                $validator->errors()->add('status', 'No se puede publicar: revisa los datos y elige una categoría activa al editar el servicio.');
            }
        }];
    }
}
