<?php

namespace BookStack\Uploads;

use BookStack\Util\HtmlDocument;
use DOMAttr;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signs local file URLs (attachments & PHP-routed images) with Laravel
 * temporary signed URL parameters (expires + HMAC signature), and provides
 * the matching verification and stripping operations.
 *
 * Signatures are purely a render-time concern: storage, editor content and
 * saved page/entity content keep raw URLs. Expiring signatures are stripped
 * again on save so they never enter storage.
 */
class FileUrlSigner
{
    /**
     * The HTML attributes which may contain file URLs within rendered content.
     */
    protected const CONTENT_ATTR_XPATH = '//img/@src | //a/@href | //iframe/@src | //video/@src | //video/@poster | //source/@src | //audio/@src | //embed/@src';

    /**
     * Pattern to match a markdown link/image, capturing the URL part.
     */
    protected const MARKDOWN_LINK_PATTERN = '#(!?\[[^\]]*\]\()([^)\s]+)([^)]*\))#';

    public function __construct(
        protected ImageStorage $imageStorage,
    ) {
    }

    /**
     * The expiry, in seconds, to use when signing file URLs.
     */
    protected function expiry(): int
    {
        return (int) config('app.file_url_expiry', 3600);
    }

    /**
     * Check that the given request has a valid, unexpired, signature.
     */
    public function hasValidSignature(Request $request): bool
    {
        return app('url')->hasValidSignature($request, true);
    }

    /**
     * Get a temporary signed URL for the given attachment.
     */
    public function signedAttachmentUrl(Attachment $attachment, bool $openInline = false): string
    {
        $params = ['id' => strval($attachment->id)];
        if ($openInline) {
            $params['open'] = 'true';
        }

        return app('url')->temporarySignedRoute('attachments.get', $this->expiry(), $params);
    }

    /**
     * Sign a stored/absolute/relative /uploads/images URL.
     * Returns the input unchanged when it cannot be resolved to a local
     * PHP-routed image path.
     */
    public function signedImageUrl(string $url): string
    {
        if (str_contains($url, 'signature=')) {
            return $url;
        }

        // Only sign when images are served through the PHP-routed secure image
        // endpoints. Under the default 'local' disk (or s3) files are served
        // statically/directly so signing would be inert (or break access).
        if (!$this->imageStorage->usingSecureImages()) {
            return $url;
        }

        $storagePath = $this->imageStorage->urlToPath($url);
        if ($storagePath === null) {
            return $url;
        }

        $path = preg_replace('#^uploads/images/#', '', $storagePath);
        if (empty($path)) {
            return $url;
        }

        return app('url')->temporarySignedRoute('uploads.images', $this->expiry(), ['path' => $path]);
    }

    /**
     * Sign a single URL if it points to a local file endpoint.
     * External links, anchors, data URIs and anything unresolvable are
     * returned unchanged.
     */
    public function signUrl(string $url): string
    {
        if ($url === '' || str_contains($url, 'signature=')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if ($this->isLocalAttachmentPath($url, $path)) {
            $id = intval(basename($path));
            /** @var ?Attachment $attachment */
            $attachment = Attachment::query()->find($id);
            if ($attachment === null) {
                return $url;
            }

            $params = $this->queryParams($url, ['id' => strval($attachment->id)]);

            return app('url')->temporarySignedRoute('attachments.get', $this->expiry(), $params);
        }

        if ($this->isLocalImagePath($url, $path)) {
            return $this->signedImageUrl($url);
        }

        return $url;
    }

    /**
     * Strip the temporary signature parameters (signature + expires) from a
     * URL, but only for URLs matching the local file-route shapes.
     * Other query parameters (e.g. open=true) are left intact.
     */
    public function stripUrlParams(string $url): string
    {
        if ($url === '' || !str_contains($url, 'signature=') && !str_contains($url, 'expires=')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (!$this->isLocalAttachmentPath($url, $path) && !preg_match('#(^|/)uploads/images/#', $path)) {
            return $url;
        }

        [$base, $query] = array_pad(explode('?', $url, 2), 2, null);
        if ($query === null) {
            return $url;
        }

        $pairs = array_filter(explode('&', $query), function (string $pair) {
            $key = Str::before($pair, '=');

            return $key !== 'signature' && $key !== 'expires';
        });

        return $base . ($pairs ? '?' . implode('&', $pairs) : '');
    }

    /**
     * Sign all local file URLs within the given HTML content.
     */
    public function signHtmlContent(string $html): string
    {
        return $this->mapUrlsInHtml($html, fn (string $url) => $this->signUrl($url));
    }

    /**
     * Strip temporary signature parameters from all URLs in the given HTML content.
     */
    public function stripHtmlContent(string $html): string
    {
        if (empty($html) || (!str_contains($html, 'signature=') && !str_contains($html, 'expires='))) {
            return $html;
        }

        return $this->mapUrlsInHtml($html, fn (string $url) => $this->stripUrlParams($url));
    }

    /**
     * Sign all local file URLs within the given markdown content.
     */
    public function signMarkdownContent(string $markdown): string
    {
        if (empty($markdown)) {
            return $markdown;
        }

        return preg_replace_callback(static::MARKDOWN_LINK_PATTERN, function (array $matches) {
            return $matches[1] . $this->signUrl($matches[2]) . $matches[3];
        }, $markdown);
    }

    /**
     * Strip temporary signature parameters from all URLs in the given markdown content.
     */
    public function stripMarkdownContent(string $markdown): string
    {
        if (empty($markdown) || (!str_contains($markdown, 'signature=') && !str_contains($markdown, 'expires='))) {
            return $markdown;
        }

        return preg_replace_callback(static::MARKDOWN_LINK_PATTERN, function (array $matches) {
            return $matches[1] . $this->stripUrlParams($matches[2]) . $matches[3];
        }, $markdown);
    }

    /**
     * Rewrite URL-bearing attributes in the given HTML fragment via the provided mapper.
     */
    protected function mapUrlsInHtml(string $html, callable $mapper): string
    {
        if (empty($html)) {
            return $html;
        }

        $doc = new HtmlDocument($html);
        $attrs = $doc->queryXPath(static::CONTENT_ATTR_XPATH);

        $changed = false;
        /** @var DOMAttr $attr */
        foreach ($attrs as $attr) {
            $original = $attr->nodeValue ?? '';
            $mapped = $mapper($original);
            if ($mapped !== '' && $mapped !== $original) {
                // DOMAttr::nodeValue loses values with raw "&" in this libxml
                // (8.3) pairing, so set via the owner element instead.
                $attr->ownerElement?->setAttribute($attr->name, $mapped);
                $changed = true;
            }
        }

        return $changed ? $doc->getBodyInnerHtml() : $html;
    }

    /**
     * Check if the given URL path targets this instance's attachment endpoint.
     */
    protected function isLocalAttachmentPath(string $url, string $path): bool
    {
        if (!preg_match('#/attachments/(\d+)$#', $path, $matches)) {
            return false;
        }

        // Absolute URLs must belong to this instance (guard against external
        // links or user content that merely looks like an attachment path).
        if (preg_match('#^https?://#i', $url)) {
            $urlBase = strtolower(url('/attachments/'));

            return str_starts_with(strtolower($url), $urlBase);
        }

        return (bool) preg_match('#^/?attachments/(\d+)$#', $path);
    }

    /**
     * Check if the given URL path targets this instance's image file endpoint.
     */
    protected function isLocalImagePath(string $url, string $path): bool
    {
        if (!str_contains($path, 'uploads/images/')) {
            return false;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $this->imageStorage->urlToPath(Str::before($url, '?')) !== null;
        }

        return true;
    }

    /**
     * Collect existing query parameters, minus reserved signature parameters,
     * merged on top of the given route parameters.
     * @param array<string, string> $routeParams
     * @return array<string, string>
     */
    protected function queryParams(string $url, array $routeParams): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            unset($params['signature'], $params['expires']);
            foreach ($params as $key => $value) {
                if (is_string($value) && !isset($routeParams[$key])) {
                    $routeParams[$key] = $value;
                }
            }
        }

        return $routeParams;
    }
}
