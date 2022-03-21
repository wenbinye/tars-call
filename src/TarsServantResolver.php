<?php


namespace wenbinye\tars\call;


use kuiper\annotations\AnnotationReaderInterface;
use kuiper\di\ComponentCollection;
use kuiper\helper\Text;
use kuiper\reflection\ReflectionFileFactoryInterface;
use kuiper\reflection\ReflectionNamespace;
use kuiper\rpc\exception\ConnectionException;
use kuiper\rpc\servicediscovery\InMemoryCache;
use kuiper\tars\annotation\TarsClient;
use kuiper\tars\client\TarsProxyFactory;
use kuiper\tars\server\servant\AdminServant;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Filesystem\Filesystem;
use tars\FileGenerateStrategy;
use tars\GeneratorConfig;
use tars\TarsGenerator;
use tars\TarsGeneratorContext;
use Twig\Environment;

class TarsServantResolver implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array
     */
    private static $SERVANTS = [];

    /**
     * @var TarsProxyFactory
     */
    private $tarsProxyFactory;

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var ClassLoader
     */
    private $classLoader;

    /**
     * @var ReflectionFileFactoryInterface
     */
    private $reflectionFileFactory;
    /**
     * @var AnnotationReaderInterface
     */
    private $annotationReader;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * TarsServantResolver constructor.
     * @param Environment $twig
     * @param ClassLoader $classLoader
     * @param AnnotationReaderInterface $annotationReader
     * @param ReflectionFileFactoryInterface $reflectionFileFactory
     */
    public function __construct(Environment $twig, ClassLoader $classLoader, AnnotationReaderInterface $annotationReader, ReflectionFileFactoryInterface $reflectionFileFactory, TarsProxyFactory $tarsProxyFactory)
    {
        $this->twig = $twig;
        $this->classLoader = $classLoader;
        $this->reflectionFileFactory = $reflectionFileFactory;
        $this->annotationReader = $annotationReader;
        $this->cache = new InMemoryCache();
        $this->tarsProxyFactory = $tarsProxyFactory;
    }

    public static function init(): void
    {
        foreach (ComponentCollection::getAnnotations(TarsClient::class) as $annotation) {
            /** @var TarsClient $annotation */
            self::$SERVANTS[$annotation->service] = $annotation->getTargetClass();
        }
    }

    private function evictCache(string $app, string $server): bool
    {
        try {
            $cacheKey = "tars_files.$app.$server";
            $tarsFiles = $this->cache->get($cacheKey);
            if ($tarsFiles === null) {
                $service = $this->createAdminServant($app, $server);
                $tarsFiles = $service->getTarsFiles();
                $this->cache->set($cacheKey, $tarsFiles);
            }
            $cached = true;
            foreach ($tarsFiles as $tarsFile) {
                if (!is_dir($this->classLoader->getPath($app, $server, $tarsFile->md5))) {
                    $cached = false;
                    break;
                }
            }
            if ($cached) {
                $this->scan($app, $server);
            } else {
                foreach (self::$SERVANTS as $name => $class) {
                    if (Text::startsWith($name, "$app.$server.")) {
                        unset(self::$SERVANTS[$name]);
                    }
                }
            }
            return true;
        } catch (ConnectionException $e) {
            return false;
        }
    }

    private function generate(string $app, string $server): void
    {
        try {
            $this->generateTarsCode($app, $server);
            $this->scan($app, $server);
        } catch (ConnectionException $e) {
        }
    }

    private function generateTarsCode(string $app, string $server): void
    {
        $service = $this->createAdminServant($app, $server);
        $dir = $this->classLoader->getPath($app, $server);
        $exists = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) as $name) {
                if (!Text::startsWith($name, '.') && is_dir($dir . '/' . $name)) {
                    $exists[$name] = true;
                }
            }
        }

        foreach ($service->getTarsFileContents() as $tarsFile) {
            $path = $this->classLoader->getPath($app, $server, $tarsFile->md5);
            unset($exists[basename($path)]);
            if (is_dir($path)) {
                continue;
            }

            $file = tempnam(sys_get_temp_dir(), 'tars');
            file_put_contents($file, $tarsFile->content);
            $namespace = $this->classLoader->getNamespace($app, $server, $tarsFile->md5);
            $generatorStrategy = new FileGenerateStrategy($this->twig, GeneratorConfig::fromArray([
                'namespace' => $namespace,
                'psr4_namespace' => $namespace,
                'output' => $path,
                'flat' => true,
            ]));
            $generatorStrategy->setLogger($this->logger);
            $context = new TarsGeneratorContext($generatorStrategy, false, []);
            $generator = new TarsGenerator($context->withFile($file));
            $generator->generate();
            unlink($file);
        }
        $fs = new Filesystem();
        $fs->remove(array_map(static function (string $name) use ($dir) {
            return $dir . "/" . $name;
        }, array_keys($exists)));
    }

    private function scan(string $app, string $server): void
    {
        $reflectionNamespace = new ReflectionNamespace(
            $this->classLoader->getNamespace($app, $server),
            [$this->classLoader->getPath($app, $server)],
            ['php'],
            $this->reflectionFileFactory
        );
        foreach ($reflectionNamespace->getClasses() as $class) {
            /** @var TarsClient|null $annotation */
            $annotation = $this->annotationReader->getClassAnnotation(new \ReflectionClass($class), TarsClient::class);
            if ($annotation !== null) {
                self::$SERVANTS[implode('.', [$app, $server, $annotation->service])] = $class;
            }
        }
    }

    public function resolve(string $servant): ?string
    {
        $parts = explode(".", $servant);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException("Invalid servant name '$servant'");
        }
        [$app, $server, $adapter] = $parts;
        if ($adapter === 'AdminObj') {
            return AdminServant::class;
        }
        $connected = false;
        if ($app !== 'tars') {
            $connected = $this->evictCache($app, $server);
        }
        if ($connected && !isset(self::$SERVANTS[$servant])) {
            $this->generate($app, $server);
        }

        return self::$SERVANTS[$servant] ?? null;
    }

    /**
     * @param string $app
     * @param string $server
     * @return AdminServant
     * @throws \ReflectionException
     */
    private function createAdminServant(string $app, string $server): AdminServant
    {
        return $this->tarsProxyFactory
            ->create(AdminServant::class, [
                'service' => implode(".", [$app, $server, 'AdminObj'])
            ]);
    }
}