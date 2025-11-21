<?php

namespace App\EventListener;

use App\Controller\TokenAuthenticatedController;
use Inspector\Inspector;
use Psr\Log\LoggerInterface;
use Survos\KeyValueBundle\Entity\KeyValueManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExceptionListener
{
    public function __construct(
        private KeyValueManagerInterface $keyValueManager,
        private LoggerInterface $logger,
        private ?Inspector $inspector = null,
        private array $tokens=[]
    )
    {

    }

    #[AsEventListener(ControllerEvent::class)]
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        // when a controller class defines multiple action methods, the controller
        // is returned as [$controllerInstance, 'methodName']
        if (is_array($controller)) {
            $controller = $controller[0];
        }
        if ($controller instanceof TokenAuthenticatedController) {
            $request = $event->getRequest();
            $method = $event->getRequest()->getMethod();
            $token = $request->query->get('token');

            if ($method == 'POST' && !in_array($token, $this->tokens)) {
//                throw new AccessDeniedHttpException('This action needs a valid token!');
            }
        }
//        dd($event, $controller);
    }
    #[AsEventListener(ExceptionEvent::class, priority: 10000)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception::class === NotFoundHttpException::class) {
            $request = $event->getRequest();
            $path = $request->getPathInfo();
            if (!$this->keyValueManager->has($path, 'paths')) {
                $this->keyValueManager->add($path, 'paths');
            }
            if ($this->inspector?->isRecording()) {
                $this->inspector?->stopRecording();
            }

        }
    }
}
