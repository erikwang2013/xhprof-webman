<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\HttpServer\Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
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
        $this->response = $this->response->withBody(new SwooleStream($body));
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->response = $this->response->withHeader($key, $value);
        }
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->response = $this->response->withStatus($status);
        return $this;
    }

    public function file(string $path): self
    {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $type = [
                'css' => 'text/css',
                'js' => 'application/javascript',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
            ][$ext] ?? 'application/octet-stream';
            $this->response = $this->response
                ->withBody(new SwooleStream($content))
                ->withHeader('Content-Type', $type);
        } else {
            $this->response = $this->response->withStatus(404);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
