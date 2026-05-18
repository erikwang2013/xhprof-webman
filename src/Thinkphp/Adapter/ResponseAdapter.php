<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Thinkphp\Adapter;

use think\Response;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;

class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(?Response $response = null)
    {
        $this->response = $response ?? response('');
    }

    public function withBody(string $body): self
    {
        $this->response = response($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->response = $this->response->header($headers);
        return $this;
    }

    public function file(string $path): self
    {
        $this->response = download($path, '');
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
