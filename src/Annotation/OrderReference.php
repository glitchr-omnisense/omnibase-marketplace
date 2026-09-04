<?php

namespace Base\Market\Annotation;

use Base\Entity\User;
use Base\Annotations\AbstractAnnotation;
use Base\Annotations\AnnotationReader;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Class OrderReference
 * package Base\Market\Annotation\OrderReference.
 *
 * @Annotation
 *
 * @Target({"PROPERTY"})
 *
 * @Attributes({
 *
 *   @Attribute("format", type = "string"),
 * })
 */
class OrderReference extends AbstractAnnotation
{
    /**
     * @var mixed
     */
    protected mixed $format;

    /**
     * @param array $data
     */
    public function __construct(array $data)
    {
        // Determine version
        $this->format = $data['format'];
    }

    /**
     * @param string $target
     * @param string|null $targetValue
     * @param $object
     * @return bool
     */
    public function supports(string $target, ?string $targetValue = null, $object = null): bool
    {
        return AnnotationReader::TARGET_PROPERTY == $target;
    }

    /**
     * @return string
     */
    public function generate(): string
    {
        $code = $this->format;
        $code = preg_replace('/C+/i', abc2dec(User::getCookie('country') ?? 'UN'), $code);
        $code = preg_replace('/X+/i', rand_int(substr_count($this->format, 'X')), $code);
        $code = preg_replace('/Y+/i', str_pad(date('yhdsmi'), 12, '0', STR_PAD_LEFT), $code);

        return str_strip_chars($code);
    }

    /**
     * @param OnFlushEventArgs $event
     * @param ClassMetadata $classMetadata
     * @param $entity
     * @param string|null $property
     * @return void
     */
    public function onFlush(OnFlushEventArgs $event, ClassMetadata $classMetadata, $entity, ?string $property = null)
    {
        if (null === $this->getFieldValue($entity, $property)) {
            $this->setFieldValue($entity, $property, $this->generate());
            if ($this->getUnitOfWork()->getEntityChangeSet($entity)) {
                $this->getUnitOfWork()->recomputeSingleEntityChangeSet($classMetadata, $entity);
            }
        }
    }
}
