<?php

namespace App\Services\Tracing;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class TracerProviderFactory
{
    /**
     * @param  SpanExporterInterface|null  $exporter  override (tests use an in-memory exporter)
     */
    public static function make(?SpanExporterInterface $exporter = null): TracerProviderInterface
    {
        if ($exporter === null && ! config('tracing.enabled')) {
            return new NoopTracerProvider;
        }

        $exporter ??= new SpanExporter((new OtlpHttpTransportFactory)->create(
            rtrim((string) config('tracing.endpoint'), '/').'/v1/traces',
            ContentTypes::PROTOBUF,
            timeout: (float) config('tracing.timeout'),
        ));

        return TracerProvider::builder()
            ->addSpanProcessor(BatchSpanProcessor::builder($exporter)->build())
            ->setResource(ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
                'service.name' => config('tracing.service_name'),
                'deployment.environment.name' => config('app.env'),
            ]))))
            ->setSampler(new ParentBased(new AlwaysOnSampler))
            ->build();
    }
}
