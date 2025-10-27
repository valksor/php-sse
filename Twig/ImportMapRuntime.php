<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Valksor\Component\Sse\Twig;

use InvalidArgumentException;
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
use function class_exists;
use function count;
use function htmlspecialchars;
use function in_array;
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
        private readonly ParameterBagInterface $bag,
        private readonly HttpClientInterface $client,
    ) {
        $this->charset = 'UTF-8';
        $this->polyfillImportName = false;
        $this->scriptAttributes = [];
    }

    public function ping(): bool
    {
        $port = '';

        if (!in_array($this->requestStack->getMainRequest()?->getPort(), [80, 443], true)) {
            $port = ':' . $this->requestStack->getMainRequest()?->getPort();
        }

        try {
            $this->client->request('GET', 'https://' . $this->bag->get('valksor.sse.domain') . $port . $this->bag->get('valksor.sse.path'), [
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function renderDefinition(
        string|array $entryPoint,
        array $attributes = [],
    ): string {
        if ($this->definitionRendered) {
            return '';
        }

        $this->definitionRendered = true;
        $entryPoint = (array) $entryPoint;

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
            throw new InvalidArgumentException(sprintf('The "src" and "type" attributes are not allowed on the <script> tag rendered by "%s".', self::class));
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
}
