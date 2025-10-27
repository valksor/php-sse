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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

#[AutoconfigureTag('twig.extension')]
final class ImportMapExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('valksor_sse_importmap_definition', [ImportMapRuntime::class, 'renderDefinition'], ['is_safe' => ['html']]),
            new TwigFunction('valksor_sse_importmap_scripts', [ImportMapRuntime::class, 'renderScripts'], ['is_safe' => ['html']]),
            new TwigFunction('valksor_sse_ping', [ImportMapRuntime::class, 'ping'], ['is_safe' => ['html']]),
        ];
    }
}
