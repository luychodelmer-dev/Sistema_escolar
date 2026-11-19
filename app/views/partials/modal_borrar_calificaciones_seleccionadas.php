<!-- Modal Borrar Calificaciones de Alumnos Seleccionados -->
<div class="modal fade" id="modalBorrarSeleccionados" tabindex="-1" aria-labelledby="modalBorrarSeleccionadosLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <form method="POST" id="formBorrarSeleccionados">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="eliminar_seleccionados">
                <input type="hidden" name="curso_id" value="<?= $cursoId ?>">
                <input type="hidden" name="periodo_id" value="<?= $periodoId ?>">
                <input type="hidden" name="nivel_id" value="<?= htmlspecialchars((string) $nivelId) ?>">
                <input type="hidden" name="grado_id" value="<?= htmlspecialchars((string) $gradoId) ?>">
                <input type="hidden" name="seccion_id" value="<?= htmlspecialchars((string) $seccionId) ?>">

                <div id="containerIdsBorrar"></div>

                <div class="modal-header bg-danger text-white py-3 px-4">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2 mb-0" id="modalBorrarSeleccionadosLabel">
                        <i class="bi bi-trash3-fill"></i> ¿Borrar Notas de Seleccionados?
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body p-4">
                    <p class="text-dark mb-2">
                        Estás a punto de eliminar las notas registradas en el sistema para 
                        <strong class="text-danger" id="lblModalCantSeleccionados">0 alumnos</strong> seleccionados:
                    </p>
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div><strong>Curso:</strong> <?= htmlspecialchars($cursoSeleccionado['nombre'] ?? 'Curso actual') ?></div>
                        <div><strong>Periodo:</strong> <?= htmlspecialchars($periodoSeleccionado['nombre'] ?? 'Periodo actual') ?></div>
                        <?php if ($gradoActualInfo): ?>
                            <div><strong>Aula:</strong> <?= htmlspecialchars($gradoActualInfo['nivel_nombre'] . ' - ' . $gradoActualInfo['grado_nombre']) ?></div>
                        <?php elseif ($cursoInfo && !empty($cursoInfo['aula_etiqueta'])): ?>
                            <div><strong>Aula:</strong> <?= htmlspecialchars($cursoInfo['aula_etiqueta']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="alert alert-warning py-2 px-3 small mb-0 border-0">
                        <i class="bi bi-info-circle me-1"></i> Las calificaciones de los demás estudiantes no serán modificadas.
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4 border-top">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancelar</button>
                    <button type="submit" class="btn btn-danger px-4 fw-semibold" style="border-radius: 8px;">
                        <i class="bi bi-trash3-fill me-1"></i> Confirmar y Eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
