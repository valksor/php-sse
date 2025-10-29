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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Valksor\Bundle\DependencyInjection\AbstractDependencyConfiguration;
use Valksor\Bundle\ValksorBundle;
use Valksor\Component\Sse\DependencyInjection\CompilerPass\TwigCompilerPass;
use Valksor\FullStack;
use Valksor\Functions\Local\Traits\_Exists;
use Valksor\Functions\Local\Traits\_IsInstalled;

use function dirname;
use function getcwd;
use function sprintf;
use function str_contains;

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

    public function build(
        ContainerBuilder $container,
    ): void {
        $container->addCompilerPass(new TwigCompilerPass());
    }

    public function registerPreConfiguration(
        ContainerConfigurator $container,
        ContainerBuilder $builder,
        string $component,
    ): void {
        if ($builder->hasExtension('framework')) {
            static $_helper = null;

            if (null === $_helper) {
                $_helper = new class {
                    use _Exists;
                    use _IsInstalled;
                };
            }

            if (str_contains(__DIR__, 'vendor')) {
                $path = '/vendor/valksor/php-sse/Resources/assets';

                if ($_helper->exists(FullStack::class)) {
                    $path = '/vendor/valksor/valksor/src/Valksor/Component/Sse/Resources/assets';
                }
            } else {
                $path = '/valksor/src/Valksor/Component/Sse/Resources/assets';
            }

            $prefix = getcwd();

            if ('cli' !== PHP_SAPI) {
                $prefix = dirname($prefix);
            }
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $prefix . $path => 'valksorsse',
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('twig')) {
            $valksor = $this->mergeConfig($builder, 'valksor');

            $builder->prependExtensionConfig('twig', [
                'globals' => [
                    'valksor_sse_port' => $valksor['sse']['port'],
                    'valksor_sse_path' => $valksor['sse']['path'],
                ],
            ]);
        }
    }
}
