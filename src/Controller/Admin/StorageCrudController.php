<?php

namespace App\Controller\Admin;

use Survos\EzBundle\Controller\BaseCrudController;

class StorageCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return \App\Entity\Storage::class;
    }
}
