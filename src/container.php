<?php
/**
 * This file is automatic generator by kuiper/component-installer, don't edit it manually
 */
 
return [
    "component_scan" => [
        "kuiper\\tars\\integration",
        "wenbinye\\tars\\client",
        "wenbinye\\tars\\call"
    ],
    "configuration" => [
        'kuiper\\annotations\\AnnotationConfiguration',
        'kuiper\\event\\EventConfiguration',
        'kuiper\\logger\\LoggerConfiguration',
        'kuiper\\reflection\\ReflectionConfiguration',
        'kuiper\\resilience\\ResilienceConfiguration',
        'kuiper\\serializer\\SerializerConfiguration',
        'kuiper\\swoole\\config\\FoundationConfiguration',
        'kuiper\\swoole\\config\\GuzzleHttpMessageFactoryConfiguration',
        'kuiper\\swoole\\config\\DiactorosHttpMessageFactoryConfiguration',
        'kuiper\\tars\\config\\TarsClientConfiguration',
        'kuiper\\cache\\CacheConfiguration',
    ]
];
