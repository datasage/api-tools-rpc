<?php

declare(strict_types=1);

namespace Laminas\ApiTools\Rpc\Factory;

use Exception;
use Laminas\ApiTools\Rpc\RpcController;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Psr\Container\ContainerInterface;

use function class_exists;
use function explode;
use function is_callable;
use function is_string;
use function sprintf;
use function strpos;

class RpcControllerFactory implements AbstractFactoryInterface
{
    /**
     * Marker used to ensure we do not end up in a circular dependency lookup
     * loop.
     *
     * @see https://github.com/zfcampus/zf-rpc/issues/18
     *
     * @var null|string
     */
    private $lastRequestedControllerService;

    /**
     * Determine if we can create a service with name
     *
     * @param string $requestedName
     * @return bool
     */
    public function canCreate(ContainerInterface $container, $requestedName)
    {
        // Prevent circular lookup
        if ($requestedName === $this->lastRequestedControllerService) {
            return false;
        }

        if (! $container->has('config')) {
            return false;
        }

        $config = $container->get('config');
        if (! isset($config['api-tools-rpc'][$requestedName])) {
            return false;
        }

        $config = $config['api-tools-rpc'][$requestedName];

        if (! isset($config['callable'])) {
            return false;
        }

        return true;
    }

    /**
     * Determine if we can create a service with name (v2).
     *
     * Provided for backwards compatibility; proxies to canCreate().
     *
     * @param string $name
     * @param string $requestedName
     * @return bool
     */
    public function canCreateServiceWithName(ServiceLocatorInterface $controllerManager, $name, $requestedName)
    {
        $container = $controllerManager->getServiceLocator() ?: $controllerManager;
        return $this->canCreate($container, $requestedName);
    }

    /**
     * Create and return an RpcController instance.
     *
     * @param string $requestedName
     * @param null|array $options
     * @return RpcController
     * @throws ServiceNotCreatedException If the callable configuration value
     *     associated with the controller is not callable.
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $config   = $container->get('config');
        $callable = $config['api-tools-rpc'][$requestedName]['callable'];

        if (! is_string($callable) && ! is_callable($callable)) {
            throw new ServiceNotCreatedException(
                'Unable to create a controller from the configured api-tools-rpc callable'
            );
        }

        if (
            is_string($callable)
            && strpos($callable, '::') !== false
        ) {
            $callable = $this->marshalCallable($callable, $container);
        }

        $controller = new RpcController();
        $controller->setWrappedCallable($callable);
        return $controller;
    }

    /**
     * Create and return an RpcController instance (v2).
     *
     * Provided for backwards compatibility; proxies to __invoke().
     *
     * @param string $name
     * @param string $requestedName
     * @return RpcController
     * @throws Exception
     */
    public function createServiceWithName(ServiceLocatorInterface $controllerManager, $name, $requestedName)
    {
        $container = $controllerManager->getServiceLocator() ?: $controllerManager;
        return $this($container, $requestedName);
    }

    /**
     * Marshal an instance method callback from a given string.
     *
     * @param mixed $string String of the form class::method
     * @return callable
     */
    private function marshalCallable($string, ContainerInterface $container)
    {
        $callable         = false;
        [$class, $method] = explode('::', $string, 2);

        if (
            $container->has('ControllerManager')
            && $this->lastRequestedControllerService !== $class
        ) {
            $this->lastRequestedControllerService = $class;
            $callable                             = $this->marshalCallableFromContainer(
                $class,
                $method,
                $container->get('ControllerManager')
            );
        }

        $this->lastRequestedControllerService = null;

        if (! $callable) {
            $callable = $this->marshalCallableFromContainer($class, $method, $container);
        }

        if ($callable) {
            return $callable;
        }

        if (! class_exists($class)) {
            throw new ServiceNotCreatedException(sprintf(
                'Cannot create callback %s as class %s does not exist',
                $string,
                $class
            ));
        }

        return [new $class(), $method];
    }

    /**
     * Attempt to marshal a callable from a container.
     *
     * @param string $class
     * @param string $method
     * @return false|callable
     */
    private function marshalCallableFromContainer($class, $method, ContainerInterface $container)
    {
        if (! $container->has($class)) {
            return false;
        }

        return [$container->get($class), $method];
    }
}
