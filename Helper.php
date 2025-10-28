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

namespace Valksor\Component\Sse;

use Valksor\Functions\Iteration\Traits\_JsonDecode;
use Valksor\Functions\Iteration\Traits\_JsonEncode;
use Valksor\Functions\Local\Traits\_MkDir;

trait Helper
{
    public function ensureDirectory(
        string $directory,
    ): void {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MkDir;
            };
        }

        $_helper->mkdir($directory);
    }

    public function jsonDecode(
        string $json,
    ): mixed {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _JsonDecode;
            };
        }

        return $_helper->jsonDecode($json, 1);
    }

    public function jsonEncode(
        mixed $data,
    ): string {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _JsonEncode;
            };
        }

        return $_helper->jsonEncode($data);
    }
}
