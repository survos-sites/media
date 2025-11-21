<?php
// Listens for events and sync with workflows and meili

namespace App\EventListener;

use App\Entity\Media;
use App\Entity\Thumb;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\PostPersist;
use Meilisearch\Endpoints\Indexes;
use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\MeiliBundle\Service\SettingsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

//https://symfony.com/doc/current/doctrine/events.html#doctrine-lifecycle-listeners
#[AsDoctrineListener(Events::postUpdate /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::preRemove /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::prePersist /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::postFlush /*, 500, 'default'*/)]
#[AsDoctrineListener(Events::postPersist /*, 500, 'default'*/)]
class DoctrineEventListener
{
    public function __construct(
        private MeiliService                 $meiliService,
        private SettingsService $settingsService,
        private readonly NormalizerInterface $normalizer,
        private readonly LoggerInterface     $logger,
        private array                        $objectsByClass=[],
        #[Autowire('%env(USE_MEILI)%')] private bool $useMeili = false,
    )
    {
    }

    // the listener methods receive an argument which gives you access to
    // both the entity object of the event and the entity manager itself
    public function prePersist(PrePersistEventArgs $args)
    {
    }
    public function postFlush(PostFlushEventArgs $args)
    {

        foreach ($this->objectsByClass as $class => $objects) {
            $meiliIndex = $this->getMeiliIndex($class);
//            $this->logger->warning(__METHOD__ . sprintf(" adding %d %s objects to meili", count($objects), $class));
            $task = $meiliIndex->addDocuments($objects);
//            $this->meiliService->waitForTask($task, stopOnError: true);
            $this->objectsByClass[$class] = [];
        }
        $this->objectsByClass = [];
    }

    public function postPersist(PostPersistEventArgs $args)
    {
        if (!$this->useMeili) {
            return;
        }
        $this->addToMeiliIndex($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args)
    {
        if (!$this->useMeili) {
            return;
        }

        $this->logger->info(__METHOD__);
        $this->addToMeiliIndex($args->getObject());
        // hack
        if ($args->getObject() instanceof Thumb) {
            $this->addToMeiliIndex($args->getObject()->getMedia());
        }
    }

    private function addToMeiliIndex(object $object)
    {
        static $groups;
        if (!$this->useMeili) {
            return;
        }
        // we need to look for an index attribute!
//        #[Metadata('meili', true)]
//        $this->logger->warning(__METHOD__ . '/' . $object::class);

        if (!in_array($object::class, [
            Thumb::class,
            Media::class])) {
            return;
        }
        // we need a better way to flag MeiliClasses, probably an attribute with the normalizer fields
        if (empty($groups)) {
            $groups = $this->settingsService->getNormalizationGroups($object::class);
        }

        //        // we used to use this:
//        //   $headline = (new ArticleDataTransformer())->transform($object, Headline::class, []);
//        // get the groups during the compiler pass
//        $prefix = strtolower((new \ReflectionClass($object))->getShortName());
//        $groups = ['rp','read','search','object.rp', 'marking', 'search', 'rp', 'article.story', "browse",  "$prefix.read", "$prefix.search"];
        assert($object->getId());
        if (!$object->getId()) {
            throw new \RuntimeException('Object id is null');
        }


        $data = $this->normalizer->normalize($object, 'array', ['groups' => $groups]);
        $data['id'] = $object->getId(); // hack for meili key?
//        dd($data);
        $this->objectsByClass[$object::class][] = $data;
//        dd($data, $object->getId());
//        $this->meiliService->waitForTask($task, stopOnError: true);

        // although we could do this, it's only one document, and meili has it's own queuing system.  But we could dispatch after the request is closed.
//        $this->bus->dispatch(new IndexHeadlineMessage($object->getId()));

    }

    private function getMeiliIndex(string $class): Indexes
    {
        $indexName = $this->meiliService->getPrefixedIndexName((new \ReflectionClass($class))->getShortName());
        return $this->meiliService->getIndexEndpoint($indexName);

    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if (!$this->useMeili) {
            return;
        }
        // won't work with domain!  We probably need a unique meilil key in the object, then we'll have an interface, etc.
        $object = $args->getObject();
        $task = $this->getMeiliIndex($args->getObject()::class)->deleteDocument($object->getId());
//        $this->meiliService->waitForTask($task);
    }

}
