<?php

declare(strict_types=1);

use Imi\App;
use Imi\Event\Event;
use Imi\Util\File;
use Symfony\Component\Console\Input\ArgvInput;

return function () {
    // 暂时移除 PHP 8 中有 bug 的 hook native curl
    Co::set([
        'hook_flags' => \SWOOLE_HOOK_ALL ^ \SWOOLE_HOOK_NATIVE_CURL,
    ]);
    $status = 0;
    \Swoole\Coroutine\run(function () use (&$status) {
        try
        {
            $path = null;

            if (!class_exists('Imi\App'))
            {
                (function () use (&$path) {
                    foreach ([
                        $_SERVER['PWD'] ?? getcwd(),
                        dirname(__DIR__, 4), // 在非工作路径，使用绝对路径启动
                    ] as $path)
                    {
                        $fileName = $path . '/vendor/autoload.php';
                        if (is_file($fileName))
                        {
                            break;
                        }
                    }
                    if (!is_file($fileName))
                    {
                        echo 'No file vendor/autoload.php', \PHP_EOL;
                        exit(255);
                    }
                    require $fileName;
                })();
            }

            // 事件监听
            Event::on('IMI.LOAD_RUNTIME', \Imi\Swoole\Process\Listener\LoadRuntimeListener::class, 19940000);
            Event::on('IMI.BUILD_RUNTIME', \Imi\Swoole\Process\Listener\BuildRuntimeListener::class, 19940000);
            Event::on('IMI.LOAD_RUNTIME', \Imi\Swoole\Task\Listener\LoadRuntimeListener::class, 19940000);
            Event::on('IMI.BUILD_RUNTIME', \Imi\Swoole\Task\Listener\BuildRuntimeListener::class, 19940000);

            // 运行
            App::run((function () use ($path): string {
                $input = new ArgvInput();
                $namespace = $input->getParameterOption('--app-namespace', false);
                if (false === $namespace)
                {
                    $config = include File::path($path ?? dirname($_SERVER['SCRIPT_NAME'], 2), 'config/config.php');
                    if (!isset($config['namespace']))
                    {
                        echo 'Has no namespace, please add arg: --app-namespace "Your App Namespace"', \PHP_EOL;
                        exit(255);
                    }
                    $namespace = $config['namespace'];
                }

                return $namespace;
            })(), \Imi\Swoole\SwooleApp::class);
        }
        catch (\Swoole\ExitException $e)
        {
            $status = $e->getStatus();
        }
    });
    Event::trigger('IMI.SWOOLE.MAIN_COROUTINE.AFTER');
    exit($status);
};
