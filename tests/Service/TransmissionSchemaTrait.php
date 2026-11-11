<?php

namespace Tests\Base\Marketplace\Service;

use Doctrine\ORM\Tools\SchemaTool;

/**
 * The tables of the invoices' transmission (Invoice\Transmission\Entity),
 * created when the database has them not: in omnisense/omnibase's harness
 * the schema is made before omnisense/omnibill is known to the suite
 * (tests/bootstrap.php registers it), so they are missing there.
 */
trait TransmissionSchemaTrait
{
    private function createTransmissionSchema(): void
    {
        $missing = [];
        $tables = $this->entityManager->getConnection()->createSchemaManager()->listTableNames();
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (str_starts_with($metadata->getName(), 'Base\\Marketplace\\Invoice\\Transmission\\Entity\\') && !\in_array($metadata->getTableName(), $tables, true)) {
                $missing[] = $metadata;
            }
        }
        if ([] !== $missing) {
            (new SchemaTool($this->entityManager))->createSchema($missing);
        }
    }
}
