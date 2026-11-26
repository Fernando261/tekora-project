# W6.2c-b · Reproducción aislada Windows / Docker Desktop / WSL2

Guía preparada; no se ejecutó Windows desde cloud. **Nunca usar el MySQL de Laragon, un servidor compartido ni localhost:3306.** No importar `.env`, DB, dumps, usuarios o medios reales. No fusionar ni desplegar.

Se necesita Docker Desktop en modo contenedores Linux con integración WSL2, Python 3, PHP/Composer/Node para la validación SQLite. Ejecutar el laboratorio desde un clon nuevo dentro de WSL2: el runner usa explícitamente `unix:///var/run/docker.sock`. No adaptar el script a un host MySQL existente ni cambiar sus guards para hacerlo pasar.

```bash
git clone --branch codex/w62c-b-mysql-isolation --single-branch https://github.com/Fernando261/tekora-project.git tekora-w62cb-review
cd tekora-w62cb-review
git status --short
git ls-remote origin refs/heads/main
# La base aprobada es 2441debb728982229d3a8a476ca9618c116b6172.
# Detenerse si cambió antes de iniciar; no rebasar silenciosamente.
composer install --no-scripts
# Un clon nuevo debe seguir sin .env; si existe, detenerse sin abrirlo ni borrarlo.
test ! -e .env
npm ci
npm run build
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result tests/Feature/ProfilePhotoIntegrityTest.php
php vendor/bin/phpunit --do-not-cache-result --group legacy-decoupling-w62a
php vendor/bin/phpunit --do-not-cache-result tests/Feature/RetiredProductTest.php
php vendor/bin/phpunit --do-not-cache-result tests/Feature/RetirementPreflightTest.php

env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH docker --host=unix:///var/run/docker.sock info
# Solo imágenes oficiales fijadas; no se montan carpetas del host.
docker --host=unix:///var/run/docker.sock pull mysql@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902
docker --host=unix:///var/run/docker.sock build -f tests/Integration/Dockerfile.w62cb -t tekora-w62cb-php:local tests/Integration
python3 tests/Integration/run-mysql-lab.py --output /tmp/tekora-w62cb-new-run
# El estado BLOCKED produce exit code 1. Leer los JSON, no ignorarlo como éxito.
composer validate --strict
composer check-platform-reqs
composer audit
composer audit --no-dev
npm audit
php vendor/bin/pint --test tests/Integration/*.php
git diff --check
```

La carpeta de salida debe ser nueva. No ejecutar limpieza global Docker. El script elimina únicamente sus contenedores y su red etiquetados; las imágenes quedan en caché. No publicar los temporales de credenciales ni volcar docker inspect completo o logs de inicialización del servidor. Las salidas previstas son phpunit.txt, JUnit, catálogo/resultados y aislamiento sanitizados. Revisar el estado real del JSON: si solo hay aislamiento y no resultado de migraciones, la instalación no se ejecutó.

La suite normal usa SQLite en memoria y debe conservar 688/9.224, W5.1 49/459, W62A 25/406, W62B 34/586 y W62C-A 19/198. La suite MySQL requiere otra configuración y habilitación que el runner genera solo dentro del worker. No sustituir `tests/bootstrap.php` ni el phpunit.xml normal. No pasar contraseñas reales por variables de laboratorio.

Si ya existe un `.env` local, usar otro clon limpio. En cloud se tuvo que repetir el build con envDir vacío y los tests con `.env.testing` vacío para evitar el loader normal; esa incidencia se documenta en [el informe](W62C-B-MYSQL-LAB.md). No alterar un `.env` existente para seguir estos pasos.

Las incompatibilidades históricas bloquean escenarios dependientes: no editar migraciones, saltar índices/triggers ni crear un esquema aproximado para lograr verde. La [matriz](evidence/W62C-B-scenarios.json) distingue PASS, FAIL, BLOCKED y NOT RUN. No interpretar los cinco negativos de identidad aprobados como validación de retirada/restore o de todos los negativos del esquema. Proponer soporte MySQL en otra entrega antes de repetir B–F. Nunca ejecutar W6.2c real con este procedimiento.
