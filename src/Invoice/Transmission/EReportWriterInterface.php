<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Invoice\Transmission\Entity\EReport;

/**
 * Writes an e-reporting file from the data EReporting collected for a
 * period (EReport::getData()): the file the DGFiP's external specifications
 * define (flux 10), which neither omnisense/omnibill nor this bundle writes -
 * the format is not the family's, and this bundle was written without
 * reading it. An application that has a writer registers it as a service
 * under this interface (autowired); without one, each period stays
 * COLLECTED, its data kept for whoever transmits it.
 */
interface EReportWriterInterface
{
    /**
     * @return array{content: string, filename: string, rule?: string|null}|null null: no file for this period
     */
    public function write(EReport $report): ?array;
}
