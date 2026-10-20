<?php

namespace Base\Marketplace\Attribute;

use Base\Attributes\AbstractAttribute;
use Base\Attributes\AttributeReader;
use Base\Entity\User;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * A human order reference, generated once on the first flush:
 *
 *     #[OrderReference(format: 'CCC-XXXX-YYY')]
 *
 *   C  the buyer's country (from their cookie), as digits
 *   X  random digits
 *   Y  the date, down to the minute
 *
 * Found by base-bundle's AttributeReader because MarketplaceExtension adds this
 * directory to base.attributes.paths.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class OrderReference extends AbstractAttribute
{
    public function __construct(protected string $format = 'CCC-XXXX-YYY')
    {
    }

    public function supports(string $target, ?string $targetValue = null, $object = null): bool
    {
        return AttributeReader::TARGET_PROPERTY == $target;
    }

    public function generate(): string
    {
        $code = $this->format;
        $code = preg_replace('/C+/i', abc2dec(User::getCookie('country') ?? 'UN'), $code);
        $code = preg_replace('/X+/i', rand_int(substr_count($this->format, 'X')), $code);
        $code = preg_replace('/Y+/i', str_pad(date('yhdsmi'), 12, '0', STR_PAD_LEFT), $code);

        return str_strip_chars($code);
    }

    /**
     * A reference no other order holds, neither stored nor flushed with this
     * one. The format leaves only its X digits to chance within a second (the
     * country and the date are the same for every order of that second): two
     * orders made in the same second drew the same reference once in 9 000
     * pairs, and the column being unique, the second flush failed. Seen in the
     * suite (SupplyTest, which makes orders a few milliseconds apart), and as
     * likely for two buyers paying in the same second.
     */
    public function unique(OnFlushEventArgs $event, mixed $entity, string $property): string
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        $repository = $this->getPropertyOwnerRepository($entity, $property);
        for ($try = 0; $try < self::TRIES; ++$try) {
            $code = $this->generate();
            if (!$this->isTaken($code, $unitOfWork, $repository, $entity, $property)) {
                return $code;
            }
        }

        // Every draw taken: the format cannot tell them apart any more, a suffix does.
        do {
            $code = $this->generate().'-'.rand_int(4);
        } while ($this->isTaken($code, $unitOfWork, $repository, $entity, $property));

        return $code;
    }

    private const TRIES = 20;

    private function isTaken(string $code, $unitOfWork, $repository, mixed $entity, string $property): bool
    {
        $class = $repository->getClassName();
        foreach ($unitOfWork->getScheduledEntityInsertions() as $other) {
            if ($other !== $entity && $other instanceof $class && $this->getFieldValue($other, $property) === $code) {
                return true;
            }
        }

        $holder = $repository->findOneBy([$property => $code]);

        return null !== $holder && $holder !== $entity;
    }

    public function onFlush(OnFlushEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null)
    {
        if (null === $this->getFieldValue($entity, $property)) {
            $this->setFieldValue($entity, $property, $this->unique($event, $entity, $property));
            if ($this->getUnitOfWork()->getEntityChangeSet($entity)) {
                $this->getUnitOfWork()->recomputeSingleEntityChangeSet($classMetadata, $entity);
            }
        }
    }
}
