/**
 * Sistema Escolar - Script de Control de Pagos y Caja
 */
document.addEventListener('DOMContentLoaded', () => {
    const estudianteInput = document.getElementById('estudiante');
    const matriculaInput = document.getElementById('matricula_id');
    const estudianteSugerencias = document.getElementById('estudiante-sugerencias');
    const apoderadoInput = document.getElementById('apoderado_principal');
    const conceptoSelect = document.getElementById('concepto_id');
    const totalInput = document.getElementById('monto_total');
    const abonoInput = document.getElementById('abono_hoy');
    const saldoCaja = document.getElementById('saldo-caja');
    const toggleConceptForm = document.getElementById('toggle-concept-form');
    const conceptForm = document.getElementById('concept-form');
    let temporizadorBusquedaEstudiante;
    let solicitudEstudiantes;
    let saldoDisponible = 0;
    let cuentaExiste = false;
    let deudaPendiente = false;

    if (!estudianteInput || !matriculaInput) return;

    function actualizarSaldo() {
        const abono = Number(abonoInput?.value || 0);
        if (!saldoCaja) return;
        if (!deudaPendiente && cuentaExiste) {
            saldoCaja.textContent = 'Este concepto ya está completamente pagado.';
        } else {
            const label = deudaPendiente ? 'Saldo restante' : 'Monto total por pagar';
            saldoCaja.textContent = `${label}: S/ ${saldoDisponible.toFixed(2)} · Luego del abono: S/ ${Math.max(0, saldoDisponible - abono).toFixed(2)}`;
        }
        if (abonoInput) {
            abonoInput.max = saldoDisponible.toFixed(2);
            abonoInput.disabled = cuentaExiste && saldoDisponible === 0;
        }
    }

    async function cargarApoderadoYSaldo() {
        if (!matriculaInput.value) return;
        const response = await fetch(`pagos.php?ajax=apoderado&id=${encodeURIComponent(matriculaInput.value)}`);
        if (!response.ok) throw new Error('La matrícula no tiene apoderado principal.');
        const apoderado = await response.json();
        if (apoderadoInput) apoderadoInput.value = `${apoderado.nombre} · DNI ${apoderado.dni}`;
        if (conceptoSelect?.value) await cargarSaldo();
    }

    async function cargarConcepto() {
        if (totalInput) totalInput.value = '';
        saldoDisponible = 0;
        cuentaExiste = false;
        deudaPendiente = false;
        if (abonoInput) abonoInput.disabled = false;
        if (!conceptoSelect?.value) {
            if (saldoCaja) saldoCaja.textContent = 'Selecciona estudiante y concepto para consultar su cuenta.';
            return;
        }
        const response = await fetch(`pagos.php?ajax=concepto&id=${encodeURIComponent(conceptoSelect.value)}`);
        const concepto = await response.json();
        if (!response.ok) throw new Error(concepto.error || 'Tarifa no disponible.');
        if (totalInput) {
            totalInput.value = concepto.monto_default;
            totalInput.readOnly = true;
        }
        if (matriculaInput.value) await cargarSaldo();
    }

    async function cargarSaldo() {
        const query = new URLSearchParams({ ajax: 'saldo', id: matriculaInput.value, concepto_id: conceptoSelect.value });
        const response = await fetch(`pagos.php?${query}`);
        if (!response.ok) throw new Error('No se pudo consultar el saldo de esta matrícula.');
        const cuenta = await response.json();
        if (totalInput) totalInput.value = cuenta.monto_total;
        saldoDisponible = Number(cuenta.saldo_restante || 0);
        cuentaExiste = cuenta.cuenta_existente;
        deudaPendiente = cuenta.deuda_pendiente;
        if (abonoInput) {
            abonoInput.max = saldoDisponible.toFixed(2);
            if (!abonoInput.value || Number(abonoInput.value) > saldoDisponible) {
                abonoInput.value = saldoDisponible.toFixed(2);
            }
        }
        actualizarSaldo();
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('.complete-payment');
        if (!button) return;
        estudianteInput.value = button.dataset.estudiante;
        matriculaInput.value = button.dataset.matriculaId;
        if (conceptoSelect) conceptoSelect.value = button.dataset.conceptoId;
        if (abonoInput) abonoInput.value = '';
        if (saldoCaja) saldoCaja.textContent = 'Consultando saldo pendiente…';
        try {
            await cargarApoderadoYSaldo();
            if (!deudaPendiente || saldoDisponible <= 0) {
                throw new Error('Esta deuda ya fue pagada o no tiene saldo pendiente.');
            }
            if (abonoInput) abonoInput.value = saldoDisponible.toFixed(2);
            actualizarSaldo();
            document.getElementById('payment-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            abonoInput?.focus({ preventScroll: true });
        } catch (error) {
            if (saldoCaja) saldoCaja.textContent = error.message;
        }
    });

    estudianteInput?.addEventListener('input', () => {
        matriculaInput.value = '';
        if (apoderadoInput) apoderadoInput.value = '';
        if (abonoInput) {
            abonoInput.value = '';
            abonoInput.disabled = false;
        }
        saldoDisponible = 0;
        cuentaExiste = false;
        deudaPendiente = false;
        if (saldoCaja) saldoCaja.textContent = 'Selecciona estudiante y concepto para consultar su cuenta.';
        if (estudianteSugerencias) {
            estudianteSugerencias.replaceChildren();
            estudianteSugerencias.classList.add('d-none');
        }
        clearTimeout(temporizadorBusquedaEstudiante);
        solicitudEstudiantes?.abort();
        const termino = estudianteInput.value.trim();
        if (termino.length < 2) {
            return;
        }

        temporizadorBusquedaEstudiante = setTimeout(async () => {
            solicitudEstudiantes = new AbortController();
            try {
                const respuesta = await fetch(`buscar_estudiantes_pago.php?q=${encodeURIComponent(termino)}`, {
                    headers: { Accept: 'application/json' },
                    signal: solicitudEstudiantes.signal
                });
                if (!respuesta.ok) throw new Error('No se pudo buscar estudiantes.');
                const resultados = await respuesta.json();
                resultados.forEach((item) => {
                    const opcion = document.createElement('button');
                    opcion.type = 'button';
                    opcion.className = 'list-group-item list-group-item-action';
                    opcion.textContent = `${item.nombre} · DNI ${item.dni}`;
                    opcion.addEventListener('click', () => {
                        estudianteInput.value = item.nombre;
                        matriculaInput.value = item.matricula_id;
                        if (estudianteSugerencias) estudianteSugerencias.classList.add('d-none');
                        cargarApoderadoYSaldo().catch((error) => {
                            if (apoderadoInput) apoderadoInput.value = '';
                            if (saldoCaja) saldoCaja.textContent = error.message;
                        });
                    });
                    if (estudianteSugerencias) estudianteSugerencias.append(opcion);
                });
                if (estudianteSugerencias) estudianteSugerencias.classList.toggle('d-none', resultados.length === 0);
            } catch (error) {
                if (error.name !== 'AbortError') console.error(error);
            }
        }, 250);
    });

    document.getElementById('payment-form')?.addEventListener('submit', (event) => {
        if (!matriculaInput.value || !apoderadoInput?.value) {
            event.preventDefault();
            estudianteInput.setCustomValidity('Selecciona un estudiante con apoderado principal.');
            estudianteInput.reportValidity();
        }
    });

    estudianteInput?.addEventListener('input', () => estudianteInput.setCustomValidity(''));
    conceptoSelect?.addEventListener('change', () => cargarConcepto().catch((error) => {
        if (totalInput) totalInput.value = '';
        if (saldoCaja) saldoCaja.textContent = error.message;
    }));
    abonoInput?.addEventListener('input', actualizarSaldo);
    toggleConceptForm?.addEventListener('click', () => {
        if (!conceptForm) return;
        const visible = conceptForm.classList.toggle('d-none') === false;
        toggleConceptForm.setAttribute('aria-expanded', String(visible));
    });
});
