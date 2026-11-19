/**
 * Sistema Escolar - Script de Totales y Filtros en Vivo del Reporte de Pagos
 */

function actualizarTotalesReporte(data) {
    const rows = Array.from(document.querySelectorAll('#reporte-rows tr[data-pago-row]'));
    let totalOps = rows.length;
    let totalRec = 0;
    let totalSal = 0;

    if (data && typeof data.total_operaciones === 'number') {
        totalOps = data.total_operaciones;
    }
    if (data && typeof data.total_recaudado === 'number') {
        totalRec = data.total_recaudado;
    } else {
        rows.forEach(r => {
            totalRec += parseFloat(r.dataset.abono || 0);
        });
    }
    if (data && typeof data.total_saldo === 'number') {
        totalSal = data.total_saldo;
    } else {
        rows.forEach(r => {
            totalSal += parseFloat(r.dataset.saldo || 0);
        });
    }

    const fmtRec = 'S/ ' + totalRec.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtSal = 'S/ ' + totalSal.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const opsText = totalOps + (totalOps === 1 ? ' pago' : ' pagos');

    // 1. Badge superior en pantalla y botón dinámico
    const countBadge = document.getElementById('reporte-count-badge');
    if (countBadge) {
        countBadge.textContent = `Mostrando ${opsText} • Total Recaudado: ${fmtRec}`;
    }
    const badgeOpsDinamico = document.getElementById('badge-ops-dinamico');
    if (badgeOpsDinamico) {
        badgeOpsDinamico.textContent = totalOps;
    }
    const btnImprimirDinamico = document.getElementById('btn-imprimir-dinamico');
    if (btnImprimirDinamico) {
        btnImprimirDinamico.disabled = totalOps === 0;
    }

    // 2. Metagrid (Encabezado institucional imprimible)
    const elMetaOps = document.getElementById('meta-operaciones');
    if (elMetaOps) elMetaOps.textContent = opsText;

    const elMetaRec = document.getElementById('meta-recaudado');
    if (elMetaRec) elMetaRec.textContent = fmtRec;

    const elMetaSal = document.getElementById('meta-saldo');
    if (elMetaSal) {
        elMetaSal.textContent = fmtSal;
        elMetaSal.style.color = totalSal > 0 ? '#b91c1c' : '#475569';
    }

    const elMetaPeriodo = document.getElementById('meta-periodo');
    if (elMetaPeriodo) {
        if (data && data.periodo_texto) {
            elMetaPeriodo.textContent = data.periodo_texto;
        } else {
            const fIni = document.getElementById('fecha_inicio')?.value;
            const fFin = document.getElementById('fecha_fin')?.value;
            const fmt = (d) => {
                if (!d) return '';
                const p = d.split('-');
                return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : d;
            };
            if (fIni && fFin) elMetaPeriodo.textContent = `${fmt(fIni)} al ${fmt(fFin)}`;
            else if (fIni) elMetaPeriodo.textContent = `Desde ${fmt(fIni)}`;
            else if (fFin) elMetaPeriodo.textContent = `Hasta ${fmt(fFin)}`;
            else elMetaPeriodo.textContent = 'HISTÓRICO COMPLETO';
        }
    }

    const elMetaNivel = document.getElementById('meta-nivel');
    if (elMetaNivel) {
        if (data && data.nivel_texto) {
            elMetaNivel.textContent = data.nivel_texto;
        } else {
            const selNiv = document.getElementById('filtro-nivel');
            elMetaNivel.textContent = selNiv && selNiv.selectedIndex > 0 ? selNiv.options[selNiv.selectedIndex].text.toUpperCase() : 'TODOS LOS NIVELES';
        }
    }

    const elMetaConcepto = document.getElementById('meta-concepto');
    if (elMetaConcepto) {
        if (data && data.concepto_texto) {
            elMetaConcepto.textContent = data.concepto_texto;
        } else {
            const selCon = document.getElementById('concepto_id');
            elMetaConcepto.textContent = selCon && selCon.selectedIndex > 0 ? selCon.options[selCon.selectedIndex].text.toUpperCase() : 'TODOS LOS CONCEPTOS';
        }
    }

    // 3. Pie de tabla tfoot
    const elTfootRec = document.getElementById('tfoot-total-recaudado');
    if (elTfootRec) elTfootRec.textContent = fmtRec;

    const elTfootSal = document.getElementById('tfoot-total-saldo');
    if (elTfootSal) {
        elTfootSal.textContent = fmtSal;
        elTfootSal.style.color = totalSal > 0 ? '#b91c1c' : '#64748b';
    }

    // 4. Resumen al pie del documento
    const elFooterOps = document.getElementById('doc-footer-operaciones');
    if (elFooterOps) elFooterOps.textContent = totalOps;

    const elFooterMonto = document.getElementById('doc-footer-monto');
    if (elFooterMonto) elFooterMonto.textContent = fmtRec;

    // 5. Resumen Consolidado por Concepto
    const tbodyConceptos = document.getElementById('tbody-resumen-conceptos');
    const bloqueConceptos = document.getElementById('bloque-resumen-conceptos');
    const badgeConceptos = document.getElementById('badge-total-conceptos');
    const tfootConceptosOps = document.getElementById('tfoot-conceptos-operaciones');
    const tfootConceptosRec = document.getElementById('tfoot-conceptos-recaudado');

    if (tbodyConceptos && bloqueConceptos) {
        if (data && data.resumen_conceptos && data.resumen_conceptos.length > 0) {
            bloqueConceptos.style.display = '';
            if (badgeConceptos) {
                badgeConceptos.textContent = `${data.resumen_conceptos.length} ${data.resumen_conceptos.length === 1 ? 'Concepto' : 'Conceptos'}`;
            }
            tbodyConceptos.innerHTML = data.resumen_conceptos.map(item => `
                <tr>
                    <td class="fw-semibold text-dark">${item.concepto}</td>
                    <td class="text-center text-muted">${Number(item.cantidad).toLocaleString('es-PE')}</td>
                    <td class="text-end font-monospace fw-bold text-success">${item.monto_fmt}</td>
                    <td class="text-end text-muted">${item.pct}%</td>
                </tr>
            `).join('');
            if (tfootConceptosOps) tfootConceptosOps.textContent = opsText;
            if (tfootConceptosRec) tfootConceptosRec.textContent = fmtRec;
        } else if (totalOps === 0) {
            bloqueConceptos.style.display = 'none';
        }
    }
}
window.actualizarTotalesReporte = actualizarTotalesReporte;

// Escuchar evento disparado por app_filtros.js
document.addEventListener('liveFilterUpdated', (event) => {
    actualizarTotalesReporte(event.detail);
});
