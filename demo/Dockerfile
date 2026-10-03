# 演示镜像：原生 PHP（无框架）+ xhprof + phpredis。
#
# 选 php:8.3-cli-alpine 而不是 php-fpm：演示只用内置服务器（php -S），
# 少一层 fpm/nginx 就少一处可以漂移的面。演示走 Native 入口，不装任何框架包，
# 所以这个镜像不会随某个框架的大版本升级而失效。
FROM php:8.3-cli-alpine

# apk 源可换。默认是官方 CDN；从国内访问它常常被限速到几十 KB/s（实测同一个 gcc 包：
# 官方 61 KB/s、清华 33 MB/s），首次构建会卡在这一步。换源走 compose 那个环境变量：
#     APK_MIRROR=mirrors.tuna.tsinghua.edu.cn docker compose up -d --build
ARG APK_MIRROR=dl-cdn.alpinelinux.org

# xhprof 与 phpredis 都不在 PHP 源码树里（php:8.3 自带的 docker-php-ext-install 装不了
# 这两个），只能走 PECL 现编译。$PHPIZE_DEPS 是官方镜像自带的编译工具集
# （autoconf / gcc / g++ / make / pkgconf / re2c ...），装完立刻删，运行时镜像里不留编译器。
#
# 自己取 /get/ 的 tarball 而不是 `pecl install xhprof redis`：后者要查 pecl.php.net 的
# REST 接口做版本发现，那个接口当前不可用，实测报
#     Package "xhprof" does not have REST info xml available
#     No releases available for package "pecl.php.net/redis"
# 版本号写死在 URL 里顺带把镜像钉住：升级扩展 = 改这两个 URL（当前 xhprof 2.3.10 / phpredis 6.3.0）。
#
# 下载要容忍 pecl.php.net 的间歇性断连：实测同一个 URL 会时好时坏——busybox wget、
# PHP 的 https 包装、curl 三种客户端都碰到过 "Connection reset by peer"（甚至连续
# 四次失败），过几分钟又都正常。所以重试给足：--retry-all-errors 才覆盖 TLS 层的重置
# （默认 --retry 只重试「连接被拒」与部分错误）。curl 随 .build-deps 一起装、
# 装完即被 apk del 删掉，不进运行时镜像。
RUN sed -i "s|dl-cdn.alpinelinux.org|${APK_MIRROR}|g" /etc/apk/repositories \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl \
    && curl -fsSL --retry 5 --retry-all-errors --retry-delay 3 -o /tmp/xhprof.tgz https://pecl.php.net/get/xhprof-2.3.10.tgz \
    && curl -fsSL --retry 5 --retry-all-errors --retry-delay 3 -o /tmp/redis.tgz https://pecl.php.net/get/redis-6.3.0.tgz \
    && pecl install /tmp/xhprof.tgz \
    && pecl install /tmp/redis.tgz \
    && rm -f /tmp/xhprof.tgz /tmp/redis.tgz \
    && docker-php-ext-enable xhprof redis \
    && apk del .build-deps

# 只从 composer 官方镜像借一个二进制过来，不在本镜像里再装一遍（composer:2 由 docker 拉取，
# 不需要我们构建）。运行时靠它按 path repository 装挂载进来的 /app。
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app/demo

# 刻意不在构建期 composer install：vendor 要指向运行时挂载进来的 /app（当前工作树），
# 构建期装好只会留下一份指向「构建那一刻代码」的假象。安装动作在 compose 的 command 里。
