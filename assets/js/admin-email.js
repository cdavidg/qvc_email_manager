/**
 * Admin Email JavaScript
 * Funcionalidad para el sistema de Admin Email
 */

(function($) {
    'use strict';

    // Variables globales
    let adminEmail = {
        nonce: qvcEmailManager.nonce,
        ajaxurl: qvcEmailManager.ajaxurl,
        cache: {},
        currentRecipientType: 'all'
    };

    // Inicialización cuando el DOM está listo
    $(document).ready(function() {
        initAdminEmail();
    });

    /**
     * Inicializar funcionalidad de Admin Email
     */
    function initAdminEmail() {
        // Solo ejecutar en páginas de Admin Email
        if (!$('.qvc-admin-email-wrap').length) {
            return;
        }

        bindEvents();
        loadInitialData();
    }

    /**
     * Vincular eventos
     */
    function bindEvents() {
        // Cambio en selector de destinatarios
        $(document).on('change', '#recipient_type', handleRecipientTypeChange);
        $(document).on('input', '#recipient_filter', debounce(handleUserSearch, 300));

        // Selección de usuarios
        $(document).on('click', '.qvc-user-select-item', handleUserSelect);
        $(document).on('click', '.qvc-remove-selected-user', handleRemoveSelectedUser);

        // Envío de formulario de email masivo
        $(document).on('submit', '.qvc-mass-email-form', handleMassEmailSubmit);

        // Confirmación para envío inmediato
        $(document).on('click', 'button[name="send_now"]', handleSendNowConfirm);

        // Navegación entre tickets
        $(document).on('click', '.qvc-ticket-row', handleTicketNavigation);

        // Formulario de respuesta a ticket
        $(document).on('submit', '.qvc-reply-form form', handleTicketReply);

        // Filtros de tickets
        $(document).on('change', '.qvc-filters select', handleFilterChange);

        // Acciones de campaña
        $(document).on('click', '.qvc-send-campaign', handleSendCampaign);
        $(document).on('click', '.qvc-edit-campaign', handleEditCampaign);

        // Ver email desde bandeja de salida (pestaña)
        $(document).on('click', '.qvc-view-email', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            if (!id) return;
            const nonce = (window.adminEmail && window.adminEmail.viewNonce) ? window.adminEmail.viewNonce : '';
            const url = `${adminEmail.ajaxurl}?action=qvc_view_email_details&email_id=${id}${nonce ? `&nonce=${nonce}` : ''}`;
            window.open(url, 'EmailDetails', 'width=800,height=600,scrollbars=yes');
        });

        // Auto-save para borradores
        if ($('#content').length) {
            setInterval(autoSaveDraft, 30000); // Auto-save cada 30 segundos
        }
    }

    /**
     * Cargar datos iniciales
     */
    function loadInitialData() {
        // Cargar preview inicial de destinatarios si estamos en el composer
        if ($('#recipient_type').length) {
            loadRecipientPreview();
        }

        // Marcar tickets como leídos si estamos viendo uno
        if ($('.qvc-ticket-detail').length) {
            markTicketAsRead();
        }

        // Actualizar contadores
        updateUnreadCount();
    }

    /**
     * Manejar cambio en tipo de destinatario
     */
    function handleRecipientTypeChange() {
        const type = $(this).val();
        adminEmail.currentRecipientType = type;

        const filterRow = $('#recipient_filter_row');
        const filterInput = $('#recipient_filter');
        const userSearchResults = $('#user_search_results');
        const selectedUsers = $('#selected_users');

        if (type === 'specific_user') {
            filterRow.show();
            userSearchResults.show();
            selectedUsers.show();
            filterInput.attr('required', false);
            filterInput.attr('placeholder', 'Escribe para buscar usuarios...');
            
            // Limpiar búsqueda anterior
            clearUserSelection();
            
        } else if (type === 'custom_list') {
            filterRow.show();
            userSearchResults.hide();
            selectedUsers.hide();
            filterInput.attr('required', true);
            filterInput.attr('placeholder', 'Emails separados por comas (ej: user1@example.com, user2@example.com)');
            
        } else {
            filterRow.hide();
            userSearchResults.hide();
            selectedUsers.hide();
            filterInput.attr('required', false);
            filterInput.val('');
            clearUserSelection();
        }

        loadRecipientPreview();
    }
    
    /**
     * Manejar búsqueda de usuarios
     */
    function handleUserSearch() {
        const type = $('#recipient_type').val();
        const searchTerm = $('#recipient_filter').val().trim();
        
        if (type !== 'specific_user' || searchTerm.length < 2) {
            $('#search_results_list').empty();
            return;
        }
        
        // Mostrar loading
        $('#search_results_list').html('<div class="qvc-loading-search">Buscando usuarios...</div>');
        
        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_search_users',
                search_term: searchTerm,
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success && response.data.users) {
                    displayUserSearchResults(response.data.users);
                } else {
                    $('#search_results_list').html('<div class="qvc-no-results">No se encontraron usuarios</div>');
                }
            },
            error: function() {
                $('#search_results_list').html('<div class="qvc-error">Error al buscar usuarios</div>');
            }
        });
    }
    
    /**
     * Mostrar resultados de búsqueda de usuarios
     */
    function displayUserSearchResults(users) {
        const selectedIds = getSelectedUserIds();
        let html = '';
        
        users.forEach(user => {
            const isSelected = selectedIds.includes(user.id.toString());
            const disabledClass = isSelected ? 'disabled' : '';
            const disabledAttr = isSelected ? 'disabled' : '';
            
            html += `
                <div class="qvc-user-select-item ${disabledClass}" data-user-id="${user.id}" ${disabledAttr}>
                    <div class="qvc-user-info">
                        <strong>${escapeHtml(user.name)}</strong>
                        <small>${escapeHtml(user.email)} (${user.type})</small>
                    </div>
                    <button type="button" class="button button-small" ${disabledAttr}>
                        ${isSelected ? 'Seleccionado' : 'Seleccionar'}
                    </button>
                </div>
            `;
        });
        
        $('#search_results_list').html(html);
    }
    
    /**
     * Manejar selección de usuario
     */
    function handleUserSelect(e) {
        e.preventDefault();
        
        if ($(this).hasClass('disabled')) {
            return;
        }
        
        const userId = $(this).data('user-id');
        const userName = $(this).find('strong').text();
        const userEmail = $(this).find('small').text().split(' ')[0];
        
        addSelectedUser(userId, userName, userEmail);
        $(this).addClass('disabled').find('button').text('Seleccionado').prop('disabled', true);
        
        updateRecipientFilter();
        loadRecipientPreview();
    }
    
    /**
     * Agregar usuario seleccionado
     */
    function addSelectedUser(id, name, email) {
        const selectedList = $('#selected_users_list');
        
        // Remover mensaje de "ningún usuario"
        selectedList.find('em').remove();
        
        const userHtml = `
            <div class="qvc-selected-user" data-user-id="${id}">
                <span class="qvc-user-details">
                    <strong>${escapeHtml(name)}</strong> (${escapeHtml(email)})
                </span>
                <button type="button" class="qvc-remove-selected-user button button-small">×</button>
            </div>
        `;
        
        selectedList.append(userHtml);
        updateSelectedUserIds();
    }
    
    /**
     * Manejar eliminación de usuario seleccionado
     */
    function handleRemoveSelectedUser(e) {
        e.preventDefault();
        
        const userItem = $(this).closest('.qvc-selected-user');
        const userId = userItem.data('user-id');
        
        userItem.remove();
        
        // Rehabilitar en los resultados de búsqueda
        $(`.qvc-user-select-item[data-user-id="${userId}"]`)
            .removeClass('disabled')
            .find('button')
            .text('Seleccionar')
            .prop('disabled', false);
        
        // Si no quedan usuarios, mostrar mensaje
        if ($('#selected_users_list .qvc-selected-user').length === 0) {
            $('#selected_users_list').html('<em>Ningún usuario seleccionado</em>');
        }
        
        updateSelectedUserIds();
        updateRecipientFilter();
        loadRecipientPreview();
    }
    
    /**
     * Actualizar IDs de usuarios seleccionados
     */
    function updateSelectedUserIds() {
        const ids = [];
        $('#selected_users_list .qvc-selected-user').each(function() {
            ids.push($(this).data('user-id'));
        });
        $('#selected_user_ids').val(ids.join(','));
    }
    
    /**
     * Obtener IDs de usuarios seleccionados
     */
    function getSelectedUserIds() {
        const value = $('#selected_user_ids').val();
        return value ? value.split(',') : [];
    }
    
    /**
     * Actualizar filtro de destinatarios basado en selección
     */
    function updateRecipientFilter() {
        if ($('#recipient_type').val() === 'specific_user') {
            const ids = getSelectedUserIds();
            $('#recipient_filter').val(ids.join(','));
        }
    }
    
    /**
     * Limpiar selección de usuarios
     */
    function clearUserSelection() {
        $('#selected_users_list').html('<em>Ningún usuario seleccionado</em>');
        $('#selected_user_ids').val('');
        $('#search_results_list').empty();
        $('#recipient_filter').val('');

        loadRecipientPreview();
    }

    /**
     * Cargar preview de destinatarios
     */
    function loadRecipientPreview() {
        const type = $('#recipient_type').val();
        const filter = $('#recipient_filter').val();
        const preview = $('#recipient_preview');

        // Usar cache para evitar requests repetitivos
        const cacheKey = `${type}_${filter}`;
        if (adminEmail.cache[cacheKey]) {
            updatePreview(adminEmail.cache[cacheKey]);
            return;
        }

        // Mostrar loading
        preview.html('<div class="qvc-loading-preview">Cargando destinatarios...</div>').show();

        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_load_recipient_preview',
                recipient_type: type,
                recipient_filter: filter,
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success) {
                    adminEmail.cache[cacheKey] = response.data.preview;
                    updatePreview(response.data.preview);
                } else {
                    preview.html('<div class="notice notice-error"><p>Error al cargar destinatarios</p></div>');
                }
            },
            error: function() {
                preview.html('<div class="notice notice-error"><p>Error de conexión</p></div>');
            }
        });
    }

    /**
     * Actualizar preview de destinatarios
     */
    function updatePreview(content) {
        const preview = $('#recipient_preview');
        preview.html(content).show();

        // Highlight si hay muchos destinatarios
        const recipientCount = (content.match(/destinatarios:/i) || [''])[0];
        if (recipientCount && parseInt(recipientCount.match(/\d+/)) > 100) {
            preview.addClass('qvc-high-volume-warning');
        }
    }

    /**
     * Manejar envío de email masivo
     */
    function handleMassEmailSubmit(e) {
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const sendNowBtn = form.find('button[name="send_now"]');

        // Deshabilitar botones durante el envío
        submitBtn.prop('disabled', true);
        sendNowBtn.prop('disabled', true);

        // Validaciones adicionales
        const recipientType = $('#recipient_type').val();
        const recipientFilter = $('#recipient_filter').val();

        if ((recipientType === 'specific_user' || recipientType === 'custom_list') && !recipientFilter.trim()) {
            showNotice('error', 'Debe especificar el filtro de destinatarios');
            e.preventDefault();
            submitBtn.prop('disabled', false);
            sendNowBtn.prop('disabled', false);
            return;
        }

        // Si no es envío inmediato, permitir continuar
        if (!$(e.originalEvent.submitter).is('[name="send_now"]')) {
            return;
        }

        // Para envío inmediato, verificar confirmación
        e.preventDefault();
        const recipientCount = getRecipientCount();
        
        const confirmMessage = `¿Está seguro de enviar este email inmediatamente a ${recipientCount} destinatarios?\n\nEsta acción no se puede deshacer.`;
        
        if (confirm(confirmMessage)) {
            // Cambiar texto del botón
            sendNowBtn.html('<span class="dashicons dashicons-update-alt"></span> Enviando...');
            
            // Enviar formulario
            form.off('submit').submit();
        } else {
            submitBtn.prop('disabled', false);
            sendNowBtn.prop('disabled', false);
        }
    }

    /**
     * Confirmar envío inmediato
     */
    function handleSendNowConfirm(e) {
        const recipientCount = getRecipientCount();
        const message = `¿Enviar inmediatamente a ${recipientCount} destinatarios?`;
        
        if (!confirm(message)) {
            e.preventDefault();
        }
    }

    /**
     * Obtener número de destinatarios del preview
     */
    function getRecipientCount() {
        const preview = $('#recipient_preview').text();
        const match = preview.match(/(\d+)\s+destinatarios/i);
        return match ? match[1] : '?';
    }

    /**
     * Navegación entre tickets
     */
    function handleTicketNavigation(e) {
        // No navegar si se hizo clic en un enlace o botón
        if ($(e.target).is('a, button, .button')) {
            return;
        }

        const ticketId = $(this).data('ticket-id');
        if (ticketId) {
            window.location.href = `admin.php?page=qvc-admin-email&action=inbox&ticket_id=${ticketId}`;
        }
    }

    /**
     * Manejar respuesta a ticket
     */
    function handleTicketReply(e) {
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        const replyContent = form.find('[name="reply_content"]').val().trim();

        if (!replyContent) {
            showNotice('error', 'La respuesta no puede estar vacía');
            e.preventDefault();
            return;
        }

        // Cambiar texto del botón
        submitBtn.html('<span class="dashicons dashicons-update-alt"></span> Enviando...');
        submitBtn.prop('disabled', true);

        // El formulario se envía normalmente (no AJAX para este caso)
        // pero mostramos feedback visual
        setTimeout(() => {
            if (!form.find('.notice').length) {
                submitBtn.html('Enviar Respuesta');
                submitBtn.prop('disabled', false);
            }
        }, 5000);
    }

    /**
     * Manejar cambios en filtros
     */
    function handleFilterChange() {
        const form = $(this).closest('form');
        form.submit();
    }

    /**
     * Enviar campaña existente
     */
    function handleSendCampaign(e) {
        e.preventDefault();
        
        const campaignId = $(this).data('campaign-id');
        const campaignName = $(this).data('campaign-name');
        
        if (!confirm(`¿Enviar la campaña "${campaignName}" ahora?`)) {
            return;
        }

        const btn = $(this);
        const originalText = btn.text();
        
        btn.html('<span class="dashicons dashicons-update-alt"></span> Enviando...').prop('disabled', true);

        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_send_mass_email_campaign',
                campaign_id: campaignId,
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message);
                    // Recargar la página para mostrar estadísticas actualizadas
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showNotice('error', response.data.message || 'Error al enviar campaña');
                    btn.html(originalText).prop('disabled', false);
                }
            },
            error: function() {
                showNotice('error', 'Error de conexión');
                btn.html(originalText).prop('disabled', false);
            }
        });
    }

    /**
     * Editar campaña
     */
    function handleEditCampaign(e) {
        e.preventDefault();
        // TODO: Implementar editor de campañas
        showNotice('info', 'Función de edición en desarrollo');
    }

    /**
     * Auto-guardar borrador
     */
    function autoSaveDraft() {
        if (!$('#content').length || !$('.qvc-mass-email-form').length) {
            return;
        }

        const form = $('.qvc-mass-email-form');
        const data = form.serialize() + '&action=qvc_auto_save_draft&nonce=' + adminEmail.nonce;

        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    showAutoSaveNotice();
                }
            }
        });
    }

    /**
     * Marcar ticket como leído
     */
    function markTicketAsRead() {
        const ticketId = getTicketIdFromUrl();
        if (!ticketId) return;

        // Actualizar visualmente
        $(`.qvc-ticket-row[data-ticket-id="${ticketId}"]`).removeClass('unread');
        
        // Notificar al servidor (opcional, para tracking)
        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_mark_ticket_read',
                ticket_id: ticketId,
                nonce: adminEmail.nonce
            }
        });
    }

    /**
     * Actualizar contador de no leídos
     */
    function updateUnreadCount() {
        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_get_unread_count',
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success && response.data.count !== undefined) {
                    updateUnreadBadge(response.data.count);
                }
            }
        });
    }

    /**
     * Actualizar badge de no leídos
     */
    function updateUnreadBadge(count) {
        const badge = $('.qvc-unread-badge');
        if (count > 0) {
            if (badge.length) {
                badge.text(count);
            } else {
                $('.nav-tab[href*="inbox"]').append(`<span class="qvc-unread-badge">${count}</span>`);
            }
        } else {
            badge.remove();
        }
    }

    /**
     * Mostrar notificación
     */
    function showNotice(type, message) {
        const notice = $(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
        $('.qvc-admin-email-wrap h1').after(notice);
        
        // Auto-ocultar después de 5 segundos
        setTimeout(() => {
            notice.fadeOut();
        }, 5000);
    }

    /**
     * Mostrar notificación de auto-guardado
     */
    function showAutoSaveNotice() {
        const notice = $('#qvc-autosave-notice');
        if (notice.length) {
            notice.show().delay(2000).fadeOut();
        } else {
            $('<div id="qvc-autosave-notice" style="position:fixed;top:32px;right:20px;background:#00a32a;color:white;padding:8px 12px;border-radius:3px;z-index:9999;">Borrador guardado</div>')
                .appendTo('body')
                .delay(2000)
                .fadeOut();
        }
    }

    /**
     * Obtener ID del ticket desde la URL
     */
    function getTicketIdFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('ticket_id');
    }

    /**
     * Debounce function para evitar múltiples requests
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Validar email
     */
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    /**
     * Formatear números
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // Funciones de campañas
    function handleViewCampaign(id) {
        window.open(adminEmail.ajaxurl + '?action=qvc_view_campaign&id=' + id, '_blank');
    }
    
    function handleDuplicateCampaign(id) {
        if (!confirm('¿Estás seguro de que quieres duplicar esta campaña?')) {
            return;
        }
        
        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_duplicate_campaign',
                id: id,
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Campaña duplicada exitosamente', 'success');
                    loadCampaignsTable();
                } else {
                    showNotification('Error al duplicar campaña: ' + response.data, 'error');
                }
            },
            error: function() {
                showNotification('Error de conexión al duplicar campaña', 'error');
            }
        });
    }
    
    function handleDeleteCampaign(id) {
        if (!confirm('¿Estás seguro de que quieres eliminar esta campaña? Esta acción no se puede deshacer.')) {
            return;
        }
        
        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_delete_campaign',
                id: id,
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Campaña eliminada exitosamente', 'success');
                    loadCampaignsTable();
                } else {
                    showNotification('Error al eliminar campaña: ' + response.data, 'error');
                }
            },
            error: function() {
                showNotification('Error de conexión al eliminar campaña', 'error');
            }
        });
    }
    
    function loadCampaignsTable() {
        $.ajax({
            url: adminEmail.ajaxurl,
            type: 'POST',
            data: {
                action: 'qvc_get_campaigns',
                nonce: adminEmail.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#campaigns-table tbody').html(response.data.html);
                }
            }
        });
    }
    
    // Función auxiliar para escapar HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Función para mostrar notificaciones
    function showNotification(message, type) {
        const notification = $(`
            <div class="notice notice-${type === 'success' ? 'success' : 'error'} is-dismissible" style="margin: 10px 0;">
                <p>${escapeHtml(message)}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        if ($('#admin-email-notifications').length) {
            $('#admin-email-notifications').html(notification);
        } else {
            $('.wrap h1').after(notification);
        }
        
        setTimeout(function() {
            notification.fadeOut();
        }, 5000);
        
        notification.find('.notice-dismiss').on('click', function() {
            notification.fadeOut();
        });
    }

    // Exponer funciones públicas si es necesario
    window.QvcAdminEmail = {
        loadRecipientPreview: loadRecipientPreview,
        showNotice: showNotice,
        updateUnreadCount: updateUnreadCount
    };

})(jQuery);
