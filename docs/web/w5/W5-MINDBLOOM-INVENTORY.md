# W5 · Inventario de identidad MindBloom

Base auditada: `521a081b039d8f8ee4d25a9e8936eb6ca4c0de05`. Continúa los inventarios [W3](../w3/W3-MINDBLOOM-INVENTORY.md) y [W4](../w4/W4-MINDBLOOM-CLEANUP.md). El alcance es presentación: no hay migración ni vinculación de identidades.

| Superficie | Cambio W5 | Contrato conservado |
| --- | --- | --- |
| `account/index` | Centro de identidad, saludo real, tarjetas de experiencias y estados según permisos | Mismas condiciones `@can`, destinos, POST/CSRF y valores de rol |
| `account/activate-provider` | Escena de identidad, pasos y advertencias explícitas | POST `provider`, idempotencia; no verificación, identidad histórica ni fusión automática |
| `account/roles` | Catálogo administrativo de solo lectura | `ManageAccountRoles`, nombres reales, sin asignación ni lista de usuarios |
| `perfil` | Datos privados del guard web, avatar, foto nativa y enlaces actuales | Mismos campos, rutas, disco y referencia de foto; enlaces históricos conservados |
| `editar-nombre` | Formulario accesible con errores y `old()` | PUT, `name`, máximo 255, controlador original |
| `auth/forgot-password`, `auth/reset-password` | Marca Tekora y tarjeta W1 con escena de seguridad | Broker, campos, CSRF, expiración, confirmación, redirecciones y guard originales |
| Errores cliente/prestador/419 | Presentación autónoma, sin navegación dependiente de BD | Mensajes, regreso y estado HTTP conservados; cero consultas al renderizar |

## Menciones históricas necesarias

En el perfil permanece «Historial anterior · MindBloom», con las rutas originales de citas, comunidad y conferencias. Explica la procedencia sin afirmar migración de registros. La activación indica expresamente que no crea una identidad histórica MindBloom.

La navegación privada compartida mantiene «MindBloom anterior». No se cambia el login profesional, `/mindbloom/inicio`, comunidad, citas, Calendar, PDFs, emociones, medios, publicaciones, comentarios, likes, roles históricos ni rutas de compatibilidad. No se relacionan `users` y `profesionistas` por correo ni ID. Las pruebas usan incluso el mismo correo en ambos guards y comprueban su independencia.

## Aislamiento de componentes y archivos conservados

`account-layout`, `tekora-layout` y `access-layout` incorporan una opción `identity=false` por defecto. Sus consumidores anteriores conservan assets y comportamiento. La opción `ambient=true` de `account-layout` mantiene el comportamiento previo; perfil/editor la desactivan para preservar el contrato histórico de ausencia de `data-tekora-ambient` y `tekora-interior`, cubierto por una prueba W1. Usan el fondo y escena propios W5. No se modifica esa prueba.

La recuperación reutiliza CSS W1 y `tekora-auth-card`; no carga el JS de transiciones del login, que no necesita. Las fuentes locales son los mismos archivos: W5 usa alias con `font-display: optional` para impedir desplazamientos al cargarlas, sin modificar fuentes ni estilos aprobados W1–W4.

Permanecen `public/css/perfil.css`, `public/css/editar.css`, `public/css/registro.css`, logos y medios antiguos. No se eliminan archivos ni se hace reemplazo global de textos. No cambian dependencias ni lockfiles.

## Hallazgo preexistente: integridad de la actualización de foto

La auditoría de `ProfileController::updatePhoto` detectó una pérdida de referencia ante fallo de escritura: elimina la foto anterior antes de guardar la nueva y no comprueba que `put()` devuelva éxito. Una reproducción exclusivamente con `Storage::fake('public')`, un PNG sintético y `put() => false` produjo HTTP 302 y mensaje de éxito, borró la foto falsa anterior y guardó una referencia sin archivo nuevo. Véase [evidencia](evidence/photo-integrity.json).

El controlador coincide byte por byte con la base aprobada. No se han usado, leído, movido ni eliminado fotos reales. W5 conserva el contrato solicitado; no introduce una corrección funcional silenciosa. Se recomienda un PR funcional separado que compruebe la escritura y actualización de referencia antes de retirar el archivo anterior, con pruebas de fallos de almacenamiento.

La política de contraseña actual (mínimo seis caracteres) también permanece intacta. Cualquier endurecimiento corresponde a otro PR. Cero avisos de Composer/npm no certifica ausencia de defectos de aplicación.
