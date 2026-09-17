<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsables', function (Blueprint $table): void {
            $table->id();
            $table->string('dni', 8)->unique();
            $table->string('nombres', 100);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->text('direccion')->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('alumno_responsable', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alumno_id')->constrained('alumnos')->cascadeOnDelete();
            $table->foreignId('responsable_id')->constrained('responsables')->cascadeOnDelete();
            $table->string('parentesco', 60);
            $table->string('tipo', 20);
            $table->boolean('es_apoderado')->default(false);
            $table->timestamps();

            $table->unique(['alumno_id', 'responsable_id']);
            $table->index(['alumno_id', 'tipo']);
        });

        DB::statement("ALTER TABLE alumno_responsable ADD CONSTRAINT alumno_responsable_tipo_check CHECK (tipo IN ('padre', 'madre', 'otro'))");
        DB::statement("CREATE UNIQUE INDEX alumno_unico_padre_idx ON alumno_responsable (alumno_id) WHERE tipo = 'padre'");
        DB::statement("CREATE UNIQUE INDEX alumno_unica_madre_idx ON alumno_responsable (alumno_id) WHERE tipo = 'madre'");
        DB::statement('CREATE UNIQUE INDEX alumno_unico_apoderado_idx ON alumno_responsable (alumno_id) WHERE es_apoderado = TRUE');
    }

    public function down(): void
    {
        Schema::dropIfExists('alumno_responsable');
        Schema::dropIfExists('responsables');
    }
};
