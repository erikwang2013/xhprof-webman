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
        $this->response = response($body, $this->response->getCode());
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
