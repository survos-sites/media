<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Minimal CI sample data: 25 users (user_001 … user_025).
 *
 * CrawlAsVisitorTest needs user_001 (e.g. /app/thumbs?code=user_001). The original
 * Storage/Media/File/Thumb sample blocks were removed — they bit-rotted across the
 * entity setters→public-props/ctor refactor and the crawl test doesn't need them
 * (the API list endpoints return 200 when empty). Recover from git history to rebuild.
 */
class CiDataLoad extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $roles = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_EDITOR'];

        for ($i = 0; $i < 25; $i++) {
            $userCode = 'user_' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);

            $user = new User(
                id: $userCode,
                mediaCallbackUrl: rand(0, 1) ? "https://example.com/media/callback/{$userCode}" : null,
                thumbCallbackUrl: rand(0, 1) ? "https://example.com/thumb/callback/{$userCode}" : null,
            );

            $user->setEmail("user{$i}@example.com");
            $user->setApiKey(hash('sha256', $userCode));
            $user->setRoles([$roles[array_rand($roles)]]);
            $user->setIsVerified(rand(0, 4) > 0); // 80% chance

            $manager->persist($user);
        }

        $manager->flush();
    }
}
