<?php


namespace wenbinye\tars\call;


use kuiper\di\ContainerBuilder;
use kuiper\swoole\Application;
use Psr\Container\ContainerInterface;

class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public static function setUpBeforeClass(): void
    {
        chdir(dirname(__DIR__));
        date_default_timezone_set('Asia/Shanghai');
    }

    protected function setUp(): void
    {
        $this->container = $this->createContainer($this->getDefinitions());
        $this->onSetUp();
    }

    protected function onSetUp(): void
    {
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    protected function getDefinitions(): array
    {
        return [];
    }

    protected function createContainer(array $definitions = [])
    {
        $_SERVER['APP_PATH'] = dirname(__DIR__);
        Application::create();
        return ContainerBuilder::create($_SERVER['APP_PATH'])
            ->defer(function ($container) use ($definitions) {
                foreach ($definitions as $name => $definition) {
                    $container->set($name, $definition);
                }
            })
            ->build();
    }
}