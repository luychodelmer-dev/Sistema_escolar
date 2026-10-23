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
                headers: { Accept: 'text/html' },
                signal: controller.signal
            });
            if (!response.ok) throw new Error('No se pudo actualizar la tabla.');
            target.innerHTML = await response.text();
            const countTarget = document.querySelector(form.dataset.liveCountTarget || '');
            if (countTarget) {
                const count = Array.from(target.children).filter((row) => !row.querySelector('td[colspan]')).length;
                countTarget.textContent = `${count} ${count === 1 ? 'estudiante' : 'estudiantes'}`;
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
