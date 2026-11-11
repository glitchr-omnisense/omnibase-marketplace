<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Invoice\Transmission\Entity\EReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * marketplace:invoice:ereport [--date=2026-11-03] [--kind=transactions] [--send]:
 * the e-reporting of the period containing that day - the last one ended,
 * by default - collected (EReporting), and sent with --send when the
 * application writes the file. From a cron, after each period's end.
 * Registered only with omnisense/omnibill.
 */
#[AsCommand(name: 'marketplace:invoice:ereport', description: 'Collect (and send) the e-reporting of a period: sales to private persons and abroad, their payments.')]
class EReportCommand extends Command
{
    public function __construct(private readonly EReporting $reporting)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'A day of the period: 2026-11-03 (default: the last period ended)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'transactions or payments (default: both)')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Send it to the approved platform');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->reporting->isEnabled()) {
            $io->warning('No e-reporting: marketplace.invoice.vat_regime is not set.');

            return Command::SUCCESS;
        }
        $date = $input->getOption('date');
        $day = null !== $date ? new \DateTimeImmutable((string) $date) : null;
        $kinds = null !== $input->getOption('kind') ? [(string) $input->getOption('kind')] : [EReport::TRANSACTIONS, ...($this->reporting->reportsPayments() ? [EReport::PAYMENTS] : [])];
        foreach ($kinds as $kind) {
            $report = $this->reporting->collect($kind, $day);
            if ($input->getOption('send')) {
                $this->reporting->send($report);
            }
            $data = $report->getData();
            $io->writeln(sprintf('%s: %d days, %d invoices abroad, due %s - %s%s', $report, \count($data['b2c'] ?? []), \count($data['b2b_international'] ?? []), $report->getDueOn()->format('Y-m-d'), $report->getState(), null !== $report->getError() ? ' ('.$report->getError().')' : ''));
            foreach ($data['warnings'] ?? [] as $warning) {
                $io->writeln('  ! '.$warning);
            }
        }

        return Command::SUCCESS;
    }
}
