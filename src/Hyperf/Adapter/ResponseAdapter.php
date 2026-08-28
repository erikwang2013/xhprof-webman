<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Hyperf\Adapter;

use Hyperf\HttpServer\Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;

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
        $file = StaticController::readFile($path);
        if ($file === null) {
            $this->response = $this->response->withStatus(404);
        } else {
            [$content, $type] = $file;
            $this->response = $this->response
                ->withBody(new SwooleStream($content))
                ->withHeader('Content-Type', $type);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
