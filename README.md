# Valksor Component: SSE

[![BSD-3-Clause](https://img.shields.io/badge/BSD--3--Clause-green?style=flat)](https://github.com/valksor/php-sse/blob/master/LICENSE)
[![Coverage Status](https://coveralls.io/repos/github/valksor/php-sse/badge.svg?branch=master)](https://coveralls.io/github/valksor/php-sse?branch=master)

A Server-Sent Events (SSE) component that provides real-time, unidirectional communication from server to client, enabling live updates, programmatic reloads, and dynamic content streaming in Symfony applications.

## Features

- **Real-time Server-Sent Events**: Bidirectional SSE server with process management
- **Programmatic Reloads**: Automatic browser reloads during development
- **Import Map Integration**: Dynamic JavaScript module loading with modern import maps
- **Twig Integration**: Custom Twig functions for SSE and import map management
- **Process Management**: Automatic process cleanup and conflict resolution
- **Asset Mapper Support**: Integration with Symfony's asset mapper system
- **Signal Handling**: Proper signal handling for graceful shutdown

## Installation

Install the package via Composer:

```bash
composer require valksor/php-sse
```

## Requirements

- PHP 8.4 or higher
- JSON extension
- PCNTL extension (for process management)
- POSIX extension
- Symfony Framework
- Twig templating engine
- Valksor Bundle (for automatic configuration)
- AssetMapper integration (automatic)

## Usage

### Basic Setup

1. Register the bundle in your Symfony application:

```php
// config/bundles.php
return [
    // ...
    Valksor\Bundle\ValksorBundle::class => ['all' => true],
    // ...
];
```

2. Enable the SSE component:

```yaml
# config/packages/valksor.yaml
valksor:
    sse:
        enabled: true
        port: 8080           # SSE server port
        host: localhost       # SSE server host
        ping_interval: 30     # Ping interval in seconds
```

3. Set up asset mapping:

Copy the importmap.php.example file from the SSE component to your project root:

```bash
# Copy from the SSE component Resources directory
cp src/valksor/src/Valksor/Component/Sse/Resources/importmap.php.example importmap.php
```

The configuration automatically detects your environment and includes SSE assets from:
- Local development (`valksor/` directory)
- FullStack package (`vendor/valksor/valksor/`)
- Standalone SSE package (`vendor/valksor/php-sse/`)

4. Add SSE to your template:

```twig
{# In your base layout template #}
{% block javascripts %}
    {{ include('@ValksorSse/sse.html.twig') }}
{% endblock %}
```

### Development Server

Start the SSE server for development:

```bash
# Start the SSE server
php bin/console valksor:sse

# Or run in the background
php bin/console valksor:sse &
```

The server will:
- Start an SSE server on the configured host and port
- Handle client connections and send real-time events
- Provide automatic browser reload functionality
- Manage process lifecycle and cleanup

### Integration with Valksor Dev Tools

The SSE component is automatically integrated with the new Valksor build system architecture:

#### Automatic SSE Integration with Development Commands

When using the new 3-command architecture:

```bash
# Lightweight development (includes SSE + hot reload)
php bin/console valksor:dev

# Full development environment (includes SSE + all services)
php bin/console valksor:watch
```

Both commands automatically start the SSE server as part of the `hot_reload` provider, so you don't need to run `valksor:sse` separately during development.

#### Build System Integration

The SSE component works seamlessly with the service registry architecture:

- **Provider Integration**: SSE functionality is provided by the `HotReloadProvider`
- **Flag-Based Execution**: Automatically runs with commands that use the `dev` flag
- **Process Management**: SSE processes are managed by the build system's process manager
- **Dependency Resolution**: SSE server starts before file watching begins

#### Configuration Integration

The SSE component can be used independently or integrated with other systems:

```yaml
# config/packages/valksor.yaml - SSE-only configuration
valksor:
    sse:
        enabled: true
        port: 8080
        host: localhost
        ping_interval: 30
```

For build system integration examples, see the ValksorDev Build Tools documentation.

#### When to Use Standalone SSE vs Build System Integration

**Use Build System Integration (`valksor:dev`/`valksor:watch`):**
- Development environment with hot reload
- Multiple services need to run together
- Automatic process management required
- File watching + SSE functionality needed

**Use Standalone SSE (`valksor:sse`):**
- Production SSE server deployment
- Custom SSE-only applications
- Integration with other build systems
- Manual process control preferred

### Asset Setup

The SSE component requires an importmap.php configuration in your project root that automatically detects and includes the SSE assets.

Copy the importmap.php.example file from the SSE component to your project root:

```bash
# Copy from the SSE component Resources directory
cp src/valksor/src/Valksor/Component/Sse/Resources/importmap.php.example importmap.php
```

The configuration automatically detects your environment and includes SSE assets from:
- Local development (`valksor/` directory)
- FullStack package (`vendor/valksor/valksor/`)
- Standalone SSE package (`vendor/valksor/php-sse/`)

### Twig Integration

The component provides SSE integration through a simple template include:

```twig
{# Add the SSE template include to your layout #}
{% block javascripts %}
    {{ include('@ValksorSse/sse.html.twig') }}
{% endblock %}
```

The SSE template automatically handles:
- Server connection detection via `valksor_sse_ping()`
- Meta tag injection for SSE configuration (`valksor-sse-port`, `valksor-sse-path`)
- JavaScript client loading via `valksor_sse_importmap_scripts(['valksorsse/sse'])`

For advanced usage, you can also use the individual Twig functions:

```twig
{# Manual import map definition #}
{{ valksor_sse_importmap_definition(['your-assets']) }}

{# Manual script loading #}
{{ valksor_sse_importmap_scripts(['your-assets']) }}

{# Connection testing #}
{% if valksor_sse_ping() %}
    <meta name="valksor-sse-port" content="{{ valksor_sse_port }}">
    <meta name="valksor-sse-path" content="{{ valksor_sse_path }}">
{% endif %}
```

### Frontend JavaScript Usage

Once the SSE scripts are loaded, you can use the client-side API:

```javascript
// The SSE client is automatically initialized
// Listen for reload events
window.addEventListener('sse:reload', () => {
    console.log('Page will reload...');
});

// Custom event handling
const sseClient = window.sseClient;

// Listen for custom events
sseClient.addEventListener('custom-event', (event) => {
    console.log('Custom event:', event.data);
});

// Send ping messages
sseClient.ping();

// Check connection status
if (sseClient.isConnected()) {
    console.log('SSE connection is active');
}
```

### Advanced Configuration

#### Complete Configuration Example

```yaml
# config/packages/valksor.yaml
valksor:
    sse:
        enabled: true
        host: localhost
        port: 8080
        ping_interval: 30
        max_connections: 100
        timeout: 300
        debug: false
```

#### Custom Event Broadcasting

Create custom services to broadcast events:

```php
<?php

namespace App\Service;

use Valksor\Component\Sse\Service\ServiceInterface;
use Valksor\Component\Sse\Service\AbstractService;

class CustomEventService extends AbstractService implements ServiceInterface
{
    public function broadcastCustomEvent(array $data): void
    {
        $event = [
            'type' => 'custom-event',
            'data' => $data,
            'timestamp' => time(),
        ];

        $this->broadcast($event);
    }

    public function broadcastReload(): void
    {
        $this->broadcast(['type' => 'reload']);
    }
}
```

### Programmatic Usage

#### Trigger Reloads from PHP

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Valksor\Component\Sse\Service\SseService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DevelopmentController extends AbstractController
{
    #[Route('/trigger-reload')]
    public function triggerReload(SseService $sseService): Response
    {
        // Trigger a reload on all connected clients
        $sseService->broadcast(['type' => 'reload']);

        return new Response('Reload triggered');
    }

    #[Route('/custom-event')]
    public function sendCustomEvent(SseService $sseService): Response
    {
        $sseService->broadcast([
            'type' => 'custom-notification',
            'data' => [
                'message' => 'Hello from server!',
                'level' => 'info'
            ]
        ]);

        return new Response('Custom event sent');
    }
}
```

#### Custom SSE Service Implementation

```php
<?php

namespace App\Service;

use Valksor\Component\Sse\Service\SseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AppSseService extends SseService
{
    protected function handleRequest(Request $request): Response
    {
        // Custom request handling
        $response = parent::handleRequest($request);

        // Add custom headers
        $response->headers->set('X-Custom-Header', 'SSE-Service');

        return $response;
    }

    protected function sendPing(): void
    {
        // Custom ping implementation
        parent::sendPing();

        // Add custom ping behavior
        $this->broadcast(['type' => 'custom-ping', 'timestamp' => time()]);
    }
}
```

### Automatic Asset Management

The SSE component automatically handles all asset management through the importmap.php configuration. No manual AssetMapper configuration is required.

### Process Management

#### Automatic Process Cleanup

The component includes automatic process management:

```bash
# List running SSE processes
ps aux | grep "valksor:sse"

# Kill conflicting processes (done automatically)
php bin/console valksor:sse --kill-existing
```

#### Signal Handling

The SSE server handles system signals properly:

```bash
# Graceful shutdown
kill -TERM <pid>

# Force shutdown
kill -KILL <pid>

# Interrupt signal
kill -INT <pid>
```

## API Reference

### Twig Functions

- `valksor_sse_importmap_definition()` - Renders import map definition
- `valksor_sse_importmap_scripts()` - Loads SSE client scripts
- `valksor_sse_ping()` - Adds ping functionality

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | boolean | false | Enable/disable the SSE component |
| `host` | string | localhost | SSE server host |
| `port` | int | 8080 | SSE server port |
| `ping_interval` | int | 30 | Ping interval in seconds |
| `max_connections` | int | 100 | Maximum concurrent connections |
| `timeout` | int | 300 | Connection timeout in seconds |
| `debug` | boolean | false | Enable debug mode |

### Server Events

Standard event types:

- `reload` - Triggers browser reload
- `ping` - Connection keep-alive
- `custom-event` - Custom application events

## Production Considerations

### Security

- **CORS**: Configure proper CORS headers for production
- **Authentication**: Implement proper authentication for SSE endpoints
- **Rate Limiting**: Add rate limiting to prevent abuse

```yaml
# config/packages/security.yaml
security:
    firewalls:
        sse:
            pattern: ^/sse
            anonymous: false
            # Add your authentication configuration
```

### Performance

- **Load Balancing**: Consider multiple SSE servers behind a load balancer
- **Connection Limits**: Monitor and limit concurrent connections
- **Memory Management**: Monitor memory usage for long-running processes

### Deployment

```bash
# Deploy with process manager (systemd example)
sudo systemctl start valksor-sse
sudo systemctl enable valksor-sse
```

## Troubleshooting

### Common Issues

1. **Port Already in Use**
   ```bash
   # Check if port is in use
   lsof -i :8080

   # Kill existing process
   php bin/console valksor:sse --kill-existing
   ```

2. **Connection Refused**
   - Check firewall settings
   - Verify host and port configuration
   - Ensure SSE server is running

3. **Browser Not Reloading**
   - Check browser console for errors
   - Verify Twig functions are properly included
   - Ensure JavaScript is loading correctly

### Debug Mode

Enable debug mode for detailed logging:

```yaml
# config/packages/valksor.yaml
valksor:
    sse:
        debug: true
```


## Contributing

Contributions are welcome!

- Code style requirements (PSR-12)
- Testing requirements for PRs
- One feature per pull request
- Development setup instructions

To contribute to SSE component:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/sse-enhancement`)
3. Implement your SSE enhancement following existing patterns
4. Add comprehensive tests for new functionality
5. Ensure all tests pass and code style is correct
6. Submit a pull request

### Adding New SSE Features

When adding new SSE functionality:

1. Extend existing service classes or create new services
2. Implement proper event handling and broadcasting
3. Add Twig functions for frontend integration
4. Update configuration schema if needed
5. Add comprehensive tests
6. Update documentation with examples

## License

This package is licensed under the [BSD-3-Clause License](LICENSE).

## About Valksor

This package is part of the [valksor/php-valksor](https://github.com/valksor/php-valksor) project - a comprehensive PHP library and Symfony bundle that provides a collection of utilities, components, and integrations for Symfony applications.

The main project includes:
- Various utility functions and components
- Doctrine ORM tools and extensions
- API Platform integrations
- Symfony bundle for easy configuration
- And much more

If you find this SSE component useful, you might want to check out the full Valksor project for additional tools and utilities that can enhance your Symfony application development.

To install the complete package:

```bash
composer require valksor/php-valksor
```
