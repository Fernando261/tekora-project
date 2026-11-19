<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Transport-only flag: never an arbitrary return URL or a domain command field. */
final class ContractingReturn
{
    public static function requested(Request $request): bool
    {
        $value = $request->isMethod('GET') ? $request->query('follow') : $request->input('_journey');
        abort_if($value !== null && ! in_array($value, [1, '1'], true), 422);

        return $value !== null;
    }

    public static function url(int $inquiry, string $fragment = 'reservas'): string
    {
        return route('inquiries.show', $inquiry).'#'.$fragment;
    }
}
