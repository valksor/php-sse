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

/**
 * Twig extension that registers SSE-related template functions for Valksor.
 *
 * This extension serves as the bridge between the SSE component's backend functionality
 * and Twig template rendering, providing convenient template functions that developers
 * can use to generate SSE-related HTML, JavaScript, and import map configurations
 * directly in their templates.
 *
 * Extension Architecture:
 * The extension follows Symfony's Twig extension patterns:
 * - Extends AbstractExtension for Twig integration
 * - Uses AutoconfigureTag for automatic registration with Twig environment
 * - Delegates all function logic to ImportMapRuntime for consistency
 * - Provides safe HTML output with proper escaping
 *
 * Template Functions Available:
 *
 * **valksor_sse_importmap_definition()**:
 * Generates the complete importmap definition for specified entry points.
 * Creates the HTML <script type="importmap"> element with all necessary configuration,
 * polyfills, and preload links. This is the foundation for JavaScript module loading
 * in the SSE-enabled application.
 *
 * **valksor_sse_importmap_scripts()**:
 * Generates the JavaScript module import statements for specified entry points.
 * Creates HTML <script type="module"> elements that import the specified modules
 * using the previously defined importmap. Works in conjunction with the definition
 * function to complete the module loading process.
 *
 * **valksor_sse_ping()**:
 * Pings the SSE server to verify connectivity and status.
 * Useful for debugging and health checks in development environments,
 * allowing templates to verify that the SSE server is accessible and responsive.
 *
 * Integration Pattern:
 * The extension implements a delegation pattern where all template functions
 * are thin wrappers around ImportMapRuntime methods. This provides several benefits:
 * - Consistent API between Twig functions and PHP code
 * - Single source of truth for SSE functionality
 * - Simplified testing and maintenance
 * - Easy to extend with additional template functions
 *
 * Auto-configuration Strategy:
 * The #[AutoconfigureTag('twig.extension')] attribute ensures the extension
 * is automatically registered with Twig when the SSE component is loaded. This
 * provides seamless integration without requiring manual configuration from
 * application developers.
 *
 * Security Considerations:
 * All functions are marked as 'is_safe' for HTML output, ensuring that:
 * - Generated HTML is properly escaped by Twig
 * - Cross-site scripting (XSS) vulnerabilities are prevented
 * - Dynamic content is safely rendered
 * - Output integrity is maintained
 *
 * Performance Optimizations:
 * - Functions are lightweight delegations to optimized runtime methods
 * - No additional processing overhead beyond the runtime implementation
 * - Caching handled at the runtime level for optimal performance
 * - Minimal memory footprint for template rendering
 *
 * Usage Examples:
 *
 * **Basic Template Usage**:
 * ```twig
 * {# templates/base.html.twig #}
 * <head>
 *     {{ valksor_sse_importmap_definition(['app', 'shared']) }}
 *     {{ valksor_sse_importmap_scripts(['app']) }}
 * </head>
 * ```
 *
 * **Advanced Configuration**:
 * ```twig
 * {# templates/custom.html.twig #}
 * {{ valksor_sse_importmap_definition(
 *     ['admin', 'shared'],
 *     {'defer': true, 'crossorigin': 'anonymous'}
 * ) }}
 *
 * {{ valksor_sse_importmap_scripts(
 *     ['admin'],
 *     {'async': true}
 * ) }}
 * ```
 *
 * **Development Debugging**:
 * ```twig
 * {# templates/debug.html.twig #}
 * {% if app.debug %}
 *     {% if valksor_sse_ping() %}
 *         <div class="alert alert-success">SSE Server: Connected</div>
 *     {% else %}
 *         <div class="alert alert-warning">SSE Server: Disconnected</div>
 *     {% endif %}
 * {% endif %}
 * ```
 *
 * **Conditional Loading**:
 * ```twig
 * {# templates/layout.html.twig #}
 * {% block stylesheets %}
 *     {{ parent() }}
 * {% endblock %}
 *
 * {% block javascripts %}
 *     {{ parent() }}
 *     {% if app.environment == 'dev' %}
 *         {{ valksor_sse_importmap_definition(['app']) }}
 *         {{ valksor_sse_importmap_scripts(['app']) }}
 *     {% endif %}
 * {% endblock %}
 * ```
 *
 * Integration Points:
 * - **ImportMapRuntime**: Provides all core functionality for the template functions
 * - **SseConfiguration**: Configures SSE server endpoints used by runtime
 * - **Twig Environment**: Automatically registers the extension for template rendering
 * - **AssetMapper**: Integrates with JavaScript module system for importmap generation
 *
 * Extension Benefits:
 * - **Template Integration**: Enables SSE functionality directly in Twig templates
 * - **Consistency**: Provides same API across PHP and template contexts
 * - **Flexibility**: Supports various usage patterns and configurations
 * - **Safety**: Proper escaping and security for dynamic content
 *
 * @see ImportMapRuntime For the underlying implementation of all template functions
 * @see AbstractExtension For base Twig extension functionality
 * @see https://symfony.com/doc/current/template.html For Twig integration details
 *
 * @author Davis Zalitis (k0d3r1s)
 */

namespace Valksor\Component\Sse\Twig;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension that provides SSE (Server-Sent Events) template functions.
 *
 * This extension registers three main functions with Twig for SSE integration:
 * - importmap_definition: Generates importmap configuration for JavaScript modules
 * - importmap_scripts: Creates module import statements for specified entry points
 * - ping: Tests connectivity with the SSE server
 *
 * The extension uses a delegation pattern where all functions call corresponding
 * methods on ImportMapRuntime, ensuring consistent behavior between template
 * and PHP contexts while maintaining security and performance optimizations.
 *
 * Auto-Configuration:
 * The #[AutoconfigureTag('twig.extension')] attribute ensures this extension
 * is automatically registered with Twig when the SSE component is loaded, requiring
 * no manual configuration from developers.
 *
 * @see ImportMapRuntime For the implementation of all template functions
 * @see TwigFunction For function registration and configuration options
 * @see AbstractExtension For base Twig extension patterns
 *
 * @author Davis Zalitis (k0d3r1s)
 */
#[AutoconfigureTag('twig.extension')]
final class ImportMapExtension extends AbstractExtension
{
    /**
     * Register SSE template functions with the Twig environment.
     *
     * This method defines the complete set of template functions provided by the SSE
     * component. Each function is registered as a TwigFunction with appropriate
     * configuration for safe HTML output and delegates to corresponding methods
     * in the ImportMapRuntime class.
     *
     * Function Registration Strategy:
     * The method follows a delegation pattern where all template functions are
     * lightweight wrappers around ImportMapRuntime methods. This ensures:
     * - Consistent behavior between template and PHP contexts
     * - Single source of truth for SSE functionality
     * - Proper security and performance handling
     * - Easy maintenance and extension
     *
     * Security Configuration:
     * All functions are marked with 'is_safe' => ['html'], which tells Twig that:
     * - The output is safe for HTML rendering
     * - Twig should not escape the output (runtime handles HTML escaping)
     * - Cross-site scripting (XSS) protection is maintained
     * - Generated HTML can be safely included in templates
     *
     * Available Template Functions:
     *
     * **valksor_sse_importmap_definition**:
     * Generates the complete importmap definition for specified entry points.
     * Delegates to ImportMapRuntime::renderDefinition()
     * Creates HTML script element with importmap JSON configuration
     * Includes polyfills and preload links as needed
     * Essential for JavaScript module loading in SSE applications
     *
     * **valksor_sse_importmap_scripts**:
     * Generates JavaScript module import statements for specified entry points.
     * Delegates to ImportMapRuntime::renderScripts()
     * Creates HTML script elements with module imports
     * Works with the importmap definition to load modules
     * Completes the JavaScript module loading process
     *
     * **valksor_sse_ping**:
     * Tests connectivity with the SSE server.
     * Delegates to ImportMapRuntime::ping()
     * Returns boolean indicating server availability
     * Useful for debugging and health monitoring
     * Helps developers verify SSE server status in templates
     *
     * Function Signatures and Usage:
     *
     * ```php
     * // valksor_sse_importmap_definition(entryPoints, attributes)
     * valksor_sse_importmap_definition(['app', 'admin'], ['defer' => true])
     *
     * // valksor_sse_importmap_scripts(entryPoints, attributes)
     * valksor_sse_importmap_scripts(['app'], ['async' => true])
     *
     * // valksor_sse_ping()
     * valksor_sse_ping()  // Returns boolean
     * ```
     *
     * Template Usage Examples:
     *
     * ```twig
     * {# Basic usage in template #}
     * <head>
     *         {{ valksor_sse_importmap_definition(['app']) }}
     *         {{ valksor_sse_importmap_scripts(['app']) }}
     *     </head>
     *
     * {# Advanced usage with attributes #}
     * {{ valksor_sse_importmap_definition(
     *     ['admin', 'shared'],
     *     {'defer': true, 'crossorigin': 'anonymous'}
     * ) }}
     *
     * {# Development debugging #}
     * {% if app.debug %}
     *     {% set sseStatus = valksor_sse_ping() ? 'Connected' : 'Disconnected' %}
     *     <div class="sse-status">SSE Server: {{ sseStatus }}</div>
     * {% endif %}
     * ```
     *
     * Performance Considerations:
     * - Functions are lightweight delegations with minimal overhead
     * Heavy processing handled by ImportMapRuntime with optimizations
     * - Template compilation cache reduces repeated calls
     * - Safe HTML marking prevents unnecessary escaping operations
     *
     * Extension and Customization:
     * To add new template functions:
     * 1. Implement the method in ImportMapRuntime
     * 2. Register it here with appropriate safety configuration
     * 3. Follow the delegation pattern for consistency
     * 4. Update documentation with usage examples
     *
     * @return array<TwigFunction> Array of TwigFunction instances representing all SSE template functions
     *                             Each function includes name, callable, and configuration options
     *
     * @see TwigFunction For function registration and configuration details
     * @see ImportMapRuntime::renderDefinition() For importmap definition implementation
     * @see ImportMapRuntime::renderScripts() For module import implementation
     * @see ImportMapRuntime::ping() For SSE server connectivity testing
     * @see https://twig.symfony.com/doc/advanced.html#functions For custom function documentation
     *
     * @example Template Function Registration Pattern
     * ```php
     * // Standard function registration
     * new TwigFunction(
     *     'function_name',                    // Template function name
     *     [RuntimeClass::class, 'method'], // Callable method
     *     ['is_safe' => ['html']]          // Safety configuration
     * )
     * ```
     * @example Function with Multiple Parameters
     * ```php
     * // Advanced function with parameters and options
     * new TwigFunction(
     *     'custom_function',
     *     [RuntimeClass::class, 'method'],
     *     ['is_safe' => ['html'], 'needs_context' => true]
     * )
     * ```
     */
    public function getFunctions(): array
    {
        return [
            // ImportMap definition function - generates <script type="importmap"> element
            new TwigFunction(
                'valksor_sse_importmap_definition',        // Template function name
                [ImportMapRuntime::class, 'renderDefinition'], // Delegates to runtime method
                ['is_safe' => ['html']],                    // Safe for HTML output
            ),

            // ImportMap scripts function - generates module import statements
            new TwigFunction(
                'valksor_sse_importmap_scripts',           // Template function name
                [ImportMapRuntime::class, 'renderScripts'],   // Delegates to runtime method
                ['is_safe' => ['html']],                    // Safe for HTML output
            ),

            // SSE server ping function - tests server connectivity
            new TwigFunction(
                'valksor_sse_ping',                         // Template function name
                [ImportMapRuntime::class, 'ping'],          // Delegates to runtime method
                ['is_safe' => ['html']],                    // Safe for HTML output
            ),
        ];
    }
}
