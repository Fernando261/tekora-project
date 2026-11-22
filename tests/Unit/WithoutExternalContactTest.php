<?php

namespace Tests\Unit;

use App\Rules\WithoutExternalContact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WithoutExternalContactTest extends TestCase
{
    #[DataProvider('messagesWithContact')]
    public function test_rejects_contact_with_a_generic_validation_message(string $message): void
    {
        $errors = $this->errors($message);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Mantén la conversación dentro de Tekora.', $errors[0]);
        self::assertStringNotContainsString($message, $errors[0]);
    }

    public static function messagesWithContact(): array
    {
        return [
            'correo' => ['Escribe a usuario+prueba@example.test, por favor.'],
            'correo con espacios' => ['Mi correo es prueba @ ejemplo . test'],
            'correo con separadores marcados' => ['Escribe a fixture (arroba) ejemplo [punto] test.'],
            'correo con palabras' => ['Contáctame en fixture arroba ejemplo punto com.'],
            'correo con palabras y dominio es' => ['usuario arroba dominio punto es'],
            'correo de ancho completo' => ['Mi correo es prueba＠ejemplo．test.'],
            'correo con carácter invisible' => ["Usa prueba@ejem\u{200B}plo.test."],
            'teléfono continuo' => ['Mi teléfono es 5500000000.'],
            'teléfono con espacios' => ['Llámame al 55 0000 0000.'],
            'teléfono con guiones' => ['Te dejo el 55-0000-0000.'],
            'teléfono con puntos' => ['55.0000.0000'],
            'teléfono con barras' => ['55 / 0000 / 0000'],
            'teléfono con puntos y lada de tres cifras' => ['999.000.0000'],
            'teléfono con paréntesis' => ['Te dejo el (55) 0000-0000.'],
            'teléfono con lada de tres cifras' => ['Anota 999 000 0000 para hablar.'],
            'teléfono con código de país' => ['Envíame un mensaje al +52 (55) 0000-0000.'],
            'teléfono con código de país sin más' => ['Envíame un mensaje al 52 55 0000 0000.'],
            'teléfono continuo con código de país y sin contexto técnico' => ['Anota 525500000000.'],
            'indicativo explícito pese a etiqueta técnica' => ['Serie: +525500000000.'],
            'teléfono explícito antes de unidad' => ['Teléfono: 5500000000 piezas.'],
            'teléfono explícito con artículo y unidad' => ['El teléfono es 5500000000 piezas.'],
            'teléfono explícito antes de moneda' => ['Mi teléfono es 5500000000 MXN.'],
            'teléfono explícito antes de etiqueta técnica' => ['Teléfono: Serie: 525500000000.'],
            'serie y contacto separados' => ['Serie: 525500000000. Mi celular es 5500000000 piezas.'],
            'teléfono anunciado en enlace técnico' => ['Teléfono: https://docs.example.test/piezas/5500000000'],
            'teléfono en parámetros de enlace' => ['https://docs.example.test/piezas/manual?phone=5500000000'],
            'ruta técnica con parámetros de contacto' => ['https://docs.example.test/piezas/5500000000?phone=5500000000'],
            'plataforma de contacto con ruta técnica' => ['https://wa.me/piezas/5500000000'],
            'teléfono con separadores Unicode' => ['Celular ５５ ００００–００００.'],
            'teléfono en pares' => ['Te dejo 55 00 00 00 00.'],
            'enlace de teléfono local' => ['Abre tel:00000000 para hablar.'],
            'teléfono internacional' => ['Llámame al +1 (212) 555-0100.'],
            'whatsapp sin esquema' => ['Mándame un mensaje por wa.me/525500000000.'],
            'whatsapp api' => ['Usa https://api.whatsapp.com/send?phone=525500000000.'],
            'whatsapp esquema' => ['Abre whatsapp://send?text=hola.'],
            'whatsapp invitación' => ['Usa https://chat.whatsapp.com/fixtureCode.'],
            'telegram' => ['Contáctame en https://t.me/fixture_contacto.'],
            'telegram con punto marcado' => ['Escríbeme en t [punto] me/fixture_contacto.'],
            'instagram perfil' => ['Mi perfil está en instagram.com/fixture.prueba.'],
            'instagram directo' => ['Abre https://www.instagram.com/direct/t/fixture.'],
            'instagram mensaje corto' => ['Abre ig.me/m/fixture_prueba.'],
            'instagram mensaje corto con ruta codificada' => ['Abre https://ig.me/%6D/fixture_prueba.'],
            'usuario con plataforma' => ['Escríbeme en Telegram: @fixture_prueba.'],
            'facebook perfil' => ['Búscame en https://www.facebook.com/profile.php?id=123.'],
            'perfil debajo de segmento privacy' => ['https://www.facebook.com/privacy/fixture'],
            'messenger' => ['Contacta en m.me/fixture.'],
            'signal' => ['Contacta en https://signal.me/#p/fixture.'],
            'discord invitación' => ['Contacta en https://discord.gg/fixture.'],
            'linkedin' => ['Mi perfil está en linkedin.com/in/fixture.'],
        ];
    }

    #[DataProvider('legitimateMessages')]
    public function test_keeps_service_information(string $message): void
    {
        self::assertSame([], $this->errors($message));
    }

    public static function legitimateMessages(): array
    {
        return [
            'mensaje normal y Unicode' => ['Gracias. El portátil 🛠️ necesita una revisión, ¿cuándo puedes?'],
            'precio' => ['El presupuesto inicial es de $1,500 MXN.'],
            'precio largo explícito' => ['Valor declarado: $5500000000 MXN.'],
            'precio largo que comienza por 52' => ['Valor declarado: 525500000000 MXN.'],
            'precio de un teléfono' => ['El precio del teléfono es $5500000000 MXN.'],
            'horario y fecha' => ['Puedo el 21/11/2026 de 10:00 a 11:00. La compra fue el 2025-01-30.'],
            'cantidades y dimensiones' => ['Necesito 12 tornillos y dos soportes de 55 × 1000 × 2000 mm.'],
            'modelo compuesto' => ['El modelo es AB-5512345678-X y usa un conector M.2.'],
            'serie explícita' => ['Serie: 5512345678. Requiere cambio de ventilador.'],
            'serie que comienza por 52' => ['Serie: 525500000000.'],
            'código de seguimiento' => ['El código de seguimiento es 5500000000.'],
            'folio y teléfono como equipo' => ['El teléfono que voy a reparar tiene folio 5500000000.'],
            'modelo de teléfono' => ['Mi teléfono es modelo 5500000000.'],
            'cantidad explícita' => ['Hay 5500000000 piezas en inventario.'],
            'identificadores de distinta longitud' => ['Referencia 551234567, modelo 551234567890 y lote 0012.'],
            'número con unidad' => ['La capacidad registrada es de 5500000000 mAh.'],
            'arroba aislada' => ['En el código, @section indica el inicio de una sección.'],
            'arroba y punto en instrucciones normales' => ['La palabra arroba se lee en el punto dos.'],
            'fin de frase después de arroba' => ['Usa el símbolo arroba. El siguiente punto es el horario.'],
            'documentación técnica' => ['Consulta https://docs.example.test/manual y https://github.com/fixture/manual.'],
            'documentación con identificador numérico' => ['https://docs.example.test/piezas/5500000000'],
            'documentación con identificador numérico y puntuación' => ['Consulta https://docs.example.test/piezas/5500000000.'],
            'política pública de Facebook' => ['https://www.facebook.com/privacy/policy/'],
            'publicación de referencia' => ['El tipo de reparación aparece en https://instagram.com/p/fixture.'],
            'dominio ajeno con palabra similar' => ['La documentación está en https://wa.me.ejemplo.test/manual.'],
        ];
    }

    private function errors(string $message): array
    {
        $errors = [];
        (new WithoutExternalContact)->validate('body', $message, function (string $error) use (&$errors): void {
            $errors[] = $error;
        });

        return $errors;
    }
}
