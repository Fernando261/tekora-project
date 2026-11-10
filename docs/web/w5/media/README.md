# Evidencia visual W5

Capturas y videos reales de Chromium Linux, con fixtures sintéticas idénticas antes/después. No se editaron imágenes ni se generaron maquetas. Los PNG `before-*` corresponden a la base aprobada; `after-*` son páginas completas W5. Las capturas sin prefijo muestran el viewport de la matriz y sus estados. [Proveniencia y SHA-256](../evidence/media-manifest.json).

| Familia | Antes desktop | Después desktop | Antes móvil | Después móvil |
| --- | --- | --- | --- | --- |
| Cuenta | [PNG](before-account-client-1440.png) | [PNG](after-account-client-1440.png) | [PNG](before-account-client-375.png) | [PNG](after-account-client-375.png) |
| Activación | [PNG](before-activation-pending-1440.png) | [PNG](after-activation-pending-1440.png) | [PNG](before-activation-pending-375.png) | [PNG](after-activation-pending-375.png) |
| Perfil | [PNG](before-profile-photo-1440.png) | [PNG](after-profile-photo-1440.png) | [PNG](before-profile-photo-375.png) | [PNG](after-profile-photo-375.png) |
| Nombre | [PNG](before-edit-name-1440.png) | [PNG](after-edit-name-1440.png) | [PNG](before-edit-name-375.png) | [PNG](after-edit-name-375.png) |
| Roles | [PNG](before-roles-1440.png) | [PNG](after-roles-1440.png) | [PNG](before-roles-375.png) | [PNG](after-roles-375.png) |
| Recuperar | [PNG](before-forgot-1440.png) | [PNG](after-forgot-1440.png) | [PNG](before-forgot-375.png) | [PNG](after-forgot-375.png) |
| Restablecer | [PNG](before-reset-1440.png) | [PNG](after-reset-1440.png) | [PNG](before-reset-375.png) | [PNG](after-reset-375.png) |

Escenas: [identidad](widget-account-client-scene.png), [perfil](widget-profile-photo-scene.png), [seguridad](widget-forgot-scene.png). [Datos privados y foto sintéticos](widget-profile-photo-data.png).

Referencias aprobadas ejecutadas en esta validación: [W2 desktop](reference-w2-1440.png), [W2 móvil](reference-w2-375.png), [W3 desktop](reference-w3-1440.png), [W3 móvil](reference-w3-375.png), [W4 desktop](reference-w4-1440.png), [W4 móvil](reference-w4-375.png). Conservan lenguaje de superficies, tipografía, cian y navegación; W5 añade sus escenas y jerarquía propias.

Estados: [perfil sin foto](profile-no-photo-1440.png), [error de nombre](name-validation-375.png), [foto inválida](photo-validation-375.png), [correo enviado sintético](forgot-sent-1440.png), [token inválido sintético](reset-invalid-375.png), [419](session-expired-375.png), [foco](edit-name-focus.png), [hover](card-hover.png), [selección nativa sin envío](native-file-selected.png), [sin JavaScript](no-js-reset.png), [módulo fallido](failed-module-reset.png), [movimiento reducido](reduced-security.png). Los demás roles y errores están en los PNG de la matriz.

Videos: [desktop](identity-desktop.webm), [móvil](identity-mobile.webm), [movimiento reducido](identity-reduced-motion.webm), [zoom nativo 100 → 200 → 100 %](identity-native-zoom.webm). El zoom tiene también capturas `*-zoom-200.png` y foco cuando existe un control en `main`; el catálogo de roles es deliberadamente de solo lectura.

La evidencia no sustituye GPU física, Windows/Laragon, lector NVDA ni aprobación visual independiente.
