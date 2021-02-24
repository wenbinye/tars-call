<?php


namespace wenbinye\tars\call;


use kuiper\di\ComponentCollection;
use kuiper\di\ContainerBuilder;
use kuiper\helper\Text;
use kuiper\serializer\DocReaderInterface;
use kuiper\serializer\NormalizerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use wenbinye\tars\protocol\annotation\TarsClient as TarsClientAnnotation;
use wenbinye\tars\rpc\route\ChainRouteResolver;
use wenbinye\tars\rpc\route\InMemoryRouteResolver;
use wenbinye\tars\rpc\route\Route;
use wenbinye\tars\rpc\TarsClient;
use wenbinye\tars\server\framework\servant\HealthCheckServant;
use function kuiper\helper\env;

class TarsCallCommand extends Command
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var StyleInterface
     */
    private $io;

    protected function configure(): void
    {
        $this->setName("tars:call");
        $this->addOption("data", "d", InputOption::VALUE_REQUIRED, "Data file");
        $this->addOption("check", "c", InputOption::VALUE_NONE, "Do health check");
        $this->addOption("address", "a", InputOption::VALUE_REQUIRED, "server host and port");
        $this->addOption("registry", "r", InputOption::VALUE_REQUIRED, "registry host and port");
        $this->addArgument("server", InputArgument::OPTIONAL, "server name");
        $this->addArgument("params", InputArgument::OPTIONAL, "parameters");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->input = $input;
        if ($input->getOption("check")) {
            $this->doHealthCheck($input->getArgument("server"));
            return 0;
        }
        $container = ContainerBuilder::create(__DIR__ . '/..')->build();

        if ($input->getOption("data")) {
            $data = $this->getDataParams($input->getOption('data'));
            $this->doCall($container, $data);
        } else {
            $servant = $input->getArgument("server");
            $pos = strrpos($servant, '.');
            $params = json_decode($input->getArgument('params'), true);
            $data = [
                'servant' => substr($servant, 0, $pos),
                'method' => substr($servant, $pos+1),
                'params' => $params
            ];
            $this->doCall($container, $data);
        }

        return 0;
    }

    private function doHealthCheck(?string $server): void
    {
        if (empty($server)) {
            throw new \InvalidArgumentException("Argument server is required");
        }
        $address = $this->input->getOption("address");
        [$host, $port] = explode(":", $address);
        $servantName = $server . ".HealthCheckObj";
        /** @var HealthCheckServant $service */
        $service = TarsClient::builder()
            ->setRouteResolver(new InMemoryRouteResolver([
                Route::fromString($servantName . "@tcp -h $host -p $port")
            ]))
            ->createProxy(HealthCheckServant::class, $servantName);
        $start = microtime(true);
        try {
            $ret = $service->ping();
            if ($ret === 'pong') {
                $respTime = round((microtime(true) - $start) * 1000, 2);
                $this->io->success("$server($address) response in {$respTime}ms");
            } else {
                throw new \RuntimeException("response '$ret' is invalid");
            }
        } catch (\Exception $e) {
            $this->io->error("$server($address) cannot reach " . $e);
        }
    }

    private function doCall(ContainerInterface $container, array $data): void
    {
        [$registryHost, $registryPort] = explode(":", $this->getRegistryAddress());

        $servantName = $data['servant'];
        $parts = explode('.', $servantName);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException("servant '$servantName' 不正确，必须是 app.server.servantObj 形式");
        }
        $routes = [];
        $address = $this->input->getOption("address") ?? $data['server_addr'] ?? null;
        if ($address) {
            [$host, $port] = explode(":", $address);
            $routes = [
                Route::fromString($servantName . "@tcp -h $host -p $port")
            ];
        }
        $servantClass = null;
        foreach (ComponentCollection::getAnnotations(TarsClientAnnotation::class) as $annotation) {
            /** @var TarsClientAnnotation $annotation */
            if ($annotation->name === $servantName) {
                $servantClass = $annotation->getTarget()->getName();
                break;
            }
        }
        if (!$servantClass) {
            throw new \InvalidArgumentException("Cannot find $servantName");
        }
        $builder = TarsClient::builder()
            ->setLocator(Route::fromString("tars.tarsregistry.QueryObj@tcp -h $registryHost -p $registryPort"));
        if (!empty($routes)) {
            $builder->setRouteResolver(new ChainRouteResolver([
                new InMemoryRouteResolver($routes),
                $builder->getRouteResolver()
            ]));
        }
        $service = $builder->createProxy($servantClass, $servantName);
        $normalizer = $container->get(NormalizerInterface::class);
        $docReader = $container->get(DocReaderInterface::class);
        $method = new \ReflectionMethod($servantClass, $data['method']);
        $params = [];
        foreach ($docReader->getParameterTypes($method) as $i => $type) {
            $params[] = $normalizer->denormalize($data['params'][$i], $type);
        }
        $ret = call_user_func_array([$service, $method->getName()], $params);
        echo json_encode($ret, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    }

    private function getRegistryAddress(): string
    {
        $registry = $this->input->getOption("registry");
        if (Text::isEmpty($registry)) {
            return env('TARS_REGISTRY', '127.0.0.1:17890');
        }
        return $registry;
    }

    /**
     * @param string $input
     * @return mixed
     */
    protected function getDataParams(string $input)
    {
        if (file_exists($input)) {
            $input = file_get_contents($input);
        }
        $data = json_decode($input, true);
        if ($data === null) {
            throw new \InvalidArgumentException("json 解析错误");
        }
        if (isset($data['extra']['params'])) {
            $params = json_decode(str_replace("'", '"',
                str_replace("\\'", '\\"', $data['extra']['params'])), true);
            if ($params === null) {
                throw new \InvalidArgumentException("extra.params 解析错误");
            }
            $data['params'] = $params;
        }
        return $data;
    }
}