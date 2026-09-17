<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function assistant()
    {
        return view('dashboards.assistant', [
            'stats' => $this->stats(),
            'permissions' => [
                'editar' => $this->hasPermission('p_editar'),
                'matricular' => $this->hasPermission('p_matricular'),
                'cobrar' => $this->hasPermission('p_cobrar'),
            ],
            'recentActivity' => DB::table('auditoria_movimientos')
                ->where('usuario_id', auth()->id())
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function director()
    {
        return view('dashboards.director', [
            'stats' => $this->stats(),
            'recentAudit' => DB::table('auditoria_movimientos')
                ->leftJoin('usuarios', 'usuarios.id', '=', 'auditoria_movimientos.usuario_id')
                ->select('auditoria_movimientos.accion', 'auditoria_movimientos.creado_en', 'usuarios.nombres')
                ->orderByDesc('auditoria_movimientos.id')
                ->limit(5)
                ->get(),
        ]);
    }

    private function stats(): array
    {
        return [
            'padres' => DB::table('padres_apoderados')->count(),
            'matriculas' => DB::table('matriculas')->count(),
            'pagos' => DB::table('pagos')->count(),
        ];
    }

    private function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        return $user->rol === 'director' || DB::table('permisos_usuario')
            ->where('usuario_id', $user->id)
            ->where('permiso', $permission)
            ->where('otorgado', true)
            ->exists();
    }
}
