

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
        'time_limit'=>0,  //仅记录响应超过多少秒的请求  默认0记录所有
        'log_num'=>1000, //仅记录最近的多少次请求(最大值有待观察，看日志、查看响应时间) 默认1000
        'view_wtred'=>3, //列表耗时超过多少秒标红 默认3s
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
![](./doc/1.jpg)
![](./doc/2.jpg)