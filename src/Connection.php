<?php

namespace dnj\FTP\Native;

use dnj\FTP\Contracts\IAuthentication;
use dnj\FTP\Contracts\ICommand;
use dnj\FTP\Contracts\IConnection;
use dnj\FTP\Contracts\IException;
use dnj\FTP\Contracts\ModeType;
use dnj\FTP\Native\Exceptions\Exception;

class Connection implements IConnection
{
    /**
     * @return resource|\FTP\Connection
     */
    public static function createResource(string $host, int $port = 21, bool $isSSL = false, ?int $timeout = 90)
    {
        $timeout = $timeout ?? 90;
        $resource = $isSSL ?
            ftp_ssl_connect($host, $port, $timeout) :
            ftp_connect($host, $port, $timeout);

        if (false === $resource) {
            throw Exceptions\ConnectionException::fromLastError(['hostname' => $host, 'port' => $port, 'is_ssl' => $isSSL, 'timeout' => $timeout]);
        }

        return $resource;
    }

    protected string $host;
    protected int $port;
    protected bool $isSSL;
    protected ?int $timeout;
    protected bool $isPassive = false;

    protected ?IAuthentication $authentication = null;

    /**
     * @var resource|\FTP\Connection|null
     */
    protected $resource;

    /**
     * @param resource|\FTP\Connection $resource
     */
    public function __construct(string $host, int $port, bool $isSSL, ?int $timeout, $resource, bool $passive = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->isSSL = $isSSL;
        $this->timeout = $timeout;
        // @phpstan-ignore-next-line
        if (!is_resource($resource) and !$resource instanceof \FTP\Connection) {
            $type = gettype($resource);
            $given = 'object' == $type ?
                'object('.get_class($resource).')' :
                $type;
            throw new Exception($resource, __CLASS__.__FUNCTION__.': Argument #4 ($resource) must be of type resource or object of \FTP\Connection, '.$given.' given in'.__FILE__.':'.__LINE__);
        }
        $this->resource = $resource;
        $this->isPassive = $passive;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        if (!$this->resource) {
            throw new Exception($this, 'resource already closed');
        }

        // @phpstan-ignore-next-line
        return $this->resource;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isPassive(): bool
    {
        return $this->isPassive;
    }

    public function setPassive(bool $isPassive): void
    {
        if (is_null($this->authentication)) {
            throw new Exception($this, 'can not change passive mode of connection before do login');
        }
        $result = ftp_pasv($this->getResource(), $isPassive);
        if (!$result) {
            throw Exception::fromLastError($this);
        }
    }

    public function isSSL(): bool
    {
        return $this->isSSL;
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function login(IAuthentication $authentication): self
    {
        $this->authentication = $authentication->authenticate($this);
        if ($this->isPassive) {
            $this->setPassive($this->isPassive);
        }

        return $this;
    }

    /**
     * @param array<string|number> $commandLine
     */
    public function execute(array $commandLine): ICommand
    {
        $commandLine = implode(' ', array_map([$this, 'escapeArgument'], $commandLine));
        $result = @ftp_raw($this->getResource(), $commandLine);

        $isError = null === $result;

        return new Command(
            $this,
            ($isError ? null : implode(PHP_EOL, $result)),
            $isError
        );
    }

    public function close(): void
    {
        // @phpstan-ignore-next-line
        if ($this->resource and (is_resource($this->resource) or $this->resource instanceof \FTP\Connection)) {
            // @phpstan-ignore-next-line
            ftp_close($this->resource);
            $this->resource = null;
        }
    }

    /**
     * Append the contents of a file to another file on the FTP server.
     *
     * @return static
     *
     * @throws IException
     */
    public function append(string $remoteFileName, string $localFileName, ModeType $mode): self
    {
        $result = ftp_append(
            $this->getResource(),
            $remoteFileName,
            $localFileName,
            $this->modeTypeToNativeConst($mode)
        );
        if (!$result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * Changes to the parent directory.
     *
     * @return static
     *
     * @throws IException
     */
    public function cdup(): self
    {
        $result = ftp_cdup($this->getResource());
        if (!$result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * Changes the current directory on a FTP server.
     *
     * @return static
     *
     * @throws IException
     */
    public function chdir(string $directory): self
    {
        $result = @ftp_chdir($this->getResource(), $directory);
        if (!$result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * Set permissions on a file via FTP.
     *
     * @return static
     *
     * @throws IException
     */
    public function chmod(string $filename, int $permission): self
    {
        $result = ftp_chmod($this->getResource(), $permission, $filename);
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * Deletes a file on the FTP server.
     *
     * @return static
     *
     * @throws IException
     */
    public function delete(string $filename): self
    {
        $result = ftp_delete($this->getResource(), $filename);
        if (!$result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function put(string $remoteFileName, string $data, bool $append, ModeType $mode): self
    {
        $temp = tmpfile();
        if (false === $temp) {
            throw Exception::fromLastError($this);
        }
        fwrite($temp, $data);
        $localFile = stream_get_meta_data($temp)['uri'];

        try {
            if ($append) {
                $this->append($remoteFileName, $localFile, $mode);
            } else {
                $result = ftp_put(
                    $this->getResource(),
                    $remoteFileName,
                    $localFile,
                    $this->modeTypeToNativeConst($mode)
                );
                if (!$result) {
                    throw Exception::fromLastError($this);
                }
            }
        } finally {
            fclose($temp);
        }

        return $this;
    }

    /**
     * @throws IException
     *
     * @param int<0, max>|null $length
     */
    public function get(string $remoteFileName, ModeType $mode, ?int $length = null): string
    {
        $temp = tmpfile();
        if (false === $temp) {
            throw Exception::fromLastError($this);
        }
        $localFile = stream_get_meta_data($temp)['uri'];

        try {
            $result = ftp_get(
                $this->getResource(),
                $localFile,
                $remoteFileName,
                $this->modeTypeToNativeConst($mode),
            );
            if (!$result) {
                throw Exception::fromLastError($this);
            }

            $data = file_get_contents($localFile, false, null, 0, $length);
            if (false === $data) {
                throw Exception::fromLastError($this);
            }

            return $data;
        } finally {
            fclose($temp);
        }
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function mkdir(string $directory, bool $recursive): self
    {
        $mkdir = function (string $d): string {
            $result = @ftp_mkdir($this->getResource(), $d);
            if (false === $result) {
                throw Exception::fromLastError($this);
            }

            return $result;
        };
        if ($recursive) {
            $parts = explode('/', $directory);

            $pwd = $this->pwd();
            foreach ($parts as $part) {
                if (!$part) {
                    continue;
                }
                if (!$this->isDir($part)) {
                    $mkdir($part);
                }
                $this->chdir($part);
            }
            $this->chdir($pwd);
        } else {
            $mkdir($directory);
        }

        return $this;
    }

    /**
     * Returns a list of files in the given directory.
     *
     * @return array<string>|null array of name of files on success or null on failure
     */
    public function nlist(string $dirname): ?array
    {
        $result = ftp_nlist($this->getResource(), $dirname);
        if (false === $result) {
            throw Exception::fromLastError($this);
        }
        $dirnameLen = strlen($dirname);
        $result = array_map(fn (string $path): string => substr($path, $dirnameLen + 1), $result);

        return array_values(
            array_filter(
                array_map(
                    fn (string $path): string => substr($path, $dirnameLen + 1),
                    $result
                ),
                fn (string $name): bool => ('.' != $name and '..' != $name)
            )
        );
    }

    /**
     * Returns a list of files in the given directory.
     *
     * @return array<array{name:string,modify_time:int,size:int,mode:string,type:'dir'|'file'}>|null array of name of files on success or null on failure
     */
    public function ls(string $dirname): ?array
    {
        $result = ftp_mlsd($this->getResource(), $dirname);
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return array_values(array_map(fn (array $item): array => [
            'name' => $item['name'],
            'modify_time' => $item['modify'],
            'size' => $item['size'] ?? $item['sizd'],
            'mode' => $item['UNIX.mode'],
            'type' => $item['type'],
        ], array_filter($result, fn (array $item): bool => '.' != $item['name'] and '..' != $item['name'])));
    }

    /**
     * @throws IException
     */
    public function pwd(): string
    {
        $result = ftp_pwd($this->getResource());
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return $result;
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function rename(string $from, string $to): self
    {
        $result = @ftp_rename($this->getResource(), $from, $to);
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function rmdir(string $directory): self
    {
        $result = ftp_rmdir($this->getResource(), $directory);
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * @throws IException
     */
    public function size(string $filename): int
    {
        $result = ftp_size($this->getResource(), $filename);
        if (-1 == $result) {
            throw Exception::fromLastError($this);
        }

        return $result;
    }

    /**
     * @return array<int|string,int> see the documentation for stat() for details on the values which may be returned
     *
     * @throws IException
     */
    public function stat(string $path): array
    {
        $result = stat($this->getLink($path));
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return $result;
    }

    public function isFile(string $path): bool
    {
        return is_file($this->getLink($path));
    }

    public function isDir(string $path): bool
    {
        return is_dir($this->getLink($path));
    }

    public function fileExists(string $path): bool
    {
        return file_exists($this->getLink($path));
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function upload(string $localFileName, string $remote): self
    {
        $result = ftp_put(
            $this->getResource(),
            $remote,
            $localFileName
        );
        if (false === $result) {
            throw Exception::fromLastError($this);
        }

        return $this;
    }

    /**
     * @return static
     *
     * @throws IException
     */
    public function download(string $remote, string $localFileName): self
    {
        $handle = fopen($localFileName, 'r');
        if (false === $handle) {
            throw Exception::fromLastError($this);
        }

        try {
            $result = ftp_fget(
                $this->getResource(),
                $handle,
                $remote
            );
            if (false === $result) {
                throw Exception::fromLastError($this);
            }
        } finally {
            fclose($handle);
        }

        return $this;
    }

    /**
     * @return array{host:string,port:int,timeout:int|null,isSSL:bool,authentication:IAuthentication|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'isSSL' => $this->isSSL,
            'authentication' => $this->authentication,
        ];
    }

    public function serialize()
    {
        return serialize($this->jsonSerialize());
    }

    public function unserialize(string $data)
    {
        /** @var array{host:string,port:int,isSSL:bool,timeout:int,authentication:IAuthentication|null} */
        $data = unserialize($data);
        $this->host = $data['host'];
        $this->port = $data['port'];
        $this->isSSL = $data['isSSL'];
        $this->timeout = $data['timeout'];
        $this->authentication = $data['authentication'];

        if (null === $this->resource) {
            $this->resource = self::createResource($this->host, $this->port, $this->isSSL, $this->timeout);
        }
    }

    /**
     * Escapes a string to be used as a shell argument.
     */
    protected function escapeArgument(?string $argument): string
    {
        if ('' === $argument || null === $argument) {
            return '""';
        }
        if (str_contains($argument, "\0")) {
            $argument = str_replace("\0", '?', $argument);
        }
        if (!preg_match('/[\/()%!^"<>&|\s]/', $argument)) {
            return $argument;
        }
        /**
         * @var string
         */
        $argument = preg_replace('/(\\\\+)$/', '$1$1', $argument);

        return '"'.str_replace(['"', '^', '%', '!', "\n"], ['""', '"^^"', '"^%"', '"^!"', '!LF!'], $argument).'"';
    }

    protected function getLink(string $path): string
    {
        if (!$this->authentication) {
            throw new Exception($this, 'you must do login before do this action!');
        }

        return 'ftp://'.$this->authentication->getUsername().':'.$this->authentication->getPassword().'@'.$this->host.'/'.ltrim($path, '/');
    }

    protected function modeTypeToNativeConst(ModeType $mode): int
    {
        return match ($mode) {
            ModeType::ASCII => FTP_ASCII,
            ModeType::BINARY => FTP_BINARY,
        };
    }
}
