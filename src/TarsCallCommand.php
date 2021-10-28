<?php


namespace wenbinye\tars\call;


use kuiper\di\ComponentCollection;
use kuiper\di\ContainerBuilder;
use kuiper\helper\Text;
use kuiper\reflection\ReflectionDocBlockFactoryInterface;
use kuiper\reflection\ReflectionMethodDocBlockInterface;
use kuiper\rpc\client\RpcExecutor;
use kuiper\rpc\MiddlewareInterface;
use kuiper\rpc\RpcRequestHandlerInterface;
use kuiper\rpc\RpcRequestInterface;
use kuiper\rpc\RpcResponseInterface;
use kuiper\serializer\NormalizerInterface;
use kuiper\swoole\Application;
use kuiper\tars\annotation\TarsClient;
use kuiper\tars\client\TarsProxyFactory;
use kuiper\tars\client\TarsRequest;
use kuiper\tars\integration\QueryFServant;
use kuiper\tars\server\servant\AdminServant;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        $this->addOption("resolve", null, InputOption::VALUE_NONE, "Do service resolve");
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
        if ($input->getOption("resolve")) {
            $this->doRegistryQuery($input->getArgument("server"));
            return 0;
        }
        $container = Application::create()->getContainer();

        if ($input->getOption("data")) {
            $data = $this->getDataParams($input->getOption('data'));
            $this->doCall($container, $data);
        } else {
            $servant = $input->getArgument("server");
            $pos = strrpos($servant, '.');
            $params = json_decode($input->getArgument('params'), true);
            $data = [
                'servant' => substr($servant, 0, $pos),
                'method' => substr($servant, $pos + 1),
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
        $servantName = $server . ".AdminObj";
        /** @var AdminServant $service */
        $service = TarsProxyFactory::createDefault($servantName . "@tcp -h $host -p $port")
            ->create(AdminServant::class, [
                'service' => $servantName
            ]);
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
        $servantName = $data['servant'] ?? $data['service'];
        $parts = explode('.', $servantName);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException("servant '$servantName' 不正确，必须是 app.server.servantObj 形式");
        }
        $routes = [$this->getRegistryAddress()];
        $address = $this->input->getOption("address") ?? $data['server_addr'] ?? null;
        if ($address) {
            [$host, $port] = explode(":", $address);
            $routes[] = $servantName . "@tcp -h $host -p $port";
        }
        $servantClass = null;
        foreach (ComponentCollection::getAnnotations(TarsClient::class) as $annotation) {
            /** @var TarsClient $annotation */
            if ($annotation->service === $servantName) {
                $servantClass = $annotation->getTargetClass();
                break;
            }
        }
        if (!$servantClass) {
            throw new \InvalidArgumentException("Cannot find $servantName");
        }
        $service = TarsProxyFactory::createDefault(...$routes)
            ->create($servantClass);
        $normalizer = $container->get(NormalizerInterface::class);
        /** @var ReflectionMethodDocBlockInterface $docReader */
        $docReader = $container->get(ReflectionDocBlockFactoryInterface::class)
            ->createMethodDocBlock(new \ReflectionMethod($servantClass, $data['method']));
        $params = [];
        $paramNames = array_keys($docReader->getParameterTypes());
        foreach (array_values($docReader->getParameterTypes()) as $i => $type) {
            $params[] = $normalizer->denormalize($data['params'][$i] ?? $data['params'][$paramNames[$i]], $type);
        }
        if (isset($data['request_status']) || isset($data['request_context'])) {
            $executor = RpcExecutor::create($service, $data['method'], $params);
            $middleware = new class implements MiddlewareInterface {
                public $data;

                public function process(RpcRequestInterface $request, RpcRequestHandlerInterface $handler): RpcResponseInterface
                {
                    /** @var TarsRequest $request */
                    if (isset($this->data['request_context'])) {
                        $request->setContext($this->data['request_context']);
                    }
                    if (isset($this->data['request_status'])) {
                        $request->setStatus($this->data['request_status']);
                    }
                    return $handler->handle($request);
                }
            };
            $middleware->data = $data;
            $ret = $executor->addMiddleware($middleware)->execute();
        } else {
            $ret = call_user_func_array([$service, $data['method']], $params);
        }
        echo json_encode($ret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function getRegistryAddress(): string
    {
        $registry = $this->input->getOption("registry");
        if (Text::isEmpty($registry)) {
            $registry = env('TARS_REGISTRY', '127.0.0.1:17890');
        }
        [$host, $port]  = explode(':',  $registry);
        return "tars.tarsregistry.QueryObj@tcp -h $host -p $port";
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

    private function doRegistryQuery(string $server): void
    {
        if (empty($server)) {
            throw new \InvalidArgumentException("Argument server is required");
        }

        /** @var QueryFServant $service */
        $service = TarsProxyFactory::createDefault($this->getRegistryAddress())
            ->create(QueryFServant::class);
        echo json_encode($service->findObjectById($server), JSON_PRETTY_PRINT), "\n";
    }
}