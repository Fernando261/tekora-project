# Ensayo local descartable con PR #15

Se ensayó la combinación con **`07cb6dfa9de1916535999c6a9403cc5be948ae28`**, cabeza del PR #15 (`codex/etapa-9b-api-movil-base`), sin hacer push a esa rama, editar el PR, fusionarlo o desplegarlo.

El worktree `/workspace/tekora-security-9b` está detached: se creó inicialmente desde el primer commit de seguridad `a8b807a652a6fc19ea87d2b739326a4e0ae06d26` y se ejecutó `git merge --no-commit --no-ff 07cb6dfa9de1916535999c6a9403cc5be948ae28`. Hubo conflictos exclusivamente en **composer.json y composer.lock**. No hubo conflictos en código de aplicación.

Se conservaron en esos dos archivos las dependencias de seguridad y se incorporó Sanctum con Composer. Después de validar el grupo Google/JWT, se trasladaron **solo al ensayo** el manifiesto/lock finales y la nueva prueba de seguridad. El código de API, rutas, bootstrap, configuración, modelos y las tres clases MobileApi* de pruebas se mantuvo idéntico al PR #15; se comprobó con `git diff` contra su SHA.

```text
composer require laravel/sanctum:^4.0 --no-update --no-interaction
composer update laravel/sanctum --with-all-dependencies --minimal-changes --no-scripts --no-interaction
composer install --no-scripts --no-interaction
```

La instalación final se completó en PHP **8.2.28** usando una copia independiente del vendor de seguridad ya validado, después de que la extracción limpia agotara espacio temporal. Composer instaló únicamente **Sanctum 4.3.3** y regeneró el autoload para este worktree. No se usan symlinks de autoload que carguen modelos/controladores de otro worktree. Los artefactos frontend del build validado se copiaron para renderizar las vistas de la suite; package.json y package-lock.json son iguales en ambas ramas.

La comparación de lockfiles comprueba que la única diferencia de paquetes respecto de seguridad es **la incorporación de Sanctum 4.3.3**: no se rebajan Laravel 12.71, JWT 7.2.1, Google, Symfony u otras correcciones.

## Resultados reales

| Comando en PHP 8.2.28 / Composer 2.8.11 | Código | Resultado |
| --- | --- | --- |
| `composer install --no-scripts --no-interaction` | 0 | Sanctum instalado; siguientes ejecuciones sin cambios pendientes |
| `composer validate` | 0 | Manifiesto correcto |
| `composer validate --strict` | 0 | Sin advertencias de manifiesto |
| `composer check-platform-reqs` | 0 | Compatible con PHP 8.2.28 |
| `composer audit --locked --format=json` | 0 | Cero avisos |
| `composer audit --locked --no-dev --format=json` | 0 | Cero avisos |
| `php vendor/bin/phpunit --do-not-cache-result` | 0 | **403 pruebas / 5165 aserciones**, 42,235 s informados por PHPUnit |
| `php vendor/bin/phpunit --exclude-group composer-security --do-not-cache-result` | 0 | **396 pruebas / 5115 aserciones**, 42,126 s; referencia original de 9B |
| `php artisan route:list --except-vendor --json` | 0 | 105 rutas, incluidas las ocho API |
| Comparación OpenAPI/métodos/URI | 0 | Las ocho operaciones coinciden con las ocho rutas |

La suite combinada contiene las 396 pruebas de 9B y las siete nuevas de seguridad. Su cobertura incluye login/logout real con Bearer, expiración/revocación/rotación, roles vigentes y duales, separación de sesiones web/API, aislamiento por usuario, catálogo público, disponibilidad, errores JSON y las migraciones de Sanctum únicamente en SQLite **`:memory:`**. La migración de tokens no se publicó en la rama de seguridad ni se aplicó a una base persistente.

La reproducción separada de la referencia original de 9B se realiza con:

```text
php vendor/bin/phpunit --exclude-group composer-security --do-not-cache-result
```

El resultado exacto de esa reproducción figura en [VALIDACION.md](VALIDACION.md) y en la evidencia de ejecución. El grupo excluido contiene únicamente las siete pruebas nuevas de este PR; no se omiten pruebas originales para que el ensayo combinado pase.

Este worktree no se publica ni produce un segundo PR. Las resoluciones Composer son instrucciones para una futura integración autorizada; no constituyen una modificación del PR #15. No se fusiona ni despliega.
