<!-- MODAL 2: APLICAR CIERRE ANUAL DEFINITIVO -->
<div class="modal fade no-print" id="modalCierreDefinitivo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="aplicar_cierre_definitivo">

                <div class="modal-header bg-success bg-opacity-10 py-3 px-4 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success text-white p-2 rounded-circle fs-6">
                            <i class="bi bi-check2-all"></i>
                        </span>
                        <h5 class="modal-title fw-bold text-dark mb-0">Aplicar Cierre Académico Anual Definitivo</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 d-flex gap-2 mb-3">
                        <i class="bi bi-info-circle-fill fs-5 text-primary"></i>
                        <div>
                            Esta acción actualizará masivamente el <strong>Estado Académico</strong> en la base de datos de los <strong><?= count($estudiantesEvaluados) ?> estudiantes</strong> de la selección actual según su resultado final:
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <div class="p-2 border rounded text-center bg-light">
                                <span class="text-success fw-bold d-block">Promovidos</span>
                                <span class="fs-5 fw-bold text-success"><?= $totalPromovidosDirectos ?></span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 border rounded text-center bg-light">
                                <span class="text-primary fw-bold d-block">Promoción 5to</span>
                                <span class="fs-5 fw-bold text-primary"><?= $totalPromocionQuinto ?></span>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 border rounded text-center bg-light">
                                <span class="text-danger fw-bold d-block">Repitentes</span>
                                <span class="fs-5 fw-bold text-danger"><?= $totalRepitentesDirectos ?></span>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted small mb-0">
                        * Los alumnos con vacacional pendiente o desaprobado pasarán a <strong>Repitentes</strong>; los que aprobaron pasarán a <strong>Promovidos</strong> (o <strong>Promoción</strong> si es 5to).
                    </p>

                    <!-- Inputs ocultos para actualización masiva -->
                    <?php foreach ($estudiantesEvaluados as $estEv): ?>
                        <input type="hidden" name="estado_final_alumno[<?= (int)$estEv['estudiante_id'] ?>]" value="<?= htmlspecialchars($estEv['estado_final_propuesto']) ?>">
                    <?php endforeach; ?>
                </div>

                <div class="modal-footer bg-light py-3 px-4 border-top">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">
                        <i class="bi bi-shield-check me-1"></i> Aplicar Cierre Anual en BD
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
