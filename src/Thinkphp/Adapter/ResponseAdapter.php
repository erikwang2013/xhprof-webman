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

    public function withStatus(int $status): self
    {
        $this->response = $this->response->code($status);
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
            $this->response = response($content)->header(['Content-Type' => $type]);
        } else {
            $this->response = response('', 404);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
