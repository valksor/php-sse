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

namespace Valksor\Component\Sse\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Valksor\Bundle\DependencyInjection\AbstractDependencyConfiguration;
use Valksor\Bundle\ValksorBundle;

use function sprintf;

class SseConfiguration extends AbstractDependencyConfiguration
{
    public function addSection(
        ArrayNodeDefinition $rootNode,
        callable $enableIfStandalone,
        string $component,
    ): void {
        $rootNode
            ->children()
                ->arrayNode($component)
                ->{$enableIfStandalone(sprintf('%s/%s', ValksorBundle::VALKSOR, $component), self::class)}()
                ->children()
                    ->scalarNode('bind')
                        ->info('Bind address for SSE server')
                        ->defaultValue('0.0.0.0')
                    ->end()
                    ->integerNode('port')
                        ->info('Port for SSE server')
                        ->defaultValue(3000)
                    ->end()
                    ->scalarNode('path')
                        ->info('Base path for SSE endpoint')
                        ->defaultValue('/sse')
                    ->end()
                    ->scalarNode('domain')
                        ->info('Domain for TLS certificate lookup')
                        ->defaultValue('localhost')
                    ->end()
                    ->scalarNode('ssl_cert_path')
                        ->info('SSL Cert path for TLS. If null, uses /etc/ssl/private/<domain>.crt')
                        ->defaultNull()
                    ->end()
                    ->scalarNode('ssl_key_path')
                        ->info('SSL Key for TLS. If null, uses /etc/ssl/private/<domain>.key')
                        ->defaultNull()
                    ->end()
            ->end();
    }
}
