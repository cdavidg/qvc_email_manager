/**
 * QvaClick Email Manager Admin JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        QvcEmailManager.init();
    });
    
    /**
     * Main QvaClick Email Manager object
     */
    window.QvcEmailManager = {
        
        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.initDashboard();
            this.initBaseTemplateEditor();
            this.initTemplatesList();
            this.initTemplateEditor();
        },
        
        /**
         * Bind global events
         */
        bindEvents: function() {
            // Preview modal close
            $(document).on('click', '.qvc-modal-close, #qvc-preview-modal', function(e) {
                if (e.target === this) {
                    QvcEmailManager.closeModal();
                }
            });
            
            // ESC key to close modal
            $(document).on('keyup', function(e) {
                if (e.keyCode === 27) { // ESC key
                    QvcEmailManager.closeModal();
                }
            });
            
            // Template preview buttons
            $(document).on('click', '.qvc-preview-template', function(e) {
                e.preventDefault();
                var templateKey = $(this).data('template');
                QvcEmailManager.previewTemplate(templateKey);
            });
        },
        
        /**
         * Initialize dashboard functionality
         */
        initDashboard: function() {
            if (!$('.qvc-email-dashboard').length) return;
            
            // Apply base template to all
            $('#qvc-apply-base-all').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm(qvcEmailManager.messages.confirmApplyAll)) {
                    return;
                }
                
                QvcEmailManager.applyBaseTemplateToAll($(this));
            });
            
            // Animate stats cards
            $('.qvc-stat-card').each(function(index) {
                $(this).delay(index * 100).animate({
                    opacity: 1,
                    transform: 'translateY(0)'
                }, 300);
            });
        },
        
        /**
         * Initialize base template editor
         */
        initBaseTemplateEditor: function() {
            if (!$('.qvc-base-template-editor').length) return;
            
            // Select all templates functionality
            $('#select-all-templates').on('change', function() {
                $('input[name="selected_templates[]"]').prop('checked', this.checked);
                QvcEmailManager.updateSelectedCount();
            });
            
            // Update count when individual checkboxes change
            $('input[name="selected_templates[]"]').on('change', function() {
                QvcEmailManager.updateSelectedCount();
                
                // Update select all checkbox state
                var total = $('input[name="selected_templates[]"]').length;
                var checked = $('input[name="selected_templates[]"]:checked').length;
                
                $('#select-all-templates').prop('indeterminate', checked > 0 && checked < total);
                $('#select-all-templates').prop('checked', checked === total);
            });
            
            // Preview base template
            $('#qvc-preview-base').on('click', function(e) {
                e.preventDefault();
                QvcEmailManager.previewBaseTemplate();
            });
            
            // Auto-save functionality (optional)
            if (qvcEmailManager.autoSave) {
                QvcEmailManager.initAutoSave();
            }
        },
        
        /**
         * Initialize templates list functionality
         */
        initTemplatesList: function() {
            if (!$('.qvc-templates-list').length) return;
            
            // Search functionality
            QvcEmailManager.initTemplateSearch();
            
            // Bulk actions
            QvcEmailManager.initBulkActions();
        },
        
        /**
         * Initialize individual template editor
         */
        initTemplateEditor: function() {
            if (!$('.qvc-template-editor').length) return;
            
            // Auto-save functionality
            QvcEmailManager.initTemplateAutoSave();
            
            // Placeholder insertion
            QvcEmailManager.initPlaceholderInsertion();
            
            // Quick actions
            QvcEmailManager.initTemplateQuickActions();
        },
        
        /**
         * Update selected templates count
         */
        updateSelectedCount: function() {
            var count = $('input[name="selected_templates[]"]:checked').length;
            var $submit = $('input[name="apply_to_selected"]');
            
            if (count === 0) {
                $submit.val(qvcEmailManager.messages.applyToSelected);
                $submit.prop('disabled', true);
            } else {
                $submit.val(qvcEmailManager.messages.applyToSelected + ' (' + count + ')');
                $submit.prop('disabled', false);
            }
        },
        
        /**
         * Apply base template to all templates
         */
        applyBaseTemplateToAll: function($button) {
            var originalText = $button.text();
            
            $button.prop('disabled', true)
                   .text(qvcEmailManager.messages.applying)
                   .addClass('qvc-loading');
            
            $.post(ajaxurl, {
                action: 'qvc_email_apply_base_template',
                nonce: qvcEmailManager.nonce,
                base_template: '',
                apply_to: 'all'
            })
            .done(function(response) {
                if (response.success) {
                    QvcEmailManager.showMessage(response.data.message, 'success');
                    
                    // Refresh stats
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    QvcEmailManager.showMessage(response.data.message || qvcEmailManager.messages.unknownError, 'error');
                }
            })
            .fail(function() {
                QvcEmailManager.showMessage(qvcEmailManager.messages.ajaxError, 'error');
            })
            .always(function() {
                $button.prop('disabled', false)
                       .text(originalText)
                       .removeClass('qvc-loading');
            });
        },
        
        /**
         * Preview base template
         */
        previewBaseTemplate: function() {
            var templateContent = QvcEmailManager.getEditorContent('base_template');
            
            if (!templateContent.trim()) {
                alert(qvcEmailManager.messages.emptyTemplate);
                return;
            }
            
            $.post(ajaxurl, {
                action: 'qvc_email_preview_template',
                nonce: qvcEmailManager.nonce,
                template_content: templateContent,
                preview_data: {
                    '{{CONTENT}}': '<h3>Contenido de Ejemplo</h3><p>Este es el contenido que aparecería en un email real.</p>',
                    '{{SITE_NAME}}': qvcEmailManager.siteName || 'QvaClick',
                    '{{SITE_URL}}': qvcEmailManager.siteUrl || '#'
                }
            })
            .done(function(response) {
                if (response.success) {
                    QvcEmailManager.showPreviewModal(response.data.preview);
                } else {
                    alert(qvcEmailManager.messages.previewError);
                }
            })
            .fail(function() {
                alert(qvcEmailManager.messages.ajaxError);
            });
        },
        
        /**
         * Preview specific template
         */
        previewTemplate: function(templateKey) {
            $.post(ajaxurl, {
                action: 'qvc_email_preview_specific_template',
                nonce: qvcEmailManager.nonce,
                template_key: templateKey
            })
            .done(function(response) {
                if (response.success) {
                    QvcEmailManager.showPreviewModal(response.data.preview);
                } else {
                    alert(qvcEmailManager.messages.previewError);
                }
            })
            .fail(function() {
                alert(qvcEmailManager.messages.ajaxError);
            });
        },
        
        /**
         * Show preview modal
         */
        showPreviewModal: function(content) {
            $('#qvc-preview-content').html(content);
            $('#qvc-preview-modal').fadeIn(300);
            $('body').addClass('qvc-modal-open');
        },
        
        /**
         * Close modal
         */
        closeModal: function() {
            $('#qvc-preview-modal').fadeOut(300);
            $('body').removeClass('qvc-modal-open');
        },
        
        /**
         * Get content from TinyMCE or textarea
         */
        getEditorContent: function(editorId) {
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                return tinyMCE.get(editorId).getContent();
            } else {
                return $('#' + editorId).val() || '';
            }
        },
        
        /**
         * Set content to TinyMCE or textarea
         */
        setEditorContent: function(editorId, content) {
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get(editorId)) {
                tinyMCE.get(editorId).setContent(content);
            } else {
                $('#' + editorId).val(content);
            }
        },
        
        /**
         * Show message to user
         */
        showMessage: function(message, type) {
            type = type || 'info';
            
            var $message = $('<div class="qvc-message ' + type + '">' + message + '</div>');
            
            // Remove existing messages
            $('.qvc-message').remove();
            
            // Add new message
            if ($('.wrap h1').length) {
                $('.wrap h1').after($message);
            } else {
                $('.wrap').prepend($message);
            }
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $message.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $message.offset().top - 50
            }, 300);
        },
        
        /**
         * Initialize template search
         */
        initTemplateSearch: function() {
            var $searchBox = $('<div class="qvc-search-box">' +
                '<input type="text" id="qvc-template-search" placeholder="' + qvcEmailManager.messages.searchTemplates + '">' +
                '<button type="button" class="button" id="qvc-clear-search">' + qvcEmailManager.messages.clear + '</button>' +
                '</div>');
            
            $('.qvc-templates-list').before($searchBox);
            
            // Search functionality
            $('#qvc-template-search').on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                QvcEmailManager.filterTemplates(searchTerm);
            });
            
            // Clear search
            $('#qvc-clear-search').on('click', function() {
                $('#qvc-template-search').val('');
                QvcEmailManager.filterTemplates('');
            });
        },
        
        /**
         * Filter templates based on search term
         */
        filterTemplates: function(searchTerm) {
            $('table.wp-list-table tbody tr').each(function() {
                var $row = $(this);
                var text = $row.text().toLowerCase();
                
                if (searchTerm === '' || text.indexOf(searchTerm) !== -1) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
            
            // Show "no results" message if needed
            var visibleRows = $('table.wp-list-table tbody tr:visible').length;
            $('.qvc-no-results').remove();
            
            if (visibleRows === 0 && searchTerm !== '') {
                $('table.wp-list-table').after('<p class="qvc-no-results">' + qvcEmailManager.messages.noResults + '</p>');
            }
        },
        
        /**
         * Initialize bulk actions
         */
        initBulkActions: function() {
            // Add bulk action controls if not already present
            if (!$('.qvc-bulk-actions').length) {
                var $bulkActions = $('<div class="qvc-bulk-actions">' +
                    '<select id="qvc-bulk-action">' +
                    '<option value="">' + qvcEmailManager.messages.bulkActions + '</option>' +
                    '<option value="enable">' + qvcEmailManager.messages.enable + '</option>' +
                    '<option value="disable">' + qvcEmailManager.messages.disable + '</option>' +
                    '<option value="apply-base">' + qvcEmailManager.messages.applyBase + '</option>' +
                    '</select>' +
                    '<button type="button" class="button" id="qvc-bulk-apply">' + qvcEmailManager.messages.apply + '</button>' +
                    '</div>');
                
                $('.qvc-templates-list').before($bulkActions);
            }
        },
        
        /**
         * Initialize template auto-save
         */
        initTemplateAutoSave: function() {
            var autoSaveTimer;
            var hasChanges = false;
            
            // Monitor changes in subject field
            $('#template_subject').on('input', function() {
                hasChanges = true;
                QvcEmailManager.scheduleTemplateAutoSave();
            });
            
            // Monitor changes in editor
            if (typeof tinyMCE !== 'undefined') {
                $(document).on('tinymce-editor-init', function(event, editor) {
                    if (editor.id === 'template_body') {
                        editor.on('input change', function() {
                            hasChanges = true;
                            QvcEmailManager.scheduleTemplateAutoSave();
                        });
                    }
                });
            }
            
            // Monitor changes in textarea fallback
            $('#template_body').on('input', function() {
                hasChanges = true;
                QvcEmailManager.scheduleTemplateAutoSave();
            });
        },
        
        /**
         * Initialize placeholder insertion
         */
        initPlaceholderInsertion: function() {
            $('.qvc-placeholder-item').on('click', function() {
                var placeholder = $(this).data('placeholder');
                var $this = $(this);
                
                // Insert into TinyMCE or textarea
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('template_body')) {
                    tinyMCE.get('template_body').execCommand('mceInsertContent', false, placeholder + ' ');
                } else {
                    var $editor = $('#template_body');
                    var cursorPos = $editor.prop('selectionStart');
                    var textBefore = $editor.val().substring(0, cursorPos);
                    var textAfter = $editor.val().substring(cursorPos);
                    $editor.val(textBefore + placeholder + ' ' + textAfter);
                }
                
                // Show feedback
                $this.parent().append('<span class="qvc-copied">Copiado!</span>');
                setTimeout(function() {
                    $('.qvc-copied').remove();
                }, 1000);
            });
        },
        
        /**
         * Initialize template quick actions
         */
        initTemplateQuickActions: function() {
            // Reset template
            $('#qvc-reset-template').on('click', function() {
                var templateKey = $(this).data('template');
                
                if (!confirm(qvcEmailManager.messages.confirmReset || '¿Restaurar el template a su estado original?')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'qvc_email_reset_template',
                    nonce: qvcEmailManager.nonce,
                    template_key: templateKey
                })
                .done(function(response) {
                    if (response.success) {
                        QvcEmailManager.showMessage(response.data.message, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        QvcEmailManager.showMessage(response.data.message || 'Error al restaurar template', 'error');
                    }
                });
            });
            
            // Duplicate template
            $('#qvc-duplicate-template').on('click', function() {
                var templateKey = $(this).data('template');
                
                var newName = prompt(qvcEmailManager.messages.enterNewName || 'Ingrese el nombre para el nuevo template:');
                if (!newName) return;
                
                $.post(ajaxurl, {
                    action: 'qvc_email_duplicate_template',
                    nonce: qvcEmailManager.nonce,
                    template_key: templateKey,
                    new_name: newName
                })
                .done(function(response) {
                    if (response.success) {
                        QvcEmailManager.showMessage(response.data.message, 'success');
                    } else {
                        QvcEmailManager.showMessage(response.data.message || 'Error al duplicar template', 'error');
                    }
                });
            });
        },
        
        /**
         * Schedule template auto-save
         */
        scheduleTemplateAutoSave: function() {
            clearTimeout(this.templateAutoSaveTimer);
            
            this.templateAutoSaveTimer = setTimeout(function() {
                QvcEmailManager.performTemplateAutoSave();
            }, 5000); // Auto-save after 5 seconds of inactivity
        },
        
        /**
         * Perform template auto-save
         */
        performTemplateAutoSave: function() {
            var subject = $('#template_subject').val();
            var body = QvcEmailManager.getEditorContent('template_body');
            var templateKey = $('.qvc-template-editor').data('template-key') || $('[data-template]').first().data('template');
            
            if (!templateKey) return;
            
            $.post(ajaxurl, {
                action: 'qvc_email_auto_save_template',
                nonce: qvcEmailManager.nonce,
                template_key: templateKey,
                template_subject: subject,
                template_body: body
            })
            .done(function(response) {
                if (response.success) {
                    // Show subtle feedback
                    var $feedback = $('<span class="qvc-save-feedback success">Guardado automático</span>');
                    $('.submit').append($feedback);
                    setTimeout(function() {
                        $feedback.fadeOut(300, function() { $(this).remove(); });
                    }, 2000);
                }
            });
        },
        
        /**
         * Initialize auto-save functionality
         */
        initAutoSave: function() {
            var autoSaveTimer;
            var hasChanges = false;
            
            // Monitor changes in editor
            if (typeof tinyMCE !== 'undefined') {
                $(document).on('tinymce-editor-init', function(event, editor) {
                    if (editor.id === 'base_template') {
                        editor.on('input change', function() {
                            hasChanges = true;
                            QvcEmailManager.scheduleAutoSave();
                        });
                    }
                });
            }
            
            // Monitor changes in textarea fallback
            $('#base_template').on('input', function() {
                hasChanges = true;
                QvcEmailManager.scheduleAutoSave();
            });
        },
        
        /**
         * Schedule auto-save
         */
        scheduleAutoSave: function() {
            clearTimeout(this.autoSaveTimer);
            
            this.autoSaveTimer = setTimeout(function() {
                if (QvcEmailManager.hasChanges) {
                    QvcEmailManager.performAutoSave();
                }
            }, 3000); // Auto-save after 3 seconds of inactivity
        },
        
        /**
         * Perform auto-save
         */
        performAutoSave: function() {
            var content = QvcEmailManager.getEditorContent('base_template');
            
            $.post(ajaxurl, {
                action: 'qvc_email_auto_save_base_template',
                nonce: qvcEmailManager.nonce,
                base_template: content
            })
            .done(function(response) {
                if (response.success) {
                    QvcEmailManager.showMessage(qvcEmailManager.messages.autoSaved, 'success');
                    QvcEmailManager.hasChanges = false;
                }
            });
        }
    };
    
})(jQuery);

// Add modal-open class styles
jQuery(document).ready(function($) {
    $('<style>')
        .text('body.qvc-modal-open { overflow: hidden; }')
        .appendTo('head');
});
