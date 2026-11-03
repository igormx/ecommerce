<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use App\Application\Actions\ActionError;
use App\Application\Actions\ActionPayload;
use PHPUnit\Framework\TestCase;

class ActionPayloadTest extends TestCase
{
    public function testMetaIsOmittedFromSerializationWhenNull(): void
    {
        $payload = new ActionPayload(200, ['id' => 1]);

        $this->assertArrayNotHasKey('meta', $payload->jsonSerialize());
        $this->assertNull($payload->getMeta());
    }

    public function testMetaIsSerializedWhenSet(): void
    {
        $meta = ['page' => 1, 'size' => 20, 'total' => 90];
        $payload = new ActionPayload(200, [], null, $meta);

        $serialized = $payload->jsonSerialize();

        $this->assertArrayHasKey('meta', $serialized);
        $this->assertSame($meta, $serialized['meta']);
        $this->assertSame($meta, $payload->getMeta());
    }

    public function testMetaIsOmittedOnErrorPayloads(): void
    {
        $payload = new ActionPayload(404, null, new ActionError(ActionError::RESOURCE_NOT_FOUND, 'not found'));

        $this->assertArrayNotHasKey('meta', $payload->jsonSerialize());
    }
}
