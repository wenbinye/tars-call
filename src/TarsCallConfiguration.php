<?php


namespace wenbinye\tars\call;


use kuiper\di\annotation\Bean;
use kuiper\di\annotation\Configuration;
use kuiper\di\Bootstrap;
use Psr\Container\ContainerInterface;
use tars\TarsGenerateCommand;
use tars\TarsGenerator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @Configuration
 */
class TarsCallConfiguration implements Bootstrap
{
    /**
     * @Bean
     */
    public function classLoader(): ClassLoader
    {
        return new ClassLoader("wenbinye\\tars\\call\\integration", sys_get_temp_dir() . "/tars-call");
    }

    /**
     * @Bean
     */
    public function Twig(): Environment
    {
        $reflectionClass = new \ReflectionClass(TarsGenerateCommand::class);
        $viewPath = dirname($reflectionClass->getFileName(), 2) . '/resources/views';
        $loader = new FilesystemLoader($viewPath);
        $twig = new Environment($loader);
        $twig->addGlobal('generator_version', TarsGenerator::VERSION);

        return $twig;
    }

    public function boot(ContainerInterface $container): void
    {
        $container->get(ClassLoader::class)->register();
        TarsServantResolver::init();
    }
}