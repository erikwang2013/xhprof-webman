<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/Fakes.php';
// 按框架拆分的桩（Psr7.php 及 Wave 1 起的 <Fw>.php）。必须在 phpunit 跑第一个用例前
// 全部加载：这些文件声明的是 `Psr\*`/框架接口，PSR-4 自动加载找不到它们。
// **必须在 framework-stubs.php 之前**：`implements`/`extends` 在**类声明时**就要解析到
// 目标类，而 Hyperf 那份响应桩照真包声明 `implements Psr\Http\Message\ResponseInterface`
// （hyperf/http-server src/Response.php:48），接口晚一步加载就是加载期 Error。
foreach (glob(__DIR__ . '/Stubs/Framework/*.php') as $f) {
    require_once $f;
}
require_once __DIR__ . '/Stubs/framework-stubs.php';
