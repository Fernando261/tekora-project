# W6.2a · Evidencias y límites

Base de fuentes: `2d7e3c05fb3c38857dfe8102e9c5a1c6c70dfe43`. No se inspeccionaron bases persistentes, cuentas, credenciales ni contenidos de medios reales. W6.1 se conserva intacta como antecedente; las propuestas nuevas no crean historial ni archivo de MindBloom.

| Evidencia | Alcance |
| --- | --- |
| [W62A-dependencies.json](W62A-dependencies.json) | Mapa antes/después y dependencias aún pendientes de W6.2b/c |
| [W62A-removal-code.json](W62A-removal-code.json) | 75 candidatos de archivo, 18 fragmentos compartidos, 32 operaciones de rutas/nombres y 10 candidatos de paquetes; consumidores de fuente, pruebas, riesgos y bloqueos |
| [W62A-removal-data.json](W62A-removal-data.json) | Nueve tablas antiguas, 18 nativas/compartidas y ocho de infraestructura protegidas; diez FK antiguas, morfos y raíces de medios; plan W6.2c no ejecutado |
| [W62A-protected-sources.json](W62A-protected-sources.json) | OIDs Git de rutas, migraciones, modelos, policies, config, frontend nativo y locks; 21 operaciones/37 migraciones intactas |
| [W62A-validation.json](W62A-validation.json) | Resultados reales por comando/runtime, baseline, delta de conteos, restricciones y diagnósticos no aprobados como checks verdes |
| [W62A-css-extraction.json](W62A-css-extraction.json) | 22 nodos con selectores/declaraciones/orden idénticos, líneas de procedencia y hash del CSS extraído |
| [W62A-visual-comparison.json](W62A-visual-comparison.json) | Chromium, 58 comparaciones de estilos completos en 20 páginas sintéticas; ocho anchos representativos, sin errores JS; capturas reales antes/después |
| [W62A-assertion-delta.json](W62A-assertion-delta.json) | Las 97 aserciones de diferencia entre full con build y el ensayo full sin build, por siete casos existentes |
| [W62A-manifest-integrity.json](W62A-manifest-integrity.json) | Preservación/restauración del manifiesto, SHA256 iguales; no se borró para ocultar un fallo |
| [W62A-files.json](W62A-files.json) | Lista exacta de archivos de entrega y ocho archivos de código/pruebas previstos |
| [W62A-verification.json](W62A-verification.json) | Comprobaciones de scope, enlaces, JSON, modos y diff; sin nuevos ejecutables ni cambios en W6.1 |

Los TXT conservan salidas reales de PHPUnit baseline/habitual/build, W5.1, W6.2a, caso sin build, Pint, Composer y npm/build. `W62A-npm-audit.json` es la salida real de auditoría. Los dos TXT con `diagnostic` registran los ensayos sin build que fallaron; no representan una ejecución aprobada. Las pruebas existentes de previews requieren bundles reales. Ningún test anterior se modificó para resolverlos.

El inventario de retirada usa `git ls-tree` y `git show` de fuentes versionadas, no datos de producción. Las referencias de consumidores buscan literales de clase/render/ruta/asset en app, routes, resources, config, bootstrap, tests, tools y fuentes públicas CSS/JS; distinguen runtime, test y tool. Son un inventario estático, no un resolver completo de imports o consumidores dinámicos. Cero literal no autoriza eliminación. Las imágenes se listan por metadata, sin abrirlas; no se publican nombres individuales de uploads.

La comparación CSS usa el mismo HTML sintético y los mismos bundles: antes intercepta el nuevo URL para servir el home.css original, después sirve account-base.css. Compara todos los valores calculados de cada elemento y sus pseudo-elementos, con nombres de propiedades ordenados y animaciones/cursores congelados. No reduce las propiedades para conseguir igualdad. Se incluyen seis capturas de dashboard cliente/prestador y perfil a1440px; contienen únicamente fixtures sintéticos. Los tres pares PNG son idénticos byte por byte.

Las pruebas nuevas migran en SQLite `:memory:` y eliminan tablas solo dentro de sus fixtures, después de comprobar aislamiento. Usan Storage::fake y raíces temporales; no existe comando de purga ni migración de retirada. El puente de referencias de fotos conserva archivos ante cualquier error de esquema/consulta, además de referencias nativas y antiguas compartidas. Las 49 regresiones de W5.1 permanecen sin cambios.

Por espacio agotado en el filesystem de Docker, la validación final se hizo en un clon temporal con PHP8.2.35; baseline y habitual ya habían pasado con PHP8.2.28. Se preservaron cachés reconstruibles y no se inició el contenedor de aplicación existente. Los ajustes de extensiones/tzdata pertenecen únicamente a herramientas temporales, no a configuración del proyecto. La validación Windows/Laragon con PHP8.2.28 sigue pendiente del usuario.
