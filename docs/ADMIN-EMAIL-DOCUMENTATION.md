# Admin Email - Sistema de Tickets y Emails Masivos

## Descripción

La nueva sección **Admin Email** del plugin QvaClick Email Manager proporciona un sistema completo para:

1. **Gestión de tickets de soporte** - Bandeja de entrada centralizada
2. **Envío de emails masivos** - Comunicación masiva segmentada
3. **Seguimiento de campañas** - Analytics de emails enviados
4. **Configuración avanzada** - Personalización del sistema

## Características Principales

### 🎫 Sistema de Tickets de Soporte

- **Bandeja de entrada unificada** con todos los tickets
- **Clasificación por prioridad** (Baja, Normal, Alta, Urgente)
- **Estados de ticket** (Abierto, En progreso, Resuelto, Cerrado)
- **Filtros avanzados** por estado, prioridad, tipo de usuario
- **Hilo de conversación** completo entre usuario y admin
- **Respuesta directa** desde el panel de administración
- **Notificaciones automáticas** por email al usuario

### 📧 Envío de Emails Masivos

- **Segmentación de usuarios**:
  - Todos los usuarios
  - Solo Freelancers
  - Solo Employers
  - Solo Administradores
  - Usuario específico
  - Lista personalizada de emails

- **Composer avanzado** con editor WYSIWYG
- **Preview de destinatarios** en tiempo real
- **Variables dinámicas** ({{user_name}}, {{user_email}}, etc.)
- **Programación de envío** (guardar borrador vs envío inmediato)
- **Integración con plantilla base** del plugin

### 📊 Gestión de Campañas

- **Lista de todas las campañas** enviadas y borradores
- **Estadísticas de envío** (enviados, fallidos, abiertos)
- **Re-envío de campañas** guardadas como borrador
- **Tracking de emails** con IDs únicos
- **Historial completo** de actividad

### ⚙️ Configuración

- **Email de soporte personalizado**
- **Nombre del remitente**
- **Asignación automática** de tickets
- **Categorías personalizables** de tickets
- **Configuración de notificaciones**

## Estructura de Base de Datos

### Tabla: qvc_support_tickets
```sql
- id (bigint) - ID único del ticket
- ticket_id (varchar) - ID público del ticket (TKT-XXXXXXXX)
- user_id (bigint) - ID del usuario (puede ser NULL para invitados)
- user_email (varchar) - Email del usuario
- user_name (varchar) - Nombre del usuario
- user_type (enum) - Tipo: freelancer, employer, admin, guest
- subject (varchar) - Asunto del ticket
- status (enum) - Estado: open, in_progress, resolved, closed
- priority (enum) - Prioridad: low, normal, high, urgent
- category (varchar) - Categoría del ticket
- created_at (timestamp) - Fecha de creación
- updated_at (timestamp) - Última actualización
- assigned_to (bigint) - Admin asignado
- resolved_at (timestamp) - Fecha de resolución
```

### Tabla: qvc_ticket_messages
```sql
- id (bigint) - ID único del mensaje
- ticket_id (varchar) - ID del ticket padre
- user_id (bigint) - ID del usuario que escribió
- user_email (varchar) - Email del usuario
- user_name (varchar) - Nombre del usuario
- user_type (enum) - Tipo de usuario
- message (longtext) - Contenido del mensaje
- is_admin_reply (tinyint) - 1 si es respuesta de admin
- attachments (text) - JSON de archivos adjuntos
- created_at (timestamp) - Fecha del mensaje
- read_at (timestamp) - Fecha de lectura
```

### Tabla: qvc_mass_emails
```sql
- id (bigint) - ID único de la campaña
- campaign_name (varchar) - Nombre de la campaña
- subject (varchar) - Asunto del email
- content (longtext) - Contenido HTML
- recipient_type (enum) - Tipo de destinatarios
- recipient_filter (text) - Filtro adicional
- status (enum) - Estado: draft, scheduled, sending, sent, failed
- scheduled_at (timestamp) - Fecha programada
- sent_at (timestamp) - Fecha real de envío
- total_recipients (int) - Total de destinatarios
- sent_count (int) - Emails enviados exitosamente
- failed_count (int) - Emails fallidos
- created_by (bigint) - ID del admin que creó
- created_at (timestamp) - Fecha de creación
```

### Tabla: qvc_mass_email_logs
```sql
- id (bigint) - ID único del log
- mass_email_id (bigint) - ID de la campaña
- recipient_email (varchar) - Email del destinatario
- recipient_user_id (bigint) - ID del usuario destinatario
- status (enum) - Estado: sent, failed, bounced, opened, clicked
- sent_at (timestamp) - Fecha de envío
- opened_at (timestamp) - Fecha de apertura
- clicked_at (timestamp) - Fecha de clic
- error_message (text) - Mensaje de error si falló
- tracking_id (varchar) - ID único de tracking
```

## Archivos Creados

### Backend (PHP)
- `includes/class-admin-email-manager.php` - Lógica principal del sistema
- `includes/class-admin-email-interface.php` - Interfaces de administración

### Frontend (CSS/JS)
- `assets/css/admin-email.css` - Estilos específicos
- `assets/js/admin-email.js` - Funcionalidad JavaScript

### Integración
- Modificado `qvaclick-email-manager.php` para cargar las nuevas clases
- Agregado nuevo menú "Admin Email" en la navegación

## Uso

### Acceso al Sistema
1. Ve a **Email Manager > Admin Email** en el panel de WordPress
2. Usa las pestañas para navegar entre funciones:
   - **Bandeja de Entrada** - Ver y responder tickets
   - **Email Masivo** - Crear nuevas campañas
   - **Campañas** - Ver historial y estadísticas
   - **Configuración** - Ajustar preferencias

### Crear Email Masivo
1. Ve a la pestaña "Email Masivo"
2. Completa el formulario:
   - Nombre de campaña
   - Selecciona destinatarios
   - Escribe asunto y contenido
3. Usa "Guardar Borrador" o "Enviar Ahora"

### Responder Tickets
1. Ve a "Bandeja de Entrada"
2. Haz clic en cualquier ticket para abrirlo
3. Lee el hilo de conversación
4. Escribe tu respuesta en el formulario inferior
5. Cambia el estado del ticket si es necesario
6. Haz clic en "Enviar Respuesta"

## Variables Disponibles

En los emails masivos puedes usar estas variables:

- `{{user_name}}` - Nombre del usuario
- `{{user_email}}` - Email del usuario
- `{{site_name}}` - Nombre del sitio
- `{{site_url}}` - URL del sitio

## Seguridad

- Todas las acciones requieren la capacidad `qvc_manage_emails`
- Verificación de nonce en todas las operaciones AJAX
- Sanitización completa de datos de entrada
- Escape de salida HTML
- Validación de permisos por cada acción

## Integración con Exertio Framework

- Utiliza la plantilla base del sistema existente
- Compatible con las variables del framework
- Respeta la configuración de Redux/Theme Options
- Se integra con el sistema de usuarios existente

## APIs Disponibles

### AJAX Endpoints
- `qvc_load_recipient_preview` - Cargar preview de destinatarios
- `qvc_create_support_ticket` - Crear nuevo ticket (para frontend)
- `qvc_send_mass_email_campaign` - Enviar campaña existente

### Hooks de WordPress
- `qvc_ticket_created` - Se dispara al crear un ticket
- `qvc_ticket_replied` - Se dispara al responder un ticket
- `qvc_mass_email_sent` - Se dispara al enviar email masivo

## Futuras Mejoras

- [ ] API REST completa para integraciones externas
- [ ] Webhooks para notificaciones en tiempo real
- [ ] Templates de respuesta predefinidos
- [ ] Auto-respuestas inteligentes
- [ ] Integración con sistemas de helpdesk externos
- [ ] Analytics avanzados con gráficos
- [ ] Exportación de datos a CSV/PDF
- [ ] Sistema de etiquetas para tickets
- [ ] Asignación automática basada en categorías
- [ ] SLA y tiempos de respuesta
- [ ] Satisfacción del cliente (ratings)
- [ ] Chat en vivo integrado

## Troubleshooting

### Emails no se envían
1. Verifica la configuración SMTP de WordPress
2. Revisa los logs de PHP por errores
3. Confirma que el plugin esté activado correctamente

## Changelog (reciente)

- 2025-09-14: Bugfix - Se corrigió la función `get_mass_email_recipients` para usar las APIs
   de WordPress (`get_users`, `WP_User_Query`) en lugar de consultas SQL frágiles. Esto
   evita errores fatales al construir la lista de destinatarios cuando falta metadata
   o roles, y filtra direcciones inválidas. También se agregó logging para facilitar
   el diagnóstico de conteos de destinatarios. Recomendado: revisar batching/paginación
   antes de enviar campañas a listas muy grandes.

### Tickets no aparecen
1. Verifica que las tablas se hayan creado correctamente
2. Comprueba permisos de usuario
3. Revisa la configuración de la base de datos

### Destinatarios no se cargan
1. Verifica la conexión AJAX
2. Comprueba que los usuarios tengan los roles correctos
3. Revisa la consola del navegador por errores JavaScript

Para soporte adicional, revisa los logs del plugin en `wp-content/debug.log` o contacta al equipo de desarrollo.
