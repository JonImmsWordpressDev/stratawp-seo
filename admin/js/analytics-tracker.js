/**
 * StrataWP SEO — Frontend Analytics Tracker
 *
 * Lightweight page view tracking. Cookie-free, GDPR-friendly.
 * Sends data via sendBeacon on page unload.
 */
(function () {
    'use strict';

    if ( typeof swpsTracker === 'undefined' ) return;

    var startTime  = Date.now();
    var maxScroll  = 0;
    var interacted = false;
    var sent       = false;

    // Track max scroll depth.
    var scrollTimer = null;
    function onScroll() {
        if ( scrollTimer ) return;
        scrollTimer = setTimeout( function () {
            scrollTimer = null;
            var scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
            var docHeight  = Math.max( document.body.scrollHeight, document.documentElement.scrollHeight );
            var winHeight  = window.innerHeight;
            var scrollable = docHeight - winHeight;

            if ( scrollable > 0 ) {
                var pct = Math.round( ( scrollTop / scrollable ) * 100 );
                if ( pct > maxScroll ) maxScroll = pct;
            }
        }, 200 );
    }

    // Track interaction (not a bounce).
    function onInteract() {
        interacted = true;
        document.removeEventListener( 'click', onInteract );
        document.removeEventListener( 'keydown', onInteract );
    }

    // Bounce timeout — 10 seconds.
    var bounceTimer = setTimeout( function () {
        interacted = true; // Stayed long enough = not a bounce.
    }, 10000 );

    // Send tracking data.
    function sendData() {
        if ( sent ) return;
        sent = true;

        var elapsed = Math.round( ( Date.now() - startTime ) / 1000 );
        var data    = new FormData();

        data.append( 'action', 'swps_track' );
        data.append( 'nonce', swpsTracker.nonce );
        data.append( 'post_id', swpsTracker.post_id );
        data.append( 'page_url', window.location.href );
        data.append( 'referrer', document.referrer || '' );
        data.append( 'time_on_page', elapsed );
        data.append( 'scroll_depth', maxScroll );
        data.append( 'is_bounce', interacted ? 0 : 1 );

        if ( navigator.sendBeacon ) {
            navigator.sendBeacon( swpsTracker.ajax_url, data );
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', swpsTracker.ajax_url, false );
            xhr.send( data );
        }
    }

    // Listeners.
    window.addEventListener( 'scroll', onScroll, { passive: true } );
    document.addEventListener( 'click', onInteract );
    document.addEventListener( 'keydown', onInteract );
    document.addEventListener( 'visibilitychange', function () {
        if ( document.visibilityState === 'hidden' ) sendData();
    } );
    window.addEventListener( 'beforeunload', sendData );
})();
