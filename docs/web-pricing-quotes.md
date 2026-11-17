# Precios y cotizaciones web — revisión pendiente

Incremento local desde `9eea9eff32f416916e8bf451f1a9eadb9fb4fbf6`. No realiza operaciones financieras ni retiene horarios.

## Uso

- El prestador puede introducir un precio opcional al crear un servicio; después, «Precio del servicio» publica nuevas versiones desde su edición. Guardar la descripción no cambia el precio.
- MXN por 60 minutos, centavos enteros. Entrada decimal sin separadores ni símbolo, hasta dos decimales. Límite técnico heredado de B1: $10,000,000.00 MXN; no constituye una decisión comercial de ticket máximo.
- Sin precio: «Precio no publicado». Solicitudes, mensajes y reservas siguen disponibles.
- En una solicitud aceptada de un servicio público con precio, el cliente consulta una nueva cotización y acepta expresamente su importe antes del vencimiento. Ambos participantes pueden consultar el historial privado.
- Cada cotización conserva precio/versionado, identidades, MXN/60 min, total, comisión, complemento y política, emisión y caducidad. Cambiar el precio no modifica cotizaciones anteriores. Cada aceptación pertenece a su cotización, no a una reserva ni a un pago.
- Comisión 15 %, redondeo mitad hacia arriba en centavos; prestador recibe el complemento. El total del cliente es el precio publicado. Futuro procesamiento a cargo de Tekora.
- `TEKORA_QUOTE_TTL_SECONDS`: 1–86400, predeterminado 1800. Exclusivamente vigencia de cotización; no es un hold de 15 minutos.

## Esquema aditivo SQLite

Única migración nueva: `2026_11_18_000000_create_web_pricing_and_quotes.php`.

| Tabla | Propósito y restricciones |
| --- | --- |
| `service_price_versions` | Versiones inmutables por servicio; propietario, FK, moneda, duración, importes y cálculo comprobados. Unicidad servicio/versión y servicio/clave. |
| `service_quotes` | Snapshot inmutable vinculado a solicitud, cliente, perfil y versión exacta del precio; vigencia y desglose comprobados. Unicidad cliente/clave. |
| `quote_acceptances` | Recibo inmutable por cotización; cliente, estado y aceptación dentro de vigencia comprobados. |
| `service_pricing_creations` | Recibo de creación con precio: actor/clave, servicio, primera versión y huella del contenido, para evitar servicios duplicados por reintentos. |
| `pricing_write_lock` | Fila única de admisión de escritor antes de cualquier callback de negocio. |

Las FK restringen borrados. Triggers impiden UPDATE/DELETE de los cuatro registros históricos y cambios de identidad en servicios/perfiles/solicitudes ya vinculados. Las 37 migraciones previas se conservan byte por byte; su manifiesto SHA-256 se comprueba en pruebas. Las pruebas históricas de inventario se actualizan para proteger también las cinco tablas, incluidas filas de cotizaciones aceptadas; no se excluyen casos.

**No ejecutar esta migración en una instalación persistente existente sin autorización posterior.** Revisar primero esquema, copia de seguridad y configuración efectiva de esa instalación. Este trabajo solo migró SQLite nuevas sintéticas y bases de pruebas en memoria; no accedió a MySQL ni a bases del propietario.

Tras una integración autorizada, la aplicación utiliza su conexión SQLite de Laravel y dependencias fijadas por `composer.lock`; no necesita otro proyecto ni dependencias nuevas. La configuración debe conservar FK habilitadas. No se admite otro motor sin un incremento específico.

## Persistencia y recuperación

El adaptador usa `PDO::beginTransaction()`, no BEGIN IMMEDIATE manual, y rechaza transacciones ajenas, incluidas las manuales invisibles para `inTransaction()` en PHP 8.2. El diagnóstico anidado exige HY000, código nativo entero 1 y mensaje exacto; otros errores no se confunden con ese caso. La escritura de la fila de admisión serializa lectores/escritores del módulo antes de ejecutar negocio. No hay reintentos automáticos de callbacks.

Las acciones internas de creación+primer precio comparten solo su unidad propia y revierten juntas. Fallos ordinarios revierten; COMMIT incierto o fallo de limpieza invalidan el adaptador y desconectan/bloquean la reconexión del objeto Connection. Recursos inciertos quedan retenidos en cuarentena hasta terminar el proceso; no vuelven al pool. En workers persistentes, retirar/reiniciar el worker afectado antes de continuar; no reciclar ese objeto. Se conserva la excepción primaria y, separadamente, el error de cleanup. Nunca se intenta rollback ajeno. Ante incertidumbre: no afirmar éxito; consultar historial y reutilizar la misma clave/formulario.

## Reutilización y validación

Los nueve contratos/valores/servicios `app/Payments` de PR #32 se reutilizan sin cambios, con sus pruebas de dinero y de negocio. Dos pruebas B1 se adaptan explícitamente al main actual: finalización exige reserva realizada y ahora existe binding SQLite con migración aditiva. De #34/#37 se reutilizan criterios de ownership, diagnóstico preciso, inmutabilidad y observación independiente; el adaptador de laboratorio no se registra en la aplicación. Se inspeccionaron #33/#35/#36 sin importar holds, UOW ni ramas completas.

Comandos sobre checkout aislado con dependencias instaladas desde lock, sin scripts de Composer:

```text
php vendor/bin/phpunit --log-junit <directorio-evidencia>/full.xml
php vendor/bin/phpunit --filter WebPricing
php vendor/bin/pint --test <archivos-PHP-del-incremento>
npm --offline run build
```

Validado con PHP 8.4.24, SQLite 3.46.1, PHPUnit 11.5.57 y Chromium/Linux. No acredita ejecución de este incremento en Windows, PHP 8.2, MySQL o pagos reales. El laboratorio T1 y sus dependencias permanecen intactos; su suite separada no se repitió.
