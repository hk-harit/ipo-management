<script>
    // The service worker is what lets the browser offer "Install app". It never caches pages,
    // so each deploy shows up on the next open.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' })
                .then(function (registration) { registration.update(); })
                .catch(function () { /* the site works fine without it */ });
        });
    }
</script>
