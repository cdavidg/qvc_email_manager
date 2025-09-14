/**
 * Script independiente para eliminar tickets y limpiar tickets resueltos
 * No depende de la clase PHP para funcionar
 */

jQuery(document).ready(function($) {
    console.log("🚀 Script independiente de tickets cargado");
    
    // Verificar si ajaxurl existe globalmente
    if (typeof ajaxurl === 'undefined') {
        window.ajaxurl = '/wp-admin/admin-ajax.php';
    }
    
    // FUNCIÓN: Eliminar ticket individual
    $(document).on('click', '#qvc-delete-ticket', function(e) {
        e.preventDefault();
        console.log("🎯 Click en eliminar ticket detectado");
        
        const ticketId = $(this).data('ticket-id');
        console.log("📋 Ticket ID:", ticketId);
        
        if (!ticketId) {
            alert('❌ Error: No se pudo obtener el ID del ticket');
            return;
        }
        
        const confirmMessage = '¿Estás seguro de que quieres eliminar este ticket?\n\n' +
                              'Esta acción NO se puede deshacer y eliminará:\n' +
                              '• El ticket principal\n' +
                              '• Todos los mensajes asociados\n' +
                              '• Todo el historial de conversación\n\n' +
                              'Solo hazlo si estás seguro de que es spam o contenido malicioso.';
        
        if (!confirm(confirmMessage)) {
            console.log("❌ Eliminación cancelada por el usuario");
            return;
        }
        
        // Segundo nivel de confirmación
        const finalConfirm = prompt('Para confirmar la eliminación, escribe "ELIMINAR" en mayúsculas:');
        if (finalConfirm !== 'ELIMINAR') {
            alert('Eliminación cancelada.');
            console.log("❌ Eliminación cancelada - confirmación incorrecta");
            return;
        }
        
        const $button = $(this);
        const originalText = $button.html();
        
        $button.html('🔄 Eliminando...').prop('disabled', true);
        console.log("📡 Enviando petición AJAX...");
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_delete_ticket',
                ticket_id: ticketId,
                nonce: $('#_wpnonce').val() || 'fallback_nonce' // Usar nonce de la página o fallback
            },
            success: function(response) {
                console.log("✅ Respuesta AJAX recibida:", response);
                
                if (response.success) {
                    alert('✅ Ticket eliminado correctamente');
                    
                    // Redirigir a la bandeja principal
                    window.location.href = '/wp-admin/admin.php?page=qvc-admin-email&action=inbox';
                } else {
                    console.log("❌ Error en respuesta:", response);
                    alert('❌ Error: ' + (response.data || 'Error desconocido'));
                    $button.html(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.log("❌ Error AJAX:", xhr, status, error);
                alert('❌ Error de conexión: ' + error);
                $button.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // FUNCIÓN: Limpiar tickets resueltos
    $(document).on('click', '#qvc-clean-resolved', function(e) {
        e.preventDefault();
        console.log("🧹 Click en limpiar tickets resueltos detectado");
        
        const confirmMessage = '¿Quieres limpiar los tickets resueltos antiguos?\n\n' +
                              'Esta acción eliminará permanentemente:\n' +
                              '• Tickets con estado "resuelto" o "cerrado"\n' +
                              '• Que tengan más de 30 días de antigüedad\n' +
                              '• Todos sus mensajes asociados\n\n' +
                              'Esto ayudará a mantener la base de datos limpia.';
        
        if (!confirm(confirmMessage)) {
            console.log("❌ Limpieza cancelada por el usuario");
            return;
        }
        
        const $button = $(this);
        const originalText = $button.html();
        
        $button.html('🔄 Limpiando...').prop('disabled', true);
        console.log("📡 Enviando petición AJAX para limpiar...");
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_clean_resolved_tickets',
                nonce: $('#_wpnonce').val() || 'fallback_nonce'
            },
            success: function(response) {
                console.log("✅ Respuesta de limpieza recibida:", response);
                
                if (response.success) {
                    alert('✅ ' + response.data.message);
                    location.reload();
                } else {
                    console.log("❌ Error en limpieza:", response);
                    alert('❌ Error: ' + (response.data || 'Error desconocido'));
                }
                
                $button.html(originalText).prop('disabled', false);
            },
            error: function(xhr, status, error) {
                console.log("❌ Error AJAX en limpieza:", xhr, status, error);
                alert('❌ Error de conexión: ' + error);
                $button.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Función de diagnóstico
    function diagnosticar() {
        console.log("🔍 === DIAGNÓSTICO DE BOTONES ===");
        console.log("🎯 Botón eliminar encontrado:", $('#qvc-delete-ticket').length > 0);
        console.log("🧹 Botón limpiar encontrado:", $('#qvc-clean-resolved').length > 0);
        console.log("📋 AJAX URL disponible:", typeof ajaxurl !== 'undefined' ? ajaxurl : 'NO DISPONIBLE');
        console.log("🔐 Nonce disponible:", $('#_wpnonce').length > 0 ? 'SÍ' : 'NO');
        
        if ($('#qvc-delete-ticket').length > 0) {
            console.log("📄 ID del ticket:", $('#qvc-delete-ticket').data('ticket-id'));
        }
        
        console.log("🏁 === FIN DIAGNÓSTICO ===");
    }
    
    // Ejecutar diagnóstico automáticamente
    setTimeout(diagnosticar, 1000);
    
    // Exponer función globalmente para debug manual
    window.qvcDiagnosticar = diagnosticar;
});

// Agregar CSS para debug visual
jQuery(document).ready(function($) {
    $('head').append(`
        <style>
        #qvc-delete-ticket:hover, #qvc-clean-resolved:hover {
            background-color: #f0f0f0 !important;
            border: 2px solid red !important;
        }
        #qvc-delete-ticket, #qvc-clean-resolved {
            position: relative;
        }
        #qvc-delete-ticket::after {
            content: "DEBUG: Click detector activo";
            position: absolute;
            top: -20px;
            left: 0;
            font-size: 10px;
            color: green;
            background: yellow;
            padding: 2px;
            display: none;
        }
        #qvc-delete-ticket:hover::after, #qvc-clean-resolved:hover::after {
            display: block;
        }
        </style>
    `);
});
