<?php


namespace wenbinye\tars\call;

use DateTime;
use DI\Annotation\Inject;
use Exception;
use kuiper\annotations\AnnotationReader;
use kuiper\annotations\AnnotationReaderInterface;
use kuiper\di\annotation\Bean;
use kuiper\di\annotation\Configuration;
use kuiper\di\ContainerBuilderAwareTrait;
use kuiper\di\DefinitionConfiguration;
use kuiper\helper\Enum;
use kuiper\serializer\DocReader;
use kuiper\serializer\DocReaderInterface;
use kuiper\serializer\normalizer\DateTimeNormalizer;
use kuiper\serializer\normalizer\EnumNormalizer;
use kuiper\serializer\normalizer\ExceptionNormalizer;
use kuiper\serializer\NormalizerInterface;
use kuiper\serializer\Serializer;
use Psr\Container\ContainerInterface;
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
            NormalizerInterface::class => get(Serializer::class),
        ];
    }

    /**
     * @Bean()
     * @Inject({"normalizers": "Normalizers"})
     */
    public function serializer(AnnotationReaderInterface $annotationReader, DocReaderInterface $docReader, array $normalizers): Serializer
    {
        return new Serializer($annotationReader, $docReader, $normalizers);
    }

    /**
     * @Bean("Normalizers")
     */
    public function normalizers(ContainerInterface $container): array
    {
        return [
            DateTime::class => $container->get(DateTimeNormalizer::class),
            Enum::class => $container->get(EnumNormalizer::class),
            Exception::class => $container->get(ExceptionNormalizer::class),
        ];
    }
}