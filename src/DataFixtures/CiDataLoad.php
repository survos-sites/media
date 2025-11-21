<?php

namespace App\DataFixtures;

use App\Entity\File;
use App\Entity\Media;
use App\Entity\Storage;
use App\Entity\Thumb;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Google\Service\AdExchangeBuyer\Account;
use Survos\SaisBundle\Model\AccountSetup;
use Survos\SaisBundle\Service\SaisClientService;

class CiDataLoad extends Fixture
{
    function __construct(
        private readonly SaisClientService $sais,
    )
    {
        //$this->sais = new SaisClientService();
    }

    public function load(ObjectManager $manager): void
    {
        // Create Users (25 entries)
        $users = [];
        $roles = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_EDITOR'];

        for ($i = 0; $i < 25; $i++) {
            $userCode = 'user_' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);

            $user = new User(
                id: $userCode,
                approxImageCount: rand(100, 10000),
                mediaCallbackUrl: rand(0, 1) ? "https://example.com/media/callback/{$userCode}" : null,
                thumbCallbackUrl: rand(0, 1) ? "https://example.com/thumb/callback/{$userCode}" : null
            );


            $user->setEmail("user{$i}@example.com");
            $user->setApiKey(hash('sha256', $userCode . time()));
            $user->setRoles([$roles[array_rand($roles)]]);
            $user->setIsVerified(rand(0, 4) > 0); // 80% chance

            $users[] = $user;
            $manager->persist($user);
        }

        // Create Storage entities (30 entries)
        $storages = [];
        $adapters = ['local', 's3', 'ftp', 'sftp', 'azure', 'gcs'];
        $storageWords = ['documents', 'images', 'videos', 'uploads', 'cache', 'temp', 'assets', 'media'];

        for ($i = 0; $i < 30; $i++) {
            $storage = new Storage();
            $storage->setCode('storage_' . str_pad($i + 1, 3, '0', STR_PAD_LEFT));
            $storage->setAdapter($adapters[array_rand($adapters)]);
            $storage->setRoot('/storage/' . $storageWords[array_rand($storageWords)] . '/' . $storageWords[array_rand($storageWords)]);

            $storages[] = $storage;
            $manager->persist($storage);
        }

        // Create Media entities (35 entries)
        $medias = [];
        $mimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'video/mp4', 'video/avi'];
        $roots = ['gallery', 'uploads', 'media', 'assets', 'images'];
        $fileNames = ['photo', 'image', 'picture', 'snapshot', 'document', 'file', 'video', 'movie'];
        $categories = ['nature', 'animals', 'people', 'technology', 'food', 'travel', 'architecture'];

        // Real image URLs from various sources
        $realImageUrls = [
            'https://picsum.photos/800/600?random=1',
            'https://picsum.photos/1024/768?random=2',
            'https://picsum.photos/640/480?random=3',
            'https://picsum.photos/1200/900?random=4',
            'https://picsum.photos/800/800?random=5',
            'https://picsum.photos/1600/1200?random=6',
            'https://picsum.photos/400/300?random=7',
            'https://picsum.photos/900/600?random=8',
            'https://picsum.photos/720/540?random=9',
            'https://picsum.photos/1080/720?random=10',
            'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800&h=600',
            'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=1024&h=768',
            'https://images.unsplash.com/photo-1472214103451-9374bd1c798e?w=640&h=480',
            'https://images.unsplash.com/photo-1426604966848-d7adac402bff?w=1200&h=900',
            'https://images.unsplash.com/photo-1501594907352-04cda38ebc29?w=800&h=800',
            'https://images.unsplash.com/photo-1519904981063-b0cf448d479e?w=1600&h=1200',
            'https://images.unsplash.com/photo-1433086966358-54859d0ed716?w=400&h=300',
            'https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?w=900&h=600',
            'https://images.unsplash.com/photo-1447752875215-b2761acb3c5d?w=720&h=540',
            'https://images.unsplash.com/photo-1418985991508-e47386d96a71?w=1080&h=720',
            'https://source.unsplash.com/800x600/?nature',
            'https://source.unsplash.com/1024x768/?landscape',
            'https://source.unsplash.com/640x480/?forest',
            'https://source.unsplash.com/1200x900/?mountain',
            'https://source.unsplash.com/800x800/?ocean',
            'https://source.unsplash.com/1600x1200/?sunset',
            'https://source.unsplash.com/400x300/?flower',
            'https://source.unsplash.com/900x600/?animal',
            'https://source.unsplash.com/720x540/?wildlife',
            'https://source.unsplash.com/1080x720/?bird',
            'https://httpbin.org/image/jpeg',
            'https://httpbin.org/image/png',
            'https://httpbin.org/image/webp',
            'https://via.placeholder.com/800x600/0066cc/ffffff?text=Sample+Image',
            'https://via.placeholder.com/1024x768/ff6600/ffffff?text=Test+Photo'
        ];

        for ($i = 0; $i < 35; $i++) {
            //$root = $roots[array_rand($roots)];
            $filename = $fileNames[array_rand($fileNames)] . '_' . $i;
            $code = $userCode . '_' . $filename;
            $year = rand(2020, 2024);
            $month = str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT);

            $media = new Media(
                root: $userCode,
                code: $code,
                path: "/{$userCode}/{$year}/{$month}/{$filename}",
                originalUrl: $realImageUrls[array_rand($realImageUrls)]
            );

            $media->setMimeType($mimeTypes[array_rand($mimeTypes)]);
            $media->setSize(rand(50000, 5000000)); // 50KB to 5MB
            $media->setOriginalWidth(rand(400, 2000));
            $media->setOriginalHeight(rand(300, 1500));
            $media->setStatusCode([200, 404, 500][array_rand([200, 404, 500])]);
            $media->setBlur(rand(0, 1) ? hash('sha1', $code) : null);
            $media->setExt(['jpg', 'png', 'gif', 'webp', 'mp4'][array_rand(['jpg', 'png', 'gif', 'webp', 'mp4'])]);
            $media->setExif([
                'camera' => rand(0, 1) ? 'Canon EOS R5' : null,
                'iso' => rand(0, 1) ? rand(100, 3200) : null,
                'aperture' => rand(0, 1) ? round(rand(14, 80) / 10, 1) : null,
                'focal_length' => rand(0, 1) ? rand(24, 200) : null
            ]);
            $media->context  = [
                'uploaded_by' => 'user_' . str_pad(rand(1, 25), 3, '0', STR_PAD_LEFT),
                'category' => $categories[array_rand($categories)],
                'tags' => array_slice($categories, rand(0, 3), rand(1, 3))
            ];
            $media->resized = [
                'small' => "https://picsum.photos/150/150?random={$i}",
                'medium' => "https://picsum.photos/300/300?random={$i}",
//                'large' => "https://picsum.photos/600/600?random={$i}"
            ];

            $medias[] = $media;
            $manager->persist($media);
        }

        // Create File entities (40 entries)
        $files = [];
        $fileExtensions = ['txt', 'pdf', 'doc', 'jpg', 'png', 'mp4', 'zip', 'csv'];
        $directories = ['documents', 'images', 'videos', 'downloads', 'projects', 'backup', 'temp'];

        for ($i = 0; $i < 40; $i++) {
            $storage = $storages[array_rand($storages)];
            $isDir = rand(0, 2) === 0; // 33% chance of being a directory

            if ($isDir) {
                $dirName = $directories[array_rand($directories)] . '_' . $i;
                $path = '/' . $dirName;
                $name = $dirName;
            } else {
                $fileName = 'file_' . $i;
                $ext = $fileExtensions[array_rand($fileExtensions)];
                $path = '/files/' . $fileName . '.' . $ext;
                $name = $fileName . '.' . $ext;
            }

            $file = new File(
                storage: $storage,
                path: $path,
                isDir: $isDir,
                isPublic: rand(0, 2) > 0 // 66% chance of being public
            );

            $file->setName($name);
            $file->setLastModified(new \DateTime('@' . rand(time() - 31536000, time()))); // Random date in last year

            if (!$isDir) {
                $file->setFileSize(rand(1024, 10485760)); // 1KB to 10MB
            }

            $file->setListingCount($isDir ? rand(0, 50) : null);

            $files[] = $file;
            $manager->persist($file);
        }

        // Create Thumb entities (32 entries, distributed among media)
        $liipCodes = ['small', 'medium', 'large', 'tiny', 'xlarge'];

        for ($i = 0; $i < 32; $i++) {
            $media = $medias[array_rand($medias)];
            $liipCode = $liipCodes[array_rand($liipCodes)];

            $thumb = new Thumb(
                media: $media,
                liipCode: $liipCode
            );

            $dimensions = $this->getThumbDimensions($liipCode);
            $thumb->setW($dimensions['width']);
            $thumb->setH($dimensions['height']);
            $thumb->size = (rand(5000, 100000)); // 5KB to 100KB
            $thumb->setUrl("https://picsum.photos/{$dimensions['width']}/{$dimensions['height']}?random={$i}");

            $manager->persist($thumb);
        }

        $manager->flush();
    }

    private function getThumbDimensions(string $liipCode): array
    {
        return match($liipCode) {
            'tiny' => ['width' => 50, 'height' => 50],
            'small' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 300, 'height' => 300],
            'large' => ['width' => 600, 'height' => 600],
            'xlarge' => ['width' => 1200, 'height' => 1200],
            default => ['width' => 200, 'height' => 200],
        };
    }
}
