<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Finishers;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;
use TYPO3\CMS\Form\Domain\Finishers\Exception\FinisherException;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime;
use WapplerSystems\FormGotowebinar\GoTo\ApiException;
use WapplerSystems\FormGotowebinar\GoTo\Client;
use WapplerSystems\FormGotowebinar\Service\WebinarProvider;

/**
 * Registers the visitor at GoTo for every webinar ticked in the
 * GoToWebinarSelection element. Each registration is a separate API call;
 * GoTo then lists the person as a normal registrant with a personal join
 * link.
 *
 * Options:
 *   selectionField      identifier of the GoToWebinarSelection element (default "webinars")
 *   firstNameField      default "firstName"
 *   lastNameField       default "lastName"
 *   emailField          default "email"
 *   fields              further GoTo standard fields, e.g. organization: '{company}'
 *   responses           answers to custom GoTo questions, questionKey: '{field}'
 *   source              value for GoTo's "source" field (default "website")
 *   resendConfirmation  let GoTo resend its mail to an address that is already registered
 *   summaryMail         one mail listing all booked webinars with their join links:
 *                       enabled, senderAddress, senderName, subject
 *
 * An address that is already registered counts as success, so a visitor
 * may simply send the form again after an error.
 */
class WebinarRegisterFinisher extends AbstractFinisher
{
    private const LLL = 'LLL:EXT:form_gotowebinar/Resources/Private/Language/locallang.xlf:';

    /** GoTo standard fields of the v1.1 registrant format. */
    private const STANDARD_FIELDS = [
        'source', 'address', 'city', 'state', 'zipCode', 'country', 'phone', 'organization',
        'jobTitle', 'questionsAndComments', 'industry', 'numberOfEmployees', 'purchasingTimeFrame',
        'purchasingRole',
    ];

    protected $defaultOptions = [
        'selectionField' => 'webinars',
        'firstNameField' => 'firstName',
        'lastNameField' => 'lastName',
        'emailField' => 'email',
        'fields' => [],
        'responses' => [],
        'source' => 'website',
        'resendConfirmation' => false,
        'summaryMail' => [
            'enabled' => false,
            'senderAddress' => '',
            'senderName' => '',
            'subject' => '',
        ],
    ];

    public function __construct(
        private readonly Client $client,
        private readonly WebinarProvider $webinarProvider,
        private readonly MailerInterface $mailer,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @throws FinisherException
     */
    protected function executeInternal(): ?string
    {
        $formRuntime = $this->finisherContext->getFormRuntime();
        $identifier = $formRuntime->getFormDefinition()->getIdentifier();

        $tiles = $this->offeredTiles($formRuntime);
        $selected = array_values(array_unique(array_map('strval', (array)($formRuntime[(string)$this->parseOption('selectionField')] ?? []))));
        $unknown = array_diff($selected, array_keys($tiles));
        if ($unknown !== []) {
            // Webinar ended or was taken off between page view and submit.
            $this->logger?->notice('form_gotowebinar: ignoring webinars that are no longer offered', ['form' => $identifier, 'keys' => array_values($unknown)]);
        }
        $selected = array_values(array_intersect($selected, array_keys($tiles)));
        if ($selected === []) {
            $this->options['errorMessage'] = $this->translate('finisher.register.nothingSelected');
            throw new FinisherException('No bookable webinar selected', 1762560101);
        }

        $email = trim((string)($formRuntime[(string)$this->parseOption('emailField')] ?? ''));
        $registrant = self::buildRegistrant(
            trim((string)($formRuntime[(string)$this->parseOption('firstNameField')] ?? '')),
            trim((string)($formRuntime[(string)$this->parseOption('lastNameField')] ?? '')),
            $email,
            (string)$this->parseOption('source'),
            (array)($this->parseOption('fields') ?? []),
            (array)($this->parseOption('responses') ?? []),
        );

        $registered = [];
        $failed = [];
        foreach ($selected as $key) {
            $tile = $tiles[$key];
            try {
                $result = $this->client->createRegistrant($key, $registrant, (bool)$this->parseOption('resendConfirmation'));
                $registered[] = $tile + ['joinUrl' => $result['joinUrl'], 'duplicate' => $result['duplicate']];
            } catch (ApiException $e) {
                $closed = self::isRegistrationClosed($e);
                if ($closed) {
                    $this->webinarProvider->markUnavailable($key);
                }
                $failed[] = $tile + ['closed' => $closed];
                $this->logger?->error('form_gotowebinar: registration failed', [
                    'form' => $identifier,
                    'webinar' => $key,
                    'email' => self::mask($email),
                    'status' => $e->status,
                    'code' => $e->errorCode(),
                    'error' => $e->errorMessage() ?: $e->getMessage(),
                ]);
            }
        }

        $variables = $this->finisherContext->getFinisherVariableProvider();
        $variables->add($this->shortFinisherIdentifier, 'registered', $registered);
        $variables->add($this->shortFinisherIdentifier, 'failed', $failed);
        $variables->add($this->shortFinisherIdentifier, 'titles', implode(', ', array_column($registered, 'title')));

        if ($registered === []) {
            // Nothing arrived at GoTo; no success message for that.
            $allClosed = array_filter($failed, static fn(array $tile): bool => !$tile['closed']) === [];
            $this->options['errorMessage'] = $this->translate($allClosed ? 'finisher.register.allClosed' : 'finisher.register.error');
            throw new FinisherException('No GoTo registration succeeded', 1762560102);
        }

        $this->logger?->info('form_gotowebinar: registered', [
            'form' => $identifier,
            'email' => self::mask($email),
            'webinars' => array_column($registered, 'key'),
            'failed' => array_column($failed, 'key'),
        ]);

        $this->sendSummaryMail($email, $registrant, $registered);

        return $failed === [] ? null : $this->renderFailures($failed);
    }

    /**
     * @param array<array-key, mixed> $fields standard field => value
     * @param array<array-key, mixed> $responses questionKey => answer
     * @return array<string, mixed>
     */
    public static function buildRegistrant(string $firstName, string $lastName, string $email, string $source, array $fields, array $responses): array
    {
        $registrant = ['firstName' => $firstName, 'lastName' => $lastName, 'email' => $email];
        if (trim($source) !== '') {
            $registrant['source'] = trim($source);
        }
        foreach ($fields as $name => $value) {
            $name = trim((string)$name);
            $value = self::scalar($value);
            if (in_array($name, self::STANDARD_FIELDS, true) && $value !== '') {
                $registrant[$name] = $value;
            }
        }
        $answers = [];
        foreach ($responses as $questionKey => $value) {
            $value = self::scalar($value);
            if (trim((string)$questionKey) !== '' && $value !== '') {
                $answers[] = ['questionKey' => (string)$questionKey, 'responseText' => $value];
            }
        }
        if ($answers !== []) {
            $registrant['responses'] = $answers;
        }
        return $registrant;
    }

    /**
     * GoTo has no dedicated status for "full" or "registration closed";
     * the error text tells. Such a webinar is taken off the page.
     */
    public static function isRegistrationClosed(ApiException $e): bool
    {
        if (!in_array($e->status, [400, 403], true)) {
            return false;
        }
        $text = strtolower($e->errorCode() . ' ' . $e->errorMessage());
        foreach (['closed', 'full', 'limit', 'capacity', 'past', 'ended', 'cancel'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, array<string, mixed>> key => tile
     */
    private function offeredTiles(FormRuntime $formRuntime): array
    {
        $element = $formRuntime->getFormDefinition()->getElementByIdentifier((string)$this->parseOption('selectionField'));
        $tiles = [];
        foreach ((array)($element?->getProperties()['tiles'] ?? []) as $tile) {
            $tiles[(string)$tile['key']] = $tile;
        }
        return $tiles;
    }

    /**
     * @param array<string, mixed> $registrant
     * @param list<array<string, mixed>> $registered
     */
    private function sendSummaryMail(string $email, array $registrant, array $registered): void
    {
        $config = (array)($this->parseOption('summaryMail') ?? []);
        if (empty($config['enabled']) || empty($config['senderAddress'])) {
            return;
        }
        try {
            $mail = new FluidEmail();
            $mail
                ->to(new Address($email, trim($registrant['firstName'] . ' ' . $registrant['lastName'])))
                ->from(new Address((string)$config['senderAddress'], (string)($config['senderName'] ?? '')))
                ->subject((string)($config['subject'] ?? '') ?: $this->translate('mail.subject'))
                ->format(FluidEmail::FORMAT_BOTH)
                ->setTemplate('WebinarConfirmation')
                ->assignMultiple([
                    'registrant' => $registrant,
                    'registrations' => $registered,
                ]);
            $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
            if ($request instanceof ServerRequestInterface) {
                $mail->setRequest($request);
            }
            $this->mailer->send($mail);
        } catch (\Throwable $e) {
            // The registrations are done and GoTo has the join links; a
            // failed summary mail must not turn that into an error page.
            $this->logger?->error('form_gotowebinar: summary mail not sent', ['email' => self::mask($email), 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param list<array<string, mixed>> $failed
     */
    private function renderFailures(array $failed): string
    {
        $html = '';
        foreach ([true => 'finisher.register.closed', false => 'finisher.register.partial'] as $closed => $key) {
            $items = '';
            foreach ($failed as $tile) {
                if ($tile['closed'] === (bool)$closed) {
                    $items .= '<li>' . htmlspecialchars((string)$tile['title'], ENT_QUOTES, 'UTF-8') . '</li>';
                }
            }
            if ($items !== '') {
                $html .= '<p>' . htmlspecialchars($this->translate($key), ENT_QUOTES, 'UTF-8') . '</p><ul>' . $items . '</ul>';
            }
        }
        return '<div class="alert alert-warning" data-formstate="partial">' . $html . '</div>';
    }

    private static function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value), static fn(string $v): bool => $v !== ''));
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function translate(string $key): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $language = $request instanceof ServerRequestInterface ? $request->getAttribute('language') : null;
        $languageService = $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('default');
        return $languageService->sL(self::LLL . $key);
    }

    private static function mask(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false || $at === 0) {
            return substr($address, 0, 2) . '***';
        }
        return substr($address, 0, 2) . '***' . substr($address, $at);
    }
}
