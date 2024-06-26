<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\NodeVariation\Event;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Common\EmbedsContentStreamAndNodeAggregateId;
use Neos\ContentRepository\Core\Feature\Common\InterdimensionalSiblings;
use Neos\ContentRepository\Core\Feature\Common\PublishableToOtherContentStreamsInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;

/**
 * A node generalization variant was created
 *
 * @api events are the persistence-API of the content repository
 */
final readonly class NodeGeneralizationVariantWasCreated implements
    EventInterface,
    PublishableToOtherContentStreamsInterface,
    EmbedsContentStreamAndNodeAggregateId
{
    public function __construct(
        public ContentStreamId $contentStreamId,
        public NodeAggregateId $nodeAggregateId,
        public OriginDimensionSpacePoint $sourceOrigin,
        public OriginDimensionSpacePoint $generalizationOrigin,
        public InterdimensionalSiblings $variantSucceedingSiblings,
    ) {
    }

    public function getContentStreamId(): ContentStreamId
    {
        return $this->contentStreamId;
    }

    public function getNodeAggregateId(): NodeAggregateId
    {
        return $this->nodeAggregateId;
    }

    public function createCopyForContentStream(
        ContentStreamId $targetContentStreamId
    ): self {
        return new NodeGeneralizationVariantWasCreated(
            $targetContentStreamId,
            $this->nodeAggregateId,
            $this->sourceOrigin,
            $this->generalizationOrigin,
            $this->variantSucceedingSiblings,
        );
    }

    public static function fromArray(array $values): self
    {
        return new self(
            ContentStreamId::fromString($values['contentStreamId']),
            NodeAggregateId::fromString($values['nodeAggregateId']),
            OriginDimensionSpacePoint::fromArray($values['sourceOrigin']),
            OriginDimensionSpacePoint::fromArray($values['generalizationOrigin']),
            array_key_exists('variantSucceedingSiblings', $values)
                ? InterdimensionalSiblings::fromArray($values['variantSucceedingSiblings'])
                : InterdimensionalSiblings::fromDimensionSpacePointSetWithoutSucceedingSiblings(
                    DimensionSpacePointSet::fromArray($values['generalizationCoverage']),
                ),
        );
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
