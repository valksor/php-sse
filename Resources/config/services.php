<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Davis Zalitis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (
    ContainerConfigurator $container,
): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load(namespace: 'Valksor\\Component\\Sse\\', resource: __DIR__ . '/../../*')
        ->exclude(excludes: [__DIR__ . '/../../{Resources}']);

    $services->alias(ImportMapGenerator::class, 'asset_mapper.importmap.generator');
};
