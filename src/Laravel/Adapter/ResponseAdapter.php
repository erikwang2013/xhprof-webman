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
        $this->response = response($body, $this->response->getStatusCode());
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
