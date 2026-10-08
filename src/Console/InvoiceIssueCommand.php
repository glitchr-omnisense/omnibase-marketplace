<?php

namespace Base\Marketplace\Console;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Service\Invoices;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * marketplace:invoice:issue <order>...: the invoices of paid orders, by
 * their reference - what the back office's "Issue the invoice" does, for a
 * script or a catch-up. An order that has its invoice is said and skipped.
 * --paid-without-invoice issues every paid order's that has none.
 */
#[AsCommand(name: 'marketplace:invoice:issue', description: 'Issue the invoices of paid orders.')]
class InvoiceIssueCommand extends Command
{
    public function __construct(
        private readonly Invoices $invoices,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('orders', InputArgument::IS_ARRAY, 'The orders\' references (or ids)')
            ->addOption('paid-without-invoice', null, InputOption::VALUE_NONE, 'Every paid order that has no invoice');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $this->entityManager->getRepository(Order::class);
        $orders = [];
        foreach ((array) $input->getArgument('orders') as $key) {
            $order = $repository->findOneBy(['reference' => $key]) ?? (ctype_digit((string) $key) ? $repository->find((int) $key) : null);
            if (!$order instanceof Order) {
                $output->writeln(sprintf('<error>No order %s.</error>', $key));

                return Command::FAILURE;
            }
            $orders[] = $order;
        }
        if ($input->getOption('paid-without-invoice')) {
            foreach ($repository->findAll() as $order) {
                if ($order->isPaid() && null !== $order->getPaidAt() && null === $this->invoices->of($order)) {
                    $orders[] = $order;
                }
            }
        }

        $status = Command::SUCCESS;
        foreach ($orders as $order) {
            try {
                $invoice = $this->invoices->issue($order);
                $output->writeln(sprintf('%s %s', $order->getReference(), $invoice->getNumber()));
            } catch (\LogicException $e) {
                $output->writeln(sprintf('<comment>%s: %s</comment>', $order->getReference(), $e->getMessage()));
                $status = Command::FAILURE;
            }
        }

        return $status;
    }
}
