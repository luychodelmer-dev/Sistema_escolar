/**
 * Sistema Escolar - Script de Padrón de Apoderados
 */
document.addEventListener('DOMContentLoaded', () => {
    const nivelSelect = document.getElementById('nivel_id');
    const gradoSelect = document.getElementById('grado_id');

    if (!nivelSelect || !gradoSelect) return;

    function updateGradosByNivel() {
        const selectedNivel = nivelSelect.value;
        Array.from(gradoSelect.options).forEach((option) => {
            if (!option.value) return;
            const nivelId = option.dataset.nivelId;
            option.hidden = selectedNivel !== '' && nivelId !== selectedNivel;
        });

        if (gradoSelect.selectedOptions[0]?.hidden) {
            gradoSelect.value = '';
        }
    }

    nivelSelect.addEventListener('change', updateGradosByNivel);
    updateGradosByNivel();
});
