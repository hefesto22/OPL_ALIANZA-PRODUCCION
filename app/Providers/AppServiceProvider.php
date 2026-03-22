<?php

namespace App\Providers;

use App\Models\InvoiceReturn;
use App\Observers\InvoiceReturnObserver;
use Illuminate\Auth\Events\Login;
use App\Listeners\RecordUserLogin;
use Illuminate\Support\Facades\Event;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, RecordUserLogin::class);

        // ── Observers ─────────────────────────────────────────────────────
        // InvoiceReturnObserver mantiene la integridad del manifiesto y la
        // factura cuando una devolución es eliminada (soft o force delete).
        InvoiceReturn::observe(InvoiceReturnObserver::class);

        // ── Rate limiter general (todos los endpoints API) ────────────────
        // Usa el valor de config/api.php → rate_limit_per_minute
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('api.rate_limit_per_minute', 60));
        });

        // ── Rate limiter dedicado para facturas/insertar ──────────────────
        // Es el endpoint más pesado del sistema — procesa miles de facturas
        // por llamada. En condiciones normales Jaremar manda máximo 5-10
        // batches por día. Si supera 5 por minuto, algo está mal en su
        // sistema y hay que frenar antes de saturar el servidor.
        // Se ajusta independientemente en config/api.php sin tocar el resto.
        RateLimiter::for('insertar', function (Request $request) {
            return Limit::perMinute(
                config('api.rate_limit_insertar_per_minute', 5)
            )->by($request->ip());
        });

        // ── Rate limiter dedicado para devoluciones ───────────────────────
        // Jaremar consulta hasta 40 veces al día en cierre de mes.
        // Se permite un máximo de 10 llamadas por minuto por IP.
        // Así podemos ajustar este endpoint de forma independiente
        // sin afectar facturas/insertar ni los demás.
        RateLimiter::for('devoluciones', function (Request $request) {
            return Limit::perMinute(
                config('api.rate_limit_devoluciones_per_minute', 10)
            )->by($request->ip());
        });
    }
}