<?php

namespace App\Controller;

use App\Message\ResizeImageMessage;
use Imagine\Exception\RuntimeException;
use Liip\ImagineBundle\Config\Controller\ControllerConfig;
use Liip\ImagineBundle\Controller\ImagineController;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Cache\Helper\PathHelper;
use Liip\ImagineBundle\Imagine\Cache\SignerInterface;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Service\FilterService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;

class ImageController extends ImagineController
{
    public function __construct(
        #[Autowire('@liip_imagine.service.filter')] private FilterService $filterService,
        private DataManager $dataManager,
        private SignerInterface $signer,
        private ?ControllerConfig $controllerConfig = null,
        private ?CacheManager $cacheManager=null,
        private ?MessageBusInterface $messageBus=null,
    ) {
        parent::__construct($this->filterService, $this->dataManager, $this->signer, $this->controllerConfig);

    }

    /**
     * This action applies a given filter -merged with additional runtime filters- to a given image, saves the image and
     * redirects the browser to the stored image.
     *
     * The resulting image is cached so subsequent requests will redirect to the cached image instead applying the
     * filter and storing the image again.
     *
     * @param string $hash
     * @param string $path
     * @param string $filter
     *
     * @throws RuntimeException
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     *
     * @return RedirectResponse
     */
    public function filterRuntimeAction(Request $request, $hash, $path, $filter)
    {
        $resolver = $request->get('resolver');
        $path = PathHelper::urlPathToFilePath($path);
        $result = parent::filterRuntimeAction($request, $hash, $path, $filter);
        dd($result);
        $runtimeConfig = $this->getFiltersBc($request);
    }

        /**
     * This action applies a given filter to a given image, saves the image and redirects the browser to the stored
     * image.
     *
     * The resulting image is cached so subsequent requests will redirect to the cached image instead applying the
     * filter and storing the image again.
     *
     * @param string $path
     * @param string $filter
     *
     * @throws RuntimeException
     * @throws NotFoundHttpException
     *
     * @return RedirectResponse
     */
        // /media/cache/resolve/{filter}/{path}
    public function filterAction(Request $request, $path, $filter)
    {
            //TODO : replace ResizeImageMessage
            // $this->messageBus->dispatch(
            //     new ResizeImageMessage($filter, $path),
            //     stamps: [
            //         new TransportNamesStamp('sync')
            //     ]
            // );

            $redirect =  $this->filterService->getUrlOfFilteredImage(
                $path,
                $filter,
                null,
                $this->isWebpSupported($request)
            );

            return new RedirectResponse($redirect);
    }

    private function isWebpSupported(Request $request): bool
    {
        return false !== mb_stripos($request->headers->get('accept', ''), 'image/webp');
    }

}
