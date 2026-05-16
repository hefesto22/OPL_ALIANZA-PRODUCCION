<?php

namespace Tests\Feature\Services;

use App\Services\ReceiptImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests del ReceiptImageService.
 *
 * Cubre el contrato público:
 *   - Convierte JPG/PNG/WebP al formato WebP estándar.
 *   - Redimensiona imágenes que exceden 1400×1400 preservando aspect ratio.
 *   - Mantiene imágenes pequeñas sin tocar dimensiones.
 *   - Rechaza archivos corruptos / tipos no soportados con RuntimeException.
 *
 * Los tests usan Storage::fake('local') + UploadedFile::fake()->image()
 * que produce JPGs/PNGs reales con dimensiones controlables.
 */
class ReceiptImageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ReceiptImageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = app(ReceiptImageService::class);
    }

    public function test_converts_jpeg_to_webp(): void
    {
        $file = UploadedFile::fake()->image('receipt.jpg', 800, 600);

        $path = $this->service->convertToWebp($file);

        $this->assertStringStartsWith('deposits/receipts/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_converts_png_to_webp(): void
    {
        $file = UploadedFile::fake()->image('receipt.png', 800, 600);

        $path = $this->service->convertToWebp($file);

        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_resizes_image_exceeding_max_dimension(): void
    {
        // 3000×2000 entra; máximo es 1400 en cualquier eje preservando ratio.
        // Resultado esperado: 1400×933 (ratio 3:2 preservado).
        $file = UploadedFile::fake()->image('big.jpg', 3000, 2000);

        $path = $this->service->convertToWebp($file);

        $absolutePath = Storage::disk('local')->path($path);
        [$width, $height] = getimagesize($absolutePath);

        $this->assertLessThanOrEqual(1400, $width);
        $this->assertLessThanOrEqual(1400, $height);
        // Aspect ratio preservado (con tolerancia por redondeo)
        $this->assertEqualsWithDelta(3000 / 2000, $width / $height, 0.01);
    }

    public function test_preserves_small_image_dimensions(): void
    {
        // 800×600 está dentro del límite — no debe redimensionarse.
        $file = UploadedFile::fake()->image('small.jpg', 800, 600);

        $path = $this->service->convertToWebp($file);

        $absolutePath = Storage::disk('local')->path($path);
        [$width, $height] = getimagesize($absolutePath);

        $this->assertSame(800, $width);
        $this->assertSame(600, $height);
    }

    public function test_output_is_actually_webp_format(): void
    {
        // getimagesize() retorna mime type real — verificamos que el archivo
        // sea WebP de verdad, no solo que tenga extensión .webp.
        $file = UploadedFile::fake()->image('receipt.png', 800, 600);

        $path = $this->service->convertToWebp($file);
        $absolutePath = Storage::disk('local')->path($path);

        $imageInfo = getimagesize($absolutePath);
        $this->assertSame('image/webp', $imageInfo['mime']);
    }

    public function test_each_upload_gets_unique_filename(): void
    {
        // Dos uploads idénticos generan paths distintos — UUID en el nombre
        // evita colisiones cuando dos operadores suben al mismo segundo.
        $file1 = UploadedFile::fake()->image('a.jpg', 400, 300);
        $file2 = UploadedFile::fake()->image('b.jpg', 400, 300);

        $path1 = $this->service->convertToWebp($file1);
        $path2 = $this->service->convertToWebp($file2);

        $this->assertNotSame($path1, $path2);
        Storage::disk('local')->assertExists($path1);
        Storage::disk('local')->assertExists($path2);
    }

    public function test_throws_on_non_image_file(): void
    {
        // Filament filtra por acceptedFileTypes ANTES de llegar al Service,
        // pero el Service también valida — defensa en profundidad. Un PDF
        // o texto plano con extensión .jpg sería rechazado.
        $file = UploadedFile::fake()->create('fake.jpg', 100, 'application/pdf');

        $this->expectException(RuntimeException::class);
        $this->service->convertToWebp($file);
    }
}
