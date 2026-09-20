<?php

namespace Base\Market\Shopify\Console;

use Base\Market\Entity\Order;
use Base\Market\Shopify\Api\AdminApi;
use Base\Market\Shopify\Checkout\OrderReconciler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sweeps up orders left waiting on a Shopify payment.
 *
 * The safety net for the orders/paid webhook: one that never arrived because
 * the site was down, the tunnel was closed, or the subscription had not been
 * installed yet. Run it from cron every ten minutes.
 *
 * It is also what lets the whole checkout work with no webhooks at all, which
 * is what makes local development against a Shopify store possible.
 *
 * The age window matters: an order two minutes old is probably a member still
 * typing their card number, not a lost webhook.
 */
#[AsCommand(name: 'market:shopify:orders:reconcile', description: 'Settle orders still waiting on a Shopify payment')]
class ReconcileOrdersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderReconciler $reconciler,
        private readonly AdminApi $api,
        #[Autowire('%market.shopify.checkout.enabled%')] private readonly bool $enabled = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Only orders pending for at least this long', '5')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many to look at', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->enabled || !$this->api->isConfigured()) {
            $io->writeln('Shopify checkout is off; nothing to reconcile.');

            return Command::SUCCESS;
        }

        $cutoff = new \DateTime(sprintf('-%d minutes', max(0, (int) $input->getOption('minutes'))));

        $orders = $this->entityManager->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->andWhere('o.updatedAt <= :cutoff')->setParameter('cutoff', $cutoff)
            ->orderBy('o.id', 'DESC')
            ->setMaxResults((int) $input->getOption('limit'))
            ->getQuery()->getResult();

        $counts = ['paid' => 0, 'pending' => 0, 'cancelled' => 0, 'unknown' => 0];

        foreach ($orders as $order) {
            if (!$order->isPending()) {
                continue;
            }

            foreach ($order->getTransactions() as $transaction) {
                if (empty($transaction->getDetails()['shopify_draft_order'])) {
                    continue;
                }

                $status = $this->reconciler->reconcile($order, $transaction);
                ++$counts[$status];

                if ('paid' === $status) {
                    $io->writeln(sprintf('  <info>paid</info>      %s', $order->getReference()));
                } elseif ('cancelled' === $status) {
                    $io->writeln(sprintf('  <comment>cancelled</comment> %s', $order->getReference()));
                }

                break;
            }
        }

        $io->success(sprintf('%d paid, %d still pending, %d cancelled, %d unknown.', $counts['paid'], $counts['pending'], $counts['cancelled'], $counts['unknown']));

        return Command::SUCCESS;
    }
}
