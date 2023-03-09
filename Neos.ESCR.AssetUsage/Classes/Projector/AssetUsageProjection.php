<?php
declare(strict_types=1);

namespace Neos\ESCR\AssetUsage\Projector;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ESCR\AssetUsage\Dto\AssetIdsByProperty;
use Neos\ContentRepository\Core\Feature\ContentStreamForking\Event\ContentStreamWasForked;
use Neos\ContentRepository\Core\Feature\ContentStreamRemoval\Event\ContentStreamWasRemoved;
use Neos\ESCR\AssetUsage\Dto\NodeAddress;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodePeerVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPartiallyDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPartiallyPublished;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceWasRebased;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\Media\Domain\Model\ResourceBasedInterface;
use Neos\Utility\Exception\InvalidTypeException;
use Neos\Utility\TypeHandling;
use Neos\ESCR\AssetUsage\AssetUsageFinder;
use Neos\EventStore\DoctrineAdapter\DoctrineCheckpointStorage;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Doctrine\DBAL\Connection;
use Neos\EventStore\CatchUp\CatchUp;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Factory\ContentRepositoryId;

/**
 *
 * @implements ProjectionInterface<AssetUsageFinder>
 */
final class AssetUsageProjection implements ProjectionInterface
{
    private ?AssetUsageFinder $stateAccessor = null;
    private AssetUsageRepository $repository;
    private DoctrineCheckpointStorage $checkpointStorage;

    public function __construct(
        private readonly EventNormalizer $eventNormalizer,
        ContentRepositoryId $contentRepositoryId,
        Connection $dbal,
        AssetUsageRepositoryFactory $assetUsageRepositoyFactory,
    ) {
        $this->repository = $assetUsageRepositoyFactory->build($contentRepositoryId);
        $this->checkpointStorage = new DoctrineCheckpointStorage(
            $dbal,
            $this->repository->getTableNamePrefix() . '_checkpoint',
            self::class
        );
    }

    public function reset(): void
    {
        $this->repository->reset();
        $this->checkpointStorage->acquireLock();
        $this->checkpointStorage->updateAndReleaseLock(SequenceNumber::none());
    }

    public function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event): void
    {
        try {
            $assetIdsByProperty = $this->getAssetIdsByProperty($event->initialPropertyValues);
        } catch (InvalidTypeException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to extract asset ids from event "%s": %s',
                    $event->getNodeAggregateId(),
                    $e->getMessage()
                ),
                1646321894,
                $e
            );
        }
        $nodeAddress = new NodeAddress(
            $event->contentStreamId,
            $event->originDimensionSpacePoint->toDimensionSpacePoint(),
            $event->nodeAggregateId,
            WorkspaceName::fromString('live')
        );
        $this->repository->addUsagesForNode($nodeAddress, $assetIdsByProperty);
    }

    public function whenNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        try {
            $assetIdsByProperty = $this->getAssetIdsByProperty($event->propertyValues);
        } catch (InvalidTypeException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to extract asset ids from event "%s": %s',
                    $event->getNodeAggregateId(),
                    $e->getMessage()
                ),
                1646321894,
                $e
            );
        }
        $nodeAddress = new NodeAddress(
            $event->getContentStreamId(),
            $event->getOriginDimensionSpacePoint()->toDimensionSpacePoint(),
            $event->getNodeAggregateId(),
            WorkspaceName::fromString('live')
        );
        $this->repository->addUsagesForNode($nodeAddress, $assetIdsByProperty);
    }

    public function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        $this->repository->removeNode(
            $event->getNodeAggregateId(),
            $event->affectedOccupiedDimensionSpacePoints->toDimensionSpacePointSet()
        );
    }


    public function whenNodePeerVariantWasCreated(NodePeerVariantWasCreated $event): void
    {
        $this->repository->copyDimensions($event->sourceOrigin, $event->peerOrigin);
    }

    public function whenContentStreamWasForked(ContentStreamWasForked $event): void
    {
        $this->repository->copyContentStream(
            $event->sourceContentStreamId,
            $event->newContentStreamId
        );
    }

    public function whenWorkspaceWasDiscarded(WorkspaceWasDiscarded $event): void
    {
        $this->repository->removeContentStream($event->previousContentStreamId);
    }

    public function whenWorkspaceWasPartiallyDiscarded(WorkspaceWasPartiallyDiscarded $event): void
    {
        $this->repository->removeContentStream($event->previousContentStreamId);
    }

    public function whenWorkspaceWasPartiallyPublished(WorkspaceWasPartiallyPublished $event): void
    {
        $this->repository->removeContentStream($event->previousSourceContentStreamId);
    }

    public function whenWorkspaceWasPublished(WorkspaceWasPublished $event): void
    {
        $this->repository->removeContentStream($event->previousSourceContentStreamId);
    }

    public function whenWorkspaceWasRebased(WorkspaceWasRebased $event): void
    {
        $this->repository->removeContentStream($event->previousContentStreamId);
    }

    public function whenContentStreamWasRemoved(ContentStreamWasRemoved $event): void
    {
        $this->repository->removeContentStream($event->contentStreamId);
    }


    // ----------------

    /**
     * @throws InvalidTypeException
     */
    private function getAssetIdsByProperty(SerializedPropertyValues $propertyValues): AssetIdsByProperty
    {
        /** @var array<string, array<string>> $assetIdentifiers */
        $assetIdentifiers = [];
        /** @var \Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValue $propertyValue */
        foreach ($propertyValues as $propertyName => $propertyValue) {
            $assetIdentifiers[$propertyName] = $this->extractAssetIdentifiers(
                $propertyValue->getType(),
                $propertyValue->getValue()
            );
        }
        return new AssetIdsByProperty($assetIdentifiers);
    }

    /**
     * @param string $type
     * @param mixed $value
     * @return array<string>
     * @throws InvalidTypeException
     */
    private function extractAssetIdentifiers(string $type, mixed $value): array
    {
        if ($type === 'string' || is_subclass_of($type, \Stringable::class, true)) {
            // @phpstan-ignore-next-line
            preg_match_all('/asset:\/\/(?<assetId>[\w-]*)/i', (string) $value, $matches, PREG_SET_ORDER);
            return array_map(static fn(array $match) => $match['assetId'], $matches);
        }
        if (is_subclass_of($type, ResourceBasedInterface::class, true)) {
            // @phpstan-ignore-next-line
            return isset($value['__identifier']) ? [$value['__identifier']] : [];
        }

        // Collection type?
        /** @var array{type: string, elementType: string|null, nullable: bool} $parsedType */
        $parsedType = TypeHandling::parseType($type);
        if ($parsedType['elementType'] === null) {
            return [];
        }
        if (!is_subclass_of($parsedType['elementType'], ResourceBasedInterface::class, true)
            && !is_subclass_of($parsedType['elementType'], \Stringable::class, true)) {
            return [];
        }
        /** @var array<array<string>> $assetIdentifiers */
        $assetIdentifiers = [];
        /** @var iterable<mixed> $value */
        foreach ($value as $elementValue) {
            $assetIdentifiers[] = $this->extractAssetIdentifiers($parsedType['elementType'], $elementValue);
        }
        return array_merge(...$assetIdentifiers);
    }

    public function setUp(): void
    {
        $this->repository->setup();
        $this->checkpointStorage->setup();
    }

    public function canHandle(Event $event): bool
    {
        $eventClassName = $this->eventNormalizer->getEventClassName($event);
        return in_array($eventClassName, [
            NodeAggregateWithNodeWasCreated::class,
            NodePropertiesWereSet::class,
            NodeAggregateWasRemoved::class,
            NodePeerVariantWasCreated::class,
            ContentStreamWasForked::class,
            WorkspaceWasDiscarded::class,
            WorkspaceWasPartiallyDiscarded::class,
            WorkspaceWasPartiallyPublished::class,
            WorkspaceWasPublished::class,
            WorkspaceWasRebased::class,
            ContentStreamWasRemoved::class,
        ]);
    }

    public function catchUp(EventStreamInterface $eventStream, ContentRepository $contentRepository): void
    {
        $catchUp = CatchUp::create($this->apply(...), $this->checkpointStorage);
        $catchUp->run($eventStream);
    }

    private function apply(\Neos\EventStore\Model\EventEnvelope $eventEnvelope): void
    {
        if (!$this->canHandle($eventEnvelope->event)) {
            return;
        }

        $eventInstance = $this->eventNormalizer->denormalize($eventEnvelope->event);

        match ($eventInstance::class) {
            NodeAggregateWithNodeWasCreated::class => $this->whenNodeAggregateWithNodeWasCreated($eventInstance, $eventEnvelope),
            NodePropertiesWereSet::class => $this->whenNodePropertiesWereSet($eventInstance, $eventEnvelope),
            NodeAggregateWasRemoved::class => $this->whenNodeAggregateWasRemoved($eventInstance),
            NodePeerVariantWasCreated::class => $this->whenNodePeerVariantWasCreated($eventInstance),
            ContentStreamWasForked::class => $this->whenContentStreamWasForked($eventInstance),
            WorkspaceWasDiscarded::class => $this->whenWorkspaceWasDiscarded($eventInstance),
            WorkspaceWasPartiallyDiscarded::class => $this->whenWorkspaceWasPartiallyDiscarded($eventInstance),
            WorkspaceWasPartiallyPublished::class => $this->whenWorkspaceWasPartiallyPublished($eventInstance),
            WorkspaceWasPublished::class => $this->whenWorkspaceWasPublished($eventInstance),
            WorkspaceWasRebased::class => $this->whenWorkspaceWasRebased($eventInstance),
            ContentStreamWasRemoved::class => $this->whenContentStreamWasRemoved($eventInstance),
        };
    }

    public function getSequenceNumber(): SequenceNumber
    {
        return $this->checkpointStorage->getHighestAppliedSequenceNumber();
    }

    public function getState(): AssetUsageFinder
    {
        if (!$this->stateAccessor) {
            $this->stateAccessor = new AssetUsageFinder($this->repository);
        }
        return $this->stateAccessor;
    }
}
