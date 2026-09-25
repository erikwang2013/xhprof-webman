<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Joomla\Adapter;

use Joomla\CMS\Application\CMSApplicationInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;

/**
 * 契约 ResponseInterface → Joomla 应用。
 *
 * Joomla 没有「响应对象」可交给调用方去 send：插件的输出手段就是
 * setHeader() + sendHeaders() + echo。故这里先把 withX() 的状态攒着，
 * 到 send() 一次性落到应用上。
 *
 * **send() 不 close()**：终止请求由入口类在拿到 send() 之后显式做一次。
 * Core 的 deny() 会在 Xhprof::index() 内部调 send()，如果 send() 自己 close，
 * 入口类就再也回不去，也就无法在「报告页短路」这一个地方统一收口。
 * close() 在真实 CMS 里是 exit()（AbstractApplication::close()，CMS 从未覆写），
 * 多调一次不会有事，但「谁终止请求」只该有一个答案。
 */
class ResponseAdapter implements ResponseInterface
{
    private CMSApplicationInterface $app;

    private string $body = '';

    /** @var array<string, string> */
    private array $headers = [];

    private int $status = 200;

    public function __construct(CMSApplicationInterface $app)
    {
        $this->app = $app;
    }

    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function file(string $path): self
    {
        // 立刻读出内容，而不是记下路径延后到 send()：
        // 契约 R-5 要求 file() 之后 withHeaders() 仍然生效，这里紧接着写入
        // body 与 Content-Type，两种调用顺序天然等价。
        $file = StaticController::readFile($path);
        if ($file === null) {
            return $this->withStatus(404);
        }
        [$content, $type] = $file;
        return $this->withBody($content)->withHeaders(['Content-Type' => $type]);
    }

    public function send(): mixed
    {
        foreach ($this->headers as $name => $value) {
            $this->app->setHeader((string) $name, (string) $value, true);
        }

        if ($this->status !== 200) {
            // Joomla 的状态码走 'Status' 这个头：sendHeaders() 会特判它（用数字查状态
            // 短语表），即便不特判，SAPI 也把 Status 头当状态行用。故只传数字，不传短语。
            $this->app->setHeader('Status', (string) $this->status, true);
        }

        $this->app->sendHeaders();

        // 插件的输出就是 echo：Joomla 没有可以 return 响应的地方（我们停在
        // onAfterInitialise，组件的渲染根本不会发生）。
        echo $this->body;

        return null;
    }
}
