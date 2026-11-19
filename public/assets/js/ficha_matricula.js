/**
 * Sistema Escolar - Script de Ficha de Matrícula
 */

function cerrarYRegresar(urlDestino) {
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.location.href = urlDestino;
            window.opener.focus();
            window.close();
            return;
        } catch (err) {
            console.warn('No se pudo redirigir la pestaña de origen:', err);
        }
    }

    window.close();

    setTimeout(function() {
        window.location.href = urlDestino;
    }, 150);
}
window.cerrarYRegresar = cerrarYRegresar;

function cerrarEstaPestana() {
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.focus();
        } catch (err) {}
    }
    window.close();
    setTimeout(function() {
        if (window.history.length > 1) {
            window.history.back();
        }
    }, 150);
}
window.cerrarEstaPestana = cerrarEstaPestana;
