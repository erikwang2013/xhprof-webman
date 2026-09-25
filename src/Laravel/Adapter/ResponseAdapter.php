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
        // 走 Symfony 的 HeaderBag，而不是 Illuminate 的 `withHeaders()`：
        // 后者是 `ResponseTrait` 给的，只挂在 `Illuminate\Http\Response` 上，
        // 而**本适配器的 `file()`** 返回的是 Laravel `ResponseFactory::file()` 造的
        // `new BinaryFileResponse($file, 200, $headers)` —— 一个纯 Symfony 类，
        // `method_exists(..., 'withHeaders') === false`。于是 StaticController 里那句
        // `$response->file($path)->withHeaders([...])` 在真 Laravel 上直接
        // `Error: Call to undefined method`（资源请求 500，报告页无 CSS/JS）。
        // `headers` 是 Symfony Response 的属性，两边的类都有；`set()` 就地改，
        // 与 withBody()/withStatus() 的语义一致。
        foreach ($headers as $name => $value) {
            $this->response->headers->set((string) $name, (string) $value);
        }
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
