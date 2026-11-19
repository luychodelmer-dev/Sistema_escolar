/**
 * Sistema Escolar - Script de Gestión de Matrículas
 */
document.addEventListener('DOMContentLoaded', () => {
    const estudianteIdInput = document.getElementById('estudiante_id');
    const buscarEstudianteInput = document.getElementById('buscar-estudiante');
    const estudianteSugerencias = document.getElementById('estudiante-sugerencias');
    const buscarEstudianteWrap = document.getElementById('buscar-estudiante-wrap');
    const buscarHermanoInput = document.getElementById('buscar-hermano');
    const buscarHermanoWrap = document.getElementById('buscar-hermano-wrap');
    const hermanoIdInput = document.getElementById('hermano_id');
    const hermanoSugerencias = document.getElementById('hermano-sugerencias');
    const familyContactFields = document.getElementById('family-contact-fields');
    const familyPrefixes = ['padre', 'madre', 'emergencia'];
    const studentDataInputs = ['nombres', 'apellido_paterno', 'apellido_materno', 'dni'].map((id) => document.getElementById(id));
    const studentModeRadios = document.querySelectorAll('input[name="modo_estudiante"]');
    let studentSearchTimer;
    let studentSearchRequest;
    let studentDataRequest;
    let siblingSearchTimer;
    let siblingSearchRequest;

    if (!estudianteIdInput || !buscarEstudianteInput) return;

    function clearFamilyData() {
        familyPrefixes.forEach((prefix) => {
            ['dni', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular'].forEach((field) => {
                const el = document.getElementById(`${prefix}_${field}`);
                if (el) el.value = '';
            });
        });
        const em = document.getElementById('emergencia_parentesco');
        if (em) em.value = '';
        document.querySelectorAll('input[name="apoderado_principal"]').forEach((radio) => {
            radio.checked = false;
        });
    }

    function fillFamilyData(family, lastMatricula) {
        clearFamilyData();
        const selectedResponsibleId = String(lastMatricula?.apoderado_principal_id ?? '');
        familyPrefixes.forEach((prefix) => {
            const contact = family?.[prefix];
            if (!contact) return;
            ['dni', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular'].forEach((field) => {
                const el = document.getElementById(`${prefix}_${field}`);
                if (el) el.value = contact[field] ?? '';
            });
            if (prefix === 'emergencia') {
                const em = document.getElementById('emergencia_parentesco');
                if (em) em.value = contact.parentesco ?? '';
            }
            const radio = document.querySelector(`input[name="apoderado_principal"][value="${prefix}"]`);
            if (radio && (String(contact.id) === selectedResponsibleId || contact.es_apoderado === true || contact.es_apoderado === 't')) {
                radio.checked = true;
            }
        });
    }

    function setStudentMode(mode, clear = true) {
        const existing = mode === 'existente';
        if (buscarEstudianteWrap) buscarEstudianteWrap.classList.toggle('d-none', !existing);
        if (buscarHermanoWrap) buscarHermanoWrap.classList.toggle('d-none', existing);
        if (familyContactFields) familyContactFields.classList.remove('d-none');
        studentDataInputs.forEach((input) => {
            if (input) {
                input.readOnly = existing;
                if (clear) input.value = '';
            }
        });
        if (!existing && clear) {
            estudianteIdInput.value = '';
            clearFamilyData();
            buscarEstudianteInput.value = '';
            if (estudianteSugerencias) estudianteSugerencias.replaceChildren();
        }
        if (existing && clear) {
            if (hermanoIdInput) hermanoIdInput.value = '';
            if (buscarHermanoInput) buscarHermanoInput.value = '';
            if (hermanoSugerencias) hermanoSugerencias.replaceChildren();
        }
    }

    studentModeRadios.forEach((radio) => {
        radio.addEventListener('change', () => setStudentMode(radio.value));
    });
    setStudentMode(estudianteIdInput.value ? 'existente' : 'nuevo', false);

    buscarEstudianteInput.addEventListener('input', () => {
        estudianteIdInput.value = '';
        studentDataRequest?.abort();
        if (familyContactFields) familyContactFields.classList.remove('d-none');
        studentDataInputs.forEach((input) => { if (input) input.value = ''; });
        clearFamilyData();
        if (estudianteSugerencias) estudianteSugerencias.replaceChildren();
        clearTimeout(studentSearchTimer);
        studentSearchRequest?.abort();
        const term = buscarEstudianteInput.value.trim();
        if (term.length < 2) return;

        studentSearchTimer = setTimeout(async () => {
            studentSearchRequest = new AbortController();
            try {
                const query = new URLSearchParams({ ajax: 'buscar_estudiantes', q: term });
                const response = await fetch(`matriculas.php?${query}`, { signal: studentSearchRequest.signal });
                if (!response.ok) throw new Error('No se pudieron buscar estudiantes.');
                const estudiantes = await response.json();
                estudiantes.forEach((estudiante) => {
                    const opcion = document.createElement('button');
                    opcion.type = 'button';
                    opcion.className = 'list-group-item list-group-item-action';
                    opcion.textContent = `${estudiante.apellido_paterno} ${estudiante.apellido_materno}, ${estudiante.nombres} · DNI ${estudiante.dni}`;
                    opcion.addEventListener('click', () => {
                        estudianteIdInput.value = estudiante.id;
                        if (studentDataInputs[0]) studentDataInputs[0].value = estudiante.nombres;
                        if (studentDataInputs[1]) studentDataInputs[1].value = estudiante.apellido_paterno;
                        if (studentDataInputs[2]) studentDataInputs[2].value = estudiante.apellido_materno;
                        if (studentDataInputs[3]) studentDataInputs[3].value = estudiante.dni;
                        buscarEstudianteInput.value = opcion.textContent;
                        if (estudianteSugerencias) estudianteSugerencias.replaceChildren();
                        if (familyContactFields) familyContactFields.classList.remove('d-none');
                        loadExistingStudent(estudiante.id);
                    });
                    if (estudianteSugerencias) estudianteSugerencias.append(opcion);
                });
                if (!estudiantes.length && estudianteSugerencias) {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted';
                    empty.textContent = 'No se encontraron estudiantes.';
                    estudianteSugerencias.append(empty);
                }
            } catch (error) {
                if (error.name !== 'AbortError') console.error(error);
            }
        }, 250);
    });

    async function loadExistingStudent(id, { populateStudent = true, populateHistory = true } = {}) {
        studentDataRequest?.abort();
        studentDataRequest = new AbortController();
        try {
            const response = await fetch(`matriculas.php?ajax=cargar_estudiante&id=${encodeURIComponent(id)}`, {
                signal: studentDataRequest.signal
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'No se pudieron cargar los datos del estudiante.');

            const student = data.student;
            if (populateStudent) {
                if (studentDataInputs[0]) studentDataInputs[0].value = student.nombres;
                if (studentDataInputs[1]) studentDataInputs[1].value = student.apellido_paterno;
                if (studentDataInputs[2]) studentDataInputs[2].value = student.apellido_materno;
                if (studentDataInputs[3]) studentDataInputs[3].value = student.dni;
            }
            fillFamilyData(data.family, data.last_matricula);
            if (populateHistory) populateAcademicHistory(data.last_matricula);
        } catch (error) {
            if (error.name === 'AbortError') return;
            console.error(error);
            const message = document.createElement('div');
            message.className = 'alert alert-danger mt-2';
            message.textContent = error.message;
            const target = populateStudent ? estudianteSugerencias : hermanoSugerencias;
            if (target) target.replaceChildren(message);
            if (!populateStudent && hermanoIdInput) hermanoIdInput.value = '';
        }
    }

    if (buscarHermanoInput) {
        buscarHermanoInput.addEventListener('input', () => {
            if (hermanoIdInput) hermanoIdInput.value = '';
            clearTimeout(siblingSearchTimer);
            siblingSearchRequest?.abort();
            studentDataRequest?.abort();
            if (hermanoSugerencias) hermanoSugerencias.replaceChildren();
            const term = buscarHermanoInput.value.trim();
            if (term.length < 2) return;

            siblingSearchTimer = setTimeout(async () => {
                siblingSearchRequest = new AbortController();
                try {
                    const query = new URLSearchParams({ ajax: 'buscar_estudiantes', q: term });
                    const response = await fetch(`matriculas.php?${query}`, { signal: siblingSearchRequest.signal });
                    if (!response.ok) throw new Error('No se pudieron buscar hermanos.');
                    const estudiantes = await response.json();
                    estudiantes.forEach((hermano) => {
                        const opcion = document.createElement('button');
                        opcion.type = 'button';
                        opcion.className = 'list-group-item list-group-item-action';
                        opcion.textContent = `${hermano.apellido_paterno} ${hermano.apellido_materno}, ${hermano.nombres} · DNI ${hermano.dni}`;
                        opcion.addEventListener('click', async () => {
                            if (hermanoIdInput) hermanoIdInput.value = hermano.id;
                            buscarHermanoInput.value = opcion.textContent;
                            if (hermanoSugerencias) hermanoSugerencias.replaceChildren();
                            estudianteIdInput.value = '';
                            studentModeRadios.forEach((radio) => { radio.checked = radio.value === 'nuevo'; });
                            setStudentMode('nuevo', false);
                            try {
                                await loadExistingStudent(hermano.id, { populateStudent: false, populateHistory: false });
                            } catch (error) {
                                if (hermanoIdInput) hermanoIdInput.value = '';
                                console.error(error);
                            }
                        });
                        if (hermanoSugerencias) hermanoSugerencias.append(opcion);
                    });
                    if (!estudiantes.length && hermanoSugerencias) {
                        const empty = document.createElement('div');
                        empty.className = 'list-group-item text-muted';
                        empty.textContent = 'No se encontraron estudiantes.';
                        hermanoSugerencias.append(empty);
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') console.error(error);
                }
            }, 250);
        });
    }

    const levelSelect = document.getElementById('nivel_id');
    const gradeSelect = document.getElementById('grado_id');
    const sectionSelect = document.getElementById('seccion_id');
    const gradeSectionInput = document.getElementById('grado_seccion_id');

    const updateGrades = () => {
        if (!gradeSelect || !levelSelect) return;
        Array.from(gradeSelect.options).forEach((option) => {
            option.hidden = option.value !== '' && option.dataset.nivelId !== levelSelect.value;
        });
        if (gradeSelect.selectedOptions[0]?.hidden) {
            gradeSelect.value = '';
        }
        updateSections();
    };

    const updateSections = () => {
        if (!sectionSelect || !gradeSelect) return;
        Array.from(sectionSelect.options).forEach((option) => {
            option.hidden = option.value !== '' && option.dataset.gradoId !== gradeSelect.value;
        });
        const selected = sectionSelect.selectedOptions[0];
        if (!selected || selected.hidden) {
            sectionSelect.value = '';
            if (gradeSectionInput) gradeSectionInput.value = '';
        } else {
            if (gradeSectionInput) gradeSectionInput.value = selected.dataset.gradoSeccionId;
        }
    };

    if (levelSelect && gradeSelect && sectionSelect) {
        levelSelect.addEventListener('change', updateGrades);
        gradeSelect.addEventListener('change', updateSections);
        sectionSelect.addEventListener('change', updateSections);
        updateGrades();
    }

    function populateAcademicHistory(lastMatricula) {
        if (!levelSelect || !gradeSelect || !sectionSelect) return;
        if (!lastMatricula?.nivel_id) {
            levelSelect.value = '';
            gradeSelect.value = '';
            sectionSelect.value = '';
            if (gradeSectionInput) gradeSectionInput.value = '';
            updateGrades();
            return;
        }
        gradeSelect.value = '';
        sectionSelect.value = '';
        if (gradeSectionInput) gradeSectionInput.value = '';
        levelSelect.value = String(lastMatricula.nivel_id);
        updateGrades();

        const gradeOptions = Array.from(gradeSelect.options).filter((option) => !option.hidden && option.value);
        const nextGrade = gradeOptions.find((option) =>
            Number(option.dataset.numero) === Number(lastMatricula.grado_numero) + 1
        );
        const previousGrade = gradeOptions.find((option) => option.value === String(lastMatricula.grado_id));
        const chosenGrade = nextGrade || previousGrade;
        if (!chosenGrade) return;
        gradeSelect.value = chosenGrade.value;
        updateSections();

        const visibleSections = Array.from(sectionSelect.options).filter((option) => !option.hidden && option.value);
        const previousSection = visibleSections.find((option) => option.value === String(lastMatricula.seccion_id));
        sectionSelect.value = (previousSection || visibleSections[0])?.value || '';
        updateSections();
    }
});
