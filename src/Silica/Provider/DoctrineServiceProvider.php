<?php

/*
 * This file is part of the Silica framework.
 *
 * (c) Chang Loong <changlon@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silica\Provider;

use Silica\Application;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\Common\EventManager;
use Symfony\Bridge\Doctrine\Logger\DbalLogger;

/**
 * Doctrine DBAL Provider.
 *
 * @author Chang Loong <changlon@gmail.com>
 */
class DoctrineServiceProvider 
{
    public function register(Application $app)
    {
        $app['db.default_options'] = array(
            'driver'   => 'pdo_mysql',
            'dbname'   => null,
            'host'     => 'localhost',
            'user'     => 'root',
            'password' => null,
        );

        $app->protect('dbs.options.initializer', function () use ($app) {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($app['dbs.options'])) {
                $app['dbs.options'] = array('default' => isset($app['db.options']) ? $app['db.options'] : array());
            }

            $tmp = $app['dbs.options'];
            foreach ($tmp as $name => &$options) {
                $options = array_replace($app['db.default_options'], $options);

                if (!isset($app['dbs.default'])) {
                    $app['dbs.default'] = $name;
                }
            }
            $app['dbs.options'] = $tmp;
        });

        $app->share('dbs', function ($app) {
            $app['dbs.options.initializer']();

            $dbs = new \Pimple();
            foreach ($app['dbs.options'] as $name => $options) {
                if ($app['dbs.default'] === $name) {
                    // we use shortcuts here in case the default has been overridden
                    $config = $app['db.config'];
                    $manager = $app['db.event_manager'];
                } else {
                    $config = $app['dbs.config'][$name];
                    $manager = $app['dbs.event_manager'][$name];
                }

                $dbs[$name] = DriverManager::getConnection($options, $config, $manager);
            }

            return $dbs;
        });

        $app->share('dbs.config', function ($app) {
            $app['dbs.options.initializer']();

            $configs = new \Pimple();
            foreach ($app['dbs.options'] as $name => $options) {
                $configs[$name] = new Configuration();

                if (isset($app['logger']) && class_exists('Symfony\Bridge\Doctrine\Logger\DbalLogger')) {
                    $configs[$name]->setSQLLogger(new DbalLogger($app['logger'], isset($app['stopwatch']) ? $app['stopwatch'] : null));
                }
            }

            return $configs;
        });

        $app->share('dbs.event_manager', function ($app) {
            $app['dbs.options.initializer']();

            $managers = new \Pimple();
            foreach ($app['dbs.options'] as $name => $options) {
                $managers[$name] = new EventManager();
            }

            return $managers;
        });

        // shortcuts for the "first" DB
        $app->share('db', function ($app) {
            $dbs = $app['dbs'];

            return $dbs[$app['dbs.default']];
        });

        $app->share('db.config', function ($app) {
            $dbs = $app['dbs.config'];

            return $dbs[$app['dbs.default']];
        });

        $app->share('db.event_manager', function ($app) {
            $dbs = $app['dbs.event_manager'];

            return $dbs[$app['dbs.default']];
        });
    }

}
