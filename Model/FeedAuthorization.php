<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model;

class FeedAuthorization
{
    public function accepts(string $authorization, string $token, bool $secure): bool
    {
        if (!$secure || preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token) !== 1) {
            return false;
        }
        if (preg_match('/^Bearer ([A-Za-z0-9_-]{32,128})$/D', $authorization, $match) !== 1) {
            return false;
        }
        return hash_equals($token, $match[1]);
    }
}
