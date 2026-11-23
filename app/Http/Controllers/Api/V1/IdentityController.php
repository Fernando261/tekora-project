<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IdentityRequest;
use App\Jobs\SendMobileIdentityCode;
use App\Services\AccountPasswords;
use App\Services\AccountRegistration;
use App\Services\MobileRegistrationProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

final class IdentityController extends Controller
{
    public function register(IdentityRequest $request): JsonResponse
    {
        $this->requestCode($request->validated('email'), registration: true);

        return $this->accepted();
    }

    public function confirm(IdentityRequest $request, MobileRegistrationProof $proof, AccountRegistration $registration): JsonResponse
    {
        $input = $request->validated();
        if (! $proof->matches($input['token'], $input['email'])) {
            throw $this->invalidCode();
        }
        $registration->create($input, emailVerified: true);

        return response()->json(['data' => ['message' => 'Puedes iniciar sesión o recuperar tu contraseña.']], 202);
    }

    public function forgotPassword(IdentityRequest $request): JsonResponse
    {
        $this->requestCode($request->validated('email'), registration: false);

        return $this->accepted();
    }

    public function resetPassword(IdentityRequest $request, AccountPasswords $passwords): JsonResponse
    {
        if ($passwords->reset($request->validated(), clientOnly: true) !== Password::PASSWORD_RESET) {
            throw $this->invalidCode();
        }

        return response()->json(['data' => ['message' => 'Contraseña actualizada.']]);
    }

    private function requestCode(string $email, bool $registration): void
    {
        abort_unless(SendMobileIdentityCode::canDeliver(), 503);
        $job = new SendMobileIdentityCode($email, $registration);
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            defer(static fn () => dispatch_sync($job));
        } else {
            dispatch($job);
        }
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['data' => ['message' => 'Si corresponde, recibirás un correo para continuar.']], 202);
    }

    private function invalidCode(): ValidationException
    {
        return ValidationException::withMessages(['token' => 'El código no es válido o ha vencido.']);
    }
}
