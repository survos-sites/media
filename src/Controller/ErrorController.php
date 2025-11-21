<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ErrorController extends AbstractController
{
    public function show($exception): Response
    {
        return $this->render('error/index.html.twig', [
            'exception' => $exception,
            'statusCode' => $exception->getStatusCode(),
            'controller_name' => 'ErrorController',
        ]);
    }
}
