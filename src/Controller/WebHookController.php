<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class WebHookController extends AbstractController
{
    #[Route('/webhook', name: 'media_web_hook')]
    public function receiveMedia(
        Request $request,
    ): Response
    {
        return $this->json(['status' => 'ok',
            'get' => $request->query->all(),
            'payload' => $request->getContent(),
            'method' => $request->getMethod(),
            'request' => $request->request->all()]);



        return $this->render('web_hook/index.html.twig', [
            'controller_name' => 'WebHookController',
        ]);
    }
}
