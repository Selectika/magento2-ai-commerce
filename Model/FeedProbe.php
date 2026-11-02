<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

/** Explains the answer of a live feed request in terms the merchant can act on. */
class FeedProbe
{
    /** @return array{ok: bool, message: string} */
    public function interpret(int $status, string $body): array
    {
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];
        if ($status === 200 && isset($json['total_rows'], $json['total_pages'], $json['snapshot_id'])) {
            return ['ok' => true, 'message' => sprintf(
                'The feed answered: %d rows on %d page(s), snapshot %s, module %s.',
                (int) $json['total_rows'], (int) $json['total_pages'], substr((string) $json['snapshot_id'], 0, 8), (string) ($json['module_version'] ?? 'unknown')
            )];
        }
        $message = match (true) {
            $status === 0 => 'The store URL could not be reached from the server. Check DNS, firewall and that the public URL is correct.',
            $status === 401 => 'The feed rejected the stored token. Check that catalog export is enabled for this store view and that the token is 32 to 128 characters.',
            $status === 400 => 'The feed rejected the store view code.',
            $status === 503 => 'The feed has no usable snapshot. Build one, then test again.',
            $status === 502 || $status === 504 => 'A proxy or CDN in front of the store failed the request. If this started after a login or large response, raise the proxy response header buffer.',
            $status === 403 || $status === 429 => 'A firewall or rate limit in front of the store blocked the request. Allow the Selectika feed reader for /selectika/feed/catalog.',
            $status >= 300 && $status < 400 => 'The store redirected the feed request. Use the final https:// store URL as the base URL.',
            default => sprintf('The feed answered with HTTP %d and an unexpected body.', $status),
        };
        return ['ok' => false, 'message' => $message];
    }
}
