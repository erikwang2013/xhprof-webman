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

    public function file(string $path): self
    {
        $this->response = $this->response->withBody(new SwooleStream(''));
        if (file_exists($path)) {
            $this->response = $this->response->withHeader('X-Sendfile', $path);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
