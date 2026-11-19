(() => {
    const controllers = new WeakMap();
    const timers = new WeakMap();

    async function updateTable(form) {
        const target = document.querySelector(form.dataset.liveTarget || '');
        if (!target) return;

        controllers.get(form)?.abort();
        const controller = new AbortController();
        controllers.set(form, controller);
        const url = new URL(form.dataset.liveUrl || form.action || window.location.href, window.location.href);
        Array.from(form.elements).forEach((element) => {
            if (element.name) url.searchParams.delete(element.name);
        });
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string') url.searchParams.set(key, value);
        });
        url.searchParams.set(form.dataset.liveParam || 'ajax', form.dataset.liveAction || 'tabla');
        target.classList.add('is-updating');

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json, text/html' },
                signal: controller.signal
            });
            if (!response.ok) throw new Error('No se pudo actualizar la tabla.');

            const contentType = response.headers.get('content-type') || '';
            let payload = null;

            if (contentType.includes('application/json')) {
                payload = await response.json();
                target.innerHTML = payload.html || '';
            } else {
                target.innerHTML = await response.text();
                const xOps = response.headers.get('X-Total-Operaciones');
                const xRec = response.headers.get('X-Total-Recaudado');
                const xSal = response.headers.get('X-Total-Saldo');
                if (xOps !== null || xRec !== null) {
                    payload = {
                        total_operaciones: xOps !== null ? parseInt(xOps, 10) : target.querySelectorAll('tr[data-pago-row]').length,
                        total_recaudado: xRec !== null ? parseFloat(xRec) : null,
                        total_saldo: xSal !== null ? parseFloat(xSal) : null,
                        periodo_texto: response.headers.get('X-Periodo-Texto') ? decodeURIComponent(response.headers.get('X-Periodo-Texto')) : '',
                        nivel_texto: response.headers.get('X-Nivel-Texto') ? decodeURIComponent(response.headers.get('X-Nivel-Texto')) : '',
                        concepto_texto: response.headers.get('X-Concepto-Texto') ? decodeURIComponent(response.headers.get('X-Concepto-Texto')) : '',
                    };
                }
            }

            const eventDetail = payload || {
                total_operaciones: target.querySelectorAll('tr[data-pago-row], tr:not([data-empty="true"])').length
            };
            form.dispatchEvent(new CustomEvent('liveFilterUpdated', { detail: eventDetail, bubbles: true }));
            document.dispatchEvent(new CustomEvent('liveFilterUpdated', { detail: eventDetail, bubbles: true }));

            const countTarget = document.querySelector(form.dataset.liveCountTarget || '');
            if (countTarget && !form.dataset.customMetrics) {
                const count = Array.from(target.children).filter((row) => !row.querySelector('td[colspan]')).length;
                const singular = form.dataset.liveCountSingular || 'estudiante';
                const plural = form.dataset.liveCountPlural || (singular === 'estudiante' ? 'estudiantes' : singular + 's');
                const prefix = form.dataset.liveCountPrefix || '';
                countTarget.textContent = `${prefix}${count} ${count === 1 ? singular : plural}`;
            }
        } catch (error) {
            if (error.name !== 'AbortError') console.error(error);
        } finally {
            if (!controller.signal.aborted) target.classList.remove('is-updating');
        }
    }

    function scheduleUpdate(form, immediate = false) {
        clearTimeout(timers.get(form));
        if (immediate) {
            updateTable(form);
        } else {
            timers.set(form, setTimeout(() => updateTable(form), 220));
        }
    }

    document.addEventListener('input', (event) => {
        const form = event.target.closest('form[data-live-filter]');
        if (form) scheduleUpdate(form);
    });

    document.addEventListener('change', (event) => {
        const form = event.target.closest('form[data-live-filter]');
        if (form) scheduleUpdate(form, true);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-live-filter]');
        if (!form) return;
        event.preventDefault();
        scheduleUpdate(form, true);
    });

    document.addEventListener('reset', (event) => {
        const form = event.target.closest('form[data-live-filter]');
        if (!form) return;
        setTimeout(() => {
            form.querySelectorAll('input:not([type="hidden"])').forEach((input) => { input.value = ''; });
            form.querySelectorAll('select').forEach((select) => { select.value = ''; });
            scheduleUpdate(form, true);
        }, 0);
    });
})();
