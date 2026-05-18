<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface ResponseInterface
{
    public function withBody(string $body): self;
    public function withHeaders(array $headers): self;
    public function file(string $path): self;
    public function send(): mixed;
}
