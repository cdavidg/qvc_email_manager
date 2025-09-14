/**
 * QvaClick Email Manager - Security Enhanced Admin Scripts
 * Gestión de tickets con funciones de seguridad mejoradas
 */

jQuery(document).ready(function($) {
    
    // Eliminar ticket individual
    $(document).on('click', '#qvc-delete-ticket', function(e) {
        e.preventDefault();
        
        const ticketId = $(this).data('ticket-id');
        const confirmMessage = '¿Estás seguro de que quieres eliminar este ticket?\n\n' +
                              'Esta acción NO se puede deshacer y eliminará:\n' +
                              '• El ticket principal\n' +
                              '• Todos los mensajes asociados\n' +
                              '• Todo el historial de conversación\n\n' +
                              'Solo hazlo si estás seguro de que es spam o contenido malicioso.';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Segundo nivel de confirmación para tickets críticos
        const finalConfirm = prompt('Para confirmar la eliminación, escribe "ELIMINAR" en mayúsculas:');
        if (finalConfirm !== 'ELIMINAR') {
            alert('Eliminación cancelada.');
            return;
        }
        
        const $button = $(this);
        const originalText = $button.html();
        
        $button.html('🔄 Eliminando...').prop('disabled', true);
        
        $.ajax({
            url: qvc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'qvc_delete_ticket',
                ticket_id: ticketId,
                nonce: qvc_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ Ticket eliminado correctamente');
                    
                    // Redirigir a la bandeja principal
                    window.location.href = qvc_ajax.admin_url + 'admin.php?page=qvc-admin-email&action=inbox';
                } else {
                    alert('❌ Error: ' + (response.data || 'Error desconocido'));
                    $button.html(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('❌ Error de conexión: ' + error);
                $button.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Limpiar tickets resueltos
    $(document).on('click', '#qvc-clean-resolved', function(e) {
        e.preventDefault();
        
        const confirmMessage = '¿Quieres limpiar los tickets resueltos antiguos?\n\n' +
                              'Esta acción eliminará permanentemente:\n' +
                              '• Tickets con estado "resuelto" o "cerrado"\n' +
                              '• Que tengan más de 30 días de antigüedad\n' +
                              '• Todos sus mensajes asociados\n\n' +
                              'Esto ayudará a mantener la base de datos limpia.';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        const $button = $(this);
        const originalText = $button.html();
        
        $button.html('🔄 Limpiando...').prop('disabled', true);
        
        $.ajax({
            url: qvc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'qvc_clean_resolved_tickets',
                nonce: qvc_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ ' + response.data.message);
                    
                    // Recargar la página para mostrar los cambios
                    location.reload();
                } else {
                    alert('❌ Error: ' + (response.data || 'Error desconocido'));
                }
                
                $button.html(originalText).prop('disabled', false);
            },
            error: function(xhr, status, error) {
                alert('❌ Error de conexión: ' + error);
                $button.html(originalText).prop('disabled', false);
            }
        });
    });
    
    // Funciones de cuarentena
    $(document).on('click', '.qvc-quarantine-approve', function(e) {
        e.preventDefault();
        handleQuarantineAction($(this), 'approve');
    });
    
    $(document).on('click', '.qvc-quarantine-reject', function(e) {
        e.preventDefault();
        handleQuarantineAction($(this), 'reject');
    });
    
    $(document).on('click', '.qvc-quarantine-delete', function(e) {
        e.preventDefault();
        
        if (!confirm('¿Eliminar este elemento permanentemente?')) {
            return;
        }
        
        handleQuarantineAction($(this), 'delete');
    });
    
    function handleQuarantineAction($button, action) {
        const itemId = $button.data('item-id');
        const adminNotes = prompt('Notas del administrador (opcional):') || '';
        
        const originalText = $button.html();
        $button.html('🔄').prop('disabled', true);
        
        $.ajax({
            url: qvc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'qvc_quarantine_action',
                quarantine_action: action,
                item_id: itemId,
                admin_notes: adminNotes,
                nonce: qvc_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ Acción completada');
                    $button.closest('tr').fadeOut();
                } else {
                    alert('❌ Error: ' + (response.data || 'Error desconocido'));
                    $button.html(originalText).prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                alert('❌ Error de conexión: ' + error);
                $button.html(originalText).prop('disabled', false);
            }
        });
    }
    
    // Mejorar la interfaz de cuarentena
    if ($('.qvc-quarantine-page').length > 0) {
        // Auto-refresh cada 30 segundos para cuarentena
        setInterval(function() {
            const currentPage = window.location.href;
            if (currentPage.includes('action=quarantine')) {
                $('.qvc-quarantine-stats').load(currentPage + ' .qvc-quarantine-stats > *');
            }
        }, 30000);
        
        // Filtro rápido por nivel de amenaza
        $('<div class="threat-level-filter" style="margin: 10px 0;">' +
          '<label>Filtrar por amenaza: </label>' +
          '<select id="threat-filter">' +
          '<option value="">Todos</option>' +
          '<option value="low">Baja</option>' +
          '<option value="medium">Media</option>' +
          '<option value="high">Alta</option>' +
          '<option value="critical">Crítica</option>' +
          '</select>' +
          '</div>').insertBefore('.qvc-quarantine-table');
        
        $('#threat-filter').change(function() {
            const level = $(this).val();
            if (level === '') {
                $('.quarantine-item').show();
            } else {
                $('.quarantine-item').hide();
                $('.quarantine-item[data-threat-level="' + level + '"]').show();
            }
        });
    }
    
    // Notificaciones en tiempo real para nuevas amenazas
    function checkForNewThreats() {
        if (!$('.qvc-quarantine-page').length) return;
        
        $.ajax({
            url: qvc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'qvc_get_quarantine_count',
                nonce: qvc_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data.count > 0) {
                    const currentCount = $('.qvc-quarantine-badge').text() || '0';
                    if (parseInt(response.data.count) > parseInt(currentCount)) {
                        // Nueva amenaza detectada
                        showSecurityNotification('Nueva amenaza detectada en cuarentena', 'warning');
                    }
                }
            }
        });
    }
    
    function showSecurityNotification(message, type = 'info') {
        const $notification = $('<div class="qvc-security-notification">')
            .addClass('notice notice-' + type)
            .html('<p><strong>🛡️ Seguridad:</strong> ' + message + '</p>')
            .css({
                position: 'fixed',
                top: '32px',
                right: '20px',
                zIndex: 999999,
                maxWidth: '400px'
            });
        
        $('body').append($notification);
        
        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // Verificar amenazas cada minuto
    setInterval(checkForNewThreats, 60000);
    
    // Protección adicional: confirmar acciones destructivas
    $('form').on('submit', function(e) {
        const $form = $(this);
        
        // Si es una acción de eliminación masiva
        if ($form.find('select[name="quarantine_action"]').val() === 'delete') {
            const checkedItems = $form.find('input[name="item_ids[]"]:checked').length;
            
            if (checkedItems > 0) {
                const confirmMsg = `¿Eliminar permanentemente ${checkedItems} elementos?\n\nEsta acción NO se puede deshacer.`;
                
                if (!confirm(confirmMsg)) {
                    e.preventDefault();
                    return false;
                }
            }
        }
    });
    
    // Keyboard shortcuts para acciones rápidas
    $(document).keydown(function(e) {
        // Ctrl+Shift+D = Eliminar ticket (solo en vista de detalle)
        if (e.ctrlKey && e.shiftKey && e.which === 68) {
            if ($('#qvc-delete-ticket').length) {
                e.preventDefault();
                $('#qvc-delete-ticket').click();
            }
        }
        
        // Ctrl+Shift+C = Limpiar tickets resueltos
        if (e.ctrlKey && e.shiftKey && e.which === 67) {
            if ($('#qvc-clean-resolved').length) {
                e.preventDefault();
                $('#qvc-clean-resolved').click();
            }
        }
    });
    
    // Mostrar tooltips informativos
    if (typeof $.fn.tooltip !== 'undefined') {
        $('.threat-level-badge, .status-badge, .threat-tag').tooltip();
    }
});
