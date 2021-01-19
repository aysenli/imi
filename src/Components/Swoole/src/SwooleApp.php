<?php

declare(strict_types=1);

namespace Imi\Swoole;

use Imi\App;
use Imi\Bean\Annotation;
use Imi\Cache\CacheManager;
use Imi\Cli\CliApp;
use Imi\Config;
use Imi\Event\Event;
use Imi\Lock\Lock;
use Imi\Main\Helper;
use Imi\Pool\PoolManager;
use Imi\Swoole\Util\AtomicManager;
use Imi\Util\Imi;
use Imi\Util\Process\ProcessAppContexts;
use Imi\Util\Process\ProcessType;
use Imi\Worker;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Output\OutputInterface;

class SwooleApp extends CliApp
{
    /**
     * 构造方法.
     *
     * @param string $namespace
     *
     * @return void
     */
    public function __construct(string $namespace)
    {
        parent::__construct($namespace);
        $this->cliEventDispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleCommandEvent $e) {
            $this->onCommand($e);
        }, \PHP_INT_MAX - 1000);
        Event::one('IMI.SCAN_APP', function () {
            $this->onScanApp();
        });
    }

    /**
     * 获取应用类型.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'swoole';
    }

    /**
     * 加载配置.
     *
     * @return void
     */
    public function loadConfig(): void
    {
        parent::loadConfig();
        $namespace = Config::get('@app.mainServer.namespace');
        $namespaces = [];
        if (null !== $namespace)
        {
            $namespaces['main'] = $namespace;
        }
        foreach (Config::get('@app.subServers', []) as $name => $config)
        {
            $namespaces[$name] = $config['namespace'];
        }
        foreach ($namespaces as $name => $namespace)
        {
            // 加载服务器配置文件
            foreach (Imi::getNamespacePaths($namespace) as $path)
            {
                $fileName = $path . '/config/config.php';
                if (is_file($fileName))
                {
                    Config::addConfig('@server.' . $name, include $fileName);
                    break;
                }
            }
        }
    }

    /**
     * 加载入口.
     *
     * @return void
     */
    public function loadMain(): void
    {
        parent::loadMain();
        // 服务器们
        $servers = array_merge(['main' => Config::get('@app.mainServer')], Config::get('@app.subServers', []));
        foreach ($servers as $serverName => $item)
        {
            if ($item)
            {
                Helper::getMain($item['namespace'], 'server.' . $serverName);
            }
        }
    }

    /**
     * 初始化.
     *
     * @return void
     */
    public function init(): void
    {
        parent::init();
        foreach (Config::getAliases() as $alias)
        {
            // 原子计数初始化
            AtomicManager::setNames(Config::get($alias . '.atomics', []));
        }
        AtomicManager::init();
        Worker::setWorkerHandler(App::getBean('SwooleWorkerHandler'));
        $initCallback = function () {
            PoolManager::init();
            CacheManager::init();
            Lock::init();
        };
        Event::on('IMI.PROCESS.BEGIN', $initCallback);
        Event::on('IMI.MAIN_SERVER.WORKER.START', $initCallback);
    }

    private function onCommand(ConsoleCommandEvent $e): void
    {
        $this->checkEnvironment($e->getOutput());
        App::set(ProcessAppContexts::PROCESS_NAME, ProcessType::MASTER, true);
        App::set(ProcessAppContexts::MASTER_PID, getmypid(), true);
    }

    private function onScanApp(): void
    {
        $namespace = Config::get('@app.mainServer.namespace');
        $namespaces = [];
        if (null !== $namespace)
        {
            $namespaces[] = $namespace;
        }
        foreach (Config::get('@app.subServers', []) as $config)
        {
            $namespaces[] = $config['namespace'];
        }
        Annotation::getInstance()->initByNamespace($namespaces);
    }

    /**
     * 检查环境.
     *
     * @return void
     */
    private function checkEnvironment(OutputInterface $output): void
    {
        // Swoole 检查
        if (!\extension_loaded('swoole'))
        {
            $output->writeln('<error>Swoole extension must be installed!</error>');
            $output->writeln('<info>Swoole Github:</info> <comment>https://github.com/swoole/swoole-src</comment>');
            exit;
        }
        // 短名称检查
        $useShortname = ini_get_all('swoole')['swoole.use_shortname']['local_value'];
        $useShortname = strtolower(trim(str_replace('0', '', $useShortname)));
        if (\in_array($useShortname, ['', 'off', 'false'], true))
        {
            $output->writeln('<error>Please enable swoole short name before using imi!</error>');
            $output->writeln('<info>You can set <comment>swoole.use_shortname = on</comment> into your php.ini.</info>');
            exit;
        }
    }
}