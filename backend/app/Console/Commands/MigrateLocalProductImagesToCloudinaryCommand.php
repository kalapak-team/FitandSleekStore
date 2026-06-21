<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MigrateLocalProductImagesToCloudinaryCommand extends Command
{
    protected $signature = 'media:migrate-product-images-to-cloudinary
                            {--dry-run : List changes without uploading or saving}
                            {--product_id= : Migrate a single product by id}
                            {--force : Re-upload even when the reference is already an external URL}';

    protected $description = 'Upload legacy /storage/product-gallery files to Cloudinary and update product image_url, gallery, and color swatches';

    /** @var array<string, string> */
    private array $uploadCache = [];

    public function handle(): int
    {
        if (! Media::cloudinaryIsConfigured()) {
            $this->error('Cloudinary is not configured. Set CLOUDINARY_URL or CLOUDINARY_CLOUD_NAME + API credentials.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Product::query()->orderBy('id');
        if ($this->option('product_id')) {
            $query->where('id', (int) $this->option('product_id'));
        }

        $products = $query->get();
        if ($products->isEmpty()) {
            $this->warn('No products found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Scanning '.$products->count().' product(s)...');

        $updatedProducts = 0;
        $uploadedFiles = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($products as $product) {
            $dirty = false;

            $newImageUrl = $this->migrateReference($product->image_url, $dryRun, $force, $uploadedFiles, $skipped, $failed);
            if ($newImageUrl !== $product->image_url) {
                $product->image_url = $newImageUrl;
                $dirty = true;
            }

            $gallery = is_array($product->gallery) ? $product->gallery : [];
            $newGallery = [];
            foreach ($gallery as $line) {
                if (! is_string($line) || trim($line) === '') {
                    continue;
                }
                $migrated = $this->migrateReference($line, $dryRun, $force, $uploadedFiles, $skipped, $failed);
                $newGallery[] = $migrated;
                if ($migrated !== $line) {
                    $dirty = true;
                }
            }
            if ($newGallery !== $gallery) {
                $product->gallery = $newGallery;
                $dirty = true;
            }

            $colors = is_array($product->colors) ? $product->colors : [];
            foreach ($colors as $i => $color) {
                if (! is_array($color)) {
                    continue;
                }
                $img = $color['image_url'] ?? $color['imageUrl'] ?? null;
                if (! is_string($img) || trim($img) === '') {
                    continue;
                }
                $migrated = $this->migrateReference($img, $dryRun, $force, $uploadedFiles, $skipped, $failed);
                if ($migrated !== $img) {
                    $colors[$i]['image_url'] = $migrated;
                    unset($colors[$i]['imageUrl']);
                    $dirty = true;
                }
            }
            if ($dirty) {
                $product->colors = $colors;
            }

            if (! $dirty) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] would update product #{$product->id} ({$product->name})");
                $updatedProducts++;

                continue;
            }

            try {
                $product->save();
                $updatedProducts++;
                $this->info("  updated product #{$product->id} ({$product->name})");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  failed to save product #{$product->id}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done.');
        $this->line("  products updated: {$updatedProducts}");
        $this->line("  files uploaded: {$uploadedFiles}");
        $this->line("  references skipped: {$skipped}");
        if ($failed > 0) {
            $this->warn("  failures: {$failed}");
        }

        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply changes.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migrateReference(
        ?string $reference,
        bool $dryRun,
        bool $force,
        int &$uploadedFiles,
        int &$skipped,
        int &$failed
    ): ?string {
        $reference = is_string($reference) ? trim($reference) : '';
        if ($reference === '') {
            return null;
        }

        if (Media::isExternalUrl($reference)) {
            if ($force && $this->isLegacyExternalStorageUrl($reference)) {
                // Broken Cloudinary/R2 URLs built from /storage paths — try local file via storage key.
                $key = Media::storageKey($reference);
                if ($key !== null) {
                    return $this->uploadLocalKey($key, $dryRun, $uploadedFiles, $skipped, $failed) ?? $reference;
                }
            }

            $skipped++;

            return $reference;
        }

        $key = Media::storageKey($reference);
        if ($key === null || $key === '') {
            $skipped++;

            return $reference;
        }

        if (! $this->looksLikeProductMediaKey($key)) {
            $skipped++;

            return $reference;
        }

        if (isset($this->uploadCache[$key])) {
            return $this->uploadCache[$key];
        }

        $url = $this->uploadLocalKey($key, $dryRun, $uploadedFiles, $skipped, $failed);
        if ($url === null) {
            return $reference;
        }

        $this->uploadCache[$key] = $url;

        return $url;
    }

    private function looksLikeProductMediaKey(string $key): bool
    {
        if (str_starts_with($key, 'product-gallery/')) {
            return true;
        }

        if (str_starts_with($key, 'products/')) {
            return true;
        }

        return str_starts_with($key, 'storage/product-gallery/')
            || str_starts_with($key, 'storage/products/');
    }

    private function isLegacyExternalStorageUrl(string $url): bool
    {
        $lower = strtolower($url);

        return str_contains($lower, '/storage/product-gallery/')
            || str_contains($lower, '/storage/products/')
            || str_contains($lower, 'cdn.fitandsleek.online');
    }

    private function uploadLocalKey(
        string $key,
        bool $dryRun,
        int &$uploadedFiles,
        int &$skipped,
        int &$failed
    ): ?string {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        if (str_starts_with($key, 'storage/')) {
            $key = substr($key, 8);
        }

        if (! Storage::disk('public')->exists($key)) {
            $this->warn("  missing local file: storage/app/public/{$key}");
            $skipped++;

            return null;
        }

        $folder = dirname($key);
        $folder = $folder === '.' ? 'product-gallery' : $folder;

        if ($dryRun) {
            $this->line("  [dry-run] would upload {$key} → Cloudinary ({$folder})");
            $uploadedFiles++;

            return 'https://res.cloudinary.com/example/image/upload/'.$key;
        }

        try {
            $absolute = Storage::disk('public')->path($key);
            $mime = @mime_content_type($absolute) ?: 'image/jpeg';
            $upload = new UploadedFile($absolute, basename($absolute), $mime, null, true);
            $url = Media::uploadToCloudinary($upload, $folder);
            $uploadedFiles++;
            $this->line("  uploaded {$key}");

            return $url;
        } catch (\Throwable $e) {
            $this->error("  upload failed for {$key}: ".$e->getMessage());
            $failed++;

            return null;
        }
    }
}
