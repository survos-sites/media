<?php

namespace App\Controller\Admin;

use Survos\EzBundle\Controller\BaseCrudController;

class MediaCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return \App\Entity\Media::class;
    }
}
