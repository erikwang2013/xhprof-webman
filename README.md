

## 安装 ##

Use [Composer](https://github.com/composer/composer):
```sh
composer require erikwang2013/xhprof-webman
```

## 配置 ##

1. 在配置文件config中app.php添加开关

```
'xhprof'=>[
        'enable'=>true,   //是否开启xhprof分析
        'ignore_url_arr'=>["/test"],   //忽略URL配置
    ]

```

2. 创建控制器,复制下面代码

```
<?php

namespace app\controller;

use support\Request;
use Erik\Xhprof\Webman\Xhprof;

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