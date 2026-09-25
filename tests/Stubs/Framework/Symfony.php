<?php

declare(strict_types=1);

/**
 * Symfony 桩（仅单测用）。只覆盖 Symfony 适配器与入口类实际触到的 API 面：
 * 三个 Bag、Request/Response/BinaryFileResponse、两个 kernel 事件、事件订阅接口。
 *
 * 保真性不靠这份文件自证：tools/contracts/cases/Symfony.php 用真实的
 * symfony/http-foundation + symfony/http-kernel 跑**同一批输入与断言**，桩若与真实包
 * 语义不一致（返回类型、缺省值、大小写处理），验证环会红。
 *
 * 桩里**没有**建模的真实行为（都不被本包依赖，故不影响结论）：
 *  - Request 的 trusted proxy 解析（getClientIp 只读 REMOTE_ADDR）
 *  - ResponseHeaderBag 对 Cache-Control 的归一化（真实实现会把 'public, max-age=x' 排成
 *    'max-age=x, public'，所以断言用「包含」而不是全等）
 *  - RequestEvent::setResponse() 的 stopPropagation()（单测不起 dispatcher，真实传播
 *    由验证环的真实 EventDispatcher 覆盖）
 *  - ServerBag 的 PHP_AUTH 系列与 Authorization 解码
 */

namespace Symfony\Component\EventDispatcher {
    interface EventSubscriberInterface
    {
        public static function getSubscribedEvents();
    }
}

namespace Symfony\Component\HttpFoundation\Exception {
    interface RequestExceptionInterface
    {
    }

    class UnexpectedValueException extends \UnexpectedValueException
    {
    }

    class BadRequestException extends UnexpectedValueException implements RequestExceptionInterface
    {
    }
}

namespace Symfony\Component\HttpFoundation\File\Exception {
    class FileException extends \RuntimeException
    {
    }

    class FileNotFoundException extends FileException
    {
    }
}

namespace Symfony\Component\HttpFoundation {
    use Symfony\Component\HttpFoundation\Exception\BadRequestException;
    use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

    class ParameterBag
    {
        /** @var array<string, mixed> */
        protected array $parameters;

        public function __construct(array $parameters = [])
        {
            $this->parameters = $parameters;
        }

        public function all(?string $key = null): array
        {
            if ($key === null) {
                return $this->parameters;
            }
            return array_key_exists($key, $this->parameters) ? [$this->parameters[$key]] : [];
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->parameters) ? $this->parameters[$key] : $default;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->parameters);
        }
    }

    class InputBag extends ParameterBag
    {
        /** 真实签名让数组值抛 BadRequestException —— 适配器刻意绕开它（改用 all()） */
        public function get(string $key, mixed $default = null): string|int|float|bool|null
        {
            $value = parent::get($key, $default);
            if (is_array($value)) {
                throw new BadRequestException(sprintf('Input value "%s" contains a non-scalar value.', $key));
            }
            return $value;
        }
    }

    class ServerBag extends ParameterBag
    {
        /** HTTP_* → 头名（真实实现还处理 PHP_AUTH 系列与 Authorization，本包用不到） */
        public function getHeaders(): array
        {
            $headers = [];
            foreach ($this->parameters as $key => $value) {
                if (str_starts_with((string) $key, 'HTTP_')) {
                    $headers[substr((string) $key, 5)] = $value;
                } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && $value !== '') {
                    $headers[$key] = $value;
                }
            }
            return $headers;
        }
    }

    class HeaderBag
    {
        protected const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_';
        protected const LOWER = 'abcdefghijklmnopqrstuvwxyz-';

        /** @var array<string, array<int, string|null>> 键已小写、下划线已转连字符 */
        protected array $headers = [];

        /** @var array<string, string> 归一化键 → 原始大小写 */
        protected array $headerNames = [];

        public function __construct(array $headers = [])
        {
            foreach ($headers as $key => $values) {
                $this->set((string) $key, $values);
            }
        }

        public function all(?string $key = null): array
        {
            if ($key !== null) {
                return $this->headers[strtr($key, self::UPPER, self::LOWER)] ?? [];
            }
            return $this->headers;
        }

        public function get(string $key, ?string $default = null): ?string
        {
            $headers = $this->all($key);
            if (!$headers) {
                return $default;
            }
            if (null === $headers[0]) {
                return null;
            }
            return (string) $headers[0];
        }

        public function set(string $key, string|array|null $values, bool $replace = true): void
        {
            $uniqueKey = strtr($key, self::UPPER, self::LOWER);
            $this->headerNames[$uniqueKey] = $key;

            $values = is_array($values) ? array_values($values) : [$values];
            $this->headers[$uniqueKey] = $replace || !isset($this->headers[$uniqueKey])
                ? $values
                : array_merge($this->headers[$uniqueKey], $values);
        }

        public function has(string $key): bool
        {
            return isset($this->headers[strtr($key, self::UPPER, self::LOWER)]);
        }
    }

    class ResponseHeaderBag extends HeaderBag
    {
    }

    class Request
    {
        public InputBag $query;
        public InputBag $request;
        public InputBag $cookies;
        public ServerBag $server;
        public HeaderBag $headers;

        private mixed $content;

        public function __construct(
            array $query = [],
            array $request = [],
            array $attributes = [],
            array $cookies = [],
            array $files = [],
            array $server = [],
            $content = null
        ) {
            $this->query = new InputBag($query);
            $this->request = new InputBag($request);
            $this->cookies = new InputBag($cookies);
            $this->server = new ServerBag($server);
            $this->headers = new HeaderBag($this->server->getHeaders());
            $this->content = $content;
        }

        /**
         * 复刻真实 create() 里本包会碰到的部分：query string 解析、Host/端口、
         * REQUEST_URI、以及 GET 与 POST 系列对 $parameters 的不同归属。
         */
        public static function create(
            string $uri,
            string $method = 'GET',
            array $parameters = [],
            array $cookies = [],
            array $files = [],
            array $server = [],
            $content = null
        ): static {
            $server = array_replace([
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => 80,
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
                'SCRIPT_NAME' => '',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
            ], $server);

            $server['REQUEST_METHOD'] = strtoupper($method);

            $components = parse_url($uri);
            if ($components === false) {
                throw new BadRequestException('Invalid URI.');
            }

            if (isset($components['host'])) {
                $server['SERVER_NAME'] = $components['host'];
                $server['HTTP_HOST'] = $components['host'];
            }

            if (isset($components['scheme'])) {
                if ('https' === $components['scheme']) {
                    $server['HTTPS'] = 'on';
                    $server['SERVER_PORT'] = 443;
                } else {
                    unset($server['HTTPS']);
                    $server['SERVER_PORT'] = 80;
                }
            }

            if (isset($components['port'])) {
                $server['SERVER_PORT'] = $components['port'];
                $server['HTTP_HOST'] .= ':' . $components['port'];
            }

            $path = $components['path'] ?? '';
            if ($path === '') {
                $path = '/';
            }

            if (in_array($server['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'QUERY', 'PATCH'], true)) {
                $request = $parameters;
                $query = [];
            } else {
                $request = [];
                $query = $parameters;
            }

            $queryString = '';
            if (isset($components['query'])) {
                parse_str(html_entity_decode($components['query']), $qs);
                if ($query) {
                    $query = array_replace($qs, $query);
                    $queryString = http_build_query($query, '', '&');
                } else {
                    $query = $qs;
                    $queryString = $components['query'];
                }
            } elseif ($query) {
                $queryString = http_build_query($query, '', '&');
            }

            $server['REQUEST_URI'] = $path . ('' !== $queryString ? '?' . $queryString : '');
            $server['QUERY_STRING'] = $queryString;

            return new static($query, $request, [], $cookies, $files, $server, $content);
        }

        public function getMethod(): string
        {
            return strtoupper((string) ($this->server->get('REQUEST_METHOD') ?? 'GET'));
        }

        public function getClientIp(): ?string
        {
            // 真实实现还会解析 trusted proxy 的 X-Forwarded-For；无 REMOTE_ADDR 时同样返回 null
            $ip = $this->server->get('REMOTE_ADDR');
            return $ip === null ? null : (string) $ip;
        }

        public function getHost(): string
        {
            $host = $this->headers->get('HOST') ?: $this->server->get('SERVER_NAME') ?: $this->server->get('SERVER_ADDR', '');
            // 去端口 + 小写，与真实实现一致（R-2）
            return strtolower(preg_replace('/:\d+$/', '', trim((string) $host)));
        }

        public function getScheme(): string
        {
            return ($this->server->get('HTTPS') ?? '') === 'on' ? 'https' : 'http';
        }

        public function getHttpHost(): string
        {
            $host = $this->getHost();
            $port = $this->server->get('SERVER_PORT');
            $scheme = $this->getScheme();
            $standard = ($scheme === 'https' && (int) $port === 443) || ($scheme === 'http' && (int) $port === 80);
            return $port && !$standard ? $host . ':' . $port : $host;
        }

        public function getSchemeAndHttpHost(): string
        {
            return $this->getScheme() . '://' . $this->getHttpHost();
        }

        public function getRequestUri(): string
        {
            return (string) $this->server->get('REQUEST_URI', '/');
        }

        /**
         * 路由匹配用的 base path。真实 prepareBaseUrl() 有五个分支（还要比对
         * SCRIPT_FILENAME / PHP_SELF / ORIG_SCRIPT_NAME，并按 URL 编码比前缀），
         * 本包只用得到两种形态 —— 已对 symfony/http-foundation v7.4.19 逐个实测：
         *   SCRIPT_NAME=''                   → ''
         *   SCRIPT_NAME='/subdir/index.php' 且 REQUEST_URI 以它开头 → '/subdir'
         *   SCRIPT_NAME='/index.php' 且 REQUEST_URI 以它开头       → '/index.php'
         */
        public function getBaseUrl(): string
        {
            $script = (string) $this->server->get('SCRIPT_NAME', '');
            if ($script === '') {
                return '';
            }
            $requestUri = $this->pathOnly();
            if (str_starts_with($requestUri, $script)) {
                return $script;
            }
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

            return ($dir !== '' && str_starts_with($requestUri, $dir . '/')) ? $dir : '';
        }

        /**
         * 真实签名 getPathInfo(): string —— REQUEST_URI 丢掉 query 与 baseUrl 之后剩下的那段。
         * 这就是 Drupal 路由**实际匹配**的路径（RequestContext::fromRequest() 用的它），
         * 所以子目录安装（baseUrl='/subdir'）时它与 getRequestUri() 不同。
         */
        public function getPathInfo(): string
        {
            $requestUri = $this->pathOnly();
            $baseUrl = $this->getBaseUrl();
            if ($baseUrl === '') {
                return $requestUri;
            }
            $pathInfo = substr($requestUri, strlen($baseUrl));

            // 真实实现：substr() 给 false 或空串时是 '/'
            return ($pathInfo === false || $pathInfo === '') ? '/' : $pathInfo;
        }

        /** REQUEST_URI 里 query 之前那段，且保证以 '/' 开头（真实 preparePathInfo 的前两步）。 */
        private function pathOnly(): string
        {
            $requestUri = $this->getRequestUri();
            if (false !== $pos = strpos($requestUri, '?')) {
                $requestUri = substr($requestUri, 0, $pos);
            }

            return ($requestUri !== '' && $requestUri[0] !== '/') ? '/' . $requestUri : $requestUri;
        }

        public function getUri(): string
        {
            // 真实实现是 schemeAndHttpHost + baseUrl + pathInfo + '?' + queryString，
            // 无 baseUrl（非重写目录）时与 REQUEST_URI 拼接等价
            return $this->getSchemeAndHttpHost() . $this->getRequestUri();
        }

        public function getContent(): string|false
        {
            return $this->content ?? false;
        }
    }

    class Response
    {
        public ResponseHeaderBag $headers;

        protected string $content;

        protected int $statusCode;

        protected string $statusText;

        public function __construct(?string $content = '', int $status = 200, array $headers = [])
        {
            $this->headers = new ResponseHeaderBag($headers);
            $this->setContent($content);
            $this->setStatusCode($status);
        }

        public function setContent(?string $content): static
        {
            $this->content = $content ?? '';
            return $this;
        }

        public function getContent(): string|false
        {
            return $this->content;
        }

        public function setStatusCode(int $code, ?string $text = null): static
        {
            $this->statusCode = $code;
            if ($code < 100 || $code > 599) {
                throw new \InvalidArgumentException(sprintf('The HTTP status code "%s" is not valid.', $code));
            }
            $this->statusText = $text ?? '';
            return $this;
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }
    }

    class BinaryFileResponse extends Response
    {
        protected string $file;

        public function __construct(\SplFileInfo|string $file, int $status = 200, array $headers = [], bool $public = true)
        {
            $path = $file instanceof \SplFileInfo ? $file->getPathname() : $file;
            if (!is_file($path)) {
                throw new FileNotFoundException('The file "' . $path . '" does not exist.');
            }
            parent::__construct(null, $status, $headers);
            $this->file = $path;
        }

        public function getFile(): \SplFileInfo
        {
            return new \SplFileInfo($this->file);
        }
    }
}

namespace Symfony\Component\HttpKernel {
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;

    interface HttpKernelInterface
    {
        public const MAIN_REQUEST = 1;
        public const SUB_REQUEST = 2;

        public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response;
    }

    final class KernelEvents
    {
        public const REQUEST = 'kernel.request';
        public const RESPONSE = 'kernel.response';
    }
}

namespace Symfony\Component\HttpKernel\Event {
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\HttpKernelInterface;

    class KernelEvent
    {
        private HttpKernelInterface $kernel;
        private Request $request;
        private ?int $requestType;

        public function __construct(HttpKernelInterface $kernel, Request $request, ?int $requestType)
        {
            $this->kernel = $kernel;
            $this->request = $request;
            $this->requestType = $requestType;
        }

        public function getKernel(): HttpKernelInterface
        {
            return $this->kernel;
        }

        public function getRequest(): Request
        {
            return $this->request;
        }

        public function getRequestType(): int
        {
            return $this->requestType;
        }

        public function isMainRequest(): bool
        {
            return HttpKernelInterface::MAIN_REQUEST === $this->requestType;
        }
    }

    class RequestEvent extends KernelEvent
    {
        private ?Response $response = null;

        public function getResponse(): ?Response
        {
            return $this->response;
        }

        /** 真实实现还会 stopPropagation()：单测不起 dispatcher，传播由验证环的真 EventDispatcher 覆盖 */
        public function setResponse(Response $response): void
        {
            $this->response = $response;
        }

        public function hasResponse(): bool
        {
            return null !== $this->response;
        }
    }

    final class ResponseEvent extends KernelEvent
    {
        private Response $response;

        public function __construct(HttpKernelInterface $kernel, Request $request, int $requestType, Response $response)
        {
            parent::__construct($kernel, $request, $requestType);
            $this->response = $response;
        }

        public function getResponse(): Response
        {
            return $this->response;
        }

        public function setResponse(Response $response): void
        {
            $this->response = $response;
        }
    }
}
