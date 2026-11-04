<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewInput
{
    public static function publish(array $input): array
    {
        self::only($input, ['rating', 'comment']);
        if (isset($input['comment']) && is_string($input['comment'])) {
            $input['comment'] = trim(str_replace(["\r\n", "\r"], "\n", $input['comment']), " \t\n\r\v");
            if ($input['comment'] === '') $input['comment'] = null;
        }
        $data = Validator::make($input, [
            'rating' => ['required', function ($attribute, $value, $fail) {
                if (! ((is_int($value) || is_string($value)) && preg_match('/^[1-5]$/D', (string) $value))) $fail('Selecciona una calificación entera entre 1 y 5 estrellas.');
            }],
            'comment' => ['nullable', 'string', 'max:1000', 'not_regex:/\x00/'],
        ], ['rating.required' => 'Selecciona una calificación entre 1 y 5 estrellas.', 'comment.string' => 'El comentario debe ser texto plano.',
            'comment.max' => 'El comentario admite como máximo 1000 caracteres.', 'comment.not_regex' => 'El comentario no puede contener caracteres nulos.'])->validate();

        return ['rating' => (int) $data['rating'], 'comment' => $data['comment'] ?? null];
    }

    public static function only(array $input, array $allowed = []): void
    {
        if (array_diff(array_keys($input), $allowed)) throw ValidationException::withMessages(['form' => 'El formulario contiene campos no permitidos. No puedes cambiar identidad, estado ni verificación.']);
    }

    public static function page(\Illuminate\Http\Request $request, string $name = 'page'): int
    {
        $value = $request->query($name, 1);
        $validator = Validator::make([$name => $value], [$name => ['required', 'integer', 'min:1', 'max:10000']]);
        if ($validator->fails()) throw new \Illuminate\Http\Exceptions\HttpResponseException((new \App\Exceptions\ReviewFailure('La página de reseñas no es válida.', 422))->render($request));

        return (int) $value;
    }
}
