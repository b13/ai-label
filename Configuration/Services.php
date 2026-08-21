<?php

declare(strict_types=1);

use B13\AiLabel\Middleware\FlagAiContentMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    // Optional integration with b13/aim: registered and tagged manually
    // (rather than via aim's own #[AsAiMiddleware] attribute directly on the class).
    if (!class_exists(\B13\Aim\Attribute\AsAiMiddleware::class)) {
        return;
    }
    $containerBuilder->register(FlagAiContentMiddleware::class, FlagAiContentMiddleware::class)
        ->setAutowired(true)
        ->addTag(\B13\Aim\Attribute\AsAiMiddleware::TAG_NAME, ['priority' => -850]);
};
