

## 安装 ##

php要安装xhprof扩展
在php.ini中增加配置
```
[xhprof]
extension=xhprof.so;
xhprof.output_dir=/tmp/xhprof;

```

Use [Composer](https://github.com/composer/composer):
```sh
composer require aaron-dev/xhprof-webman
```

## 配置 ##

1. config增加全局中间件

```
    '' => [
        Aaron\Xhprof\Webman\XhprofMiddleware::class,
    ]
```

2. 创建控制器,复制下面代码

```
<?php

namespace app\controller;

use support\Request;
use Aaron\Xhprof\Webman\Xhprof;

class TestController
{
    public function index(Request $request)
    {
        return Xhprof::index();
    }
}

```

3. 路由增加以下代码
```
Route::get('/test', ['app\controller\TestController','index']);

```

4. 然后重启服务就可以访问了。
![](./doc/1.jpg)
![](./doc/2.jpg)

本插件参考[phpxxb/xhprof](https://github.com/xiexianbo123/xhprof)