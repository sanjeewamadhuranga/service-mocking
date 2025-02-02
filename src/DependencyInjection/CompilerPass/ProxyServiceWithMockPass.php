<?php

namespace Happyr\ServiceMocking\DependencyInjection\CompilerPass;

use Happyr\ServiceMocking\Generator\GeneratorFactory;
use ProxyManager\Configuration;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ProxyServiceWithMockPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        /** @var array $serviceIds */
        $serviceIds = $container->getParameter('happyr_service_mock.services');

        foreach ($container->findTaggedServiceIds('happyr_service_mock') as $id => $tags) {
            $serviceIds[] = $id;
        }

        $proxiesDirectory = $container->getParameter('kernel.cache_dir').'/happyr_service_mock';
        @mkdir($proxiesDirectory);

        $config = new Configuration();
        $config->setGeneratorStrategy(new FileWriterGeneratorStrategy(new FileLocator($proxiesDirectory)));
        $config->setProxiesTargetDir($proxiesDirectory);
        \spl_autoload_register($config->getProxyAutoloader());
        $factory = new GeneratorFactory($config);

        foreach (array_unique($serviceIds) as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $definition = $container->getDefinition($serviceId);
            } elseif ($container->hasAlias($serviceId)) {
                $definition = $container->getDefinition($container->getAlias($serviceId)->__toString());
            } else {
                throw new \LogicException(sprintf('[HappyrServiceMocking] Service or alias with id "%s" does not exist.', $serviceId));
            }

            $initializer = function () {
                return true;
            };

            $proxy = $factory->createProxy($definition->getClass(), $initializer);
            $definition->setClass($proxyClass = get_class($proxy));
            $definition->setPublic(true);
            $definition->setLazy(false);

            if (null !== $definition->getFactory()) {
                $factoryMethod = $definition->getFactory();
                $arguments = $definition->getArguments();
                array_unshift($arguments, $factoryMethod);
                $definition->setFactory([$proxyClass, '__construct_with_factory']);
                $definition->setArguments($arguments);
            }
        }
    }
}
