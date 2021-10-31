<?php

declare(strict_types=1);

namespace Symplify\ComposerJsonManipulator\Tests\Kernel;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symplify\AutowireArrayParameter\DependencyInjection\CompilerPass\AutowireArrayParameterCompilerPass;
use Symplify\ComposerJsonManipulator\DependencyInjection\Extension\ComposerJsonManipulatorExtension;
use Symplify\ConfigTransformer\Exception\ShouldNotHappenException;
use Symplify\SymfonyContainerBuilder\ContainerBuilderFactory;
use Symplify\SymplifyKernel\Contract\LightKernelInterface;
use Symplify\SymplifyKernel\DependencyInjection\Extension\SymplifyKernelExtension;

final class ComposerJsonManipulatorKernel implements LightKernelInterface
{
    private Container|null $container = null;

    /**
     * @param string[] $configFiles
     */
    public function createFromConfigs(array $configFiles): ContainerInterface
    {
        $containerBuilderFactory = new ContainerBuilderFactory();

        $extensions = [new ComposerJsonManipulatorExtension(), new SymplifyKernelExtension()];
        $compilerPasses = [new AutowireArrayParameterCompilerPass()];

        $containerBuilder = $containerBuilderFactory->create($extensions, $compilerPasses, $configFiles);
        $containerBuilder->compile();

        $this->container = $containerBuilder;

        return $containerBuilder;
    }

    public function getContainer(): ContainerInterface
    {
        if (! $this->container instanceof Container) {
            throw new ShouldNotHappenException();
        }

        return $this->container;
    }
}
