<?php

namespace dnj\FTP\Native;

use dnj\FTP\Contracts\ICommand;
use dnj\FTP\Contracts\IConnection;

class Command implements ICommand
{
    public function __construct(protected IConnection $connection, protected ?string $output, protected bool $isError, protected ?string $error = null)
    {
        if ($isError and is_null($error)) {
            $lastError = error_get_last();
            $this->error = $lastError ? $lastError['message'] : null;
        }
    }

    public function getConnection(): IConnection
    {
        return $this->connection;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
