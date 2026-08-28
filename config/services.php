<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * Serializer package wiring.
 *
 * Registers the package's services with autowire + autoconfigure so the #[AsAlias] on
 * DefaultMessageSerializer binds the MessageSerializer contract. The interface, the exceptions,
 * and the SerializedMessage value object are not services.
 */

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Storm\\Serializer\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/Exception/',
            dirname(__DIR__).'/config/',
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/SerializedMessage.php', // a value object with a private ctor and factories, not a service
            // registered by RegisterPersonalDataPass ONLY when at least one #[Personal] class is
            // compiled: an empty map means zero decoration, zero cost; and its map argument is the
            // pass's scan product, which autowiring could not supply anyway
            dirname(__DIR__).'/CipheringMessageSerializer.php',
        ]);
};
