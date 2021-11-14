<?php

declare(strict_types=1);

namespace PHPSTORM_META;

    use kuiper\tars\client\TarsProxyFactory;

    override(\Psr\Container\ContainerInterface::get(0), map([
        '' => '@',
    ]));

    override(TarsProxyFactory::create(0), map([
        '' => '@',
    ]));
