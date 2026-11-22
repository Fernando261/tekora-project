<?php

namespace Tests\Unit;

use App\Rules\WithoutExternalContact;
use PHPUnit\Framework\TestCase;

final class WithoutExternalContactSpacedPhoneTest extends TestCase
{
    public function test_rejects_ten_digits_separated_individually(): void
    {
        $errors = $this->validateText(
            'Necesito reparar mi computadora. Mi telefono es 3 3 2 2 3 3 2 2 3 3, llamame.'
        );

        $this->assertNotEmpty($errors, 'Debe bloquear el telefono separado por espacios.');
    }

    public function test_allows_a_normal_technical_description(): void
    {
        $errors = $this->validateText(
            'Necesito reparar el modelo ABC-443. El presupuesto es MXN 1500.'
        );

        $this->assertEmpty($errors, 'No debe bloquear datos tecnicos normales.');
    }

    public function test_allows_explicitly_labelled_spaced_serial(): void
    {
        $errors = $this->validateText(
            'Necesito diagnosticar el equipo. Serie: 3 3 2 2 3 3 2 2 3 3.'
        );

        $this->assertEmpty($errors, 'Un numero de serie identificado no deberia bloquearse.');
    }

    public function test_rejects_phone_with_double_spaces(): void
    {
        $errors = $this->validateText(
            'Mi WhatsApp es 3  3  2  2  3  3  2  2  3  3.'
        );

        $this->assertNotEmpty($errors, 'Debe reconocer los digitos con espacios dobles.');
    }

    private function validateText(string $text): array
    {
        $errors = [];

        (new WithoutExternalContact())->validate(
            'message',
            $text,
            static function (string $message) use (&$errors): void {
                $errors[] = $message;
            }
        );

        return $errors;
    }
}