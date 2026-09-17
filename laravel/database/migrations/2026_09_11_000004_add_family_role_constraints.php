<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX alumno_unico_padre_idx ON alumno_responsable (alumno_id) WHERE tipo = 'padre'");
        DB::statement("CREATE UNIQUE INDEX alumno_unica_madre_idx ON alumno_responsable (alumno_id) WHERE tipo = 'madre'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS alumno_unico_padre_idx');
        DB::statement('DROP INDEX IF EXISTS alumno_unica_madre_idx');
    }
};
