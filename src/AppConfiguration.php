<?php


namespace wenbinye\tars\call;

use kuiper\annotations\AnnotationReader;
use kuiper\annotations\AnnotationReaderInterface;
use kuiper\di\annotation\Configuration;
use kuiper\di\ContainerBuilderAwareTrait;
use kuiper\di\DefinitionConfiguration;
use kuiper\serializer\DocReader;
use kuiper\serializer\DocReaderInterface;
use kuiper\serializer\NormalizerInterface;
use DI;
use kuiper\serializer\Serializer;
use kuiper\serializer\SerializerTest;
use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * @Configuration
 */
class AppConfiguration implements DefinitionConfiguration
{
    use ContainerBuilderAwareTrait;

    public function getDefinitions(): array
    {
        return [
            AnnotationReaderInterface::class => factory([AnnotationReader::class, 'getInstance']),
            DocReaderInterface::class => autowire(DocReader::class),
            NormalizerInterface::class => autowire(Serializer::class),
        ];
    }
}