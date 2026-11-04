<?php

namespace App\Models;

use App\Enums\ReturnPublicationMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configuración de devoluciones (Configuración → Devoluciones). Una sola
 * fila (id = 1, CHECK return_settings_single_row) por entorno.
 *
 * Se lee con current(); se cambia solo por ReturnSettingsService. Cada
 * cambio queda en la bitácora con quién lo hizo.
 *
 * @property int $id
 * @property int|null $window_business_days
 * @property ReturnPublicationMode $publication_mode
 * @property int $publication_delay_days
 * @property int|null $updated_by
 * @property-read User|null $updatedBy
 */
class ReturnSetting extends Model
{
    use LogsActivity;

    public const SINGLETON_ID = 1;

    /** Topes de la pantalla; los mismos que los CHECK de la tabla. */
    public const MAX_WINDOW_DAYS = 30;

    public const MAX_DELAY_DAYS = 30;

    protected $table = 'return_settings';

    protected $fillable = [
        'window_business_days',
        'publication_mode',
        'publication_delay_days',
        'updated_by',
    ];

    protected $attributes = [
        'publication_mode' => 'window',
        'publication_delay_days' => 1,
    ];

    protected function casts(): array
    {
        return [
            'window_business_days' => 'integer',
            'publication_mode' => ReturnPublicationMode::class,
            'publication_delay_days' => 'integer',
        ];
    }

    /**
     * La fila de este entorno. Si faltara (no debería: la crea la
     * migración), se usa una sin guardar con el comportamiento de siempre.
     */
    public static function current(): self
    {
        return static::query()->find(self::SINGLETON_ID) ?? new static;
    }

    /**
     * Quién guardó el último cambio desde la pantalla.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Días hábiles (lunes a sábado) para registrar devoluciones de un
     * manifiesto. Sin valor elegido en el panel, el del servidor
     * (config api.devoluciones_ventana_dias_habiles).
     */
    public function windowBusinessDays(): int
    {
        return max(1, $this->window_business_days ?? (int) config('api.devoluciones_ventana_dias_habiles', 7));
    }

    /**
     * Días entre que se registra una devolución y que Jaremar la ve; null
     * cuando se publica al cerrar la ventana del manifiesto.
     */
    public function publicationDelayDays(): ?int
    {
        return $this->publication_mode === ReturnPublicationMode::AfterRegistration
            ? max(0, (int) $this->publication_delay_days)
            : null;
    }

    /**
     * Momento en que Jaremar podrá ver una devolución registrada en
     * $registeredAt. Null = cuando cierre la ventana del manifiesto.
     * Un día son 24 horas: registrada el lunes a las 3 pm con 1 día, se ve
     * el martes a las 3 pm.
     */
    public function publishedAtFor(CarbonInterface $registeredAt): ?Carbon
    {
        $delayDays = $this->publicationDelayDays();

        return $delayDays === null
            ? null
            : Carbon::instance($registeredAt)->addDays($delayDays);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['window_business_days', 'publication_mode', 'publication_delay_days'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
