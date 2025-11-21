<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Media;
use App\Entity\Thumb;
use App\Entity\User;
use App\Repository\MediaRepository;
use App\Repository\ThumbRepository;
use App\Repository\UserRepository;
use App\Util\Ndjson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

#[AsCommand('app:export', 'Export users, media, and thumbs to an NDJSON archive')]
final class ExportArchiveCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private MediaRepository $mediaRepo,
        private ThumbRepository $thumbRepo,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Output path (.zip to archive, or directory for plain files)')]
        string $output,

        #[Option('Batch size for streaming export')]
        int $batchSize = 1000,

        #[Option('Restrict export to a single user code (root)')]
        ?string $root = null,

        #[Option('Include sensitive fields (email, apiKey) for users')]
        bool $withSensitive = true,

        #[Option('Write as zip (otherwise plain jsonld')]
        ?bool $zip = null,
    ): int {
        $fs = new Filesystem();

        $isZip = $zip && str_ends_with(strtolower($output), '.zip');
        $workDir = $isZip ? ($output . '.tmpdir') : rtrim($output, DIRECTORY_SEPARATOR);

        if (!$fs->exists($workDir)) {
            $fs->mkdir($workDir);
        }

        $usersPath  = $workDir . DIRECTORY_SEPARATOR . 'users.ndjson';
        $mediaPath  = $workDir . DIRECTORY_SEPARATOR . 'media.ndjson';
        $thumbsPath = $workDir . DIRECTORY_SEPARATOR . 'thumbs.ndjson';

        // fresh files
        foreach ([$usersPath, $mediaPath, $thumbsPath] as $p) {
            if ($fs->exists($p)) { $fs->remove($p); }
        }

        // USERS
        $io->section('Exporting users');
        $qb = $this->userRepo->createQueryBuilder('u')->orderBy('u.code', 'ASC');
//        if ($root) { $qb->andWhere('u.code = :code')->setParameter('code', $root); }
        $count = 0;
        foreach ($qb->getQuery()->toIterable() as $user) {
            /** @var User $user */
            $row = [
                'code'             => $user->getId(),
                'approxImageCount' => $user->getApproxImageCount(),
                'mediaCallbackUrl' => $user->getMediaCallbackUrl(),
                'thumbCallbackUrl' => $user->getThumbCallbackUrl(),
                'binCount'         => $user->getBinCount(),
                'roles'            => $user->getRoles(),
                'isVerified'       => $user->isVerified(),
            ];
            $row['email'] = $user->getEmail();
            if ($withSensitive) {
                $row['apiKey'] = $user->getApiKey();
                $row['password'] = $user->getPassword();
            }
            Ndjson::writeRow($usersPath, $row);
            if ((++$count % $batchSize) === 0) { $io->text("Users exported: $count"); $this->em->clear(); }
        }
        $io->success("Users exported: $count");

        // MEDIA
        $io->section('Exporting media');
        $qb = $this->mediaRepo->createQueryBuilder('m')
            ->leftJoin('m.user', 'u')->addSelect('u')
            ->orderBy('m.code', 'ASC');
        if ($root) { $qb->andWhere('u.code = :code')->setParameter('code', $root); }
        $count = 0;
        foreach ($qb->getQuery()->toIterable() as $media) {
            /** @var Media $media */
            Ndjson::writeRow($mediaPath, [
                'code'           => $media->getCode(),
                'marking'        => $media->getMarking(),
                'root'           => $media->getRoot(), // user code
                'path'           => $media->getPath(),
                'originalUrl'    => $media->getOriginalUrl(),
                'mimeType'       => $media->getMimeType(),
                'size'           => $media->getSize(),
                'statusCode'     => $media->getStatusCode(),
                'originalWidth'  => $media->getOriginalWidth(),
                'originalHeight' => $media->getOriginalHeight(),
                'ext'            => $media->getExt(),
                'exif'           => $media->getExif(),
                'context'        => $media->context,
                'blur'           => $media->blur,
                'resized' => $media->resized,
                'userCode' => $media->userCode,
                'createdAt'      => $media->createdAt?->format(DATE_ATOM),
                'updatedAt'      => $media->updatedAt?->format(DATE_ATOM),
            ]);
            if ((++$count % $batchSize) === 0) { $io->text("Media exported: $count"); $this->em->clear(); }
        }
        $io->success("Media exported: $count");

        // THUMBS
        $io->section('Exporting thumbs');
        $qb = $this->thumbRepo->createQueryBuilder('t')
            ->leftJoin('t.media', 'm')->addSelect('m')
            ->leftJoin('m.user', 'u')->addSelect('u')
            ->orderBy('m.code', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($root) { $qb->andWhere('u.code = :code')->setParameter('code', $root); }
        $count = 0;
        foreach ($qb->getQuery()->toIterable() as $thumb) {
            /** @var Thumb $thumb */
            Ndjson::writeRow($thumbsPath, [
                'id' => $thumb->getId(),
                'media'    => $thumb->getMedia()?->getCode(),
                'liipCode' => self::getLiipCode($thumb),
                'size'     => $thumb->size,
                'w'        => $thumb->getW(),
                'h'        => $thumb->getH(),
                'url'      => $thumb->getUrl(),
                'marking'  => $thumb->getMarking(),
            ]);
            if ((++$count % $batchSize) === 0) { $io->text("Thumbs exported: $count"); $this->em->clear(); }
        }
        $io->success("Thumbs exported: $count");

        if ($isZip) {
            $io->section('Creating zip');
            $zip = new ZipArchive();
            if (true !== $zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                $io->error('Unable to create zip: ' . $output);
                return Command::FAILURE;
            }
            foreach (['users.ndjson','media.ndjson','thumbs.ndjson'] as $f) {
                $zip->addFile($workDir . DIRECTORY_SEPARATOR . $f, $f);
            }
            $zip->close();
            $io->success('Wrote ' . $output);
            try { $fs->remove($workDir); } catch (\Throwable) {}
        } else {
            $io->success('Wrote NDJSON files into ' . $workDir);
        }

        return Command::SUCCESS;
    }

    /** Safe access for private(set) promoted property */
    private static function getLiipCode(Thumb $t): ?string
    {
        $rp = new \ReflectionProperty($t, 'liipCode');
        $rp->setAccessible(true);
        /** @var ?string $val */
        $val = $rp->getValue($t);
        return $val;
    }
}
