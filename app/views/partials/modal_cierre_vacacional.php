<!-- MODAL 1: INSCRIBIR MASIVAMENTE A VACACIONAL -->
<div class="modal fade no-print" id="modalEnviarVacacional" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="inscribir_vacacional_masivo">
                
                <div class="modal-header bg-warning bg-opacity-10 py-3 px-4 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-warning text-dark p-2 rounded-circle fs-6">
                            <i class="bi bi-sun-fill"></i>
                        </span>
                        <h5 class="modal-title fw-bold text-dark mb-0">Inscribir a Programa de Recuperación Vacacional</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="text-muted mb-3">
                        Se detectaron <strong><?= $totalARecuperacion ?> estudiantes</strong> con 1 a 3 asignaturas en inicio (C). Al confirmar, el sistema realizará las siguientes acciones automáticas:
                    </p>
                    <ul class="text-secondary small mb-3">
                        <li>Creará el registro en el padrón de recuperación para cada una de las materias desaprobadas.</li>
                        <li>Actualizará el estado académico del estudiante en la base de datos a <strong>"Recuperación"</strong>.</li>
                        <li>Habilitará la casilla de calificación para ingresar sus notas de verano cuando rindan su evaluación.</li>
                    </ul>

                    <div class="alert alert-light border small text-muted mb-0">
                        <strong>Estudiantes a inscribir:</strong>
                        <div class="mt-2" style="max-height: 180px; overflow-y: auto;">
                            <?php foreach ($alumnosParaVacacional as $av): ?>
                                <input type="hidden" name="matriculas_recuperacion[]" value="<?= (int)$av['matricula_id'] ?>">
                                <?php foreach ($av['cursos_desaprobados'] as $cd): ?>
                                    <input type="hidden" name="cursos_recuperacion[<?= (int)$av['matricula_id'] ?>][]" value="<?= (int)$cd['curso_id'] ?>">
                                <?php endforeach; ?>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span><?= htmlspecialchars($av['nombre_completo']) ?> (<?= htmlspecialchars($av['grado_nombre'] . ' ' . $av['seccion_nombre']) ?>)</span>
                                    <span class="text-danger fw-semibold"><?= count($av['cursos_desaprobados']) ?> curso(s) en C</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4 border-top">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-4">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirmar e Inscribir en Vacacional
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
