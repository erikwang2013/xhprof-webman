<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\Response;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(?Response $response = null)
    {
        $this->response = $response ?? response('');
    }

    public function withBody(string $body): self
    {
        // 就地改：`response($body, $code)` 会重建响应，把此前 header() 设的头丢掉。
        // content() 是 think\Response 的内容设置器（改 $this 并返回它）。
        $this->response = $this->response->content($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->header($headers);
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->response = $this->response->code($status);
        return $this;
    }

    public function file(string $path): self
    {
        $file = StaticController::readFile($path);
        if ($file === null) {
            $this->response = response('', 404);
        } else {
            [$content, $type] = $file;
            $this->response = response($content)->header(['Content-Type' => $type]);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
