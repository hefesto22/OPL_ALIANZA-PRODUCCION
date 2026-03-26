<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Notificación de facturas importadas via API.
 *
 * Usa el canal 'database' nativo de Laravel para insertar directamente
 * en la tabla `notifications`. Filament lee de esta misma tabla y
 * renderiza las notificaciones en la campanita del panel.
 *
 * IMPORTANTE: El array `data` debe incluir 'format' => 'filament'
 * para que Filament lo reconozca y renderice correctamente con
 * título, body, icono y color.
 *
 * Las acciones se serializan como arrays planos replicando exactamente
 * la estructura de Filament\Actions\Action::toArray(), lo que permite
 * renderizar botones (como "Ver Manifiesto") sin depender de contexto
 * Livewire ni de clases de Filament.
 *
 * NO implementa ShouldQueue para garantizar ejecución síncrona
 * en contexto API. Si en el futuro se configura un queue worker,
 * basta con agregar `implements ShouldQueue` y `use Queueable`.
 */
class InvoicesImported extends Notification
{
    public function __construct(
        protected string  $title,
        protected string  $body,
        protected ?string $actionUrl = null,
        protected ?string $actionLabel = null,
        protected string  $icon = 'heroicon-o-inbox-arrow-down',
        protected string  $iconColor = 'success',
    ) {}

    /**
     * Canal de entrega: solo base de datos.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload que se guarda en la columna `data` de `notifications`.
     *
     * El formato replica exactamente lo que Filament espera:
     * - format: 'filament' → le dice a Filament que lo renderice
     * - title/body: texto visible en la campanita
     * - icon/iconColor: icono y color del badge
     * - duration: 'persistent' → no desaparece automáticamente
     * - actions: array de acciones con estructura de Filament\Actions\Action::toArray()
     */
    public function toDatabase(object $notifiable): array
    {
        $data = [
            'title'     => $this->title,
            'body'      => $this->body,
            'icon'      => $this->icon,
            'iconColor' => $this->iconColor,
            'format'    => 'filament',
            'duration'  => 'persistent',
            'actions'   => [],
        ];

        if ($this->actionUrl) {
            $data['actions'][] = [
                'name'                 => 'ver_manifiesto',
                'label'                => $this->actionLabel ?? 'Ver Manifiesto',
                'url'                  => $this->actionUrl,
                'color'                => null,
                'icon'                 => null,
                'iconPosition'         => null,
                'iconSize'             => null,
                'isOutlined'           => false,
                'isDisabled'           => false,
                'shouldClose'          => false,
                'shouldMarkAsRead'     => true,
                'shouldMarkAsUnread'   => false,
                'shouldOpenUrlInNewTab' => false,
                'shouldPostToUrl'      => false,
                'size'                 => null,
                'tooltip'              => null,
                'view'                 => null,
                'event'                => null,
                'eventData'            => null,
                'dispatchDirection'    => null,
                'dispatchToComponent'  => null,
                'extraAttributes'      => [],
                'alpineClickHandler'   => null,
            ];
        }

        return $data;
    }
}
