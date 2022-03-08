<?php

namespace dnj\FTP\Native;

use dnj\FTP\Contracts\IConnector;
use dnj\FTP\Native\Exceptions\Exception;

class Connector implements IConnector
{
    public function __construct()
    {
        if (!extension_loaded('ftp')) {
            throw new Exception("php's 'ftp' extension is required to use this connector");
        }
    }

    public function connect(string $host, int $port = 21, bool $isSSL = false, ?int $timeout = 90, bool $passive = false): Connection
    {
        return new Connection($host, $port, $isSSL, $timeout, Connection::createResource($host, $port, $isSSL, $timeout), $passive);
    }
}
