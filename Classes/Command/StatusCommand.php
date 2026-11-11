<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\FormGotowebinar\GoTo\ApiException;
use WapplerSystems\FormGotowebinar\GoTo\Client;
use WapplerSystems\FormGotowebinar\GoTo\TokenStore;
use WapplerSystems\FormGotowebinar\Service\WebinarProvider;

/**
 * Lists the upcoming webinars straight from GoTo (bypassing the cache).
 * Doubles as keep-alive: run it from cron once a day so the refresh token
 * is used regularly even when nobody visits the page.
 */
#[AsCommand(name: 'gotowebinar:status', description: 'Show the GoTo connection and the upcoming webinars')]
class StatusCommand extends Command
{
    public function __construct(
        private readonly Client $client,
        private readonly TokenStore $tokenStore,
        private readonly WebinarProvider $webinarProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fields', null, InputOption::VALUE_NONE, 'Also show the registration fields and questions of each webinar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->client->isConnected()) {
            $io->error('Not connected. Run "typo3 gotowebinar:connect".');
            return Command::FAILURE;
        }
        try {
            $now = new \DateTimeImmutable();
            $webinars = $this->client->upcomingWebinars($now, $now->modify('+18 months'));
        } catch (ApiException $e) {
            $io->error($e->getMessage() . ($e->body !== '' ? "\n" . $e->body : ''));
            return Command::FAILURE;
        }
        $this->webinarProvider->flush();
        $this->webinarProvider->storeFetched($webinars);

        $io->writeln('Connected as ' . ($this->tokenStore->principal() ?: 'unknown principal'));
        $rows = [];
        foreach ($webinars as $webinar) {
            $start = $webinar['startTime'] !== ''
                ? (new \DateTimeImmutable($webinar['startTime']))->setTimezone(new \DateTimeZone($webinar['timeZone'] ?: 'Europe/Berlin'))->format('d.m.Y H:i')
                : '';
            $rows[] = [$webinar['webinarKey'], $start, mb_strimwidth($webinar['subject'], 0, 70, '…')];
        }
        $io->table(['Webinar key', 'Start', 'Subject'], $rows);

        if ($input->getOption('fields')) {
            foreach ($webinars as $webinar) {
                $fields = $this->client->registrationFields($webinar['webinarKey']);
                $io->section($webinar['subject']);
                foreach ($fields['fields'] as $field) {
                    $io->writeln(sprintf('  %s%s', $field['field'] ?? '?', !empty($field['required']) ? ' (required)' : ''));
                }
                foreach ($fields['questions'] as $question) {
                    $io->writeln(sprintf('  Q %s: %s%s', $question['questionKey'] ?? '?', $question['question'] ?? '', !empty($question['required']) ? ' (required)' : ''));
                }
            }
        }
        return Command::SUCCESS;
    }
}
