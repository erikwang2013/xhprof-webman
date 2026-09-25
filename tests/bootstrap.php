<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Fixtures/Fakes.php';
require_once __DIR__ . '/Stubs/framework-stubs.php';
// 按框架拆分的桩（Psr7.php 及 Wave 1 起的 <Fw>.php）。必须在 phpunit 跑第一个用例前
// 全部加载：这些文件声明的是 `Psr\*`/框架接口，PSR-4 自动加载找不到它们。
foreach (glob(__DIR__ . '/Stubs/Framework/*.php') as $f) {
    require_once $f;
}
