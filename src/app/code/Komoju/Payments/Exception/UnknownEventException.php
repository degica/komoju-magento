<?php

namespace Komoju\Payments\Exception;

class UnknownEventException extends \Magento\Framework\Exception\LocalizedException {
    public $statusCode;
    
    public function __construct($msg, $statusCode = 400)
    {
        $this->statusCode = $statusCode;
        if (is_string($msg))
            parent::__construct(__($msg));
        else
            parent::__construct($msg);
    }
}