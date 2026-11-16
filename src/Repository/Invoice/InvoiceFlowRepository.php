<?php

namespace Base\Marketplace\Repository\Invoice;

use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;

/**
 * Where the invoices stand on their gateways (Invoice\Transmission\Entity\InvoiceFlow). Mapped only when
 * omnisense/omnibill is installed: building the repository reads no mapping, so a site without it never notices.
 *
 * @extends ServiceEntityRepository<InvoiceFlow>
 */
class InvoiceFlowRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, InvoiceFlow::class);
    }

    /**
     * The flows with a status declared and not taken yet.
     *
     * @return list<InvoiceFlow>
     */
    public function withUnsentDeclarations(): array
    {
        // Partial on a JSON field: a LIKE on its text, on both engines - a declaration not sent says "sent": false.
        return $this->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('declared', '%false%', Parser::OPTION_PARTIAL))]);
    }
}
