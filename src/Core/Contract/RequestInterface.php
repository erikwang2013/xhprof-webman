<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface RequestInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function all(): array;
    public function method(): string;
    public function header(string $name): ?string;
    public function host(): string;
    public function uri(): string;
    public function url(): string;
    public function getRealIp(): string;
}
