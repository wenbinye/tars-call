<?php


namespace wenbinye\tars\call;


use kuiper\helper\Text;
use kuiper\swoole\Application;
use kuiper\tars\client\TarsProxyFactory;
use kuiper\tars\integration\QueryFServant;
use kuiper\tars\server\servant\AdminServant;
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
        $app = Application::create();

        if ($input->getOption("data")) {
            $data = $this->getDataParams($input->getOption('data'));
            $context = new TarsCallContext(
                $data['service'],
                $data['method'],
                $data['params'],
                $data['request_context'] ?? [],
                $data['request_status'] ?? []
            );
        } else {
            $servant = $input->getArgument("server");
            $pos = strrpos($servant, '.');
            $params = json_decode($input->getArgument('params'), true);
            $context = new TarsCallContext(
                substr($servant, 0, $pos),
                substr($servant, $pos + 1),
                $params,
                $data['request_context'] ?? [],
                $data['request_status'] ?? []
            );
        }
        $registry = $this->getRegistryAddress();
        if ($registry !== null) {
            $app->getConfig()->set('application.tars.client.locator', $registry);
        }
        $app->getConfig()->set('application.client.service_discovery.enable_dns', true);
        $container = $app->getContainer();
        $address = $this->input->getOption("address") ?? $data['server_addr'] ?? null;
        if (isset($address)) {
            $context->setAddress('tcp://'.$address);
        }
        echo json_encode($container->get(TarsCaller::class)->call($context),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

    private function getRegistryAddress(): ?string
    {
        $registry = $this->input->getOption("registry");
        if (Text::isEmpty($registry)) {
            $registry = env('TARS_REGISTRY');
            if (Text::isEmpty($registry)) {
                return null;
            }
        }
        [$host, $port] = explode(':', $registry);
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
        if (!isset($data['service'])) {
            $data['service'] = $data['servant'];
        }
        if (!isset($data['service'], $data['method'], $data['params'])) {
            throw new \InvalidArgumentException("service, method, params 不能为空");
        }
        return $data;
    }

    private function doRegistryQuery(string $server): void
    {
        if (empty($server)) {
            throw new \InvalidArgumentException("Argument server is required");
        }
        $registry = $this->getRegistryAddress();
        if ($registry === null) {
            throw new \InvalidArgumentException("Registry address not found");
        }

        /** @var QueryFServant $service */
        $service = TarsProxyFactory::createDefault($registry)
            ->create(QueryFServant::class);
        echo json_encode($service->findObjectById($server), JSON_PRETTY_PRINT), "\n";
    }
}