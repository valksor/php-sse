/*
 * SSE reload client.
 *
 * Connects to the sse server (served from the php container) using
 * Server-Sent Events (SSE). When the server broadcasts a reload event the
 * current page is refreshed.
 */

const portMeta = document.querySelector('meta[name="valksor-sse-port"]');
const pathMeta = document.querySelector('meta[name="valksor-sse-path"]');
const configuredPort = portMeta ? parseInt(portMeta.content, 10) : undefined;
const configuredPath = pathMeta ? pathMeta.content.trim() : '';

const endpoint = (() => {
    if (configuredPath) {
        const origin = window.location.origin || `${window.location.protocol}//${window.location.host}`;
        return `${origin.replace(/\/$/, '')}${configuredPath}`;
    }

    if (!configuredPort) {
        throw new Error('[sse] SSE port or path must be configured via meta tags');
    }

    const protocol = window.location.protocol === 'https:' ? 'https:' : 'http:';
    const host = window.location.hostname;
    return `${protocol}//${host}:${configuredPort}${configuredPath}`;
})();

function log(message, ...args) {
    console?.debug?.(`[sse] ${message}`, ...args);
}

(function bootstrap() {
    if (!window.EventSource) {
        log('eventSource not supported by this browser. Live reload disabled.');
        return;
    }

    let reloadScheduled = false;
    let reconnectAttempts = 0;
    const MAX_RECONNECT_ATTEMPTS = 10;

    function connect() {
        const source = new EventSource(endpoint, {withCredentials: false});

        source.addEventListener('open', () => {
            log('connected to sse server at %s', endpoint);
            reconnectAttempts = 0;
        });

        source.addEventListener('error', () => {
            if (source.readyState === EventSource.CLOSED) {
                reconnectAttempts++;
                log('connection to sse server lost (attempt %d/%d). Awaiting retry…',
                    reconnectAttempts, MAX_RECONNECT_ATTEMPTS);

                if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
                    log('Mmax reconnection attempts reached. Stopping.');
                    source.close();
                }
            }
        });

        source.addEventListener('reload', (event) => {
            if (reloadScheduled) {
                return;
            }

            reloadScheduled = true;
            let detail = null;

            if (event?.data) {
                try {
                    detail = JSON.parse(event.data);
                } catch (error) {
                    log('failed to parse reload payload: %o', error);
                }
            }

            log('change detected%s. Reloading…', detail ? `: ${JSON.stringify(detail)}` : '');

            // Small delay to ensure log is visible and any pending operations complete
            setTimeout(() => window.location.reload(), 100);
        });

        source.addEventListener('ping', () => {
            // Keep-alive event from the server. No action needed.
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            source.close();
        });

        return source;
    }

    connect();
})();
