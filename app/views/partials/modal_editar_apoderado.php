<!-- Modal Editar Apoderado y Contactos Familiares -->
<div class="modal fade" id="editContactModal" tabindex="-1" aria-labelledby="editContactTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <form method="POST" action="editar_apoderado.php">
            <div class="modal-header"><h2 class="modal-title fs-5 fw-bold" id="editContactTitle">Editar apoderado y contactos familiares</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="edit-contact-id">
                <input type="hidden" name="relacion_id" id="edit-contact-relacion-id">
                <input type="hidden" name="student_id" id="edit-contact-student-id">
                <input type="hidden" name="emergency_id" id="edit-emergency-id">
                <input type="hidden" name="emergency_relacion_id" id="edit-emergency-relacion-id">
                <input type="hidden" name="father_id" id="edit-father-id">
                <input type="hidden" name="father_relacion_id" id="edit-father-relacion-id">
                <input type="hidden" name="mother_id" id="edit-mother-id">
                <input type="hidden" name="mother_relacion_id" id="edit-mother-relacion-id">
                <h3 class="h6 border-bottom pb-2 fw-bold text-dark mt-2" style="font-size: 1.05rem;"><i class="bi bi-person-fill text-primary"></i> Datos del Apoderado Principal</h3>
                <div class="col-md-4"><label class="form-label" for="edit-contact-dni">DNI</label><input class="form-control" id="edit-contact-dni" name="dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;" required></div>
                <div class="col-md-8"><label class="form-label" for="edit-contact-nombres">Nombres</label><input class="form-control" id="edit-contact-nombres" name="nombres" maxlength="100" style="text-transform: uppercase;" required></div>
                <div class="col-md-6"><label class="form-label" for="edit-contact-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-contact-apellido-paterno" name="apellido_paterno" maxlength="100" style="text-transform: uppercase;" required></div>
                <div class="col-md-6"><label class="form-label" for="edit-contact-apellido-materno">Apellido materno</label><input class="form-control" id="edit-contact-apellido-materno" name="apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-contact-parentesco">Parentesco</label><input class="form-control" id="edit-contact-parentesco" name="parentesco" maxlength="60" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-contact-celular">Celular</label><input class="form-control" id="edit-contact-celular" name="celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                <div class="col-12"><label class="form-label" for="edit-contact-direccion">Dirección</label><input class="form-control" id="edit-contact-direccion" name="direccion" maxlength="500" style="text-transform: uppercase;"></div>
                <h3 class="h6 border-bottom pb-2 fw-bold text-dark mt-3" style="font-size: 1.05rem;"><i class="bi bi-gender-male text-primary"></i> Datos del Padre (si aplica)</h3>
                <div class="col-md-4"><label class="form-label" for="edit-father-dni">DNI</label><input class="form-control" id="edit-father-dni" name="father_dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;"></div>
                <div class="col-md-8"><label class="form-label" for="edit-father-nombres">Nombres</label><input class="form-control" id="edit-father-nombres" name="father_nombres" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-father-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-father-apellido-paterno" name="father_apellido_paterno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-father-apellido-materno">Apellido materno</label><input class="form-control" id="edit-father-apellido-materno" name="father_apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-father-parentesco">Parentesco</label><input class="form-control" id="edit-father-parentesco" name="father_parentesco" maxlength="60" value="Padre" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-father-celular">Celular</label><input class="form-control" id="edit-father-celular" name="father_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                <h3 class="h6 border-bottom pb-2 fw-bold text-dark mt-3" style="font-size: 1.05rem;"><i class="bi bi-gender-female text-primary"></i> Datos de la Madre (si aplica)</h3>
                <div class="col-md-4"><label class="form-label" for="edit-mother-dni">DNI</label><input class="form-control" id="edit-mother-dni" name="mother_dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;"></div>
                <div class="col-md-8"><label class="form-label" for="edit-mother-nombres">Nombres</label><input class="form-control" id="edit-mother-nombres" name="mother_nombres" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-mother-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-mother-apellido-paterno" name="mother_apellido_paterno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-mother-apellido-materno">Apellido materno</label><input class="form-control" id="edit-mother-apellido-materno" name="mother_apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-mother-parentesco">Parentesco</label><input class="form-control" id="edit-mother-parentesco" name="mother_parentesco" maxlength="60" value="Madre" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-mother-celular">Celular</label><input class="form-control" id="edit-mother-celular" name="mother_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
                <h3 class="h6 border-bottom pb-2 fw-bold text-dark mt-3" style="font-size: 1.05rem;"><i class="bi bi-telephone-plus-fill text-primary"></i> Contacto de Emergencia</h3>
                <div class="col-md-4"><label class="form-label" for="edit-emergency-dni">DNI</label><input class="form-control" id="edit-emergency-dni" name="emergency_dni" maxlength="8" pattern="[0-9]{8}" style="text-transform: uppercase;"></div>
                <div class="col-md-8"><label class="form-label" for="edit-emergency-nombres">Nombres</label><input class="form-control" id="edit-emergency-nombres" name="emergency_nombres" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-emergency-apellido-paterno">Apellido paterno</label><input class="form-control" id="edit-emergency-apellido-paterno" name="emergency_apellido_paterno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-emergency-apellido-materno">Apellido materno</label><input class="form-control" id="edit-emergency-apellido-materno" name="emergency_apellido_materno" maxlength="100" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-emergency-parentesco">Parentesco</label><input class="form-control" id="edit-emergency-parentesco" name="emergency_parentesco" maxlength="60" style="text-transform: uppercase;"></div>
                <div class="col-md-6"><label class="form-label" for="edit-emergency-celular">Celular de emergencia</label><input class="form-control" id="edit-emergency-celular" name="emergency_celular" minlength="9" maxlength="9" pattern="[0-9]{9}" style="text-transform: uppercase;"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary px-4 fw-semibold">Guardar cambios</button></div>
        </form>
    </div></div>
</div>
