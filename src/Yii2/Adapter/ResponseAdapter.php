<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Yii2\Adapter;

use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;
use yii\web\Response;

/**
 * Yii2 响应适配器：包装**应用自己那个** `yii\web\Response`（`Yii::$app->getResponse()`）。
 *
 * 为什么不新建一个 `new Response()`：
 *  1. 错误处理器写的是应用响应（`web/ErrorHandler::renderException()`），两个响应对象会分叉；
 *  2. `Application::end($status, $response = null)` 的兜底是 `$this->getResponse()`
 *     （`base/Application.php:656`）——包装活对象时，即使适配器返回了错的东西，发出去的
 *     仍是同一个响应；
 *  3. 应用可以在 `components.response` 里配 charset / formatters / version，新建的就全丢了。
 * 代价是短路路径上会改到应用响应对象——但短路必然终止请求（`end()` 不返回），改动不外溢。
 *
 * `send()` **不发送**：Yii2 的发送动作属于 `Application::end()`（它还会顺带触发
 * `EVENT_AFTER_REQUEST`、接管状态机与 `exit`），本方法只把准备好的响应对象交还调用方。
 * 与 Yii3/Symfony 两家的 `send()` 语义同构。
 */
class ResponseAdapter implements ResponseInterface
{
    private Response $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
    }

    /**
     * R-4：`withStatus()->withBody()->send()` 是 `Xhprof::deny()` 的调用链，故一律 `return $this`。
     */
    public function withBody(string $body): self
    {
        // 四件事必须一起做：Yii2 的 `prepare()`（`web/Response.php:1099`）在 `data !== null`
        // 时会把 data 覆盖到 content，然后 `is_array($content)` 直接抛
        // InvalidArgumentException —— 应用只要在 bootstrap 里设过 `Yii::$app->response->data`，
        // 报告页就会 500；`stream !== null` 时 prepare() 直接 return、sendContent() 又会
        // 走流分支，正文被丢弃。
        // `format = FORMAT_RAW` 也是必需的：默认的 `html` 会走 `HtmlResponseFormatter`，
        // 它**无条件** `set('Content-Type', ...)`（`web/HtmlResponseFormatter.php:37`），
        // 把静态资源的 text/css 覆盖成 text/html（报告页变纯文本、样式表被浏览器拒收）。
        // 而 `raw` 不在 `defaultFormatters()` 里（`web/Response.php:1072`），走 prepare()
        // 的 elseif 分支，一个头都不碰。
        $this->response->content = $body;
        $this->response->format = Response::FORMAT_RAW;
        $this->response->data = null;
        $this->response->stream = null;

        return $this;
    }

    /**
     * R-5：header 是写在响应对象的 HeaderCollection 上的（不是重建响应），所以
     * `file($f)->withHeaders([...])` 两个调用谁先谁后都生效，同名后写覆盖先写。
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            // 数组值原样交给 HeaderCollection：它的 set() 内部就是 `(array) $value`，
            // 先 `(string)` 会变成 "Array" 并触发一次 PHP warning（单测里 failOnWarning 直接判失败）。
            $this->response->getHeaders()->set((string) $name, is_array($value) ? $value : (string) $value);
        }

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->response->setStatusCode($status);

        return $this;
    }

    public function file(string $path): self
    {
        // 与 Hyperf / WordPress / Native / Yii3 四家同形：读不出文件就退化成 404，
        // MIME 由 Core 的 MIME_TYPES 唯一一张表推断（StaticController::contentType）。
        $file = StaticController::readFile($path);
        if ($file === null) {
            return $this->withStatus(404)->withBody('');
        }

        [$content, $type] = $file;

        return $this->withBody($content)->withHeaders(['Content-Type' => $type]);
    }

    /**
     * 返回准备好的 `yii\web\Response`，由调用方交给 `Application::end()` 发送。
     *
     * 返回值同时是「调用方该发这个对象」的凭证：`Xhprof::deny()` 把本方法的返回值一路透传，
     * 入口类据此判断「403/400 已经由适配器写好了」。
     */
    public function send(): mixed
    {
        return $this->response;
    }
}
