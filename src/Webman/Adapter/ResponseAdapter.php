<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Webman\Adapter;

use Webman\Http\Response;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    public function withBody(string $body): self
    {
        $this->response = $this->response->withBody($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->withHeaders($headers);
        return $this;
    }

    public function withStatus(int $status): self
    {
        // 就地改：workerman 的 Response::withStatus() 改的就是 $this 并返回它。
        // `new Response($status)` 会把此前 withBody()/withHeaders() 攒下的正文与头全丢掉
        // （R-5 的同类问题：先设头再设状态时 Cache-Control 静默消失）。
        $this->response = $this->response->withStatus($status);
        return $this;
    }

    public function file(string $path): self
    {
        $this->response = response()->file($path);
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
