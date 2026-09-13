<?php

namespace Base\Market\Attribute;

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
 * Found by base-bundle's AttributeReader because MarketExtension adds this
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

    public function onFlush(OnFlushEventArgs $event, ClassMetadata $classMetadata, mixed $entity, ?string $property = null)
    {
        if (null === $this->getFieldValue($entity, $property)) {
            $this->setFieldValue($entity, $property, $this->generate());
            if ($this->getUnitOfWork()->getEntityChangeSet($entity)) {
                $this->getUnitOfWork()->recomputeSingleEntityChangeSet($classMetadata, $entity);
            }
        }
    }
}
