<?php

declare(strict_types=1);

/**
 * L0 —— 桩保真：`tests/Stubs/Framework/Psr7.php` 的接口签名 vs 真实 `psr/http-message`。
 *
 * 两件事：
 *  1) 反射对比。必须在**两个互不相干的子进程**里各做一次：我们的桩与真实包声明了同名接口，
 *     同进程加载 = 加载期 fatal（Cannot declare interface）。本 case 进程自己两个都不加载，
 *     只负责起子进程 + 比 JSON。
 *  2) fake 行为。这些 fake 是 Wave 1 六个 agent 单测的共同底座，"签名对但跑不起来"会让
 *     六个 agent 同时卡住，所以在冻结它的这一波就把行为钉死。
 *
 * 覆盖不到：`src/` 适配器**调用**的方法是否真实存在（那是 L1/L2 的事）。
 */

return static function (): array {
    $interfaces = [
        'Psr\Http\Message\MessageInterface',
        'Psr\Http\Message\UriInterface',
        'Psr\Http\Message\StreamInterface',
        'Psr\Http\Message\RequestInterface',
        'Psr\Http\Message\ServerRequestInterface',
        'Psr\Http\Message\ResponseInterface',
        'Psr\Http\Message\UploadedFileInterface',
        'Psr\Http\Message\ResponseFactoryInterface',
        'Psr\Http\Message\StreamFactoryInterface',
    ];

    $stub = contracts_repo_root() . '/tests/Stubs/Framework/Psr7.php';
    $realAutoload = contracts_dir() . '/vendor/autoload.php';
    $dumper = contracts_dir() . '/lib/dump.php';

    if (!is_file($stub)) {
        return ['status' => 'FAIL', 'detail' => "桩文件不存在：{$stub}", 'skips' => 0];
    }
    if (!is_file($realAutoload)) {
        // 缺包必须 FAIL 而不是 SKIP：装不上真实包就证明不了任何事，SKIP 会让 CI 假绿。
        return [
            'status' => 'FAIL',
            'detail' => 'tools/contracts/vendor 未安装，先跑 composer install -d tools/contracts',
            'skips' => 0,
        ];
    }

    // ---- 1) 反射对比 ----

    $ours = contracts_run_php(array_merge([$dumper, $stub], $interfaces));
    $real = contracts_run_php(array_merge([$dumper, $realAutoload], $interfaces));

    foreach (['桩侧' => $ours, '真实包侧' => $real] as $side => $r) {
        $decoded = json_decode(trim($r['stdout']), true);
        if (!is_array($decoded) || ($decoded['missing'] ?? []) !== []) {
            return [
                'status' => 'FAIL',
                'detail' => "{$side}反射子进程没有给出完整 JSON（exit {$r['code']}）："
                    . trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout']),
                'skips' => 0,
            ];
        }
    }

    $oursJson = json_decode(trim($ours['stdout']), true);
    $realJson = json_decode(trim($real['stdout']), true);

    $diffs = [];
    contracts_diff($oursJson['interfaces'], $realJson['interfaces'], '', $diffs);

    if ($diffs !== []) {
        return [
            'status' => 'FAIL',
            'detail' => count($diffs) . " 处签名与真实 PSR 规范不一致：\n  - " . implode("\n  - ", array_slice($diffs, 0, 15)),
            'skips' => 0,
        ];
    }

    // ---- 2) fake 行为 ----

    $fakeCheck = contracts_run_php(['-r', contracts_fakes_check_script($stub)]);
    $fakeResult = json_decode(trim($fakeCheck['stdout']), true);

    if (!is_array($fakeResult) || !isset($fakeResult['checks'])) {
        return [
            'status' => 'FAIL',
            'detail' => "fake 行为子进程没有给出 JSON（exit {$fakeCheck['code']}）："
                . trim($fakeCheck['stderr'] !== '' ? $fakeCheck['stderr'] : $fakeCheck['stdout']),
            'skips' => 0,
        ];
    }

    if ($fakeResult['failures'] !== []) {
        return [
            'status' => 'FAIL',
            'detail' => "fake 行为 " . count($fakeResult['failures']) . ' 项不符：'
                . implode('；', array_slice($fakeResult['failures'], 0, 10)),
            'skips' => 0,
        ];
    }

    return [
        'status' => 'PASS',
        'detail' => count($interfaces) . ' 个接口签名逐字段一致；'
            . $fakeResult['checks'] . ' 项 fake 行为通过',
        'skips' => 0,
    ];
};

/**
 * 逐字段递归比两份反射快照，差异写进 &$out（形如 `方法.getHeaderLine.params.0.type: 桩="string" 真实="int"`）。
 *
 * @param array<mixed> $a
 * @param array<mixed> $b
 * @param list<string> $out
 */
function contracts_diff(array $a, array $b, string $path, array &$out): void
{
    foreach ($a as $key => $value) {
        $where = $path . '.' . $key;
        if (!array_key_exists($key, $b)) {
            $out[] = "{$where}: 只在桩里存在";
            continue;
        }
        if (is_array($value)) {
            contracts_diff($value, $b[$key], $where, $out);
        } elseif ($value !== $b[$key]) {
            $out[] = "{$where}: 桩=" . json_encode($value, JSON_UNESCAPED_SLASHES)
                . ' 真实=' . json_encode($b[$key], JSON_UNESCAPED_SLASHES);
        }
    }
    foreach ($b as $key => $value) {
        if (!array_key_exists($key, $a)) {
            $out[] = $path . '.' . $key . ': 只在真实包里存在';
        }
    }
}

/**
 * 生成「fake 行为自检」脚本（在只加载桩文件的子进程里跑）。
 *
 * 故意不用 `assert()`：本机 `zend.assertions=-1` 时 assert 会被整个编译掉，
 * 写成一堆 assert 的自检会在什么都没跑的情况下打印"通过"。
 */
function contracts_fakes_check_script(string $stub): string
{
    return '$stub = ' . var_export($stub, true) . ';' . <<<'PHP'

require $stub;

use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakePsrResponse;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeResponseFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeServerRequest;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeStream;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeStreamFactory;
use ErikWang2013\Xhprof\Tests\Stubs\Framework\FakeUri;

$checks = 0;
$failures = [];
$expect = function (string $label, mixed $actual, mixed $expected) use (&$checks, &$failures): void {
    $checks++;
    if ($actual !== $expected) {
        $failures[] = $label . '：得到 ' . var_export($actual, true) . '，期望 ' . var_export($expected, true);
    }
};

$request = new FakeServerRequest(
    'POST',
    'http://example.com:8080/admin?x=1&y=2',
    ['REMOTE_ADDR' => '10.0.0.9'],
    ['X-Foo' => 'bar'],
    'read-body'
);
$expect('默认构造可实例化', (new FakeServerRequest())->getMethod(), 'GET');
$expect('默认 query 为空', (new FakeServerRequest())->getQueryParams(), []);
$expect('默认 REMOTE_ADDR', (new FakeServerRequest())->getServerParams(), ['REMOTE_ADDR' => '127.0.0.1']);
$expect('getMethod', $request->getMethod(), 'POST');
$expect('getUri()->getHost()', $request->getUri()->getHost(), 'example.com');
$expect('getUri()->getPort()', $request->getUri()->getPort(), 8080);
$expect('getUri()->getPath()', $request->getUri()->getPath(), '/admin');
$expect('query 自动填 getQueryParams()', $request->getQueryParams(), ['x' => '1', 'y' => '2']);
$expect('getHeaderLine 大小写不敏感', $request->getHeaderLine('x-foo'), 'bar');
$expect('getHeaderLine 缺省返回空串', $request->getHeaderLine('Nope'), '');
$expect('getHeader 缺省返回 []', $request->getHeader('Nope'), []);
$expect('getHeaders 保留原始大小写', array_keys($request->getHeaders()), ['X-Foo']);
$expect('hasHeader 大小写不敏感', $request->hasHeader('X-FOO'), true);
$expect('getBody 可读', (string) $request->getBody(), 'read-body');

$withQuery = $request->withQueryParams(['a' => 'b']);
$expect('withQueryParams 返回新实例', $withQuery !== $request, true);
$expect('withQueryParams 不改原实例', $request->getQueryParams(), ['x' => '1', 'y' => '2']);
$expect('withQueryParams 改新实例', $withQuery->getQueryParams(), ['a' => 'b']);
$expect('withHeader 返回新实例', $request->withHeader('A', '1') !== $request, true);
$expect('withHeader 不改原实例', $request->hasHeader('A'), false);
$expect('withAddedHeader 追加', $request->withAddedHeader('X-Foo', 'baz')->getHeader('x-foo'), ['bar', 'baz']);
$expect('withoutHeader', $request->withoutHeader('x-foo')->hasHeader('X-Foo'), false);
$expect('withMethod', $request->withMethod('PUT')->getMethod(), 'PUT');
$expect('withAttribute/getAttribute', $request->withAttribute('k', 'v')->getAttribute('k'), 'v');
$expect('getAttribute 缺省', $request->getAttribute('k', 'fallback'), 'fallback');
$expect('withParsedBody/getParsedBody', $request->withParsedBody(['p' => 1])->getParsedBody(), ['p' => 1]);
$expect('withUri 更新 Host', $request->withUri(new FakeUri('http://other.test/'))->getHeaderLine('host'), 'other.test');
$expect('保留端口地串回 URI', (string) $request->getUri(), 'http://example.com:8080/admin?x=1&y=2');

$response = new FakePsrResponse(403);
$response->getBody()->write('a');
$response->getBody()->write('b');
$expect('getBody()->write() 可累积', (string) $response->getBody(), 'ab');
$expect('getStatusCode', $response->getStatusCode(), 403);
$expect('getReasonPhrase 由状态码推出', $response->getReasonPhrase(), 'Forbidden');
$withStatus = $response->withStatus(404);
$expect('withStatus 返回新实例', $withStatus !== $response, true);
$expect('withStatus 不改原实例', $response->getStatusCode(), 403);
$expect('withStatus 改新实例', $withStatus->getStatusCode(), 404);
$expect('withStatus 后的 reason', $withStatus->getReasonPhrase(), 'Not Found');
$expect('显式 reason 优先', $response->withStatus(200, 'Fine')->getReasonPhrase(), 'Fine');
$expect('响应 withHeader', $response->withHeader('X-A', '1')->getHeaderLine('x-a'), '1');
$thrown = null;
try {
    $response->withStatus(99);
} catch (\InvalidArgumentException) {
    $thrown = true;
}
$expect('非法状态码抛异常', $thrown, true);

$expect('ResponseFactory', (new FakeResponseFactory())->createResponse(500)->getStatusCode(), 500);
$expect('StreamFactory', (string) (new FakeStreamFactory())->createStream('hi'), 'hi');
$expect('StreamFactory::createStreamFromFile', (string) (new FakeStreamFactory())->createStreamFromFile($stub), file_get_contents($stub));

$stream = new FakeStream('hello');
$expect('FakeStream::getSize', $stream->getSize(), 5);
$expect('FakeStream::read 推进游标', $stream->read(2), 'he');
$expect('FakeStream::getContents 取剩余', $stream->getContents(), 'llo');
$expect('FakeStream::eof', $stream->eof(), true);
$stream->rewind();
$expect('FakeStream::rewind', $stream->tell(), 0);
$stream->seek(-1, SEEK_END);
$expect('FakeStream::seek SEEK_END', $stream->tell(), 4);
$stream->seek(1, SEEK_CUR);
$expect('FakeStream::seek SEEK_CUR', $stream->tell(), 5);

$expect('FakeUri::__toString', (string) new FakeUri('/only/path?q=1'), '/only/path?q=1');
$expect('FakeUri::withQuery 去掉问号', (string) (new FakeUri('/p'))->withQuery('?a=1'), '/p?a=1');
$expect('FakeUri::withX 返回新实例', (new FakeUri('/p'))->withScheme('http') !== new FakeUri('/p'), true);
$expect('FakeUri::getAuthority', (new FakeUri('http://u:p@host:81/x'))->getAuthority(), 'u:p@host:81');
$expect('FakeUri::getUserInfo', (new FakeUri('http://u:p@host/x'))->getUserInfo(), 'u:p');
$expect('FakeUri::带 userinfo 串回', (string) new FakeUri('http://u:p@host:81/x'), 'http://u:p@host:81/x');

$expect('匿名子类仍满足 ServerRequestInterface', (new class extends FakeServerRequest {}) instanceof \Psr\Http\Message\ServerRequestInterface, true);
$expect('匿名子类仍满足 ResponseInterface', (new class extends FakePsrResponse {}) instanceof \Psr\Http\Message\ResponseInterface, true);
$expect('fake 满足 PSR-17 工厂接口', (new FakeResponseFactory()) instanceof \Psr\Http\Message\ResponseFactoryInterface, true);

echo json_encode(['checks' => $checks, 'failures' => $failures]), "\n";
exit($failures === [] ? 0 : 1);
PHP;
}
