<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DirectorAdminController extends Controller
{
    private const PERMISSIONS = [
        'p_editar' => 'Editar teléfonos y direcciones',
        'p_matricular' => 'Registrar matrículas',
        'p_cobrar' => 'Registrar pagos',
    ];

    public function permissions()
    {
        $this->authorizeDirector();
        $assistants = DB::table('usuarios')
            ->where('rol', 'asistente')
            ->where('estado', true)
            ->orderBy('nombres')
            ->get();
        $assigned = DB::table('permisos_usuario')->get();
        $permissionMap = [];
        foreach ($assigned as $item) {
            $permissionMap[$item->usuario_id][$item->permiso] = (bool) $item->otorgado;
        }

        return view('director.permissions', [
            'assistants' => $assistants,
            'permissions' => self::PERMISSIONS,
            'permissionMap' => $permissionMap,
        ]);
    }

    public function updatePermissions(Request $request)
    {
        $this->authorizeDirector();
        $data = $request->validate(['usuario_id' => ['required', 'integer']]);
        $assistant = DB::table('usuarios')->where('id', $data['usuario_id'])->where('rol', 'asistente')->where('estado', true)->first();
        abort_unless($assistant, 400);

        $selected = [];
        foreach (array_keys(self::PERMISSIONS) as $permission) {
            $selected[$permission] = $request->boolean("permisos.{$permission}");
        }

        DB::transaction(function () use ($data, $selected): void {
            foreach ($selected as $permission => $granted) {
                DB::table('permisos_usuario')->upsert([
                    ['usuario_id' => $data['usuario_id'], 'permiso' => $permission, 'otorgado' => $granted, 'otorgado_por' => auth()->id(), 'actualizado_en' => now()],
                ], ['usuario_id', 'permiso'], ['otorgado', 'otorgado_por', 'actualizado_en']);
            }
            $this->auditMovement('cambio_permisos', 'Actualizó permisos del usuario ID ' . $data['usuario_id'] . ': ' . json_encode($selected, JSON_UNESCAPED_UNICODE));
        });

        return to_route('director.permissions')->with('success', 'Permisos actualizados correctamente.');
    }

    public function audit()
    {
        $this->authorizeDirector();
        $movements = DB::table('auditoria_movimientos')
            ->leftJoin('usuarios', 'usuarios.id', '=', 'auditoria_movimientos.usuario_id')
            ->select('auditoria_movimientos.*', 'usuarios.nombres as usuario')
            ->orderByDesc('auditoria_movimientos.id')
            ->paginate(25);

        return view('director.audit', compact('movements'));
    }

    private function authorizeDirector(): void
    {
        abort_unless(auth()->user()?->rol === 'director', 403);
    }

    private function auditMovement(string $action, string $details): void
    {
        DB::table('auditoria_movimientos')->insert([
            'usuario_id' => auth()->id(),
            'accion' => $action,
            'detalles' => $details,
            'ip_origen' => request()->ip(),
            'creado_en' => now(),
        ]);
    }
}
