<?php

namespace dnj\FTP\Native;

use dnj\FTP\Contracts\IAuthentication;
use dnj\FTP\Contracts\IConnection;
use dnj\FTP\Native\Exceptions\Exception;

class Authentication implements IAuthentication
{
    protected string $username;
    protected string $password;

    public function __construct(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function authenticate(IConnection $connection): self
    {
        if (!$connection instanceof Connection) {
            throw new Exception('connection ['.get_class($connection).'] is unsupported, only '.Connection::class.' is supported');
        }

        $result = ftp_login($connection->getResource(), $this->username, $this->password);
        if (!$result) {
            throw Exceptions\AuthenticationException::fromLastError($this);
        }

        return $this;
    }

    /**
     * @return array{username:string,password:string}
     */
    public function jsonSerialize(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    public function serialize()
    {
        return serialize($this->jsonSerialize());
    }

    public function unserialize(string $data)
    {
        /** @var array{username:string,password:string} $data */
        $data = unserialize($data);
        $this->username = $data['username'];
        $this->password = $data['password'];
    }
}
