<?php


namespace wenbinye\tars\call;


use kuiper\annotations\AnnotationReaderInterface;
use kuiper\helper\Text;
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
     * @var TarsProxyFactory
     */
    private $tarsProxyFactory;

    /**
     * @var TypeParser
     */
    private $typeParser;
    /**
     * @var AnnotationReaderInterface
     */
    private $annotationReader;

    /**
     * @var array
     */
    private $services;

    /**
     * TarsCaller constructor.
     * @param TarsServantResolver $tarsServantResolver
     * @param AnnotationReaderInterface $annotationReader
     * @param TarsNormalizer $tarsNormalizer
     */
    public function __construct(
        TarsServantResolver $tarsServantResolver,
        AnnotationReaderInterface $annotationReader,
        TarsNormalizer $tarsNormalizer,
        TarsProxyFactory $tarsProxyFactory)
    {
        $this->tarsServantResolver = $tarsServantResolver;
        $this->typeParser = new TypeParser($annotationReader);
        $this->annotationReader = $annotationReader;
        $this->tarsNormalizer = $tarsNormalizer;
        $this->tarsProxyFactory = $tarsProxyFactory;
    }

    public function call(TarsCallContext $context)
    {
        [$servantClass, $service] = $this->createService($context);
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
     * @throws \ReflectionException
     */
    protected function createService(TarsCallContext $context): array
    {
        $servantClass = $this->tarsServantResolver->resolve($context->getServant());
        if (!$servantClass) {
            throw new \InvalidArgumentException("Cannot find {$context->getServant()}");
        }

        $key = md5(serialize([$context->getAddress(), $servantClass, $context->getServant()]));
        if (!isset($this->services[$key])) {
            $this->services[$key] = $this->tarsProxyFactory
                ->create($servantClass, [
                    'recv_timeout' => 60,
                    'service' => $context->getServant(),
                    'endpoint' => $context->getAddress()
                ]);
        }
        return [$servantClass, $this->services[$key]];
    }
}