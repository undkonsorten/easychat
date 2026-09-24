<?php

declare(strict_types=1);

use Lochmueller\Index\Indexing\Frontend\FrontendContextBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Undkonsorten\Easychat\Indexing\GuestFrontendContextBuilder;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    // EXT:index is optional. GuestFrontendContextBuilder extends one of its classes, so registering
    // it without EXT:index (e.g. through the Classes/* scan in Services.yaml) breaks the container.
    if (!class_exists(FrontendContextBuilder::class)) {
        return;
    }

    // Renders EXT:index's Frontend technology as an anonymous visitor, so hidden and scheduled
    // content never reaches the vector store (see the class).
    $container->services()
        ->set(GuestFrontendContextBuilder::class)
        ->autowire()
        ->decorate(FrontendContextBuilder::class, null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->arg('$inner', service('.inner'));
};
