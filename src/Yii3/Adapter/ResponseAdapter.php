<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii3\Adapter;

use Psr\Http\Message\ResponseFactoryInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;

/**
 * PSR-7 响应适配器。
 *
 * 与其余框架不同：PSR-15 中间件拿不到「框架正在构造的那个响应对象」，Yii3 也没有
 * 全局 response()。所以本适配器**不包装任何已有响应**，而是把状态（status/headers/body）
 * 缓存在自己身上，到 send() 时才用注入的 ResponseFactoryInterface 造一个新响应返回。
 *
 * 中间件的契约据此调整：把 send() 的返回值直接作为 process() 的返回值（PSR-15 就是
 * 靠返回值传响应的），因此这里返回的必须是**真实可用的 PSR-7 响应**，不是被包装的对象。
 */
class ResponseAdapter implements ResponseInterface
{
    private int $status = 200;

    /** @var array<string, string|string[]> */
    private array $headers = [];

    private string $body = '';

    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function withBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * R-5：header 在 send() 时才落到响应上，所以 `file($f)->withHeaders([...])`
     * 两个调用谁先谁后都生效——file() 不会用「重建响应」把先设的 header 冲掉。
     * 同名 header 后写的覆盖先写的（不是追加），与其余框架适配器一致。
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = $value;
        }

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function file(string $path): self
    {
        $file = StaticController::readFile($path);
        if ($file === null) {
            $this->status = 404;
            $this->body = '';

            return $this;
        }

        [$content, $type] = $file;
        $this->body = $content;
        $this->headers['Content-Type'] = $type;

        return $this;
    }

    /**
     * R-4：`withStatus()->withBody()->send()` 是 Xhprof::deny() 的调用链，
     * 这里每次 send() 都用当前状态造一个新响应（不缓存、不复用），所以链式调用
     * 一次性生效，也不需要调用方重置状态。
     *
     * 注意：body 是**写进** factory 造出来的空响应体（createResponse() 的 body
     * 是空且可写的流），不引入 PSR-17 的 StreamFactory。每个响应只写一次，
     * 不依赖流的追加语义。
     */
    public function send(): mixed
    {
        $response = $this->responseFactory->createResponse($this->status);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if ($this->body !== '') {
            $response->getBody()->write($this->body);
        }

        return $response;
    }
}
