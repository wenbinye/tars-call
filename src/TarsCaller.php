<?php


namespace wenbinye\tars\call;


use kuiper\annotations\AnnotationReaderInterface;
use kuiper\helper\Arrays;
use kuiper\helper\Text;
use kuiper\reflection\ReflectionDocBlockFactoryInterface;
use kuiper\rpc\client\RpcExecutor;
use kuiper\tars\annotation\TarsParameter;
use kuiper\tars\annotation\TarsReturnType;
use kuiper\tars\client\TarsProxyFactory;
use kuiper\tars\type\TypeParser;

class TarsCaller
{
    /**
     * @var TarsServantResolver
     */
    private $tarsServantResolver;

    /**
     * @var TarsNormalizer
     */
    private $tarsNormalizer;

    /**
     * @var TypeParser
     */
    private $typeParser;
    /**
     * @var AnnotationReaderInterface
     */
    private $annotationReader;

    /**
     * TarsCaller constructor.
     * @param TarsServantResolver $tarsServantResolver
     * @param AnnotationReaderInterface $annotationReader
     * @param TarsNormalizer $tarsNormalizer
     */
    public function __construct(TarsServantResolver $tarsServantResolver, AnnotationReaderInterface $annotationReader, TarsNormalizer $tarsNormalizer)
    {
        $this->tarsServantResolver = $tarsServantResolver;
        $this->typeParser = new TypeParser($annotationReader);
        $this->annotationReader = $annotationReader;
        $this->tarsNormalizer = $tarsNormalizer;
    }

    public function call(TarsCallContext $context): array
    {
        $routes = $this->prepareServiceEndpoints($context);
        $servantClass = $this->tarsServantResolver->withRoutes($routes)->resolve($context->getServant());
        if (!$servantClass) {
            throw new \InvalidArgumentException("Cannot find {$context->getServant()}");
        }
        $service = TarsProxyFactory::createDefault(...$routes)
            ->create($servantClass, [
                'recv_timeout' => 60,
                'service' => $context->getServant()
            ]);
        $paramData = $context->getParams();
        $method = new \ReflectionMethod($servantClass, $context->getMethod());
        $paramsIndex = [];
        $params = [];
        foreach ($method->getParameters() as $i => $parameter) {
            $paramsIndex[$parameter->getName()] = $i;
        }
        $ns = $method->getDeclaringClass()->getNamespaceName();
        /** @var TarsReturnType $returnType */
        $returnType = $this->annotationReader->getMethodAnnotation($method, TarsReturnType::class);
        $outTypes = [['', $this->typeParser->parse($returnType->value, $ns)]];
        foreach ($this->annotationReader->getMethodAnnotations($method) as $annotation) {
            if ($annotation instanceof TarsParameter) {
                $params[$paramsIndex[$annotation->name]] = $this->tarsNormalizer->denormalize(
                    $paramData[$annotation->name] ?? $paramData[$paramsIndex[$annotation->name]],
                    $this->typeParser->parse($annotation->type, $ns)
                );
                if ($annotation->out) {
                    $outTypes[] = [$annotation->name,  $this->typeParser->parse($annotation->type, $ns)];
                }
            }
        }
        $executor = RpcExecutor::create($service, $context->getMethod(), $params);
        $result = $executor->addMiddleware(new TarsCallContextMiddleware($context))->execute();
        $ret = [];
        foreach ($outTypes as $i => $outType) {
            if (!$outType[1]->isVoid()) {
                $ret[$outType[0]] = $this->tarsNormalizer->normalize($result[$i], $outType[1]);
            }
        }
        if (count($ret) === 1 && isset($ret[''])) {
            return $ret[''];
        } else {
            return $ret;
        }
    }

    /**
     * @param TarsCallContext $context
     * @return array
     */
    private function prepareServiceEndpoints(TarsCallContext $context): array
    {
        $routes = [];
        if (Text::isNotEmpty($context->getRegistry())) {
            $routes[] = $context->getRegistry();
        }
        if (Text::isNotEmpty($context->getAddress())) {
            [$app, $server, $adapter] = explode(".", $context->getServant());
            [$host, $port] = explode(":", $context->getAddress());
            $routes[] = sprintf("%s.%s.AdminObj@tcp -h %s -p %s", $app, $server, $host, $port);
            $routes[] = sprintf("%s@tcp -h %s -p %s", $context->getServant(), $host, $port);
        }
        return $routes;
    }
}