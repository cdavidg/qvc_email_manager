/* QvaClick Email Manager Admin JavaScript */

jQuery(document).ready(function($) {
    console.log('QvaClick Email Manager Admin JS loaded');
    
    // Form validation
    $('form').on('submit', function(e) {
        const requiredFields = $(this).find('[required]');
        let hasErrors = false;
        
        requiredFields.each(function() {
            if (!$(this).val()) {
                $(this).addClass('error');
                hasErrors = true;
            } else {
                $(this).removeClass('error');
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            alert('Por favor, completa todos los campos requeridos.');
        }
    });
    
    // Auto-hide success messages
    setTimeout(function() {
        $('.notice-success').fadeOut();
    }, 5000);
});
