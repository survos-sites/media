<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Media;
use App\Entity\Thumb;
use App\Entity\User;
use App\Repository\MediaRepository;
use App\Repository\UserRepository;
use App\Util\Ndjson;
use Doctrine\ORM\EntityManagerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use ZipArchive;

#[AsCommand('app:import', 'Import users, media, and thumbs from an NDJSON archive')]
final class ImportArchiveCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private MediaRepository $mediaRepo,
        private ObjectMapperInterface $mapper
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Path to .zip archive or directory with users.ndjson, media.ndjson, thumbs.ndjson')]
        string $input,

        #[Option('Batch size for flushing/clearing')]
        int $batchSize = 1000,

        #[Option('Validate but do not write changes')]
        bool $dryRun = false,

        #[Option('Limit phases (comma-separated): users,media,thumbs')]
        ?string $only = null,

        #[Option('Skip rows that already exist (do not update)')]
        bool $skipExisting = false
    ): int {
        $fs = new Filesystem();

        $phases = $only ? array_values(array_filter(array_map('trim', explode(',', strtolower($only))))) : [];

        // Handle zip extraction
        $workDir = $input;
        $cleanup = false;
        if (is_file($input) && str_ends_with(strtolower($input), '.zip')) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sais_import_' . bin2hex(random_bytes(4));
            $fs->mkdir($tmp);
            $zip = new ZipArchive();
            if (true !== $zip->open($input)) {
                $io->error('Cannot open zip: ' . $input);
                return Command::FAILURE;
            }
            $zip->extractTo($tmp);
            $zip->close();
            $workDir = $tmp;
            $cleanup = true;
            $io->note('Extracted to ' . $workDir);
        }

        $usersPath  = $workDir . DIRECTORY_SEPARATOR . 'users.ndjson';
        $mediaPath  = $workDir . DIRECTORY_SEPARATOR . 'media.ndjson';
        $thumbsPath = $workDir . DIRECTORY_SEPARATOR . 'thumbs.ndjson';

        try {
            if ($this->shouldRun('users', $phases) && is_file($usersPath)) {
                $userCodes = $this->importUsers($io, $usersPath, $batchSize, $dryRun, $skipExisting);
            }
            if ($this->shouldRun('media', $phases) && is_file($mediaPath)) {
                $this->importMedia($io, $userCodes, $mediaPath, $batchSize, $dryRun, $skipExisting);
            }
            if ($this->shouldRun('thumbs', $phases) && is_file($thumbsPath)) {
                $this->importThumbs($io, $thumbsPath, $batchSize, $dryRun, $skipExisting);
            }

            if ($dryRun) {
                    $this->em->clear();
                $io->success('Dry-run complete (no changes written).');
                return Command::SUCCESS;
            }

            $io->success('Import complete.');
            return Command::SUCCESS;
        } finally {
            if ($cleanup) {
                try { $fs->remove($workDir); } catch (\Throwable) {}
            }
        }
    }

    private function shouldRun(string $phase, array $only): bool
    {
        return $only === [] || in_array($phase, $only, true);
    }

    private function flushBatch(bool $dryRun): void
    {
        if ($dryRun) { $this->em->clear(); return; }
        $this->em->flush();
        $this->em->clear();
    }

    private function importUsers(SymfonyStyle $io, string $path, int $batchSize, bool $dryRun, bool $skipExisting): array
    {
        $io->section('Importing users');
        $n = 0;
        $users = [];
        foreach (Ndjson::read($path) as $row) {
            /** @var array{code:string} $row */
            dump($row['code']);
            $user = $this->userRepo->findOneBy(['id' => $row['code']]);
            dd($row, $user);
            if ($user) {
                if (!$skipExisting) {
                    // don't overwrite identifiers; map other fields by name
//                    unset($row['password']);
                    $row['medias'] = [];
                    $obj = (object) $row;
                    $this->mapper->map($obj, $user);
                }
            } else {
                // constructor requires code, approxImageCount, mediaCallbackUrl, thumbCallbackUrl
                $user = new User(
                    id: $row['code'],
                    approxImageCount: (int)($row['approxImageCount'] ?? 0),
                    mediaCallbackUrl: $row['mediaCallbackUrl'] ?? null,
                    thumbCallbackUrl: $row['thumbCallbackUrl'] ?? null
                );
//                unset($row['code']); // prevent re-setting identifier
//                $row['password'] = ''; // hmm, how do we want to handle this?
//                $row['apiKey'] = null;
                $row['medias'] = [];
                $row['id'] = $row['code'];
                unset($row['code']);
                $this->mapper->map((object)$row, $user);
                $this->em->persist($user);
            }
            $users[] = $user->getId();
            if ((++$n % $batchSize) === 0) { $this->flushBatch($dryRun); $io->text("Users processed: $n"); }
        }
        $this->flushBatch($dryRun);
        $io->success("Users processed: $n");
        return $users;
    }

    private function loadUsers(): array
    {
        $users = [];
        foreach ($this->userRepo->findAll() as $user) {
            $users[$user->getId()] = $user;
        }
        return $users;

    }
    private function importMedia(SymfonyStyle $io, array $userCodes, string $path, int $batchSize, bool $dryRun, bool $skipExisting): void
    {
        $io->section('Importing media');
        $n = 0;
        // refresh because of clear()
        $users = $this->loadUsers();
        foreach (Ndjson::read($path) as $row) {
            /** @var array{code:string,root:string} $row */
            $user = $this->userRepo->find($row['root']);
            if (!$user) {
                throw new \RuntimeException('User not found for media root=' . $row['root']);
            }
            $userCode = $row['userCode'];
            SurvosUtils::assertKeyExists($userCode, $users);
            $media = $this->mediaRepo->find($row['code']);
            if ($media) {
                if (!$skipExisting) {
                    // never remap code; make sure relation is correct
                    $media->setUser($user);
//                    unset($row['code'], $row['root']);
                    // createdAt/updatedAt map back if present
                    $this->mapper->map((object)$row, $media);
                }
            } else {
                // ctor: root, code, path?, originalUrl?
                $media = new Media(
                    root: $row['root'],
                    code: $row['code'],
                    path: $row['path'] ?? null,
                    originalUrl: $row['originalUrl'] ?? null
                );
                $media->setUser($user);
                $this->em->persist($media);
            }
//                unset($row['root'], $row['code'], $row['path'], $row['originalUrl']);
                if (!isset($row['resized'])) {
                    $row['resized'] = [];
                }
                $row['thumbs'] = []; // will be loaded later
                $row['user'] = $users[$userCode];
                $row['markingHistory'] = [];
                $row['enabledTransitions'] = [];
                $row['lastTransitionTime'] = null;
                unset($row['userCode']);
                if (!$row['mimeType']) {
//                    dump($row);
                }

                $this->mapper->map((object)$row, $media);
                $media->marking = $row['marking'];
//                $users[$userCode]->addMedia($media);

            if ((++$n % $batchSize) === 0) {
                $this->flushBatch($dryRun);
                $io->text("Media processed: $n");
                $users = $this->loadUsers();
            }
        }
        $this->flushBatch($dryRun);
        $io->success("Media processed: $n");
    }

    private function importThumbs(SymfonyStyle $io, string $path, int $batchSize, bool $dryRun, bool $skipExisting): void
    {
        $io->section('Importing thumbs');
        $n = 0;
        foreach (Ndjson::read($path) as $row) {
            /** @var array{media:string,liipCode:?string} $row */
            $media = $this->mediaRepo->find($row['media'] ?? '');
            if (!$media) {
                throw new \RuntimeException('Media not found for thumb (media=' . ($row['media'] ?? 'null') . ')');
            }

            $thumb = null;
            if ($skipExisting && isset($row['liipCode'])) {
                foreach ($media->getThumbs() as $t) {
                    if (self::getLiipCode($t) === $row['liipCode']) { $thumb = $t; break; }
                }
            }

            if ($thumb) {
                // skipExisting => no updates
            } else {
                $liipCode = $row['liipCode'];
                assert($liipCode);

                $thumb = new Thumb(media: $media, liipCode: $liipCode);

                // Map remaining fields by name; guard promoted/public props
                $safe = $row;
                unset($safe['liipCode']);
                $safe['markingHistory'] = [];
                $safe['enabledTransitions'] = [];
                $safe['lastTransitionTime'] = null;
                $thumb->url = $row['url'];
                $thumb->size = $row['size'];
                $thumb->marking = $row['marking'];
//                $this->mapper->map((object)$safe, $thumb);

                // size is a public promoted property; ensure assignment if present
                if (array_key_exists('size', $row)) { $thumb->size = $row['size']; }

                $this->em->persist($thumb);
            }

            if ((++$n % $batchSize) === 0) { $this->flushBatch($dryRun); $io->text("Thumbs processed: $n"); }
        }
        $this->flushBatch($dryRun);
        $io->success("Thumbs processed: $n");
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
