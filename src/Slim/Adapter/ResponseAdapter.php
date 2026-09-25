<?php

declare(strict_types=1);

namespace ErikWang2013\Xhprof\Slim\Adapter;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use ErikWang2013\Xhprof\Core\Contract\ResponseInterface;
use ErikWang2013\Xhprof\Core\StaticController;

/**
 * 直接适配 PSR-7 的 ResponseInterface —— 不依赖 Slim 的任何类。
 * 用这个适配器的 Slim / nyholm / laminas 等任何 PSR-7 实现都能跑。
 */
class ResponseAdapter implements ResponseInterface
{
    private PsrResponseInterface $response;

    public function __construct(PsrResponseInterface $response)
    {
        $this->response = $response;
    }

    public function withBody(string $body): self
    {
        // 就地写 getBody()，不引入 PSR-17 流工厂（无法保证用户装了哪个实现）。
        //
        // 实测结论（tools/contracts/cases/Slim.php 用真实包钉住）：
        //  - slim/psr7 1.8.0 与 nyholm/psr7 1.8.2 的 getBody() 都返回**可写**流；
        //    write() 之后 `(string) $response->getBody()` 里就能看到内容。
        //  - withHeader()/withStatus() 返回的 clone 与原响应**共享同一个 stream 对象**，
        //    所以写进去的内容跨 clone 存活 —— R-5（file() 之后 withHeaders() 仍生效）
        //    正是靠这一点成立。
        //  - 注意 PSR-7 响应**没有** __toString()，读回内容必须走 getBody()。
        //
        //  - 注意 write() 写的是**当前流指针位置**，既不追加也不截断：上一句写完后指针
        //    停在末尾，所以第二次写看着像追加；若指针回到 0，第二次写是覆盖开头。
        //
        // 代价（已知上限）：因此「每个响应只写一次」是本适配器**真实承载正确性**的不变量
        // （响应体初始为空、指针在 0，写一次即完整内容）。所有调用路径都保证这一点：
        // 报告页 / deny() / file() 三者互斥。
        $this->response->getBody()->write($body);
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $key => $value) {
            $this->response = $this->response->withHeader($key, $value);
        }
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->response = $this->response->withStatus($status);
        return $this;
    }

    public function file(string $path): self
    {
        $file = StaticController::readFile($path);
        if ($file === null) {
            $this->response = $this->response->withStatus(404);
        } else {
            [$content, $type] = $file;
            // 先落 header 再写 body：withHeader() 的 clone 与原响应共享 stream，
            // 顺序其实无关；但写成先 header 后 body 更贴合「一次构造完」的直觉。
            $this->response = $this->response->withHeader('Content-Type', $type);
            $this->response->getBody()->write($content);
        }
        return $this;
    }

    public function send(): mixed
    {
        return $this->response;
    }
}
