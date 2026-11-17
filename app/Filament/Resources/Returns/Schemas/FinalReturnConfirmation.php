<?php

namespace App\Filament\Resources\Returns\Schemas;

use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

/**
 * Aviso + casilla obligatoria cuando la devolución queda definitiva al
 * guardarse (ReturnService::registersAsFinal): con "Al momento" Jaremar la
 * ve de una vez y ya no se edita ni se anula, así que quien registra
 * confirma que revisó y que responde por lo registrado.
 *
 * La casilla no se guarda: la constancia de quién registró es created_by y
 * la bitácora de la devolución. El candado real lo pone ReturnService.
 */
class FinalReturnConfirmation
{
    public const FIELD = 'confirmacion_responsable';

    /**
     * @param  Closure  $isFinal  Filament la evalúa con su inyección ($record,
     *                            $get…) y decide si se muestran aviso y casilla.
     * @return array<int, Placeholder|Checkbox>
     */
    public static function components(Closure $isFinal): array
    {
        return [
            Placeholder::make('aviso_devolucion_definitiva')
                ->label('')
                ->visible($isFinal)
                ->content(new HtmlString(
                    '<div style="border:1px solid #dc2626;background:rgba(220,38,38,.08);'.
                    'border-radius:8px;padding:12px 14px;margin-top:4px;">'.
                    '<strong style="color:#ef4444;font-size:14px;">Revise bien antes de registrar</strong>'.
                    '<div style="font-size:13px;line-height:1.55;margin-top:4px;">'.
                    'Esta devolución <strong>no se podrá editar ni anular</strong> después de registrarla: '.
                    'Jaremar la puede ver desde que se guarda. Verifique la factura, el motivo y las cantidades. '.
                    '<strong>Usted es el absoluto responsable</strong> de lo que registre.'.
                    '</div></div>'
                )),

            Checkbox::make(self::FIELD)
                ->label('Revisé la factura, el motivo y las cantidades. Entiendo que no se puede editar ni anular y que soy responsable de esta devolución.')
                ->visible($isFinal)
                ->accepted()
                ->validationMessages([
                    'accepted' => 'Confirme que revisó la devolución antes de registrarla.',
                ])
                ->dehydrated(false),
        ];
    }
}
