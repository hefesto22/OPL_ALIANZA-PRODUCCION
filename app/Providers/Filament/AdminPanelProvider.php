<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->profile()
            ->brandLogo(asset('Logo-Hosana.png'))
            ->darkModeBrandLogo(asset('Logo-Hosana.png'))
            ->brandLogoHeight('3.6rem')
            ->favicon(asset('Logo-Hosana.ico'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            /*
             * Registro explícito de NavigationGroups con icono y orden.
             *
             * Filament autodescubre los grupos desde el getNavigationGroup()
             * de cada Resource, pero sin icono ni orden controlado. Aquí los
             * declaramos para:
             *   1. Asignar icono a cada header (mejora jerarquía visual).
             *   2. Fijar el orden (Configuración → Administración → Permisos).
             *   3. Renombrar "Filament Shield" → "Permisos" sin tocar el plugin
             *      (NavigationGroup::make($name) matchea por nombre interno;
             *      ->label() controla el display).
             */
            ->navigationGroups([
                NavigationGroup::make('Configuración')
                    ->icon('heroicon-o-cog-6-tooth'),
                NavigationGroup::make('Administración')
                    ->icon('heroicon-o-user-group'),
                NavigationGroup::make('Filament Shield')
                    ->label('Permisos')
                    ->icon('heroicon-o-shield-check'),
            ])
            ->sidebarCollapsibleOnDesktop();

    }
}
