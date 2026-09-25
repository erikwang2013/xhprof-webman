<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private $response;

    public function __construct($response = null)
    {
        $this->response = $response ?? response('');
    }

    public function withBody(string $body): self
    {
        // 就地改：`response($body, $code)` 会重建响应，把此前 withHeaders() 设的头丢掉。
        // setContent() 来自 Symfony 的 Response（Illuminate\Http\Response 覆写了它，
        // 返回 $this），与 Drupal/Symfony 两个适配器的 withBody() 是同一个调用。
        $this->response = $this->response->setContent($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->withHeaders($headers);
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->response = $this->response->setStatusCode($status);
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
