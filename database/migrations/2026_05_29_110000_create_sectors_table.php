<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed with existing hardcoded sectors
        $sectors = [
            'Agroalimentare',
            'Artigianato',
            'Commercio al dettaglio',
            'Commercio all\'ingrosso',
            'Consulenza e servizi professionali',
            'Editoria e media',
            'Energia e ambiente',
            'Formazione e istruzione',
            'ICT e tecnologia',
            'Immobiliare',
            'Logistica e trasporti',
            'Manifatturiero',
            'Ristorazione e ospitalità',
            'Salute e benessere',
            'Sport e tempo libero',
            'Turismo',
            'Altro',
        ];

        foreach ($sectors as $i => $name) {
            DB::table('sectors')->insert([
                'name'       => $name,
                'is_active'  => true,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
