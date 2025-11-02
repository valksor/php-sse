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

/*
 * Symfony Dependency Injection services configuration for the Valksor SSE component.
 *
 * This configuration file defines how the SSE component's services are registered
 * in Symfony's dependency injection container. It implements an automatic service
 * discovery and registration strategy that enables seamless integration with the
 * broader Symfony application.
 *
 * Service Configuration Strategy:
 *
 * The configuration follows Symfony's modern service registration patterns:
 * - **Auto-discovery**: Automatically loads all classes in the SSE component
 * - **Auto-wiring**: Enables constructor injection for all dependencies
 * - **Auto-configuration**: Applies default tags and configurations automatically
 * - **Resource exclusion**: Prevents non-PHP files from being registered as services
 * - **Alias creation**: Provides convenient access to external dependencies
 *
 * Service Loading Architecture:
 *
 * The services are loaded using Symfony's filesystem service loader which:
 * - Scans the SSE component directory for PHP classes
 * - Automatically creates service definitions for each discovered class
 * - Applies default configuration (autowire, autoconfigure) to all services
 * - Respects namespace and directory structure for service organization
 * - Excludes Resources directory to prevent asset/template registration as services
 *
 * Auto-discovered Services:
 *
 * All classes in the Valksor\Component\Sse namespace are automatically registered:
 * - **SseService**: Main SSE server implementation
 * - **ImportMapRuntime**: Twig runtime for import map generation
 * - **SseCommand**: Console command for starting SSE server
 * - **AbstractService**: Base class for SSE services (abstract, not registered)
 * - **AbstractCommand**: Base class for SSE commands (abstract, not registered)
 * - **SseConfiguration**: Dependency injection configuration (handled separately)
 * - **ImportMapExtension**: Twig extension for SSE template functions
 * - **TwigCompilerPass**: Compiler pass for template registration
 * - **Helper**: Utility trait (not registered as service)
 *
 * Service Features Enabled:
 *
 * **Autowiring (autowire: true)**:
 * - Constructor injection automatically resolves dependencies
 * - No manual service definition required for most classes
 * - Type hints determine dependency injection
 * - Reduces boilerplate configuration
 *
 * **Auto-configuration (autoconfigure: true)**:
 * - Automatic tag application based on interfaces and attributes
 * - Framework integration (console commands, Twig extensions, etc.)
 * - Compiler pass registration and execution
 * - Default configuration based on service type
 *
 * External Dependency Integration:
 *
 * The configuration provides seamless integration with Symfony's AssetMapper component:
 * - Creates an alias for ImportMapGenerator from AssetMapper
 * - Enables SSE component to use AssetMapper's import map functionality
 * - Provides centralized access to import map generation services
 * - Maintains compatibility with AssetMapper's service naming conventions
 *
 * Path Resolution Strategy:
 *
 * The service loading uses relative path resolution for flexibility:
 * - Base path: __DIR__ . '/../..' (from Resources/config/ to component root)
 * - Excludes Resources: Prevents template/asset registration as services
 * - Namespace mapping: File paths map to Valksor\Component\Sse\ namespace
 *
 * Integration Benefits:
 *
 * **Developer Experience**:
 * - No manual service registration required for new SSE classes
 * - Automatic dependency injection with type hints
 * - Standard Symfony patterns and conventions
 * - Easy service extension and customization
 *
 * **Performance Considerations**:
 * - Efficient service discovery with filesystem scanning
 * - Cached container compilation after initial discovery
 * - Minimal runtime overhead due to compiled container
 * - Selective loading excludes non-service resources
 *
 * **Maintenance Benefits**:
 * - Automatic service registration for new classes
 * - Consistent configuration across all SSE services
 * - Reduced configuration file maintenance
 * - Framework integration handled automatically
 *
 * Usage Examples:
 *
 * **Controller with SSE Service Injection**:
 * ```php
 * use Valksor\Component\Sse\Service\SseService;
 *
 * class MyController
 * {
 *     public function __construct(private SseService $sseService) {}
 *
 *     public function triggerReload(): JsonResponse
 *     {
 *         $this->sseService->triggerReload(['*']);
 *         return new JsonResponse(['status' => 'reload triggered']);
 *     }
 * }
 * ```
 *
 * **Custom Service Extending SSE Functionality**:
 * ```php
 * namespace App\Service;
 *
 * use Valksor\Component\Sse\Service\SseService;
 *
 * class CustomSseService extends SseService
 * {
 *     // Automatically registered and autowired
 * }
 * ```
 *
 * **Twig Template Usage**:
 * ```twig
 * {# ImportMapRuntime functions available via auto-configuration #}
 * {{ importmap_definition(['app', 'shared']) }}
 * {{ importmap_scripts(['app'], {'defer': true}) }}
 * ```
 *
 * Configuration Override Examples:
 *
 * **Custom Service Configuration**:
 * ```yaml
 * # config/services.yaml
 * services:
 *     Valksor\Component\Sse\Service\SseService:
 *         arguments:
 *             $customOption: 'custom_value'
 *         calls:
 *             - setCustomMethod: ['@another_service']
 * ```
 *
 * **Disable Auto-configuration for Specific Service**:
 * ```yaml
 * services:
 *     Valksor\Component\Sse\Service\SseService:
 *         autoconfigure: false
 *         tags: ['my.custom.tag']
 * ```
 *
 * @see https://symfony.com/doc/current/service_container.html For Symfony DI documentation
 * @see https://symfony.com/doc/current/service_container/autowiring.html For autowiring details
 * @see SseConfiguration For component-specific configuration
 * @see ImportMapGenerator For AssetMapper integration
 *
 * @author Davis Zalitis (k0d3r1s)
 * @package Valksor\Component\Sse\Resources\config
 */

use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Configure SSE component services in the Symfony dependency injection container.
 *
 * This function automatically registers all SSE component services with the container,
 * enabling seamless integration and automatic dependency injection throughout the
 * application.
 *
 * Service Registration Strategy:
 * - Auto-discover all classes in the SSE component namespace
 * - Enable autowiring for constructor dependency injection
 * - Apply auto-configuration for framework integration
 * - Create convenient aliases for external dependencies
 *
 * @param ContainerConfigurator $container The Symfony DI container configurator
 *                                        Provides service definition registration methods
 *                                        Used to configure all SSE component services
 *
 * @return void This function modifies the container configuration by reference
 *
 * @throws \InvalidArgumentException If service paths cannot be resolved
 * @throws \RuntimeException If container configuration fails
 *
 * @see ContainerConfigurator::services() For service definition methods
 * @see https://symfony.com/doc/current/service_container/configuration.html For configuration patterns
 *
 * @example Service Usage After Configuration
 * ```php
 * // Services are automatically available for injection
 * use Valksor\Component\Sse\Service\SseService;
 * use Valksor\Component\Sse\Twig\ImportMapRuntime;
 *
 * class MyController
 * {
 *     public function __construct(
 *         private SseService $sseService,
 *         private ImportMapRuntime $importMapRuntime
 *     ) {}
 * }
 * ```
 *
 * @example Extending SSE Services
 * ```php
 * // Create custom services that extend SSE functionality
 * namespace App\Service;
 *
 * class CustomSseExtension extends \Valksor\Component\Sse\Service\AbstractService
 * {
 *     // Automatically registered and available for injection
 * }
 * ```
 */
return static function (
    ContainerConfigurator $container,
): void {
    // Initialize the services configurator for SSE component
    $services = $container->services();

    // Apply default configuration to all SSE services
    // - autowire: Enables automatic constructor dependency injection
    // - autoconfigure: Applies default tags and configurations based on interfaces/attributes
    $services->defaults()
        ->autowire()      // Auto-resolve constructor dependencies via type hints
        ->autoconfigure(); // Auto-apply tags like 'twig.extension', 'console.command', etc.

    // Load all SSE component services automatically
    // Scans the entire SSE component directory and creates service definitions
    // Namespace: Valksor\Component\Sse\ maps to file structure
    // Resource: Loads all PHP files from component root directory
    // Exclude: Resources directory (contains templates, assets, not services)
    $services->load(namespace: 'Valksor\\Component\\Sse\\', resource: __DIR__ . '/../../*')
        ->exclude(excludes: [__DIR__ . '/../../{Resources,vendor,Tests}']);

    // Create convenient alias for AssetMapper's ImportMapGenerator
    // This enables SSE components to easily access AssetMapper functionality
    // Alias maps the concrete ImportMapGenerator service to a clean interface name
    // Allows dependency injection by interface or class name consistently
    $services->alias(ImportMapGenerator::class, 'asset_mapper.importmap.generator');
};
