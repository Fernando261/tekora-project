# V8.1.11 · Privacidad, certificación y ubicación

## Actualización de una instalación sellada

La migración nueva se aplica como lote 3. Se conservan el lote base de 39 identidades y el checkout V8.1.10 en lote 2, incluidos los hashes históricos. El instalador reconoce sellos de 39, 40 y 41 migraciones. Una actualización desde 40 ejecuta únicamente el lote 3. La variante SQLite del incremento se registra desde `database/extensions`; el directorio histórico permanece intacto.

En una ventana autorizada, detener PHP-FPM, workers, scheduler y otros escritores, bloquear la cuenta de aplicación en MySQL y terminar sus conexiones activas. Preparar un respaldo privado de la base y `storage/app/private` con el procedimiento [V8.1.10](V810-CHECKOUT-UPGRADE.md). Probar restauración antes de habilitar tráfico.

```sh
mysql --defaults-file="$admin" --protocol=SOCKET -e "ALTER USER 'tekora_app'@'localhost' ACCOUNT LOCK"
deploy/vps/provision.sh "$admin" "$migrator" --verify
deploy/vps/backup.sh "$backup" "$storage" "$snapshot"
deploy/vps/provision.sh "$admin" "$migrator" --upgrade
deploy/vps/provision.sh "$admin" "$migrator" --verify
mysql --defaults-file="$admin" --protocol=SOCKET < deploy/vps/mysql-trust-grants.sql
```

El segundo resultado de verificación debe indicar 41/41. En instalaciones nuevas también aplicar los permisos base y de checkout. Para restaurar un respaldo válido, `restore.sh` detecta la extensión y aplica sus permisos. Si se interrumpe el DDL o falla un sello, mantener mantenimiento y aplicar el plan de recuperación del runbook V8.1.10. El migrador ordinario MySQL conserva su bloqueo. El esquema se valida antes de reabrir la cuenta de aplicación para pruebas privadas. La transición se prepara en laboratorio y se autoriza por separado para la VPS.

## Información y consentimiento

- La aceptación web vincula cuenta, versión y fecha UTC. Conserva la primera aceptación por versión. Las cuentas anteriores pueden aceptar desde Términos. El registro móvil histórico conserva su contrato y puede usar el flujo web de aceptación con la misma cuenta cuando esté integrado.
- La elección de cookies se registra en una cookie cifrada, HttpOnly y SameSite Lax, con duración de hasta 180 días. Las cookies esenciales siguen activas. Esta versión no instala rastreadores opcionales. El número de cédula se publica solo tras aprobación y consentimiento revocable.
- Los textos son aptos para revisión y presentación con datos sintéticos. Para una apertura pública hay que completar identidad y domicilio del responsable, contacto y procedimiento ARCO, plazos de conservación, información sobre transferencias y revisar la versión final con el responsable del tratamiento. La Secretaría Anticorrupción y Buen Gobierno ejerce las atribuciones federales previstas por la LFPDPPP vigente. No se han asignado datos ni canales de contacto ficticios.

## Certificación y ubicación

El prestador declara que tiene cédula o que su actividad no la requiere. Puede sustituir o retirar el certificado vigente desde el perfil. La descarga requiere ser titular con rol vigente o contar con el permiso administrativo existente. Los archivos quedan en `storage/app/private/professional-certificates`, con nombres generados y entrega como adjuntos. Las decisiones conservan administrador, fecha, motivo y versión de la evidencia. La aprobación es documental y manual. Para actividades reguladas, el responsable debe definir cómo cotejar credenciales y qué documentación admite.

Una edición de cédula o certificado retira la insignia y exige nueva aprobación. Retirar la autorización para publicar el número oculta el número sin borrar la aprobación de la evidencia. Los servicios presenciales o mixtos nuevos solicitan uno de los nueve municipios. Los históricos conservan su modalidad y se muestran con ubicación no especificada si venían sin municipio. Remoto es cobertura definida por la modalidad existente. El filtro de prestadores por municipio coincide con servicios publicados que satisfacen también la categoría y modalidad solicitadas.

## Integración móvil

La [especificación OpenAPI](../api/openapi.json) incorpora `GET /api/v1/municipalities`. En listado de servicios, prestadores y servicios de un prestador, enviar `municipality=<slug>&verified=1` junto con `q`, `category`, `modality`, `page` y `per_page` según corresponda. En pantallas que ya consumen los contratos históricos, tratar `location` y `professional_credential` como adiciones opcionales. Si falta `location.municipality`, usar modalidad para distinguir remoto de «Ubicación no especificada». Mostrar `badge` solo cuando venga como «Profesional Tekora» y mostrar `license_number` solo cuando su valor sea distinto de `null`. Hay ejemplos [HTTP](../api/examples/V811-trust-location.http). Los documentos y motivos de revisión se gestionan exclusivamente en Web con sesión autorizada.

## Recorrido de exposición

1. Abrir el catálogo, elegir solo cookies esenciales y consultar Aviso de privacidad y Términos en el pie de página.
2. Registrar un prestador sintético, aceptar los términos y declarar si cuenta con cédula. Desde el perfil, cargar un certificado sintético y comprobar el estado pendiente.
3. En otra sesión, entrar a Revisión profesional con una cuenta administrativa del laboratorio. Revisar el documento privado y aprobar con un motivo. Volver al perfil público para mostrar la insignia y, si se autorizó, la cédula.
4. Publicar un servicio presencial con municipio. Combinar municipio, categoría, texto y profesionales verificados en el catálogo. Usar las dos sesiones del flujo de checkout V8.1.10 para mostrar la contratación simulada.
