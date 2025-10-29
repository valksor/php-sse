const hasSSE = document.querySelector('meta[name="valksor-sse-port"], meta[name="valksor-sse-path"]');

if (hasSSE) {
    import('./sse.js');
}
