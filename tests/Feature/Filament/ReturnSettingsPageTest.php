<?php

namespace Tests\Feature\Filament;

use App\Enums\ReturnPublicationMode;
use App\Filament\Pages\ReturnSettingsPage;
use App\Models\ReturnSetting;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantalla Configuración → Devoluciones: la abren super_admin y admin,
 * muestra lo vigente y guarda por ReturnSettingsService.
 */
class ReturnSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $encargado;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $oac = Warehouse::factory()->oac()->create();
        $this->encargado = User::factory()->forWarehouse($oac)->create(['is_active' => true]);
        $this->encargado->assignRole('encargado');

        config(['api.devoluciones_ventana_dias_habiles' => 7]);

        Filament::setCurrentPanel('admin');
    }

    public function test_el_admin_abre_la_pantalla_con_lo_vigente(): void
    {
        $this->actingAs($this->admin)
            ->get(ReturnSettingsPage::getUrl())
            ->assertOk()
            ->assertSee('Configuración de Devoluciones')
            ->assertSee('Días hábiles por manifiesto')
            ->assertSee('Al cerrar la ventana del manifiesto');

        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->assertFormSet([
                'window_business_days' => 7,
                // El radio con opciones de enum devuelve el enum, no el texto.
                'publication_mode' => ReturnPublicationMode::Window,
            ]);
    }

    public function test_el_encargado_no_entra(): void
    {
        $this->actingAs($this->encargado)
            ->get(ReturnSettingsPage::getUrl())
            ->assertForbidden();
    }

    public function test_guarda_dias_de_ventana_y_publicacion_al_dia_siguiente(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->fillForm([
                'window_business_days' => 5,
                'publication_mode' => ReturnPublicationMode::AfterRegistration->value,
            ])
            ->fillForm(['publication_delay_days' => 1])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Configuración de devoluciones guardada.');

        $settings = ReturnSetting::current();
        $this->assertSame(5, $settings->window_business_days);
        $this->assertSame(ReturnPublicationMode::AfterRegistration, $settings->publication_mode);
        $this->assertSame(1, $settings->publication_delay_days);
        $this->assertSame($this->admin->id, $settings->updated_by);
    }

    public function test_no_acepta_cero_dias_de_ventana(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->fillForm(['window_business_days' => 0])
            ->call('save')
            ->assertHasFormErrors(['window_business_days' => 'min']);

        $this->assertNull(ReturnSetting::current()->window_business_days);
    }
}
