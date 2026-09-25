<?php

declare(strict_types=1);

/**
 * 接口反射快照器（L0 专用）。
 *
 * 用法：php lib/dump.php <bootstrap 文件> <接口名> [<接口名> ...]
 *
 * 只做一件事：require 给定的 bootstrap，然后把指定接口的**签名**dump 成 JSON 打到 stdout。
 * 关键设计：本脚本**不关心** bootstrap 从哪来——真实 PSR 包和我们自己的桩走的是同一段
 * 代码，所以两份 JSON 可以逐字段直接比。若分成两套 dump 逻辑，差异报告就分不清
 * "签名不同" 与 "dump 方式不同"。
 *
 * 之所以要求调用方用子进程调用：真实 `psr/http-message` 与 `tests/Stubs/Framework/Psr7.php`
 * 声明同名接口，同进程加载必 fatal（Cannot declare interface）。本脚本只被单方加载。
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * @return array<string, mixed>
 */
function contracts_dump_interface(ReflectionClass $rc): array
{
    $methods = [];
    foreach ($rc->getMethods() as $m) {
        $params = [];
        foreach ($m->getParameters() as $p) {
            $params[] = [
                'name' => $p->getName(),
                'type' => $p->getType() === null ? null : (string) $p->getType(),
                'hasDefault' => $p->isDefaultValueAvailable(),
                'default' => $p->isDefaultValueAvailable()
                    ? var_export($p->getDefaultValue(), true)
                    : null,
                'byRef' => $p->isPassedByReference(),
                'variadic' => $p->isVariadic(),
            ];
        }
        $methods[$m->getName()] = [
            'return' => $m->getReturnType() === null ? null : (string) $m->getReturnType(),
            'returnsRef' => $m->returnsReference(),
            'params' => $params,
        ];
    }
    ksort($methods);

    $constants = [];
    foreach ($rc->getReflectionConstants() as $c) {
        $constants[$c->getName()] = var_export($c->getValue(), true);
    }
    ksort($constants);

    $extends = $rc->getInterfaceNames();
    sort($extends);

    return [
        'extends' => $extends,
        'constants' => $constants,
        'methods' => $methods,
    ];
}

$argvv = array_slice($argv, 1);
if (count($argvv) < 2) {
    echo json_encode(['error' => 'usage: dump.php <bootstrap> <interface> [<interface>...]']), "\n";
    exit(2);
}

$bootstrap = array_shift($argvv);
$interfaces = $argvv;

$out = ['error' => null, 'interfaces' => [], 'missing' => []];

if (!is_file($bootstrap)) {
    echo json_encode(['error' => "bootstrap not found: {$bootstrap}", 'interfaces' => [], 'missing' => $interfaces]), "\n";
    exit(2);
}

require $bootstrap;

foreach ($interfaces as $name) {
    if (!interface_exists($name)) {
        $out['missing'][] = $name;
        continue;
    }
    $out['interfaces'][$name] = contracts_dump_interface(new ReflectionClass($name));
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
