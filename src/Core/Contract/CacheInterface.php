<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Core\Contract;

interface CacheInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): bool;
    public function incr(string $key): int;
    public function lPush(string $key, mixed $value): int;
    public function rPop(string $key): mixed;
    public function lRange(string $key, int $start, int $end): array;
    public function del(string ...$keys): int;
    public function decr(string $key): int;
}
