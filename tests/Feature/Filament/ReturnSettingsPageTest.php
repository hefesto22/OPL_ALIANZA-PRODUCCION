<?php

namespace Tests\Feature\Filament;

use App\Enums\ReturnPublicationMode;
use App\Filament\Pages\ReturnSettingsPage;
use App\Models\ReturnSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReturnSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantalla Configuración → Devoluciones: la abren super_admin y admin,
 * muestra lo guardado y guarda por ReturnSettingsService.
 *
 * Las tres tarjetas de "cuándo ve Jaremar":
 *   Al momento            → after_registration, 0 días
 *   Después de unos días  → after_registration, 1 a 30 días
 *   Al cerrar la ventana  → window
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

        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Admin Prueba']);
        $this->admin->assignRole('admin');

        $oac = Warehouse::factory()->oac()->create();
        $this->encargado = User::factory()->forWarehouse($oac)->create(['is_active' => true]);
        $this->encargado->assignRole('encargado');

        config(['api.devoluciones_ventana_dias_habiles' => 7]);

        Filament::setCurrentPanel('admin');
    }

    private function guardarAjuste(ReturnPublicationMode $mode, int $delayDays): void
    {
        app(ReturnSettingsService::class)->update(7, $mode, $delayDays, false, $this->admin);
    }

    // ── Acceso y vista ───────────────────────────────────────────

    public function test_el_admin_abre_la_pantalla_con_las_tres_opciones(): void
    {
        $this->actingAs($this->admin)
            ->get(ReturnSettingsPage::getUrl())
            ->assertOk()
            ->assertSee('Configuración de Devoluciones')
            ->assertSee('Días hábiles por manifiesto')
            ->assertSee('Al momento')
            ->assertSee('En vivo')
            ->assertSee('Después de unos días')
            ->assertSee('Al cerrar la ventana del manifiesto')
            ->assertSee('Sin cambios desde que se instaló');

        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->assertFormSet(['window_business_days' => 7])
            ->assertSet('publication_choice', ReturnSettingsPage::CHOICE_WINDOW);
    }

    public function test_el_encargado_no_entra(): void
    {
        $this->actingAs($this->encargado)
            ->get(ReturnSettingsPage::getUrl())
            ->assertForbidden();
    }

    public function test_al_volver_a_entrar_marca_lo_guardado(): void
    {
        $this->actingAs($this->admin);

        $this->guardarAjuste(ReturnPublicationMode::AfterRegistration, 0);
        Livewire::test(ReturnSettingsPage::class)
            ->assertSet('publication_choice', ReturnSettingsPage::CHOICE_IMMEDIATE);

        $this->guardarAjuste(ReturnPublicationMode::AfterRegistration, 3);
        Livewire::test(ReturnSettingsPage::class)
            ->assertSet('publication_choice', ReturnSettingsPage::CHOICE_AFTER_DAYS)
            ->assertSet('publication_delay_days', 3);

        // El resumen de arriba dice lo guardado y quién lo cambió.
        $this->get(ReturnSettingsPage::getUrl())
            ->assertOk()
            ->assertSee('3 días después de registrarla')
            ->assertSee('Admin Prueba');
    }

    // ── Guardar ──────────────────────────────────────────────────

    public function test_guarda_al_momento(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->fillForm(['window_business_days' => 5])
            ->set('publication_choice', ReturnSettingsPage::CHOICE_IMMEDIATE)
            ->assertSee('Si la bodega registra una devolución hoy')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertHasNoErrors()
            ->assertNotified('Configuración de devoluciones guardada.');

        $settings = ReturnSetting::current();
        $this->assertSame(5, $settings->window_business_days);
        $this->assertSame(ReturnPublicationMode::AfterRegistration, $settings->publication_mode);
        $this->assertSame(0, $settings->publication_delay_days);
        $this->assertSame($this->admin->id, $settings->updated_by);
    }

    public function test_guarda_despues_de_unos_dias(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->set('publication_choice', ReturnSettingsPage::CHOICE_AFTER_DAYS)
            ->set('publication_delay_days', 2)
            ->call('save')
            ->assertHasNoErrors();

        $settings = ReturnSetting::current();
        $this->assertSame(ReturnPublicationMode::AfterRegistration, $settings->publication_mode);
        $this->assertSame(2, $settings->publication_delay_days);
    }

    public function test_guarda_al_cerrar_la_ventana(): void
    {
        $this->actingAs($this->admin);
        $this->guardarAjuste(ReturnPublicationMode::AfterRegistration, 0);

        Livewire::test(ReturnSettingsPage::class)
            ->set('publication_choice', ReturnSettingsPage::CHOICE_WINDOW)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(ReturnPublicationMode::Window, ReturnSetting::current()->publication_mode);
    }

    public function test_despues_de_unos_dias_pide_al_menos_un_dia(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReturnSettingsPage::class)
            ->set('publication_choice', ReturnSettingsPage::CHOICE_AFTER_DAYS)
            ->set('publication_delay_days', 0)
            ->call('save')
            ->assertHasErrors(['publication_delay_days']);

        // No se guardó nada.
        $this->assertSame(ReturnPublicationMode::Window, ReturnSetting::current()->publication_mode);
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
