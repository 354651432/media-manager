<?php

namespace Encore\Admin\Media;

trait BootExtension
{
    /**
     * {@inheritdoc}
     */
    public static function boot()
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function import()
    {
        parent::createMenu('Media manager', 'media', 'fa-file');

        parent::createPermission('Media manager', 'ext.media-manager', 'media*');
    }
}
