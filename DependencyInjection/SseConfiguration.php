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

/**
 * Dependency Injection configuration for Valksor SSE (Server-Sent Events) component.
 *
 * This class manages the complete integration of SSE functionality into Symfony applications
 * through dependency injection configuration. It handles server configuration, asset mapping,
 * Twig integration, and SSL/TLS certificate management for secure SSE connections.
 *
 * Configuration Architecture:
 * The configuration system provides three main integration points:
 * 1. **Server Configuration**: Network settings, SSL/TLS setup, and endpoint configuration
 * 2. **Asset Integration**: Automatic registration of JavaScript assets with AssetMapper
 * 3. **Twig Integration**: Global variables for template access and runtime configuration
 *
 * SSL/TLS Support:
 * - Automatic certificate discovery based on domain configuration
 * - Support for custom certificate paths when using non-standard locations
 * - Integration with system certificate stores for development and production
 * - Flexible binding options for both development and deployment scenarios
 *
 * AssetMapper Integration:
 * - Automatically detects whether the package is installed as a dependency or in development
 * - Registers SSE JavaScript assets under the 'valksorsse' namespace
 * - Handles path resolution for both vendor and development installations
 * - Provides seamless integration with Symfony's modern asset management system
 *
 * Twig Template Integration:
 * - Exposes SSE configuration to templates through global variables
 * - Provides port and path information for client-side JavaScript configuration
 * - Enables template-level SSE endpoint configuration and customization
 * - Supports dynamic SSE server configuration in different environments
 *
 * Network Configuration:
 * - Flexible binding options (0.0.0.0 for production, 127.0.0.1 for development)
 * - Configurable port allocation with default fallbacks
 * - Custom endpoint paths for API versioning or routing flexibility
 * - Domain-based SSL certificate resolution
 *
 * Environment Considerations:
 * - Development-friendly defaults (localhost, port 3000, no SSL requirements)
 * - Production-ready security configurations (custom SSL, specific bindings)
 * - CLI vs web request detection for proper path resolution
 * - Support for containerized and traditional deployment environments
 *
 * Integration Points:
 *
 * **Framework Integration (AssetMapper)**:
 * ```yaml
 * # Automatically added to framework.yaml
 * framework:
 *     asset_mapper:
 *         paths:
 *             '/path/to/assets': 'valksorsse'
 * ```
 *
 * **Twig Integration**:
 * ```yaml
 * # Automatically added to twig.yaml
 * twig:
 *     globals:
 *         valksor_sse_port: '%env(default:3000:int:SSE_PORT)%'
 *         valksor_sse_path: '%env(default:/sse:string:SSE_PATH)%'
 * ```
 *
 * **SSE Configuration Structure**:
 * ```yaml
 * valksor:
 *     sse:
 *         bind: '0.0.0.0'              # Server bind address
 *         port: 3000                   # Server port
 *         path: '/sse'                 # Endpoint path
 *         domain: 'localhost'          # Domain for SSL cert lookup
 *         ssl_cert_path: null          # Custom SSL cert path (optional)
 *         ssl_key_path: null           # Custom SSL key path (optional)
 * ```
 *
 * Compiler Pass Integration:
 * - Registers TwigCompilerPass for runtime function registration
 * - Enables SSE-related Twig functions and templates
 * - Provides automatic dependency injection setup
 * - Ensures proper service container optimization
 *
 * Security Features:
 * - SSL/TLS encryption support for production deployments
 * - Configurable certificate paths for custom PKI setups
 * - Domain-based certificate validation
 * - Development-friendly insecure options for local testing
 *
 * Performance Optimizations:
 * - Compiler pass integration for optimal service container performance
 * - Conditional asset registration based on available extensions
 * - Path caching and intelligent resolution
 * - Minimal overhead when SSE is not used
 *
 * Usage Examples:
 *
 * **Development Configuration**:
 * ```yaml
 * valksor:
 *     sse:
 *         bind: '127.0.0.1'
 *         port: 3000
 *         path: '/sse'
 *         domain: 'localhost'
 * ```
 *
 * **Production Configuration**:
 * ```yaml
 * valksor:
 *     sse:
 *         bind: '0.0.0.0'
 *         port: 8080
 *         path: '/api/v1/sse'
 *         domain: 'app.example.com'
 *         ssl_cert_path: '/etc/ssl/certs/app.crt'
 *         ssl_key_path: '/etc/ssl/private/app.key'
 * ```
 *
 * **Template Usage**:
 * ```twig
 * {# Uses injected globals #}
 * <script>
 *     const sseUrl = `https://{{ app.request.host }}:{{ valksor_sse_port }}{{ valksor_sse_path }}`;
 *     const eventSource = new EventSource(sseUrl);
 * </script>
 * ```
 *
 * @see AbstractDependencyConfiguration For base configuration patterns
 * @see TwigCompilerPass For Twig runtime integration
 * @see AssetMapper For asset management integration
 *
 * @author Davis Zalitis (k0d3r1s)
 */
class SseConfiguration extends AbstractDependencyConfiguration
{
    /**
     * Define the configuration tree structure for SSE component settings.
     *
     * This method creates the comprehensive configuration schema that defines all
     * available options for the SSE server, network settings, and SSL/TLS configuration.
     * The configuration tree supports both development and production use cases
     * with appropriate defaults and validation rules.
     *
     * Configuration Structure:
     * The method defines a hierarchical configuration tree with the following main sections:
     *
     * **Network Configuration**:
     * - bind: Server bind address (default: '0.0.0.0' for production, '127.0.0.1' for development)
     * - port: Server port number (default: 3000, configurable for different environments)
     * - path: SSE endpoint path (default: '/sse', customizable for API versioning)
     *
     * **SSL/TLS Configuration**:
     * - domain: Domain name for SSL certificate lookup (default: 'localhost')
     * - ssl_cert_path: Custom SSL certificate file path (optional, auto-discovery if null)
     * - ssl_key_path: Custom SSL private key file path (optional, auto-discovery if null)
     *
     * Configuration Validation and Defaults:
     * - All values have sensible defaults for immediate development use
     * - SSL paths are optional to support both HTTP and HTTPS deployments
     * - Domain configuration enables automatic certificate discovery
     * - Network settings support both local and production deployment scenarios
     *
     * Environment-specific Configuration Patterns:
     *
     * **Development Environment**:
     * ```yaml
     * valksor:
     *     sse:
     *         bind: '127.0.0.1'    # Localhost only
     *         port: 3000           # Development port
     *         path: '/sse'         # Simple endpoint
     *         domain: 'localhost'  # Local development
     *         # SSL not required for development
     * ```
     *
     * **Staging Environment**:
     * ```yaml
     * valksor:
     *     sse:
     *         bind: '0.0.0.0'     # Accept external connections
     *         port: 8080           # Different port to avoid conflicts
     *         path: '/api/v1/sse'  # Versioned API endpoint
     *         domain: 'staging.example.com'
     *         # Optional SSL for staging
     * ```
     *
     * **Production Environment**:
     * ```yaml
     * valksor:
     *     sse:
     *         bind: '0.0.0.0'     # Production-ready binding
     *         port: 443            # Standard HTTPS port
     *         path: '/events'      # Clean endpoint path
     *         domain: 'app.example.com'
     *         ssl_cert_path: '/etc/ssl/certs/app.crt'
     *         ssl_key_path: '/etc/ssl/private/app.key'
     * ```
     *
     * SSL/TLS Certificate Handling:
     * - When ssl_cert_path and ssl_key_path are null, the system attempts automatic discovery
     * - Automatic discovery looks for certificates in /etc/ssl/private/<domain>.crt and .key
     * - Custom paths allow for non-standard certificate locations or PKI setups
     * - Domain-based discovery supports multiple SSL certificates on the same server
     *
     * Security Considerations:
     * - Bind address '0.0.0.0' exposes the SSE server to all network interfaces
     * - '127.0.0.1' limits access to localhost, recommended for development
     * - SSL/TLS configuration is optional but recommended for production
     * - Certificate paths should have appropriate file permissions
     *
     * Performance Considerations:
     * - Port selection should avoid conflicts with other services
     * - Path configuration affects client-side URL construction
     * - SSL/TLS adds overhead but provides essential security for production
     * - Domain configuration affects certificate lookup and validation
     *
     * @param ArrayNodeDefinition $rootNode           The root configuration node to attach SSE settings to
     *                                                This is typically the main Valksor configuration tree
     * @param callable            $enableIfStandalone Conditional enablement function for standalone mode
     *                                                Determines if SSE should be enabled when used independently
     * @param string              $component          The component name identifier ('sse' in this case)
     *                                                Used for namespacing and configuration tree construction
     *
     * @return void This method modifies the configuration tree by reference
     *
     * @see https://symfony.com/doc/current/components/config/definition.html For configuration tree syntax
     * @see SseService For the service that consumes this configuration
     *
     * @example Basic configuration usage
     * ```yaml
     * # config/packages/valksor.yaml
     * valksor:
     *     sse:
     *         port: 8080
     *         path: '/events'
     * ```
     * @example SSL configuration for production
     * ```yaml
     * valksor:
     *     sse:
     *         bind: '0.0.0.0'
     *         port: 443
     *         domain: 'secure.example.com'
     *         ssl_cert_path: '/etc/ssl/certs/secure.crt'
     *         ssl_key_path: '/etc/ssl/private/secure.key'
     * ```
     */
    public function addSection(
        ArrayNodeDefinition $rootNode,
        callable $enableIfStandalone,
        string $component,
    ): void {
        $rootNode
            ->children()
                ->arrayNode($component)
                ->{$enableIfStandalone(sprintf('%s/%s', ValksorBundle::VALKSOR, $component), self::class)}()
                ->addDefaultsIfNotSet()
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
