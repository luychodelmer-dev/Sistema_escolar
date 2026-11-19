<!-- Modal Reasignar Apoderado Principal -->
<div class="modal fade" id="principalModal" tabindex="-1" aria-labelledby="principalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="principalTitle">Reasignar apoderado Principal</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <label class="form-label" for="replacement-select">Selecciona al nuevo Principal</label>
            <select class="form-select" id="replacement-select" required><option value="">Cargando apoderados...</option></select>
            <div class="form-text">Debes elegir a otro apoderado vinculado con el estudiante.</div>
            <div class="alert alert-danger mt-3 d-none" id="principal-modal-error" role="alert"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="confirm-principal-delete" disabled>Reasignar y eliminar</button></div>
    </div></div>
</div>
