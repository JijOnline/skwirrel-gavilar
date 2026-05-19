<?php
declare(strict_types=1);

namespace JijOnline\SkwirrelGavilar\Api;

class ApiException extends \RuntimeException {}
class AuthException extends ApiException {}
class TransportException extends ApiException {}
class RpcException extends ApiException
{
    public function __construct(string $message, public readonly int $rpcCode, public readonly mixed $data = null)
    {
        parent::__construct($message, $rpcCode);
    }
}
