<?php

namespace App\Http\Controllers;

class ModuleController extends Controller
{
    public function index(string $module)
    {
        abort_unless(in_array($module, ['padres', 'matriculas', 'pagos'], true), 404);

        $modules = [
            'padres' => ['title' => 'Padres y apoderados', 'description' => 'Registra y consulta la información de los responsables.'],
            'matriculas' => ['title' => 'Alumnos y matrículas', 'description' => 'Gestiona matrículas por nivel, grado y sección.'],
            'pagos' => ['title' => 'Control de pagos', 'description' => 'Registra cuotas y otros pagos escolares.'],
        ];

        return view('modules.index', ['module' => $module, ...$modules[$module]]);
    }
}
