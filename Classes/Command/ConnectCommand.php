<?php

declare(strict_types=1);

namespace WapplerSystems\GotoWebinar\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\GotoWebinar\GoTo\ApiException;
use WapplerSystems\GotoWebinar\GoTo\Client;
use WapplerSystems\GotoWebinar\GoTo\TokenStore;
use WapplerSystems\GotoWebinar\Service\WebinarProvider;

/**
 * One-time OAuth grant: an organizer of the GoTo account opens the printed
 * URL, logs in, and GoTo redirects to the configured redirect URI with
 * ?code=... in the address bar. That code (or the whole URL) goes back
 * into this command; from then on the refresh token keeps the connection.
 */
#[AsCommand(name: 'gotowebinar:connect', description: 'Connect the site with a GoTo Webinar account (OAuth authorization code flow)')]
class ConnectCommand extends Command
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
        $this->addOption('code', null, InputOption::VALUE_REQUIRED, 'Authorization code, or the complete redirect URL containing it');
        $this->addOption('disconnect', null, InputOption::VALUE_NONE, 'Forget the stored tokens');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('disconnect')) {
            $this->tokenStore->clear();
            $this->webinarProvider->flush();
            $io->success('GoTo tokens removed.');
            return Command::SUCCESS;
        }
        if (!$this->client->hasCredentials()) {
            $io->error('Set clientId and clientSecret in the extension configuration of goto_webinar (or GOTO_CLIENT_ID / GOTO_CLIENT_SECRET) first.');
            return Command::FAILURE;
        }

        $code = (string)$input->getOption('code');
        if ($code === '') {
            $io->writeln('Open this URL, log in with an organizer account and allow access:');
            $io->writeln('');
            $io->writeln('  ' . $this->client->authorizeUrl());
            $io->writeln('');
            $code = (string)$io->ask('Paste the URL you were redirected to (or just the code)');
        }
        $code = self::extractCode($code);
        if ($code === '') {
            $io->error('No authorization code given.');
            return Command::FAILURE;
        }

        try {
            $response = $this->client->connect($code);
        } catch (ApiException $e) {
            $io->error($e->getMessage() . ($e->body !== '' ? "\n" . $e->body : ''));
            return Command::FAILURE;
        }
        $this->webinarProvider->flush();

        if (empty($response['organizer_key']) && !$this->client->isConnected()) {
            $io->warning('Connected, but GoTo did not return an organizer key. Set organizerKey in the extension configuration.');
            return Command::SUCCESS;
        }
        $io->success(sprintf('Connected as %s. Check with "typo3 gotowebinar:status".', $response['principal'] ?? 'organizer ' . ($response['organizer_key'] ?? '')));
        return Command::SUCCESS;
    }

    public static function extractCode(string $input): string
    {
        $input = trim($input);
        if (str_contains($input, 'code=')) {
            parse_str((string)parse_url($input, PHP_URL_QUERY) ?: $input, $query);
            return trim((string)($query['code'] ?? ''));
        }
        return $input;
    }
}
