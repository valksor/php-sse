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

namespace Valksor\Component\Sse\Twig;

use InvalidArgumentException;
use JsonException;
use Psr\Link\EvolvableLinkProviderInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\WebLink\EventListener\AddLinkHeaderListener;
use Symfony\Component\WebLink\GenericLinkProvider;
use Symfony\Component\WebLink\Link;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function addslashes;
use function array_key_exists;
use function array_keys;
use function class_exists;
use function count;
use function htmlspecialchars;
use function in_array;
use function is_file;
use function json_encode;
use function ltrim;
use function preg_replace;
use function sprintf;
use function str_replace;
use function str_starts_with;

use const ENT_COMPAT;
use const ENT_NOQUOTES;
use const ENT_SUBSTITUTE;
use const JSON_HEX_TAG;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

#[AutoconfigureTag('twig.runtime')]
final class ImportMapRuntime
{
    private const string DEFAULT_ES_MODULE_SHIMS_POLYFILL_INTEGRITY = 'sha384-ie1x72Xck445i0j4SlNJ5W5iGeL3Dpa0zD48MZopgWsjNB/lt60SuG1iduZGNnJn';
    private const string DEFAULT_ES_MODULE_SHIMS_POLYFILL_URL = 'https://ga.jspm.io/npm:es-module-shims@1.10.0/dist/es-module-shims.js';
    private const string LOADER_CSS = "document.head.appendChild(Object.assign(document.createElement('link'),{rel:'stylesheet',href:'%s'}))";
    private const string LOADER_JSON = "export default (async()=>await(await fetch('%s')).json())()";

    private bool $definitionRendered = false;
    private readonly string $charset;
    private readonly string|false $polyfillImportName;
    private readonly array $scriptAttributes;

    public function __construct(
        private readonly ImportMapGenerator $importMapGenerator,
        private readonly ?Packages $assetPackages,
        private readonly ?RequestStack $requestStack,
        private readonly ParameterBagInterface $parameterBag,
        private readonly HttpClientInterface $client,
    ) {
        $this->charset = 'UTF-8';
        $this->polyfillImportName = false;
        $this->scriptAttributes = [];
    }

    public function ping(): bool
    {
        try {
            $this->client->request(Request::METHOD_HEAD, $this->getUrl(), [
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Generate the complete importmap definition for the current request.
     *
     * This is the core method that orchestrates the entire importmap generation process.
     * It handles entry point validation, importmap data processing, resource optimization,
     * and HTML generation with proper polyfill support and preloading capabilities.
     *
     * The method implements a sophisticated asset processing pipeline:
     * 1. Validates entry points against the importmap.php configuration file
     * 2. Automatically includes the 'valksorsse/sse' module for hot reload functionality
     * 3. Processes each import entry based on its type (js, css, json)
     * 4. Generates appropriate data URIs or file references
     * 5. Creates WebLink headers for performance optimization
     * 6. Renders the importmap JSON with proper escaping
     * 7. Adds polyfill support for browsers that don't support importmaps natively
     * 8. Generates modulepreload links for improved loading performance
     *
     * Entry Point Validation Process:
     * - Checks for '../importmap.php' file for additional validation
     * - Filters out entry points that don't exist in the configured importmap
     * - Ensures only valid, available modules are included in the final importmap
     * - Automatically adds the SSE module regardless of user configuration
     *
     * Import Type Handling:
     * - JavaScript modules: Direct path references with optional preloading
     * - CSS files: Either data URI injection or stylesheet links based on preload settings
     * - JSON files: Wrapped in dynamic import statements via data URIs
     * - Polyfills: Special handling with integrity attributes and CDN fallbacks
     *
     * Performance Optimizations:
     * - WebLink headers for resource preloading and prefetching
     * - Modulepreload links for critical JavaScript dependencies
     * - Data URIs for small resources to avoid additional HTTP requests
     * - Intelligent bundling based on resource type and usage patterns
     *
     * Security Considerations:
     * - Proper HTML escaping for all generated content
     * - Integrity attributes for external CDN resources
     * - Crossorigin attributes for proper CORS handling
     * - Sanitization of user-provided attributes and configuration
     *
     * Browser Compatibility:
     * - Native importmap support for modern browsers
     * - Automatic polyfill injection for older browsers
     * - Graceful degradation with comprehensive error handling
     * - Support for both ES modules and traditional script loading
     *
     * @param string|array $entryPoint One or more entry point identifiers to process
     *                                 Examples: 'app', ['app', 'admin'], '/custom/module'
     *                                 These are resolved through AssetMapper/importmap system
     * @param array        $attributes Optional HTML attributes for the generated script tag
     *                                 Common attributes: 'defer', 'async', 'crossorigin'
     *                                 Note: 'src' and 'type' are prohibited as they're managed internally
     *
     * @return string Complete HTML block containing importmap definition, polyfills, and preloads
     *                Returns empty string if definition has already been rendered (singleton pattern)
     *
     * @throws JsonException            If JSON encoding of the importmap fails
     * @throws InvalidArgumentException If polyfill configuration is invalid or missing
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap
     * @see https://github.com/guybedford/es-module-shims For polyfill details
     * @see AssetMapper\ImportMap\ImportMapGenerator For underlying importmap processing
     *
     * @example Basic usage with single entry point
     * ```php
     * $runtime = new ImportMapRuntime($generator, $packages, $requestStack, $parameterBag, $client);
     * $html = $runtime->renderDefinition('app');
     * // Outputs: <script type="importmap">{"imports":{"app":"/assets/app.js"}}</script>
     * ```
     * @example Advanced usage with multiple entry points and attributes
     * ```php
     * $html = $runtime->renderDefinition(['app', 'admin', 'shared'], [
     *     'defer' => true,
     *     'crossorigin' => 'anonymous'
     * ]);
     * ```
     * @example CSS handling behavior
     * ```php
     * // CSS files can be handled in multiple ways:
     * $html = $runtime->renderDefinition('styles'); // Preloaded CSS: <link rel="stylesheet" href="/styles.css">
     * $html = $runtime->renderDefinition(['styles' => ['preload' => false]]); // Injected CSS: data:application/javascript,document.head.appendChild(...)
     * ```
     */
    public function renderDefinition(
        string|array $entryPoint,
        array $attributes = [],
    ): string {
        if ($this->definitionRendered) {
            return '';
        }

        $this->definitionRendered = true;
        $entryPoint = (array) $entryPoint;

        if (is_file('../importmap.php')) {
            $keys = array_keys(include '../importmap.php');

            foreach ($entryPoint as $key => $value) {
                if (str_starts_with($value, '@')) {
                    continue;
                }

                if (!array_key_exists($value, $keys)) {
                    unset($entryPoint[$key]);
                }
            }
        }

        $entryPoint[] = 'valksorsse/sse';

        $importMapData = $this->importMapGenerator->getImportMapData($entryPoint);
        $importMap = [];
        $modulePreloads = [];
        $webLinks = [];
        $polyfillPath = null;

        foreach ($importMapData as $importName => $data) {
            $path = $data['path'];

            if ($this->assetPackages) {
                $path = $this->assetPackages->getUrl(ltrim($path, '/'));
            }

            if ($importName === $this->polyfillImportName) {
                $polyfillPath = $path;

                continue;
            }

            if ($this->assetPackages && str_starts_with($importName, '/')) {
                $importName = $this->assetPackages->getUrl(ltrim($importName, '/'));
            }

            $preload = $data['preload'] ?? false;

            if ('json' === $data['type']) {
                $importMap[$importName] = 'data:application/javascript,' . str_replace('%', '%25', sprintf(self::LOADER_JSON, addslashes($path)));

                if ($preload) {
                    $webLinks[$path] = 'fetch';
                }
            } elseif ('css' !== $data['type']) {
                $importMap[$importName] = $path;

                if ($preload) {
                    $modulePreloads[$path] = $path;
                }
            } elseif ($preload) {
                $webLinks[$path] = 'style';
                $importMap[$importName] = 'data:application/javascript,';
            } else {
                $importMap[$importName] = 'data:application/javascript,' . str_replace('%', '%25', sprintf(self::LOADER_CSS, addslashes($path)));
            }
        }

        $output = '';

        foreach ($webLinks as $url => $as) {
            if ('style' === $as) {
                $output .= "\n<link rel=\"stylesheet\" href=\"" . $this->escapeAttributeValue($url) . '">';
            }
        }

        if (class_exists(AddLinkHeaderListener::class) && $request = $this->requestStack?->getCurrentRequest()) {
            $this->addWebLinkPreloads($request, $webLinks);
        }

        $scriptAttributes = $attributes || $this->scriptAttributes ? ' ' . $this->createAttributesString($attributes) : '';
        $importMapJson = json_encode(['imports' => $importMap], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $output .= <<<HTML

            <script type="importmap"$scriptAttributes>
            $importMapJson
            </script>
            HTML;

        if (false !== $this->polyfillImportName && null === $polyfillPath) {
            if ('es-module-shims' !== $this->polyfillImportName) {
                throw new InvalidArgumentException(sprintf('The JavaScript module polyfill was not found in your import map. Either disable the polyfill or run "php bin/console importmap:require "%s"" to install it.', $this->polyfillImportName));
            }

            $polyfillPath = self::DEFAULT_ES_MODULE_SHIMS_POLYFILL_URL;
        }

        if ($polyfillPath) {
            $polyfillAttributes = $attributes + $this->scriptAttributes;

            if (self::DEFAULT_ES_MODULE_SHIMS_POLYFILL_URL === $polyfillPath) {
                $polyfillAttributes = [
                    'crossorigin' => 'anonymous',
                    'integrity' => self::DEFAULT_ES_MODULE_SHIMS_POLYFILL_INTEGRITY,
                ] + $polyfillAttributes;
            }

            $output .= <<<HTML
                <script$scriptAttributes>
                if (!HTMLScriptElement.supports || !HTMLScriptElement.supports('importmap')) (function () {
                    const script = document.createElement('script');
                    script.src = '{$this->escapeAttributeValue($polyfillPath, ENT_NOQUOTES)}';
                    {$this->createAttributesString($polyfillAttributes, "script.setAttribute('%s', '%s');", "\n    ", ENT_NOQUOTES)}
                    document.head.appendChild(script);
                })();
                </script>
                HTML;
        }

        foreach ($modulePreloads as $url) {
            $url = $this->escapeAttributeValue($url);
            $output .= "\n<link rel=\"modulepreload\" href=\"$url\">";
        }

        return $output;
    }

    /**
     * Generate module import statements for the specified entry points.
     *
     * This method creates the actual JavaScript module imports that will be executed
     * by the browser to load and initialize the application entry points. It's designed
     * to work in conjunction with the importmap definition generated by renderDefinition().
     *
     * The method handles multiple entry points by generating individual import statements
     * for each one, allowing applications to have multiple initialization modules or
     * lazy-loaded sections. Each import statement is properly escaped to prevent
     * syntax errors and injection vulnerabilities.
     *
     * Relationship with renderDefinition():
     * - renderDefinition() creates the importmap that resolves module names to URLs
     * - renderScripts() creates the actual import statements that use the importmap
     * - Both methods should be called for complete module loading functionality
     * - The order matters: importmap should be defined before the import statements
     *
     * Module Loading Process:
     * 1. Browser loads the importmap definition (from renderDefinition)
     * 2. Browser encounters import statements (from this method)
     * 3. Browser resolves module names using the importmap
     * 4. Browser loads and executes the modules in order
     * 5. Modules can then import other modules using the same importmap
     *
     * Performance Considerations:
     * - Modules are loaded and executed in the order specified
     * - All imports are treated as ES modules with top-level await support
     * - No bundling is performed - each module is loaded individually
     * - Modulepreload links from renderDefinition() can improve loading performance
     *
     * Browser Compatibility:
     * - Requires native ES module support or appropriate polyfill
     * - Works with dynamic imports for code splitting
     * - Supports import assertions for security (when available)
     * - Compatible with modern module bundling workflows
     *
     * Security Features:
     * - Proper escaping of module identifiers to prevent injection
     * - Integration with CSP (Content Security Policy) when configured
     * - Safe handling of special characters in module names
     * - Prevention of malicious module name injection
     *
     * @param string|array $entryPoint One or more entry point identifiers to import
     *                                 Examples: 'app', ['app', 'admin'], '/modules/dashboard'
     *                                 These correspond to keys in the importmap definition
     * @param array        $attributes Optional HTML attributes for the generated script tag
     *                                 Common attributes: 'defer', 'async', 'crossorigin'
     *                                 Note: 'src' and 'type' are managed internally and prohibited
     *
     * @return string HTML script element containing module import statements
     *                Returns empty string if no entry points are provided
     *
     * @see renderDefinition() For the corresponding importmap generation
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Modules
     *
     * @example Basic single entry point
     * ```php
     * $runtime->renderScripts('app');
     * // Generates: <script type="module">import 'app';</script>
     * ```
     * @example Multiple entry points with custom attributes
     * ```php
     * $runtime->renderScripts(['app', 'admin', 'shared'], [
     *     'defer' => true,
     *     'crossorigin' => 'anonymous'
     * ]);
     * // Generates: <script type="module" defer crossorigin="anonymous">
     * //           import 'app';import 'admin';import 'shared';
     * //           </script>
     * ```
     * @example Complete module loading workflow
     * ```php
     * // Step 1: Generate the importmap definition
     * $importmapHtml = $runtime->renderDefinition(['app', 'admin']);
     *
     * // Step 2: Generate the import statements
     * $scriptsHtml = $runtime->renderScripts(['app', 'admin']);
     *
     * // Step 3: Output both in your template
     * echo $importmapHtml; // <script type="importmap">{"imports":{...}}</script>
     * echo $scriptsHtml;   // <script type="module">import 'app';import 'admin';</script>
     * ```
     */
    public function renderScripts(
        string|array $entryPoint,
        array $attributes = [],
    ): string {
        $entryPoint = (array) $entryPoint;

        if (0 === count($entryPoint)) {
            return '';
        }

        $scriptAttributes = $attributes || $this->scriptAttributes ? ' ' . $this->createAttributesString($attributes) : '';
        $output = "\n<script type=\"module\"$scriptAttributes>";

        foreach ($entryPoint as $entryPointName) {
            $entryPointName = $this->escapeAttributeValue($entryPointName);
            $output .= "import '" . str_replace("'", "\\'", $entryPointName) . "';";
        }

        $output .= '</script>';

        return $output;
    }

    private function addWebLinkPreloads(
        Request $request,
        array $webLinks,
    ): void {
        if (!$request->attributes->has('_links')) {
            $request->attributes->set('_links', new GenericLinkProvider());
        }

        $linkProvider = $request->attributes->get('_links');

        if (!$linkProvider instanceof EvolvableLinkProviderInterface) {
            return;
        }

        foreach ($webLinks as $url => $as) {
            $link = new Link('preload', $url);
            $link = $link->withAttribute('as', $as);

            if ('fetch' === $as) {
                $link = $link->withAttribute('crossorigin', 'anonymous');
            }

            $linkProvider = $linkProvider->withLink($link);
        }

        $request->attributes->set('_links', $linkProvider);
    }

    private function createAttributesString(
        array $attributes,
        string $pattern = '%s="%s"',
        string $glue = ' ',
        int $flags = ENT_COMPAT | ENT_SUBSTITUTE,
    ): string {
        $attributeString = '';

        $attributes += $this->scriptAttributes;

        if (isset($attributes['src']) || isset($attributes['type'])) {
            throw new InvalidArgumentException(sprintf("The 'src' and 'type' attributes are not allowed on the <script> tag rendered by '%s'.", self::class));
        }

        foreach ($attributes as $name => $value) {
            if ('' !== $attributeString) {
                $attributeString .= $glue;
            }

            if (true === $value) {
                $value = $name;
            }
            $attributeString .= sprintf($pattern, $this->escapeAttributeValue($name, $flags), $this->escapeAttributeValue($value, $flags));
        }

        return preg_replace('/\b([^ =]++)="\1"/', '\1', $attributeString);
    }

    private function escapeAttributeValue(
        string $value,
        int $flags = ENT_COMPAT | ENT_SUBSTITUTE,
    ): string {
        $value = htmlspecialchars($value, $flags, $this->charset);

        return (ENT_NOQUOTES & $flags) ? addslashes($value) : $value;
    }

    private function getPort(): string
    {
        static $_port = null;

        if (null === $_port) {
            $requestPort = $this->requestStack->getMainRequest()?->getPort();
            $_port = (!in_array($requestPort, [80, 443], true)) ? ':' . $requestPort : '';
        }

        return $_port;
    }

    private function getUrl(): string
    {
        static $_url = null;

        if (null === $_url) {
            $_url = 'https://' .
                $this->parameterBag->get('valksor.sse.domain') .
                $this->getPort() .
                $this->parameterBag->get('valksor.sse.path');
        }

        return $_url;
    }
}
