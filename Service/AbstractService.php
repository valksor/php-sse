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

namespace Valksor\Component\Sse\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractService implements ServiceInterface
{
    public SymfonyStyle $io {
        set {
            $this->io = $value;
        }
        get => $this->io;
    }
}
