<?php

namespace Base\Marketplace\Console;

use Base\Marketplace\Service\Referrals;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** marketplace:referrals: every night from the cron container - the referrers whose referee's order held are rewarded. */
#[AsCommand(name: 'marketplace:referrals', description: 'Reward the referrers whose referee\'s first order has held for the cooling-off period.')]
class ReferralsCommand extends Command
{
    public function __construct(private readonly Referrals $referrals)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $released = $this->referrals->release();
        (new SymfonyStyle($input, $output))->success(sprintf('%d referrer(s) rewarded, %d referral(s) rejected.', $released['rewarded'], $released['rejected']));

        return Command::SUCCESS;
    }
}
