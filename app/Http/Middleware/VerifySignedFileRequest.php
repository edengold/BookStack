<?php

namespace BookStack\Http\Middleware;

use BookStack\Uploads\FileUrlSigner;
use Closure;
use Illuminate\Http\Request;

/**
 * Handles access to file-serving routes (attachments & secure images) which
 * may be accessed via a temporary signed URL instead of session authentication.
 *
 * A valid signature grants access (temporary capability), allowing guests and
 * non-browsing consumers (like PDF renderers) to fetch the file.
 * Unsigned, invalid or expired requests fall through to the standard
 * authentication behaviour, preserving existing access control.
 */
class VerifySignedFileRequest
{
    public function __construct(
        protected FileUrlSigner $signer,
        protected Authenticate $auth,
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->signer->hasValidSignature($request)) {
            $request->attributes->set('signed-file-access', true);

            return $next($request);
        }

        // Unsigned/invalid/expired -> today's behavior (login redirect / 401)
        return $this->auth->handle($request, $next);
    }
}
