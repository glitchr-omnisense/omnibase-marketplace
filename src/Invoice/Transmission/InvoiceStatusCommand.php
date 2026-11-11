<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * marketplace:invoice:status [<number>...]: the catch-up of the invoices'
 * statuses on their gateway (omnisense/omnibill), from a cron:
 *
 *   - each invoice transmitted whose status is not final (paid, refused,
 *     rejected) - or those named - asked where it stands (FetchStatus);
 *   - the lifecycle flows an approved platform has for the shop read from
 *     the shop's cursor: the buyers' approvals, refusals and payments;
 *   - the statuses the shop declared and the gateway did not take (no
 *     lifecycle message writer yet, a platform unreachable) sent again.
 *
 * Registered only with omnisense/omnibill.
 */
#[AsCommand(name: 'marketplace:invoice:status', description: 'Catch up with the invoices\' statuses on their gateway: ask, receive the buyers\' statuses, send the pending ones.')]
class InvoiceStatusCommand extends Command
{
    public function __construct(
        private readonly InvoiceTransmission $transmission,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('numbers', InputArgument::IS_ARRAY, 'The invoices\' numbers: F-2026-000041')
            ->addOption('no-receive', null, InputOption::VALUE_NONE, 'Do not read the lifecycle flows coming in')
            ->addOption('no-send', null, InputOption::VALUE_NONE, 'Do not send the statuses waiting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $numbers = (array) $input->getArgument('numbers');
        $failed = false;
        if ([] !== $numbers) {
            $invoices = array_filter(array_map(fn (string $n) => $this->entityManager->getRepository(Invoice::class)->findOneBy(['number' => $n]), $numbers));
        } else {
            $invoices = array_map(static fn (InvoiceFlow $f) => $f->getInvoice(), $this->entityManager->getRepository(InvoiceFlow::class)->createQueryBuilder('f')
                ->where('f.status IS NULL OR f.status NOT IN (:final)')->setParameter('final', ['paid', 'refused', 'rejected'])
                ->getQuery()->getResult());
        }
        foreach ($invoices as $invoice) {
            try {
                $asked = $this->transmission->refresh($invoice);
                $io->writeln(sprintf('%s: %s', $invoice->getNumber(), null !== $asked ? ($this->transmission->describe($invoice) ?? 'unknown') : 'not transmitted through a gateway'));
            } catch (TransmissionException $e) {
                $failed = true;
                $io->writeln(sprintf('<error>%s: %s</error>', $invoice->getNumber(), $e->getMessage()));
            }
        }
        if (!$input->getOption('no-receive')) {
            try {
                $io->writeln(sprintf('Statuses received from the buyers: %d', $this->transmission->receiveStatuses()));
            } catch (TransmissionException $e) {
                $failed = true;
                $io->writeln(sprintf('<error>Receiving: %s</error>', $e->getMessage()));
            }
        }
        if (!$input->getOption('no-send')) {
            $io->writeln(sprintf('Statuses sent again: %d', $this->transmission->sendPending()));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
