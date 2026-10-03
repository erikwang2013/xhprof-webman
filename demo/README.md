# docker 演示（原生 PHP 入口）

不装 PHP、不装 xhprof 扩展、不装 Redis，起三个命令就能看到一份真实的 xhprof 报告。

演示走**原生 PHP 入口**（`Native\XhprofBootstrap`），不依赖任何框架包——所以它不会随某个框架的大版本升级而失效，这也是这个演示唯一要保证的「长期可用」。

## 跑起来

```sh
cd demo
docker compose up -d    # 首次会构建镜像：装编译工具、编译 xhprof / phpredis
```

构建耗时完全看网络，没有固定数字：本机实测（2026-10-03）换清华源后 Dockerfile 里那条 `RUN` 80.9 秒、`docker compose build` 端到端 83 秒（xhprof 编译完 30.1s、phpredis 76.8s，还不算冷机器上先拉 php:8.3-cli-alpine / composer:2 / redis:7-alpine 三个 base 镜像的时间）；同一台机器用默认源只有 61 KB/s，40 分钟没装完。网络慢时超过 10 分钟很正常。

构建卡在 apk 下载就换个源（国内访问官方源常见）：

```sh
APK_MIRROR=mirrors.tuna.tsinghua.edu.cn docker compose up -d --build
```

打几次业务请求（浏览器直接开 <http://127.0.0.1:8080/> 也行，每刷新一次产生一条采样）：

```sh
curl -s http://127.0.0.1:8080/ > /dev/null
curl -s http://127.0.0.1:8080/ > /dev/null
```

然后打开报告页：<http://127.0.0.1:8080/xhprof>

构建期需要能访问 apk 源与 pecl.php.net（两个扩展的源码从那里取）。

## 这是演示环境

- **默认不鉴权**：`auth_token` 为 null（包内默认值），报告页谁都能看。端口**只发布到 `127.0.0.1`**，不要改绑公网。
- Redis 无密码，且只在 compose 的内部网络里，没有对外发布端口。
- 采样数据保留 7 天（`log_ttl` 默认值）。

## 里面有什么

| 文件 | 作用 |
| --- | --- |
| `docker-compose.yml` | 两个服务：`app`（PHP 内置服务器）与 `redis`；仓库根挂到 `/app` |
| `Dockerfile` | `php:8.3-cli-alpine` + PECL 编译 xhprof / phpredis + 从 composer 官方镜像拷一个 composer 二进制 |
| `composer.json` | 用 path repository 把本仓库（`..`）当作依赖装上 |
| `public/index.php` | 示例应用：一行 `XhprofBootstrap::start()` + 三段人为耗时的工作 |

示例应用里三段工作各有用途：递归 `fib(23)`（约 9.3 万次调用，撑起报告页的调用树——xhprof 对每次调用都要记录，规模大了会明显拖慢请求，这个数字是量过的）、字符串拼接 20000 轮、`usleep(20000)`（只计入整条请求的墙钟时间：`usleep` / `md5` 这类内建函数不采集，本包启用采样时带了 `XHPROF_FLAGS_NO_BUILTINS`，报告页的函数列表里只有你自己写的代码）。

## 它跑的永远是当前工作树的代码

`docker-compose.yml` 把仓库根挂到容器里的 `/app`，而 `composer.json` 里 path repository 的 url 是 `..`——于是 `vendor/aaron-dev/xhprof-webman` 是指向 `/app` 的**软链**而不是拷贝。改了 `src/` 下的代码，**下一个请求**就是新代码，不用重建镜像（改了 `Dockerfile` 才需要 `docker compose up -d --build`）。

compose 每次启动都跑一次 `composer update`（不是 `install`）：path 包的版本随分支/标签变，提交一份 lock 进来只会随分支切换而过期。`composer.json` 里 packagist 已被显式关掉（`{"packagist.org": false}`），只有 `..` 这一个本地包要解析，不联网、亚秒级。

## 改配置

`public/index.php` 里 `XhprofBootstrap::start([...])` 的数组就是全部配置，键集与另外十一家的 `config/xhprof.php` 相同，默认值在 `src/Native/config/xhprof.php`。演示只覆盖了 Redis 地址（compose 的服务名 `redis`）。例如打开鉴权：

```php
XhprofBootstrap::start([
    'redis' => ['host' => 'redis'],
    'auth_token' => 'demo',   // 之后报告页要带 ?token=demo
]);
```

改完下一个请求生效——`php -S` 每个请求都会重新执行一遍 `index.php`。

## 停掉 / 清理

```sh
docker compose down           # 停掉（Redis 数据留下，下次 up 还在）
docker compose down -v        # 连 Redis 数据一起清
rm -rf vendor composer.lock   # compose 在 demo/ 下生成的（.gitignore 已排除）；
                              # 容器里的 composer 以 root 运行，新生成的文件属主是 root，宿主上删不掉就 sudo
```

## 实跑记录

2026-10-03 在 Docker 26.1.5 + Compose v2.26.1 上完整跑通（`APK_MIRROR=mirrors.tuna.tsinghua.edu.cn docker compose up -d`）：

- 示例页 `http://127.0.0.1:8080/` 三次都是 HTTP 200，每次 0.24–0.34s；
- `/xhprof` 200，列表里有 4 条 run（run id 形如 `3c30f63962d899c6`），`/xhprof` 自己的请求不在列表里（`ignore_url_arr` 生效）；
- run 详情页 200，调用树里有 `fib@1` … `fib@22`；`/xhprof-assets/css/xhprof.css` 200（12 KB，`text/css`）；
- 容器内 `php -m` 有 `xhprof` 与 `redis`；`vendor/aaron-dev/xhprof-webman` 是指向 `/app` 的软链，容器里 `/app/src/Native/config/xhprof.php` 与宿主工作树同一个 md5。

用官方 apk 源构建的那次没跑完：apk 卡在 gcc（实测下载只有 61 KB/s），换清华源后这一步 9 秒。pecl.php.net 的下载会间歇性被重置，Dockerfile 里已给足重试。

## 相关文档

- 这个入口的完整说明：根 README 的「原生 PHP（无框架）」一节
- 配置键的含义：`src/Native/config/xhprof.php`
- 报告页怎么读：根 README 的「报告页」一节
