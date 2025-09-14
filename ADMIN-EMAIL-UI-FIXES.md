# QVC Email Manager V1 — Admin Email UI: Mapeo CSS y Fixes

Fecha: 2025-09-10

Este documento resume el origen de los estilos CSS que afectan a la página "Admin Email" y sus pestañas, los problemas detectados y las correcciones aplicadas para recuperar un layout limpio y consistente.

## 1) Fuentes de CSS que afectan Admin Email

- assets/css/admin.css (handle: `qvc-email-admin`)
  - Dónde se carga: en `qvaclick-email-manager.php` dentro de `enqueue_admin_assets()` cuando el hook contiene `qvc-email`.
  - Propósito: estilos generales del admin del plugin (grid KPI, modales, espaciados base, editor/base template).

- assets/css/admin-email.css (handle: `qvc-admin-email`)
  - Dónde se carga: en `qvaclick-email-manager.php` sólo cuando el hook contiene `qvc-admin-email`.
  - Propósito: estilos específicos de Admin Email (pestañas, KPI, listado de tickets, estados, hilo de mensajes, badges en pestañas).

- includes/class-notification-system.php — `wp_add_inline_style('wp-admin', ...)`
  - Dónde se inyecta: `enqueue_notification_scripts()` para páginas admin relevantes.
  - Propósito: estilos del badge de notificaciones en menú top/submenú y barra de admin.

- includes/class-admin-email-interface.php — bloques `<style>` en el render
  - Dónde están: múltiples `<style>` dentro de `render_admin_email_page()`, `render_general_inbox()`, `render_quarantine()` y secciones relacionadas a campañas.
  - Propósito: estilos ad-hoc que duplicaban/entraban en conflicto con `admin-email.css`.

- qvaclick-email-manager.php — `add_action('admin_head', ...)`
  - Inyección puntual: oculta un submenú (editor por hook) vía CSS inline.

- includes/class-admin-email-interface.php — `enqueue_admin_scripts()` (ANTES)
  - Encolaba `../assets/admin-style.css` y `../assets/admin-script.js` adicionales. Esto generaba solapamiento con los assets canónicos del plugin.

## 2) Problemas detectados

- Estilos inline dentro de la interfaz (varios `<style>`) que:
  - Duplicaban reglas ya existentes en `assets/css/admin-email.css`.
  - Tenían especificidad distinta provocando overrides inconsistentes (badges, grid KPI, espaciados).
  - Afectaban la estructura visual y, tras cambios recientes, rompieron el layout.
- Encolado duplicado de CSS/JS desde `includes/class-admin-email-interface.php` que competía con los encolados del archivo principal del plugin.
- Fragmentos JS sueltos insertados por error en la plantilla (detectados y removidos), causando parseos incorrectos y ruptura visual.

## 3) Correcciones aplicadas en este commit

- `includes/class-admin-email-interface.php`
  - `enqueue_admin_scripts()` ahora sólo localiza datos (nonce, ajax_url) para los scripts canónicos ya encolados desde el archivo principal. Se eliminó el encolado duplicado de `admin-style.css`/`admin-script.js` de esta clase.
  - `render_admin_email_page()` fue reconstruida: se restauró el título y la navegación de pestañas (Outbox, Bandeja General, Tickets, Cuarentena, Email Masivo, Campañas, Configuración) con badges seguros. Se eliminó el gran bloque `<style>` inline que duplicaba reglas.
  - Se removieron fragmentos JS sueltos y un bloque erróneo de código PHP duplicado que aparecía dentro de `render_ticket_detail()` (causaba ruptura visual y de sintaxis).
  - Se añadieron botones de acción coherentes en el formulario de respuesta de ticket para evitar contenido colado.

- Validaciones
  - Lint PHP del archivo editado y de todo el plugin: sin errores de sintaxis.

## 4) Guía de consolidación (pendiente sugerida)

- Migrar los estilos inline restantes de esta clase a `assets/css/admin-email.css` (quedaron algunos para modales de Bandeja General y tabla de Cuarentena) para un solo punto de verdad visual.
- Centralizar el CSS de badges de menú en `assets/css/admin.css` (o un archivo `assets/css/notifications.css`) y eliminar `wp_add_inline_style` si se desea máxima limpieza.
- Mantener una sola fuente de encolado para Admin Email: `qvaclick-email-manager.php`.

## 5) Comprobación visual

Tras los cambios:
- La cabecera y pestañas vuelven a mostrarse correctamente.
- Las badges de pestañas se posicionan con las reglas de `admin-email.css`.
- El contenido de cada pestaña carga su sección correspondiente sin estilos ad-hoc que rompan el layout.

Si detectas una vista concreta aún desalineada, indícame la pestaña/acción exacta y haré un pase fino moviendo los estilos inline restantes a `admin-email.css` con la prioridad adecuada.
