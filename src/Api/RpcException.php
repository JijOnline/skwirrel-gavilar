<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Api;

class RpcException extends ApiException
{
    public function __construct(string $message, public readonly int $rpcCode, public readonly mixed $data = null)
    {
        parent::__construct($message, $rpcCode);
    }
}
