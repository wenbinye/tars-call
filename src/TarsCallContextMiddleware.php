<?php


namespace wenbinye\tars\call;


use kuiper\rpc\MiddlewareInterface;
use kuiper\rpc\RpcRequestHandlerInterface;
use kuiper\rpc\RpcRequestInterface;
use kuiper\rpc\RpcResponseInterface;
use kuiper\tars\client\TarsRequest;

class TarsCallContextMiddleware implements MiddlewareInterface
{
    /**
     * @var TarsCallContext
     */
    private $context;

    /**
     *  constructor.
     * @param TarsCallContext $context
     */
    public function __construct(TarsCallContext $context)
    {
        $this->context = $context;
    }

    public function process(RpcRequestInterface $request, RpcRequestHandlerInterface $handler): RpcResponseInterface
    {
        /** @var TarsRequest $request */
        if (!empty($this->context->getContext())) {
            $request->setContext($this->context->getContext());
        }
        if (!empty($this->context->getStatuses())) {
            $request->setStatus($this->context->getStatuses());
        }
        return $handler->handle($request);
    }
}