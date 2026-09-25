<?php

/**
 * Joomla 4.4 / 5.x 插件服务提供者（J4 起插件的标准装载形式）。
 *
 * 直接从浏览器访问本文件时终止（Joomla 装载本文件时 _JEXEC 一定已定义）。
 */
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Xhprof\Extension\Xhprof;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // 先取到**变量**再 new：4.4 的 CMSPlugin 构造签名是
                // `__construct(&$subject, $config = [])`，按引用传参，写成
                // `new Xhprof($container->get(...), ...)` 会触发
                // "Notice: Only variables should be passed by reference"。
                // 核心插件的 provider 也是这么写的（plugins/system/accessibility）。
                $dispatcher = $container->get(DispatcherInterface::class);

                $plugin = new Xhprof(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'xhprof')
                );

                // getApplication() 是插件取应用实例的唯一入口；不设的话真实 CMSPlugin
                // 只会返回 null（$application 是 private，无 Factory 兜底），
                // 我们的 onAfterInitialise 会当场解引用 null。
                // 核心插件的 provider 同样有这一行。
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

/*
 * 跨版本取舍（4.4 vs 5.x）：传 dispatcher 给构造函数是 4.4 的硬要求，5.x 则不需要——
 *   4.4：`CMSPlugin::__construct(&$subject, $config = [])`，构造尾部 `setDispatcher($subject)`
 *        带型别约束，不传就是 TypeError，插件一个监听器都注册不上。
 *   5.x：`CMSPlugin::__construct($config = [])` 且 `PluginHelper::import()` 会自己
 *        `$plugin->setDispatcher(...)` + `$plugin->registerListeners()`（PluginHelper.php:235/:243），
 *        所以 5.x 的核心插件 provider 只传数组。
 * 同一份 provider 要同时服务两者，只能传——代价是 5.x 下构造函数会发一条 deprecation
 * （"Passing an instance of DispatcherInterface ... will not be supported in 7.0"），
 * 被 Joomla 自己用 `@trigger_error` 抑制。5.x 上这次 setDispatcher 与框架随后做的是同一件事，
 * 幂等；到 7.0 这条兼容路径消失时本文件需要改（届时另行处理）。
 */
