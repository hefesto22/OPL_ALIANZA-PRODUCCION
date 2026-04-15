<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class JaremarApiKeySeeder extends Seeder
{
    public function run(): void
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        // Verificar si ya existe una key configurada
        if (str_contains($envContent, 'JAREMAR_API_KEY=') && ! str_contains($envContent, 'JAREMAR_API_KEY=\n') && ! str_contains($envContent, 'JAREMAR_API_KEY=""')) {
            $this->command->warn('JAREMAR_API_KEY ya está configurado en .env. No se generó uno nuevo.');
            $this->command->warn('Si desea regenerarlo, elimine manualmente la línea JAREMAR_API_KEY del .env y ejecute este seeder nuevamente.');

            return;
        }

        $apiKey = Str::uuid()->toString();

        // Agregar o actualizar en .env
        if (str_contains($envContent, 'JAREMAR_API_KEY=')) {
            $envContent = preg_replace('/JAREMAR_API_KEY=.*/', "JAREMAR_API_KEY={$apiKey}", $envContent);
        } else {
            $envContent .= "\n# API Key para el OPL de Jaremar\nJAREMAR_API_KEY={$apiKey}\n";
        }

        file_put_contents($envPath, $envContent);

        // Limpiar cache de config para que tome el nuevo valor
        $this->command->call('config:clear');

        $this->command->newLine();
        $this->command->info('✅ ApiKey de Jaremar generado exitosamente.');
        $this->command->newLine();
        $this->command->table(
            ['Dato', 'Valor'],
            [
                ['ApiKey generado', $apiKey],
                ['Guardado en', '.env → JAREMAR_API_KEY'],
                ['Endpoint POST', url('api/v1/facturas/insertar')],
                ['Endpoint GET',  url('api/v1/manifiestos/{numero}/estado')],
                ['Header requerido', 'ApiKey: '.$apiKey],
            ]
        );
        $this->command->newLine();
        $this->command->warn('⚠  Comparta este ApiKey de forma segura con los técnicos de Jaremar.');
        $this->command->warn('   Una vez compartido, NO lo regenere sin coordinar con ellos primero.');
    }
}
