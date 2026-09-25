<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Symfony\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(?Response $response = null)
    {
        $this->response = $response ?? new Response();
    }

    public function withBody(string $body): self
    {
        $this->response->setContent($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            // HeaderBag::set() 声明 string|array|null，strict_types 下传 int 会 TypeError
            $this->response->headers->set((string) $name, is_array($value) ? $value : (string) $value);
        }
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->response->setStatusCode($status);
        return $this;
    }

    public function file(string $path): self
    {
        // Content-Type 必须自己钉，不能交给 BinaryFileResponse::prepare() 猜：它走 Mime 组件
        // 的**内容**嗅探，实测本包的 css/js 全被猜成 text/plain（jquery.autocomplete.js 甚至
        // 是 text/x-Algol68），浏览器就会丢弃样式表/脚本。类型统一取 StaticController 的表
        // —— 与 Slim/Yii3/Wordpress 三个适配器同源，四家输出一致。
        // （readFile() 会把内容读出来只用它的类型，多一次读；换来的是不复制 Core 的 MIME 表。）
        $file = StaticController::readFile($path);
        if ($file === null) {
            // 与另外三个适配器同形：读不出文件就退化成 404，而不是抛 FileNotFoundException
            // （那会变成 500，且异常要穿到 HttpKernel 之外）
            $this->response = new Response('', 404);
            return $this;
        }

        // 注意 BinaryFileResponse 替换掉整个响应对象，file() 之前设的 status/body 不保留；
        // 之后的 withHeaders() 仍然生效（R-5 的调用点是 file()->withHeaders()）。
        $this->response = new BinaryFileResponse($path);
        $this->response->headers->set('Content-Type', $file[1]);
        return $this;
    }

    public function send(): mixed
    {
        // 返回响应对象而不是 echo：Symfony 由 public/index.php 统一 send()，
        // 监听器只负责 RequestEvent::setResponse()；这里再 send 一次会输出两遍。
        return $this->response;
    }
}
