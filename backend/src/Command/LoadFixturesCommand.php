<?php

namespace App\Command;

use App\Entity\Merchant;
use App\Entity\Transaction;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:load-fixtures', description: 'Reset and seed merchants + transactions')]
class LoadFixturesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $platform = $this->connection->getDatabasePlatform();
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->connection->executeStatement($platform->getTruncateTableSQL('refund', true));
        $this->connection->executeStatement($platform->getTruncateTableSQL('transaction', true));
        $this->connection->executeStatement($platform->getTruncateTableSQL('merchant', true));
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $merchants = [];
        foreach (['Cafe Vienna', 'Sushi Place', 'Pizza Roma'] as $name) {
            $m = new Merchant();
            $m->setName($name);
            $m->setBalance('0.00');
            $this->em->persist($m);
            $merchants[] = $m;
        }

        $samples = [
            ['Cafe Vienna', '100.00', '0.00', 'paid'],
            ['Cafe Vienna', '45.50', '0.00', 'paid'],
            ['Sushi Place', '230.00', '50.00', 'paid'],
            ['Sushi Place', '19.99', '0.00', 'paid'],
            ['Pizza Roma', '75.25', '75.25', 'refunded'],
            ['Pizza Roma', '310.00', '0.00', 'paid'],
            ['Cafe Vienna', '12.40', '0.00', 'paid'],
            ['Sushi Place', '88.80', '0.00', 'paid'],
        ];

        $rate = '0.0290';
        $i = 0;
        foreach ($samples as [$merchantName, $amount, $refunded, $status]) {
            $merchant = $merchants[array_search($merchantName, ['Cafe Vienna', 'Sushi Place', 'Pizza Roma'], true)];

            $tx = new Transaction();
            $tx->setMerchant($merchant);
            $tx->setAmount($amount);
            $tx->setCurrency('EUR');
            $tx->setFeeRate($rate);
            $tx->setFee(number_format(round((float) $amount * (float) $rate, 2), 2, '.', ''));
            $tx->setStatus($status);
            $tx->setRefundedAmount($refunded);
            $tx->setExternalId('ext_'.str_pad((string) (++$i), 6, '0', \STR_PAD_LEFT));
            $this->em->persist($tx);
        }

        $this->em->flush();

        $io->success(sprintf('Seeded %d merchants, %d transactions', \count($merchants), \count($samples)));

        return Command::SUCCESS;
    }
}
