<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Service\Invoices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * marketplace:invoice:status [<number>...]: asks the gateway the invoices
 * went through (glitchr/omnibill) where they stand, and keeps it on them -
 * every invoice transmitted whose status is not final (paid, refused,
 * rejected) when none is named. For a platform without webhooks, from a
 * cron. Registered only with glitchr/omnibill.
 */
#[AsCommand(name: 'marketplace:invoice:status', description: 'Ask the invoicing gateway where the invoices sent stand.')]
class InvoiceStatusCommand extends Command
{
    private const FINAL = ['paid', 'refused', 'rejected'];

    public function __construct(
        private readonly Invoices $invoices,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('numbers', InputArgument::IS_ARRAY, 'The invoices\' numbers: F-2026-000041');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $this->entityManager->getRepository(Invoice::class);
        $numbers = (array) $input->getArgument('numbers');
        $invoices = [] !== $numbers
            ? array_filter(array_map(static fn (string $n) => $repository->findOneBy(['number' => $n]), $numbers))
            : $repository->createQueryBuilder('i')
                ->where('i.flowReference IS NOT NULL')
                ->andWhere('i.lifecycleStatus IS NULL OR i.lifecycleStatus NOT IN (:final)')->setParameter('final', self::FINAL)
                ->getQuery()->getResult();
        foreach ($invoices as $invoice) {
            $asked = $this->invoices->refresh($invoice);
            $output->writeln(sprintf('%s: %s', $invoice->getNumber(), $asked ? ($invoice->getLifecycleStatus() ?? 'unknown') : 'not transmitted through a gateway'));
        }

        return Command::SUCCESS;
    }
}
