# Restricción de Google y resolución compatible

En la base `google/apiclient` estaba fijado exactamente a **2.15**. Los comandos se ejecutaron con Composer 2.8.11, sin modificar configuración global ni ignorar avisos:

```text
composer why firebase/php-jwt --locked
  google/apiclient  v2.15.0 requires firebase/php-jwt (~6.0)
  google/auth       v1.50.0 requires firebase/php-jwt (^6.0||^7.0)
  laravel/socialite v5.24.2 requires firebase/php-jwt (^6.4|^7.0)

composer why-not firebase/php-jwt 7.2.1 --locked
  google/apiclient v2.15.0 requires firebase/php-jwt (~6.0)
```

`why-not` devuelve código **1**, identificando la restricción que impide la versión solicitada. JWT **6.11.1**, última 6.x publicada observada, sigue afectado por **CVE-2025-45769 / GHSA-2x45-7fc3-mxwq**, severidad baja e intervalo vulnerable `<7.0.0`. Actualizar solo dentro de 6.x no resuelve ese aviso. Los cuatro primeros grupos de actualización redujeron la auditoría a ese único aviso; no se dio por resuelto ni se añadió a una lista de ignorados.

## Alternativa evaluada

Se consultaron los metadatos oficiales de **google/apiclient 2.20.1**, referencia fuente `31b577e7012c4e68d5289d63aaf7e6d471ec1ff1`. Declara:

```json
{
  "php": "^8.1",
  "google/auth": "^1.53",
  "firebase/php-jwt": "^6.0||^7.0",
  "guzzlehttp/guzzle": "^7.8.2||^8.0",
  "guzzlehttp/psr7": "^2.6.3||^3.0"
}
```

Se ensayó en un worktree descartable derivado del primer commit de seguridad. Se cambió exclusivamente el requisito de Google API Client a `^2.20.1` y se ejecutó:

```text
composer update google/apiclient firebase/php-jwt --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

El solver escogió **Google API Client 2.20.1, google/auth 1.55.3 y JWT 7.2.1**, modificando solamente esos tres paquetes respecto del lock de los cuatro grupos anteriores. JWT 7 no se fuerza a través de una restricción incompatible: lo admiten expresamente Google API Client, Google Auth y Socialite. No se agregan `replace`, aliases, dependencias JWT directas, excepciones de plataforma ni exclusiones de auditoría.

La prueba usa PHP **8.2.28 real**, el SDK y proveedor Socialite reales, con transporte Guzzle simulado que no puede salir a Google. Comprueba renovación, preservación del refresh token, consulta FreeBusy, creación de evento, cabecera Bearer, state OAuth, tratamiento de errores, firma RS256 válida y rechazo de firma alterada. Se agrega además una regresión que exige rechazar una firma válida hecha con RSA de 1024 bits: no se desactiva la validación de tamaño de clave añadida en JWT 7 para mantener compatibilidad.

Los resultados finales del candidato y la rama están registrados en [VALIDACION.md](VALIDACION.md). No cambian controladores, configuración Google, rutas, credenciales, guards ni workflows. No se realiza una conexión OAuth con cuentas reales; ese ensayo requiere credenciales y autorización del titular y queda fuera de la evidencia automatizada. La guía Laragon distingue esa limitación.

## Otros límites revisados

- Laravel **12.68.0** no podía instalarse manteniendo todos los locks iniciales: `why-not` identificó CommonMark 2.7.0 frente a `^2.8.1` y polyfill-php83 1.32 frente a `^1.33`. Actualizar el grupo relacionado resolvió esos bloqueos. La versión final es 12.71.0, y el mínimo del manifiesto es `^12.69` para cubrir también los demás avisos observados.
- `config.platform.php=8.2.28` mantiene el solver en la plataforma local. La comprobación real no usa `--ignore-platform-reqs`. Laravel permanece en 12.x, Symfony en 7.x y PHPUnit en 11.x.
- Google API Client nuevo admite Guzzle 8, pero Laravel 12 y los requisitos compartidos conservan **Guzzle 7.16.1**; no se necesita un salto mayor de transporte.
- Los polyfills PHP 8.4/8.5 son código de compatibilidad requerido por Laravel 12.71, no requisitos de esos intérpretes.

La ausencia de avisos en una consulta no valida configuraciones de correo, Google, almacenamiento, permisos del servidor o exposición de herramientas de desarrollo. No constituye una autorización de despliegue.
