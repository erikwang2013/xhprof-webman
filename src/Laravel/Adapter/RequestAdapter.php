<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Laravel\Adapter;

use Illuminate\Http\Request;
use ErikWang2013\Xhprof\Core\Contract\RequestInterface;

class RequestAdapter implements RequestInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Symfony 的 Request::get()：attributes → query → body，query 胜出。
        return $this->request->get($key, $default);
    }

    public function all(): array
    {
        // 框架的 all() 是 **body 胜出**（InteractsWithInput::input() = body + query，
        // `+` 保留左侧），与上面 get() 的 query 优先正好相反：`?run=<合法>` + POST
        // `run=<任意>` 时 get('run') 合法、all()['run'] 任意。Xhprof::index() 两条路都用
        // ——白名单校验走前者、渲染走后者，等于「校验合法值、渲染非法值」。
        // 逐键回读 get()（以框架 all() 的值兜底，顺便带上文件键）收敛到同一条规则，
        // 与 Slim/Yii3/Joomla/WordPress 的 query 优先一致。
        $all = $this->request->all();
        foreach ($all as $key => $value) {
            $all[$key] = $this->request->get((string) $key, $value);
        }

        return $all;
    }

    public function method(): string
    {
        return $this->request->method();
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function host(): string
    {
        return $this->request->getHost();
    }

    public function uri(): string
    {
        return $this->request->getRequestUri();
    }

    public function url(): string
    {
        return $this->request->url();
    }

    public function getRealIp(): string
    {
        return $this->request->ip();
    }
}
