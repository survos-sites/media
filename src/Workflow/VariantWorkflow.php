<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Asset;
use App\Entity\Variant;
use App\Service\AnalysisService;
use App\Service\VariantPlan;
use App\Workflow\VariantFlowDefinition as WF;
use League\Flysystem\FilesystemOperator;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use App\Util\ImageProbe;
use Survos\SaisBundle\Util\ShardedKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use App\Workflow\AssetFlow as AWF;

final class VariantWorkflow
{
    public function __construct(
        private readonly FilesystemOperator $archiveStorage, // originals + variants (S3/Hetzner)
        private readonly FilesystemOperator $localStorage,   // local.storage (Liip loader)
        #[Autowire('%kernel.project_dir%/public')] private string                  $publicDir,

        private readonly AnalysisService    $analysis,
        private readonly VariantPlan $variantPlan,
        private readonly LoggerInterface    $logger,
    ) {}

    private function getVariant($event): Variant { /** @var Variant */return $event->getSubject(); }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_RESIZE)]
    public function onVariantResize(TransitionEvent $event): void
    {
        /** @var Variant $variant */
        $variant = $event->getSubject();
        $asset   = $variant->asset;
//        dd($asset->id, $asset->path, __METHOD__);

        $url = $this->filterService->getUrlOfFilteredImage(
            path: $asset->path,
            filter: $variant->preset,
            resolver: null,
            webpSupported: true
        );
        $this->logger->warning(sprintf('%s (%s) has been resolved to %s',
            $asset->path, $variant->preset, $url));

        // update the info in the database?  Seems like the wrong place to do this.
        // although this is slow, it's nice to know the generated size.
        $cachedUrl = $this->filterService->getUrlOfFilteredImage(
            path: $asset->path,
            filter: $variant->preset,
            resolver: null,
            webpSupported: true
        );
        $variant->url = $cachedUrl;

        // hackish, we know the absolute path
        $absolutePath = $this->publicDir . parse_url($url, PHP_URL_PATH);
        $info = ImageProbe::probe(file_get_contents($absolutePath));


        $variant->size = filesize($absolutePath);
        $variant->width = $info['width'];
        $variant->height = $info['height'];
//        dd($absolutePath, file_exists($absolutePath), $info);
//        dd($url, $cachedUrl);
//        // $url _might_ be /resolve?
//        $thumb->setUrl($cachedUrl);
        return;



        // 1) Ensure we know where the ORIGINAL is (in archive); mirror to local for Liip
        $origKey = $this->requireOriginalInArchive($asset);
        $localOrigPath = $this->mirrorOriginalToLocal($origKey);

        // 2) Generate the variant using Liip (preset == filter name)
        $binary = $this->applyLiipFilter($localOrigPath, $variant->preset);

        // 3) (Optional) run analysis from THIS variant if it's the "small" one
        //    or whatever preset you prefer for analysis.
        if (in_array($variant->preset, ['thumb','small','preview'], true)) {
            $analytics = $this->analysis->analyzeFromBytes($binary->getContent(), $binary->getMimeType());
            // You can stash results on the Asset meta (JSON) or Variant itself as you prefer.
            // Example: put on Asset meta (not shown in entity; add a JSON column if you want).
            // $asset->meta = array_replace($asset->meta ?? [], $analytics);
        }

        // 4) Write the variant to ARCHIVE storage (CDN-able), using ShardedKey
        $hex   = $asset->id;
        $ext   = $this->extFromMime($binary->getMimeType()) ?? $variant->format ?? 'webp';
        $vKey  = ShardedKey::variantKey($hex, $variant->preset, $ext);
        $this->archiveStorage->write($vKey, $binary->getContent());

        // 5) Fill variant metadata quickly
        $probe = ImageProbe::probe($binary->getContent());
        $variant->storageBackend = 'archive';
        $variant->storageKey     = $vKey;
        $variant->url            = $this->publicUrl($vKey);
        $variant->format         = $ext;
        $variant->size           = \strlen($binary->getContent());
        $variant->width          = $probe['width'] ?? $variant->width;
        $variant->height         = $probe['height'] ?? $variant->height;
        $variant->touch();

        // 6) Archive original *after* we’ve generated at least one variant + analysis
        //    In your current design, the original is already in archive; if not,
        //    move from temp->archive here.

        // 7) Remove the local ORIGINAL to keep disk tidy
        $this->unlinkLocalPath($localOrigPath);
    }

    /**
     * Each time a Variant finishes resizing, check if all required presets for the parent Asset are done.
     * If yes, trigger Asset->analyze (your onAnalyze will run and use the small variant data).
     */
    #[AsCompletedListener(WF::WORKFLOW_NAME, WF::TRANSITION_RESIZE)]
    public function onVariantResizeCompleted(CompletedEvent $event): void
    {
        /** @var Variant $variant */
        $variant = $event->getSubject();
        $asset   = $variant->asset;

        // Are all required presets done?
        $required = $this->variantPlan->requiredPresetsForAsset($asset->mime ?? '');
        if ($required === []) {
            return;
        }

        $done = [];
        foreach ($asset->variants as $v) {
            if ($v->marking === WF::PLACE_DONE) {
                $done[$v->preset] = true;
            }
        }

        foreach ($required as $preset) {
            if (!isset($done[$preset])) {
                // Still waiting on others
                return;
            }
        }

        // extract features based on site

        // All required variants are done — kick Asset->analyze
        if (false) // this don't pass the smell test.
        if ($this->assetWorkflow->can($asset, AWF::TRANSITION_ANALYZE)) {
            $this->assetWorkflow->apply($asset, AWF::TRANSITION_ANALYZE);
            $this->logger->info('Triggered asset analyze after all variants done', [
                'hash' => $asset->id, 'presets' => $required
            ]);
        }
    }

    /** Ensure original exists in archive; return its key. */
    private function requireOriginalInArchive(Asset $asset): string
    {
        $key = $asset->storageKey;
        if (!$key) {
            throw new \RuntimeException('Asset missing storageKey for original.');
        }
        if (!$this->archiveStorage->fileExists($key)) {
            throw new \RuntimeException("Original not found in archive: $key");
        }
        return $key;
    }

    /** Mirror the archive original into local.storage for Liip; return absolute local path. */
    private function mirrorOriginalToLocal(string $archiveKey): string
    {
        // Keep same key layout under local
        $localKey = $archiveKey;

        if (!$this->localStorage->fileExists($localKey)) {
            $stream = $this->archiveStorage->readStream($archiveKey);
            $this->localStorage->writeStream($localKey, $stream);
            if (is_resource($stream)) { fclose($stream); }
        }

        // Materialize absolute path; this must match your local.storage root
        $root = $this->localRoot();
        $absolute = rtrim($root, '/').'/'.$localKey;

        (new Filesystem())->mkdir(\dirname($absolute), 0775);
        return $absolute;
    }

    private function applyLiipFilter(string $localAbsolutePath, string $preset): BinaryInterface
    {
        $bytes = @\file_get_contents($localAbsolutePath);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to read local original: '.$localAbsolutePath);
        }
        $mime = $this->mimeFromPath($localAbsolutePath) ?? 'image/jpeg';

        // Lightweight Binary wrapper
        $bin = new class($bytes, $mime) implements BinaryInterface {
            public function __construct(private string $c, private string $m) {}
            public function getContent(): string { return $this->c; }
            public function getMimeType(): string { return $this->m; }
            public function getFormat(): string { return 'bin'; }
        };

        return $this->filterManager->applyFilter($bin, $preset);
    }

    private function unlinkLocalPath(string $absolute): void
    {
        @\unlink($absolute);
        // optional: also delete empty parent dirs, if your FilesystemOperator doesn’t handle it
    }

    private function publicUrl(string $key): string
    {
        // Build your CDN URL; or resolve via a storage URL generator service.
        return ($_ENV['CDN_BASE'] ?? 'https://cdn.example.com').'/'.$key;
    }

    private function localRoot(): string
    {
        // Absolute path that backs "local.storage"
        return $_ENV['LOCAL_STORAGE_ROOT'] ?? '/var/storage/local';
    }

    private function mimeFromPath(string $path): ?string
    {
        $m = new MimeTypes();
        $types = $m->getMimeTypes(pathinfo($path, PATHINFO_EXTENSION));
        return $types[0] ?? null;
    }

    private function extFromMime(?string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => null,
        };
    }
}
