<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Wordpress\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;

/**
 * WordPress 响应适配器：不持有任何框架对象，`send()` 直接向 SAPI 输出。
 *
 * WordPress 没有「框架接管返回值」这一层（没有 PSR-7、没有 Response 对象），
 * 插件输出响应只能自己发状态行 + 头 + 体，所以 `withX()` 攒状态、`send()` 一次吐出。
 * body / headers / status 三个字段分开存，`file()` 之后再 `withHeaders()` 才不会被覆盖
 * （`StaticController::serve()` 的调用是 `file($realFile)->withHeaders([...])`）。
 */
class ResponseAdapter implements ResponseInterface
{
    private string $body = '';

    /** @var array<string, string> */
    private array $headers = [];

    private int $status = 200;

    public function withBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function file(string $path): self
    {
        // 与 Hyperf 适配器同形：读不出文件就退化成 404，MIME 由 StaticController 推断。
        $file = StaticController::readFile($path);
        if ($file === null) {
            $this->status = 404;
            $this->body = '';

            return $this;
        }

        [$content, $type] = $file;
        $this->body = $content;
        $this->headers['Content-Type'] = $type;

        return $this;
    }

    /**
     * 输出到 SAPI。
     *
     * 返回 null 是刻意的：`Xhprof::deny()` 把本方法的返回值一路透传给 `Xhprof::index()`
     * 的调用者，返回 body 字符串会让调用方再输出一遍（403 页面被 200 覆盖）。
     * 返回值恰好为「已自行输出」这个事实留了标记——调用方只需判断 `is_string()`。
     */
    public function send(): mixed
    {
        // 已经吐过输出（含被 PHPUnit 写过 stdout）时调 header() 会报
        // "Cannot modify header information" 警告，phpunit 的 failOnWarning 会直接判失败。
        if (!headers_sent()) {
            status_header($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;

        return null;
    }
}
