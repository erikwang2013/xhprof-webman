<?php

declare(strict_types=1);

/**
 * `Psr\Http\Message` 一族接口桩 + 具体 fake。
 *
 * 与 `tests/Stubs/framework-stubs.php` 的分工：那份是「框架 API 最小桩」，本文件专门
 * 放 PSR-7 / PSR-17 —— 它们是跨框架共享的，且**签名必须逐字忠实于规范**。
 * `tools/contracts/cases/Psr7.php`(L0) 会拿真实 `psr/http-message` 做反射逐字段对比，
 * 所以这里宁可啰嗦也不要"差不多"。
 *
 * 覆盖范围只到本包实际用到的面（7 个 PSR-7 接口 + 2 个 PSR-17 工厂），
 * 其余 PSR-17 工厂不声明；**已声明的部分与 psr/http-message 2.0 / psr/http-factory 1.0 逐字一致**。
 *
 * 注意：psr/http-message 任何版本都**没有** `RequestInterface::METHOD_*` 常量（1.1 与 2.0
 * 均已核实），别凭印象补上——补了 L0 会红。
 *
 * 本文件在 Wave 0 冻结。后续框架桩**不得**再声明任何 `Psr\*` 接口：同名重声明是
 * 加载期 fatal（Cannot declare interface），且会让"桩与真实规范不一致"这件事失去哨兵。
 */

namespace Psr\Http\Message {

    interface MessageInterface
    {
        public function getProtocolVersion(): string;

        public function withProtocolVersion(string $version): MessageInterface;

        public function getHeaders(): array;

        public function hasHeader(string $name): bool;

        public function getHeader(string $name): array;

        public function getHeaderLine(string $name): string;

        public function withHeader(string $name, $value): MessageInterface;

        public function withAddedHeader(string $name, $value): MessageInterface;

        public function withoutHeader(string $name): MessageInterface;

        public function getBody(): StreamInterface;

        public function withBody(StreamInterface $body): MessageInterface;
    }

    interface UriInterface
    {
        public function getScheme(): string;

        public function getAuthority(): string;

        public function getUserInfo(): string;

        public function getHost(): string;

        public function getPort(): ?int;

        public function getPath(): string;

        public function getQuery(): string;

        public function getFragment(): string;

        public function withScheme(string $scheme): UriInterface;

        public function withUserInfo(string $user, ?string $password = null): UriInterface;

        public function withHost(string $host): UriInterface;

        public function withPort(?int $port): UriInterface;

        public function withPath(string $path): UriInterface;

        public function withQuery(string $query): UriInterface;

        public function withFragment(string $fragment): UriInterface;

        public function __toString(): string;
    }

    interface StreamInterface
    {
        public function __toString(): string;

        public function close(): void;

        public function detach();

        public function getSize(): ?int;

        public function tell(): int;

        public function eof(): bool;

        public function isSeekable(): bool;

        public function seek(int $offset, int $whence = SEEK_SET): void;

        public function rewind(): void;

        public function isWritable(): bool;

        public function write(string $string): int;

        public function isReadable(): bool;

        public function read(int $length): string;

        public function getContents(): string;

        public function getMetadata(?string $key = null);
    }

    interface RequestInterface extends MessageInterface
    {
        public function getRequestTarget(): string;

        public function withRequestTarget(string $requestTarget): RequestInterface;

        public function getMethod(): string;

        public function withMethod(string $method): RequestInterface;

        public function getUri(): UriInterface;

        public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface;
    }

    interface ServerRequestInterface extends RequestInterface
    {
        public function getServerParams(): array;

        public function getCookieParams(): array;

        public function withCookieParams(array $cookies): ServerRequestInterface;

        public function getQueryParams(): array;

        public function withQueryParams(array $query): ServerRequestInterface;

        public function getUploadedFiles(): array;

        public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface;

        public function getParsedBody();

        public function withParsedBody($data): ServerRequestInterface;

        public function getAttributes(): array;

        public function getAttribute(string $name, $default = null);

        public function withAttribute(string $name, $value): ServerRequestInterface;

        public function withoutAttribute(string $name): ServerRequestInterface;
    }

    interface ResponseInterface extends MessageInterface
    {
        public function getStatusCode(): int;

        public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface;

        public function getReasonPhrase(): string;
    }

    interface UploadedFileInterface
    {
        public function getStream(): StreamInterface;

        public function moveTo(string $targetPath): void;

        public function getSize(): ?int;

        public function getError(): int;

        public function getClientFilename(): ?string;

        public function getClientMediaType(): ?string;
    }

    interface ResponseFactoryInterface
    {
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;
    }

    interface StreamFactoryInterface
    {
        public function createStream(string $content = ''): StreamInterface;

        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface;

        public function createStreamFromResource($resource): StreamInterface;
    }
}

namespace ErikWang2013\Xhprof\Tests\Stubs\Framework {

    use Psr\Http\Message\MessageInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseFactoryInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\StreamFactoryInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UriInterface;

    /**
     * MessageInterface 的公共实现（protocolVersion / headers / body）。
     * 请求与响应共用，避免两份逐字重复的 header 归一化逻辑悄悄跑偏。
     *
     * header 名大小写不敏感（HTTP 语义），但 `getHeaders()` 保留调用方传入的原始大小写。
     */
    trait FakeMessageTrait
    {
        protected string $protocolVersion = '1.1';

        /** @var array<string, list<string>> */
        protected array $headers = [];

        protected StreamInterface $body;

        public function getProtocolVersion(): string
        {
            return $this->protocolVersion;
        }

        public function withProtocolVersion(string $version): MessageInterface
        {
            $clone = clone $this;
            $clone->protocolVersion = $version;

            return $clone;
        }

        public function getHeaders(): array
        {
            return $this->headers;
        }

        public function hasHeader(string $name): bool
        {
            return $this->headerKey($name) !== null;
        }

        public function getHeader(string $name): array
        {
            $key = $this->headerKey($name);

            return $key === null ? [] : $this->headers[$key];
        }

        public function getHeaderLine(string $name): string
        {
            return implode(', ', $this->getHeader($name));
        }

        public function withHeader(string $name, $value): MessageInterface
        {
            $clone = clone $this;
            $existing = $clone->headerKey($name);
            if ($existing !== null) {
                unset($clone->headers[$existing]);
            }
            $clone->headers[$name] = self::normalizeHeaderValue($value);

            return $clone;
        }

        public function withAddedHeader(string $name, $value): MessageInterface
        {
            $clone = clone $this;
            $key = $clone->headerKey($name) ?? $name;
            $clone->headers[$key] = array_merge(
                $clone->headers[$key] ?? [],
                self::normalizeHeaderValue($value)
            );

            return $clone;
        }

        public function withoutHeader(string $name): MessageInterface
        {
            $clone = clone $this;
            $key = $clone->headerKey($name);
            if ($key !== null) {
                unset($clone->headers[$key]);
            }

            return $clone;
        }

        public function getBody(): StreamInterface
        {
            return $this->body;
        }

        public function withBody(StreamInterface $body): MessageInterface
        {
            $clone = clone $this;
            $clone->body = $body;

            return $clone;
        }

        /** @return list<string> */
        private static function normalizeHeaderValue(mixed $value): array
        {
            return array_map('strval', is_array($value) ? array_values($value) : [$value]);
        }

        private function headerKey(string $name): ?string
        {
            foreach (array_keys($this->headers) as $key) {
                if (strcasecmp($key, $name) === 0) {
                    return $key;
                }
            }

            return null;
        }
    }

    /**
     * 内存流。`write()` 一律**追加到末尾**（不按游标插入）——本包的适配器每个响应只写一次，
     * 追加语义正是单测想断言的「多次 write 可累积」。
     */
    class FakeStream implements StreamInterface
    {
        private int $pos = 0;

        public function __construct(private string $content = '')
        {
        }

        public function __toString(): string
        {
            return $this->content;
        }

        public function close(): void
        {
            $this->content = '';
            $this->pos = 0;
        }

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return strlen($this->content);
        }

        public function tell(): int
        {
            return $this->pos;
        }

        public function eof(): bool
        {
            return $this->pos >= strlen($this->content);
        }

        public function isSeekable(): bool
        {
            return true;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            $this->pos = match ($whence) {
                SEEK_CUR => $this->pos + $offset,
                SEEK_END => strlen($this->content) + $offset,
                default => $offset,
            };
        }

        public function rewind(): void
        {
            $this->pos = 0;
        }

        public function isWritable(): bool
        {
            return true;
        }

        public function write(string $string): int
        {
            $this->content .= $string;
            $this->pos = strlen($this->content);

            return strlen($string);
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            $chunk = substr($this->content, $this->pos, $length);
            $this->pos += strlen($chunk);

            return $chunk;
        }

        public function getContents(): string
        {
            $rest = substr($this->content, $this->pos);
            $this->pos = strlen($this->content);

            return $rest;
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    }

    /** 由字符串解析的 URI，`withX()` 返回新实例，`__toString()` 按 RFC 3986 组装回去。 */
    class FakeUri implements UriInterface
    {
        private string $scheme = '';

        private string $userInfo = '';

        private string $host = '';

        private ?int $port = null;

        private string $path = '';

        private string $query = '';

        private string $fragment = '';

        public function __construct(string $uri = '')
        {
            $parts = $uri === '' ? [] : (parse_url($uri) ?: []);

            $this->scheme = (string) ($parts['scheme'] ?? '');
            $this->host = (string) ($parts['host'] ?? '');
            $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
            $this->path = (string) ($parts['path'] ?? '');
            $this->query = (string) ($parts['query'] ?? '');
            $this->fragment = (string) ($parts['fragment'] ?? '');
            if (isset($parts['user'])) {
                $this->userInfo = $parts['user']
                    . (isset($parts['pass']) ? ':' . $parts['pass'] : '');
            }
        }

        public function getScheme(): string
        {
            return $this->scheme;
        }

        public function getAuthority(): string
        {
            if ($this->host === '') {
                return '';
            }

            return ($this->userInfo === '' ? '' : $this->userInfo . '@')
                . $this->host
                . ($this->port === null ? '' : ':' . $this->port);
        }

        public function getUserInfo(): string
        {
            return $this->userInfo;
        }

        public function getHost(): string
        {
            return $this->host;
        }

        public function getPort(): ?int
        {
            return $this->port;
        }

        public function getPath(): string
        {
            return $this->path;
        }

        public function getQuery(): string
        {
            return $this->query;
        }

        public function getFragment(): string
        {
            return $this->fragment;
        }

        public function withScheme(string $scheme): UriInterface
        {
            $clone = clone $this;
            $clone->scheme = $scheme;

            return $clone;
        }

        public function withUserInfo(string $user, ?string $password = null): UriInterface
        {
            $clone = clone $this;
            $clone->userInfo = $user . ($password === null ? '' : ':' . $password);

            return $clone;
        }

        public function withHost(string $host): UriInterface
        {
            $clone = clone $this;
            $clone->host = $host;

            return $clone;
        }

        public function withPort(?int $port): UriInterface
        {
            $clone = clone $this;
            $clone->port = $port;

            return $clone;
        }

        public function withPath(string $path): UriInterface
        {
            $clone = clone $this;
            $clone->path = $path;

            return $clone;
        }

        public function withQuery(string $query): UriInterface
        {
            $clone = clone $this;
            $clone->query = ltrim($query, '?');

            return $clone;
        }

        public function withFragment(string $fragment): UriInterface
        {
            $clone = clone $this;
            $clone->fragment = ltrim($fragment, '#');

            return $clone;
        }

        public function __toString(): string
        {
            $uri = $this->scheme === '' ? '' : $this->scheme . ':';
            $authority = $this->getAuthority();
            $path = $this->path;
            if ($authority !== '') {
                $uri .= '//' . $authority;
                if ($path !== '' && $path[0] !== '/') {
                    $path = '/' . $path;
                }
            }
            $uri .= $path;
            if ($this->query !== '') {
                $uri .= '?' . $this->query;
            }
            if ($this->fragment !== '') {
                $uri .= '#' . $this->fragment;
            }

            return $uri;
        }
    }

    /**
     * 默认 `REMOTE_ADDR = 127.0.0.1`（`getRealIp()` 三档兜底要有个起点），
     * 构造时会按 URI 的 query 填好 `getQueryParams()`。
     */
    class FakeServerRequest implements ServerRequestInterface
    {
        use FakeMessageTrait;

        private string $method;

        private UriInterface $uri;

        /** @var array<string, mixed> */
        private array $serverParams;

        /** @var array<string, mixed> */
        private array $cookieParams = [];

        /** @var array<string, mixed> */
        private array $queryParams = [];

        /** @var array<string, mixed> */
        private array $uploadedFiles = [];

        private mixed $parsedBody = null;

        /** @var array<string, mixed> */
        private array $attributes = [];

        private ?string $requestTarget = null;

        /**
         * @param array<string, mixed>              $serverParams
         * @param array<string, string|list<string>> $headers
         */
        public function __construct(
            string $method = 'GET',
            string $uri = '/',
            array $serverParams = [],
            array $headers = [],
            string $body = ''
        ) {
            $this->method = $method;
            $this->uri = new FakeUri($uri);
            $this->serverParams = array_replace(['REMOTE_ADDR' => '127.0.0.1'], $serverParams);
            $this->body = new FakeStream($body);
            foreach ($headers as $name => $value) {
                $this->headers[$name] = self::normalizeHeaderValue($value);
            }
            parse_str($this->uri->getQuery(), $this->queryParams);
        }

        public function getRequestTarget(): string
        {
            if ($this->requestTarget !== null) {
                return $this->requestTarget;
            }

            $target = $this->uri->getPath() === '' ? '/' : $this->uri->getPath();

            return $this->uri->getQuery() === '' ? $target : $target . '?' . $this->uri->getQuery();
        }

        public function withRequestTarget(string $requestTarget): RequestInterface
        {
            $clone = clone $this;
            $clone->requestTarget = $requestTarget;

            return $clone;
        }

        public function getMethod(): string
        {
            return $this->method;
        }

        public function withMethod(string $method): RequestInterface
        {
            $clone = clone $this;
            $clone->method = $method;

            return $clone;
        }

        public function getUri(): UriInterface
        {
            return $this->uri;
        }

        public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
        {
            $clone = clone $this;
            $clone->uri = $uri;
            if (!$preserveHost && $uri->getHost() !== '') {
                $clone->headers['Host'] = [$uri->getHost()];
            }

            return $clone;
        }

        public function getServerParams(): array
        {
            return $this->serverParams;
        }

        public function getCookieParams(): array
        {
            return $this->cookieParams;
        }

        public function withCookieParams(array $cookies): ServerRequestInterface
        {
            $clone = clone $this;
            $clone->cookieParams = $cookies;

            return $clone;
        }

        public function getQueryParams(): array
        {
            return $this->queryParams;
        }

        public function withQueryParams(array $query): ServerRequestInterface
        {
            $clone = clone $this;
            $clone->queryParams = $query;

            return $clone;
        }

        public function getUploadedFiles(): array
        {
            return $this->uploadedFiles;
        }

        public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
        {
            $clone = clone $this;
            $clone->uploadedFiles = $uploadedFiles;

            return $clone;
        }

        public function getParsedBody()
        {
            return $this->parsedBody;
        }

        public function withParsedBody($data): ServerRequestInterface
        {
            $clone = clone $this;
            $clone->parsedBody = $data;

            return $clone;
        }

        public function getAttributes(): array
        {
            return $this->attributes;
        }

        public function getAttribute(string $name, $default = null)
        {
            return $this->attributes[$name] ?? $default;
        }

        public function withAttribute(string $name, $value): ServerRequestInterface
        {
            $clone = clone $this;
            $clone->attributes[$name] = $value;

            return $clone;
        }

        public function withoutAttribute(string $name): ServerRequestInterface
        {
            $clone = clone $this;
            unset($clone->attributes[$name]);

            return $clone;
        }
    }

    class FakePsrResponse implements ResponseInterface
    {
        use FakeMessageTrait;

        private const REASONS = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
        ];

        private int $statusCode;

        private string $reasonPhrase;

        /**
         * @param array<string, string|list<string>> $headers
         */
        public function __construct(int $statusCode = 200, array $headers = [], string $body = '')
        {
            $this->statusCode = $statusCode;
            $this->reasonPhrase = '';
            $this->body = new FakeStream($body);
            foreach ($headers as $name => $value) {
                $this->headers[$name] = self::normalizeHeaderValue($value);
            }
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }

        public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
        {
            if ($code < 100 || $code > 599) {
                throw new \InvalidArgumentException("Status code {$code} is not in the valid range 100-599");
            }
            $clone = clone $this;
            $clone->statusCode = $code;
            $clone->reasonPhrase = $reasonPhrase;

            return $clone;
        }

        public function getReasonPhrase(): string
        {
            return $this->reasonPhrase !== ''
                ? $this->reasonPhrase
                : (self::REASONS[$this->statusCode] ?? '');
        }
    }

    class FakeResponseFactory implements ResponseFactoryInterface
    {
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
        {
            $response = new FakePsrResponse($code);
            if ($reasonPhrase !== '') {
                $response = $response->withStatus($code, $reasonPhrase);
            }

            return $response;
        }
    }

    class FakeStreamFactory implements StreamFactoryInterface
    {
        public function createStream(string $content = ''): StreamInterface
        {
            return new FakeStream($content);
        }

        public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
        {
            if (!is_file($filename)) {
                throw new \RuntimeException("File {$filename} does not exist");
            }

            return new FakeStream((string) file_get_contents($filename));
        }

        public function createStreamFromResource($resource): StreamInterface
        {
            return new FakeStream((string) stream_get_contents($resource));
        }
    }
}
