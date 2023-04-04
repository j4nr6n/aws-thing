<?php

namespace App\Command;

use Aws\Ec2\Ec2Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:test')]
class TestCommand extends Command
{
    private Ec2Client $ec2Client;

    public function __construct(Ec2Client $ec2Client)
    {
        $this->ec2Client = $ec2Client;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $images = $this->getImages();
        $snapshots = $this->getSnapshots();
        $oneYearAgo = new \DateTime('-1 year');
        $continue = new ConfirmationQuestion('Continue?', true);

        foreach ($snapshots as $snapshot) {
            $isInUse = array_reduce($images, static function ($carry, $image) use ($snapshot) {
                $isMatch = array_reduce(
                    $image['BlockDeviceMappings'] ?? [],
                    static function ($carry, $blockDevice) use ($snapshot) {
                        return (($blockDevice['Ebs']['SnapshotId'] ?? false) === $snapshot['SnapshotId']) || $carry;
                    }
                );

                return $isMatch || $carry;
            });

            $nameTag = array_filter($snapshot['Tags'] ?? [], static function (array $tag): bool {
                return $tag['Key'] === 'Name';
            })[0]['Value'] ?? null;

            if (!$isInUse && $snapshot['StartTime'] < $oneYearAgo) {
                $io->section(sprintf('"%s" is not in use!', $snapshot['SnapshotId']));
                $io->writeln(sprintf('Name:        %s', $nameTag));
                $io->writeln(sprintf('Created:     %s', $snapshot['StartTime']->format(\DateTimeInterface::ATOM)));
                $io->writeln(sprintf('Description: %s', $snapshot['Description']));

                $io->askQuestion($continue);
            }
        }

        return Command::SUCCESS;
    }

    private function getSnapshots(): array
    {
        $snapshots = [];

        do {
            $response = $this->ec2Client->describeSnapshots([
                'OwnerIds' => ['self'],
                'MaxResults' => 50,
                'NextToken' => $nextToken ?? null,
            ]);

            $snapshots[] = $response->get('Snapshots');
        } while ($nextToken = $response->get('NextToken'));

        return array_merge([], ...$snapshots);
    }

    private function getImages(): array
    {
        $images = [];

        do {
            $response = $this->ec2Client->describeImages([
                'Owners' => ['self'],
                'MaxResults' => 50,
                'NextToken' => $nextToken ?? null,
            ]);

            $images[] = $response->get('Images');
        } while ($nextToken = $response->get('NextToken'));

        return array_merge([], ...$images);
    }
}
