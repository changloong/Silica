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
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\XcacheCache;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\Driver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Mapping\Driver\StaticPHPDriver;

/**
 * Doctrine ORM Pimple Service Provider.
 *
 * @author Chang Loong <changlon@gmail.com>
 */
class DoctrineOrmServiceProvider
{
    public function register(Application $app)
    {
        foreach ($this->getOrmDefaults($app) as $key => $value) {
            if (!isset($app[$key])) {
                $app[$key] = $value;
            }
        }

        $app['orm.em.default_options'] = array(
            'connection' => 'default',
            'mappings' => array(),
        );

        $app->protect('orm.ems.options.initializer', function () use ($app) {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($app['orm.ems.options'])) {
                $app['orm.ems.options'] = array('default' => isset($app['orm.em.options']) ? $app['orm.em.options'] : array());
            }

            $tmp = $app['orm.ems.options'];
            foreach ($tmp as $name => &$options) {
                $options = array_replace($app['orm.em.default_options'], $options);

                if (!isset($app['orm.ems.default'])) {
                    $app['orm.ems.default'] = $name;
                }
            }
            $app['orm.ems.options'] = $tmp;
        });

        $app->protect('orm.em_name_from_param_key', function ($paramKey) use ($app) {
            $app['orm.ems.options.initializer']();

            if (isset($app[$paramKey])) {
                return $app[$paramKey];
            }

            return $app['orm.ems.default'];
        });

        $app->share('orm.ems', function($app) {
            $app['orm.ems.options.initializer']();

            $ems = new \Pimple();
            foreach ($app['orm.ems.options'] as $name => $options) {
                if ($app['orm.ems.default'] === $name) {
                    // we use shortcuts here in case the default has been overridden
                    $config = $app['orm.em.config'];
                } else {
                    $config = $app['orm.ems.config'][$name];
                }

                $ems[$name] = EntityManager::create(
                    $app['dbs'][$options['connection']],
                    $config,
                    $app['dbs.event_manager'][$options['connection']]
                );
            }

            return $ems;
        });

        $app->share('orm.ems.config', function($app) {
            $app['orm.ems.options.initializer']();

            $configs = new \Pimple();
            foreach ($app['orm.ems.options'] as $name => $options) {
                $config = new Configuration;

                $app['orm.cache.configurer']($name, $config, $options);

                $config->setProxyDir($app['orm.proxies_dir']);
                $config->setProxyNamespace($app['orm.proxies_namespace']);
                $config->setAutoGenerateProxyClasses($app['orm.auto_generate_proxies']);

                $chain = $app['orm.mapping_driver_chain.locator']($name);
                foreach ((array) $options['mappings'] as $entity) {
                    if (!is_array($entity)) {
                        throw new \InvalidArgumentException(
                            "The 'orm.em.options' option 'mappings' should be an array of arrays."
                        );
                    }

                    if (!empty($entity['resources_namespace'])) {
                        $entity['path'] = $app['psr0_resource_locator']->findFirstDirectory($entity['resources_namespace']);
                    }

                    switch ($entity['type']) {
                        case 'annotation':
                            $useSimpleAnnotationReader =
                                isset($entity['use_simple_annotation_reader'])
                                ? $entity['use_simple_annotation_reader']
                                : true;
                            $driver = $config->newDefaultAnnotationDriver((array) $entity['path'], $useSimpleAnnotationReader);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'yml':
                            $driver = new YamlDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'xml':
                            $driver = new XmlDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'php':
                            $driver = new StaticPHPDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        default:
                            throw new \InvalidArgumentException(sprintf('"%s" is not a recognized driver', $entity['type']));
                            break;
                    }
                }
                $config->setMetadataDriverImpl($chain);

                $configs[$name] = $config;
            }

            return $configs;
        });

        $app->protect('orm.cache.configurer', function($name, Configuration $config, $options) use ($app) {
            $config->setMetadataCacheImpl($app['orm.cache.locator']($name, 'metadata', $options));
            $config->setQueryCacheImpl($app['orm.cache.locator']($name, 'query', $options));
            $config->setResultCacheImpl($app['orm.cache.locator']($name, 'result', $options));
        });

        $app->protect('orm.cache.locator', function($name, $cacheName, $options) use ($app) {
            $cacheNameKey = $cacheName . '_cache';

            if (!isset($options[$cacheNameKey])) {
                $options[$cacheNameKey] = $app['orm.default_cache'];
            }

            if (isset($options[$cacheNameKey]) && !is_array($options[$cacheNameKey])) {
                $options[$cacheNameKey] = array(
                    'driver' => $options[$cacheNameKey],
                );
            }

            if (!isset($options[$cacheNameKey]['driver'])) {
                throw new \RuntimeException("No driver specified for '$cacheName'");
            }

            $driver = $options[$cacheNameKey]['driver'];

            $cacheInstanceKey = 'orm.cache.instances.'.$name.'.'.$cacheName;
            if (isset($app[$cacheInstanceKey])) {
                return $app[$cacheInstanceKey];
            }

            return $app[$cacheInstanceKey] = $app['orm.cache.factory']($driver, $options);
        });

        $app->protect('orm.cache.factory.backing_memcache', function() {
            return new \Memcache;
        });

        $app->protect('orm.cache.factory.memcache', function($cacheOptions) use ($app) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for memcache cache');
            }

            $memcache = $app['orm.cache.factory.backing_memcache']();
            $memcache->connect($cacheOptions['host'], $cacheOptions['port']);

            $cache = new MemcacheCache;
            $cache->setMemcache($memcache);

            return $cache;
        });

        $app->protect('orm.cache.factory.backing_memcached', function() {
            return new \Memcached;
        });

        $app->protect('orm.cache.factory.memcached', function($cacheOptions) use ($app) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for memcached cache');
            }

            $memcached = $app['orm.cache.factory.backing_memcached']();
            $memcached->addServer($cacheOptions['host'], $cacheOptions['port']);

            $cache = new MemcachedCache;
            $cache->setMemcached($memcached);

            return $cache;
        });

        $app->protect('orm.cache.factory.array', function() {
            return new ArrayCache;
        });

        $app->protect('orm.cache.factory.apc', function() {
            return new ApcCache;
        });

        $app->protect('orm.cache.factory.xcache', function() {
            return new XcacheCache;
        });

        $app->protect('orm.cache.factory', function($driver, $cacheOptions) use ($app) {
            switch ($driver) {
                case 'array':
                    return $app['orm.cache.factory.array']();
                case 'apc':
                    return $app['orm.cache.factory.apc']();
                case 'xcache':
                    return $app['orm.cache.factory.xcache']();
                case 'memcache':
                    return $app['orm.cache.factory.memcache']($cacheOptions);
                case 'memcached':
                    return $app['orm.cache.factory.memcached']($cacheOptions);
                default:
                    throw new \RuntimeException("Unsupported cache type '$driver' specified");
            }
        });

        $app->protect('orm.mapping_driver_chain.locator', function($name = null) use ($app) {
            $app['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $app['orm.ems.default'];
            }

            $cacheInstanceKey = 'orm.mapping_driver_chain.instances.'.$name;
            if (isset($app[$cacheInstanceKey])) {
                return $app[$cacheInstanceKey];
            }

            return $app[$cacheInstanceKey] = $app['orm.mapping_driver_chain.factory']($name);
        });

        $app->protect('orm.mapping_driver_chain.factory', function($name) use ($app) {
            return new MappingDriverChain;
        });

        $app->protect('orm.add_mapping_driver', function(MappingDriver $mappingDriver, $namespace, $name = null) use ($app) {
            $app['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $app['orm.ems.default'];
            }

            $driverChain = $app['orm.mapping_driver_chain.locator']($name);
            $driverChain->addDriver($mappingDriver, $namespace);
        });

        $app->protect('orm.generate_psr0_mapping', function($resourceMapping) use ($app) {
            $mapping = array();
            foreach ($resourceMapping as $resourceNamespace => $entityNamespace) {
                $directory = $app['psr0_resource_locator']->findFirstDirectory($resourceNamespace);
                if (!$directory) {
                    throw new \InvalidArgumentException("Resources for mapping '$entityNamespace' could not be located; Looked for mapping resources at '$resourceNamespace'");
                }
                $mapping[$directory] = $entityNamespace;
            }

            return $mapping;
        });

        $app->share('orm.em', function($app) {
            $ems = $app['orm.ems'];

            return $ems[$app['orm.ems.default']];
        });

        $app->share('orm.em.config', function($app) {
            $configs = $app['orm.ems.config'];
            return $configs[$app['orm.ems.default']];
        });
    }

    /**
     * Get default ORM configuration settings.
     *
     * @param Application $app Application
     *
     * @return array
     */
    protected function getOrmDefaults(Application $app)
    {
        return array(
            'orm.proxies_dir' => __DIR__.'/../../../../../../../../cache/doctrine/proxies',
            'orm.proxies_namespace' => 'DoctrineProxy',
            'orm.auto_generate_proxies' => true,
            'orm.default_cache' => 'array',
        );
    }
}
