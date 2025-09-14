/**
 * Script de depuración para verificar funcionamiento del botón eliminar
 */

jQuery(document).ready(function($) {
    console.log("🔍 Script de depuración cargado");
    
    // Verificar si existe el botón
    const deleteButton = $('#qvc-delete-ticket');
    console.log("🎯 Botón eliminar encontrado:", deleteButton.length > 0);
    
    if (deleteButton.length > 0) {
        console.log("📋 Ticket ID:", deleteButton.data('ticket-id'));
        console.log("🔗 Botón HTML:", deleteButton[0].outerHTML);
    }
    
    // Verificar si existe la variable qvc_ajax
    if (typeof qvc_ajax !== 'undefined') {
        console.log("✅ Variable qvc_ajax disponible:", qvc_ajax);
    } else {
        console.log("❌ Variable qvc_ajax NO disponible");
    }
    
    // Test manual del click
    $('#qvc-delete-ticket').on('click', function() {
        console.log("🖱️ Click detectado en botón eliminar");
        console.log("📋 Ticket ID obtenido:", $(this).data('ticket-id'));
    });
    
    // Verificar otros scripts cargados
    console.log("📜 Scripts cargados:");
    $('script[src]').each(function() {
        const src = $(this).attr('src');
        if (src.includes('qvc') || src.includes('security') || src.includes('admin')) {
            console.log("   -", src);
        }
    });
});
