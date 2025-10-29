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

namespace Valksor\Component\Sse\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Symfony Dependency Injection compiler pass for SSE Twig template integration.
 *
 * This compiler pass automatically registers the SSE component's Twig template directory
 * with Symfony's Twig filesystem loader, enabling the use of SSE templates in the Twig
 * environment. It's a crucial integration component that makes SSE templates available
 * for rendering within the broader application.
 *
 * Compiler Pass Architecture:
 * Compiler passes in Symfony are executed during the container compilation phase,
 * allowing components to modify the service container definition before it's cached.
 * This pass specifically targets the Twig filesystem loader to add SSE template paths.
 *
 * Integration Purpose:
 * The SSE component provides Twig templates for:
 * - HTML generation with importmap definitions
 * - JavaScript module loading scripts
 * - Hot reload integration snippets
 * - SSE client connection templates
 *
 * Without this compiler pass, these templates would not be discoverable by Twig
 * and the SSE functionality would not work properly in the Twig environment.
 *
 * Template Registration Process:
 * 1. The compiler pass checks if Twig's native filesystem loader is available
 * 2. If available, it retrieves the loader definition from the container
 * 3. It adds the SSE component's views directory with the 'ValksorSse' namespace
 * 4. This enables templates to be referenced as '@ValksorSse/template_name.twig'
 *
 * Namespace Strategy:
 * The 'ValksorSse' namespace prevents template name conflicts and provides
 * a clear, namespaced way to reference SSE templates. This follows Symfony
 * best practices for bundle template organization.
 *
 * Path Resolution:
 * The template path is resolved relative to this file's location:
 * - File location: src/Valksor/Component/Sse/DependencyInjection/CompilerPass/TwigCompilerPass.php
 * - Template directory: src/Valksor/Component/Sse/Resources/views/
 * - Calculated path: __DIR__ . '/../../Resources/views'
 *
 * Error Handling Strategy:
 * The compiler pass gracefully handles scenarios where Twig is not installed
 * or the filesystem loader is not available. It simply returns early without
 * errors, allowing the application to function without SSE template support.
 *
 * Container Integration:
 * The compiler pass is automatically registered through the SSE configuration
 * and executed during the normal Symfony container compilation process. No
 * manual configuration is required by end users.
 *
 * Performance Considerations:
 * - The compiler pass only runs during container compilation (not on every request)
 * - Template path addition is a lightweight operation
 * - No runtime overhead once the container is compiled
 * - Graceful degradation when Twig is unavailable
 *
 * Debugging and Development:
 * During development, if SSE templates are not found, common issues include:
 * - Twig not installed (composer require symfony/twig-bundle)
 * - Compiler pass not registered (check SSE configuration)
 * - Template file permissions or path issues
 *
 * Usage Examples:
 *
 * **Template Reference in Twig:**
 * ```twig
 * {# Use SSE template with namespace #}
 * {% include '@ValksorSse/importmap.html.twig' %}
 *
 * {# Extend SSE base template #}
 * {% extends '@ValksorSse/base.html.twig' %}
 * ```
 *
 * **Custom Template Integration:**
 * ```php
 * // In a controller
 * return $this->render('@ValksorSse/custom_template.twig', [
 *     'importmap' => $importMapData
 * ]);
 * ```
 *
 * Integration Points:
 * - **SseConfiguration**: Registers this compiler pass in the build() method
 * - **ImportMapRuntime**: Uses templates for rendering importmap HTML
 * - **Twig Environment**: Automatically discovers SSE templates after compilation
 * - **AssetMapper**: Coordinates with asset paths used in templates
 *
 * @see SseConfiguration::build() Where this compiler pass is registered
 * @see ImportMapRuntime Uses the registered templates for rendering
 * @see https://symfony.com/doc/current/service_container/compiler_passes.html For compiler pass documentation
 *
 * @author Davis Zalitis (k0d3r1s)
 */
class TwigCompilerPass implements CompilerPassInterface
{
    /**
     * Process the container to register SSE template directory with Twig filesystem loader.
     *
     * This method is executed during Symfony's container compilation phase and automatically
     * configures Twig to discover SSE component templates. It implements the core integration
     * logic that makes SSE templates available for use in the broader application.
     *
     * Processing Logic:
     * The method follows a defensive programming approach to ensure graceful degradation:
     * 1. Verify Twig's native filesystem loader service exists in the container
     * 2. If unavailable, exit early without errors (allowing application to function without SSE templates)
     * 3. If available, retrieve the loader service definition
     * 4. Add the SSE template directory with the 'ValksorSse' namespace
     *
     * Container Integration:
     * The method works with Symfony's dependency injection container at the definition level,
     * modifying how services will be created before the container is compiled and cached. This
     * ensures the template path registration happens efficiently and only once during compilation.
     *
     * Path Resolution Strategy:
     * The template path is calculated relative to this compiler pass file location:
     * - Compiler pass file: .../SSE/DependencyInjection/CompilerPass/TwigCompilerPass.php
     * - Target template directory: .../SSE/Resources/views/
     * - Relative path calculation: __DIR__ . '/../../Resources/views'
     * This approach works regardless of where the SSE component is installed.
     *
     * Namespace Registration:
     * The 'ValksorSse' namespace provides several benefits:
     * - Prevents template name conflicts with other bundles
     * - Makes template origins clear and explicit
     * - Follows Symfony bundle template naming conventions
     * - Enables template usage like: '@ValksorSse/template_name.twig'
     *
     * Error Handling Philosophy:
     * The method implements graceful failure handling:
     * - No exceptions thrown when Twig is unavailable
     * - Silent degradation for applications without Twig integration
     * - Allows the SSE component to be used in non-Twig contexts
     * - Prevents container compilation failures
     *
     * Performance Considerations:
     * - Method executes only during container compilation (once per cache clear)
     * - No runtime performance impact after compilation
     * - Lightweight path addition operation
     * - Efficient service definition modification
     *
     * Integration Dependencies:
     * The method depends on:
     * - symfony/twig-bundle being installed and configured
     * - Twig filesystem loader service being available ('twig.loader.native_filesystem')
     * - SSE component's Resources/views directory existing and being readable
     *
     * @param ContainerBuilder $container The Symfony DI container builder instance
     *                                    Contains all service definitions before compilation
     *                                    Used to locate and modify the Twig loader definition
     *
     * @return void This method modifies the container by reference and returns no value
     *              Changes are applied to the container definition during compilation
     *
     * @throws InvalidArgumentException If template path resolution fails (handled by PHP's filesystem operations)
     *
     * @see ContainerBuilder::hasDefinition() For checking service definition existence
     * @see ContainerBuilder::getDefinition() For retrieving service definitions
     * @see https://symfony.com/doc/current/service_container/compiler_passes.html For compiler pass patterns
     *
     * @example Template Usage After Registration
     * ```php
     * // After this compiler pass runs, templates can be used like this:
     *
     * // In a controller:
     * return $this->render('@ValksorSse/importmap.html.twig', [
     *     'importmap' => $importMapData,
     *     'attributes' => ['defer' => true]
     * ]);
     *
     * // In another Twig template:
     * {% include '@ValksorSse/sse_client.html.twig' %}
     * ```
     * @example Debugging Template Registration
     * ```php
     * // To verify template registration, you can check the Twig loader:
     * $loader = $container->get('twig.loader.native_filesystem');
     * $paths = $loader->getPaths();
     * // Should contain an entry for 'ValksorSse' pointing to SSE Resources/views
     * ```
     */
    public function process(
        ContainerBuilder $container,
    ): void {
        // Check if Twig's native filesystem loader is available
        if (!$container->hasDefinition('twig.loader.native_filesystem')) {
            // Gracefully degrade - SSE templates won't be available but application still works
            return;
        }

        // Retrieve the Twig filesystem loader definition
        $definition = $container->getDefinition('twig.loader.native_filesystem');

        // Add SSE template directory with 'ValksorSse' namespace
        // This enables templates to be referenced as '@ValksorSse/template_name.twig'
        $definition->addMethodCall('addPath', [
            __DIR__ . '/../../Resources/views', // Path to SSE Resources/views directory
            'ValksorSse',                      // Namespace for template references
        ]);
    }
}
