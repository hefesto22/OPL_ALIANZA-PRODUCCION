<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Convierte cualquier imagen subida (JPG/PNG/WebP) a WebP con resize máximo.
 *
 * Antes el FileUpload de Filament hacía resize automático pero conservaba
 * el formato de entrada (JPG entraba → JPG salía). A escala (~100 depósitos/día
 * con foto), la diferencia entre JPG y WebP es ~60% de espacio en disco:
 * un año de receipts pasa de ~14 GB a ~5-6 GB.
 *
 * Decisión de diseño: usa PHP-GD nativo en lugar de intervention/image para
 * no agregar dependencia — Filament ya usa GD internamente para sus resizes,
 * así que sabemos que está disponible. La API es verbosa pero el código
 * queda aislado en este Service.
 *
 * Calidad 85: balance entre compresión y legibilidad. Comprobantes bancarios
 * tienen texto fino (montos, referencias); por debajo de 80 la legibilidad
 * empieza a sufrir según pruebas internas.
 *
 * Resize 1400×1400 máximo: preserva aspect ratio. Suficiente para
 * comprobante completo legible a tamaño completo en pantalla.
 */
class ReceiptImageService
{
    protected const MAX_DIMENSION = 1400;

    protected const WEBP_QUALITY = 85;

    protected const STORAGE_DIRECTORY = 'deposits/receipts';

    protected const DISK = 'local';

    /**
     * Carga la imagen subida, la redimensiona si excede el límite, la
     * convierte a WebP y la guarda en disk 'local'/deposits/receipts/.
     * Retorna la ruta relativa para guardar en deposit.receipt_image.
     *
     * Lanza RuntimeException si el archivo está corrupto o no es un tipo
     * de imagen soportado; el FileUpload de Filament lo captura como
     * fallo de validación y muestra al usuario.
     */
    public function convertToWebp(UploadedFile $file): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Extensión GD no disponible — requerida para conversión WebP.');
        }

        $image = $this->loadImage($file);

        try {
            $image = $this->resizeIfNeeded($image);

            $filename = Str::uuid()->toString().'.webp';
            $relativePath = self::STORAGE_DIRECTORY.'/'.$filename;
            $absolutePath = Storage::disk(self::DISK)->path($relativePath);

            // Garantizar que el directorio existe (primera vez en setup nuevo
            // o tras truncate manual).
            $directory = dirname($absolutePath);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            if (! imagewebp($image, $absolutePath, self::WEBP_QUALITY)) {
                throw new RuntimeException('No se pudo escribir el archivo WebP.');
            }

            return $relativePath;
        } finally {
            // imagedestroy() asegura liberación de memoria GD aunque la
            // escritura falle. Imágenes grandes (8MB JPG) ocupan 30-50MB
            // en memoria mientras GD las procesa.
            imagedestroy($image);
        }
    }

    /**
     * Carga la imagen según el MIME type real del archivo subido.
     */
    protected function loadImage(UploadedFile $file): GdImage
    {
        $path = $file->getRealPath();
        $mime = $file->getMimeType();

        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image) {
            throw new RuntimeException("Tipo de imagen no soportado o archivo corrupto: {$mime}");
        }

        return $image;
    }

    /**
     * Si la imagen excede MAX_DIMENSION en cualquier eje, la redimensiona
     * preservando aspect ratio. Si ya está dentro del límite, retorna
     * la misma instancia sin tocar.
     */
    protected function resizeIfNeeded(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return $source;
        }

        $ratio = min(
            self::MAX_DIMENSION / $width,
            self::MAX_DIMENSION / $height
        );
        $newWidth = (int) round($width * $ratio);
        $newHeight = (int) round($height * $ratio);

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preservar transparencia para sources PNG/WebP — sin esto los
        // pixeles transparentes salen negros en el WebP final.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled(
            $resized,
            $source,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $width, $height
        );

        imagedestroy($source);

        return $resized;
    }
}
