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

/**
 * Common interface for all dev services (Tailwind, Importmap, HotReload, etc.).
 *
 * Defines the contract for services that can be controlled programmatically
 * and respond to signals for reload/shutdown operations.
 */
interface ServiceInterface
{
    /**
     * Check if the service is currently running.
     */
    public function isRunning(): bool;

    /**
     * Reload the service.
     *
     * For build services: re-run the build
     * For watch services: restart watchers, rebuild assets
     *
     * This is typically called in response to a SIGHUP signal.
     */
    public function reload(): void;

    public function setIo(
        SymfonyStyle $io,
    ): static;

    /**
     * Start the service with the given configuration.
     *
     * This method will block until the service is stopped (for long-running services)
     * or return immediately after completing (for build-only services).
     *
     * @param array<string,mixed> $config Service-specific configuration
     *
     * @return int Exit code (0 for success, non-zero for failure)
     */
    public function start(
        array $config = [],
    ): int;

    /**
     * Stop the service gracefully.
     *
     * This is typically called in response to SIGINT or SIGTERM signals.
     */
    public function stop(): void;
}
