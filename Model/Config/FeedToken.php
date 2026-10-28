<?php
declare(strict_types=1);

namespace Selectika\AiCommerce\Model\Config;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Exception\LocalizedException;

class FeedToken extends Encrypted
{
    public function beforeSave()
    {
        $value = (string) $this->getValue();
        if ($value !== '' && preg_match('/^\*+$/D', $value) !== 1 && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $value) !== 1) {
            throw new LocalizedException(__('Use a random feed token containing 32–128 letters, numbers, underscores or hyphens.'));
        }
        return parent::beforeSave();
    }
}
