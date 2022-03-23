<?php


namespace wenbinye\tars\call;


class TarsCallContext
{
    /**
     * @var string
     */
    private $servant;
    /**
     * @var string
     */
    private $method;
    /**
     * @var array
     */
    private $params;
    /**
     * @var array
     */
    private $context;
    /**
     * @var array
     */
    private $statuses;
    /**
     * @var string|null
     */
    private $address;
    /**
     * @var array
     */
    private $options;
    /**
     * @var string|null
     */
    private $registry;

    /**
     * TarsCallContext constructor.
     * @param string $servant
     * @param string $method
     * @param array $params
     * @param array $context
     * @param array $statuses
     */
    public function __construct(string $servant, string $method, array $params, array $context = [], array $statuses = [])
    {
        $this->servant = $servant;
        $this->method = $method;
        $this->params = $params;
        $this->context = $context;
        $this->statuses = $statuses;
        $this->options = [];
    }

    /**
     * @return string
     */
    public function getServant(): string
    {
        return $this->servant;
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return array
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }

    /**
     * @return string|null
     */
    public function getAddress(): ?string
    {
        return $this->address;
    }

    /**
     * @param string|null $address
     */
    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * @return string|null
     */
    public function getRegistry(): ?string
    {
        return $this->registry;
    }

    /**
     * @param string|null $registry
     */
    public function setRegistry(?string $registry): void
    {
        $this->registry = $registry;
    }
}