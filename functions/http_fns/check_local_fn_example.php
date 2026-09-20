<?php


// Whether a request came from a page this server itself served.
//
// is_trusted_ref() calls this when the referrer matched none of $TRUSTED_REFS,
// so it decides every request that did not come from the game's own sites.
// Returning true made that decision constant and require_trusted_ref() never
// refused anything at any of its call sites.
//
// The referrer's host is compared with the host the request was addressed to.
// A form on one of this site's own pages therefore passes, and a form on
// somebody else's does not, which is the case this is here to separate. A
// request that says nothing about where it came from has not shown it came
// from here, so it does not pass.
//
// This sits behind the posted token that the mutating pages require. The token
// is what stops the request; this refuses the same thing earlier and costs
// nothing.
function check_local()
{
    $referer = isset($_SERVER['HTTP_REFERER']) ? trim((string) $_SERVER['HTTP_REFERER']) : '';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';

    if ($referer === '' || $host === '') {
        return false;
    }

    $referer_host = parse_url($referer, PHP_URL_HOST);

    if (empty($referer_host)) {
        return false;
    }

    // the host the request was addressed to may carry a port; the referrer's
    // host as parsed does not
    $host = preg_replace('/:\d+$/', '', $host);

    return strcasecmp($referer_host, $host) === 0;
}
