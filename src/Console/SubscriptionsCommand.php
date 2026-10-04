<?php

namespace Base\Marketplace\Console;

use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Service\Subscriptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * marketplace:subscriptions: every night from the cron container. Announces
 * the rights that ended (EntitlementEndedEvent) and those about to
 * (EntitlementEndingEvent), once each; --sync first asks the providers where
 * each running subscription stands, for the events a webhook missed.
 */
#[AsCommand(name: 'marketplace:subscriptions', description: 'End the rights that ran out, announce those about to; --sync reads the subscriptions from their providers.')]
class SubscriptionsCommand extends Command
{
    public function __construct(private readonly Subscriptions $subscriptions, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sync', null, InputOption::VALUE_NONE, 'Read each running subscription from its provider first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('sync')) {
            foreach ($this->entityManager->getRepository(Subscription::class)->findAll() as $subscription) {
                if (!$subscription->isRunning()) {
                    continue;
                }
                try {
                    $this->subscriptions->refresh($subscription);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('%s: %s', $subscription, $e->getMessage()));
                }
            }
        }
        $swept = $this->subscriptions->sweep();
        $io->success(sprintf('%d right(s) ended, %d ending soon.', $swept['ended'], $swept['ending']));

        return Command::SUCCESS;
    }
}
