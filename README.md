# QvaClick Email Manager V1

QvaClick Email Manager V1 es un plugin avanzado de WordPress para gestionar correos: bandejas, tickets de soporte, campañas de email masivo, plantillas y sincronización con Exertio/Redux.

## Características
- Campañas de email masivo con vista previa y segmentación
- Sistema de tickets (recepción, respuesta, estados, cuarentena)
- Bandeja de salida y bandeja general
- Plantillas y sincronización con Redux/Exertio
- Integración CF7 y lector IMAP (endurecido)
- Envío SMTP configurable

## Requisitos
- WordPress >= 5.8
- PHP >= 7.4
- Tema/Framework Exertio activos

## Instalación (manual)
1. Copia la carpeta `qvaclick-email-manager-v1` a `wp-content/plugins/`
2. Activa el plugin en el panel de WordPress

## Desarrollo
- Rama de trabajo: `export-to-github-plugin`
- Versionado en `qvaclick-email-manager.php` (cabecera y constante `QVC_EMAIL_MANAGER_VERSION`)
- Scripts/CSS admin en `assets/`
- Código principal en `includes/` y clase bootstrap `qvaclick-email-manager.php`

## Seguridad y buenas prácticas
- `.gitignore` evita subir `backups/`, logs y artefactos locales
- AJAX protegido por nonces (`qvc_email_nonce` / `qvc_admin_nonce` donde aplique)
- Capacidades: `qvc_manage_emails` o `manage_options`

## Publicación
- Crea un tag semántico (ej. `v3.2.2`) para marcar releases
- Changelog en `CHANGELOG.md`

## Licencia
GPL-2.0-or-later
