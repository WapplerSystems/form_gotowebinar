# GoTo Webinar for EXT:form

Lists the upcoming webinars of a GoTo Webinar account as tiles with a checkbox
each, and registers the visitor for every ticked webinar through the GoTo API.
GoTo then lists the person as a normal registrant with a personal join link.

- **Form element `GoToWebinarSelection`**: options come live from GoTo. Ended,
  cancelled and full webinars disappear on their own.
- **Records `tx_formgotowebinar_webinar`** (list module, in a folder): graphic,
  label ("WEBINAR 01 / 13"), title, text and host for one GoTo webinar. A GoTo
  webinar without record still shows with title and description from GoTo
  (`includeUnmanaged`, narrowed by `subjectFilter`).
- **Finisher `GoToWebinarRegister`**: one API call per ticked webinar. An
  address that is already registered counts as success, so sending the form
  again after an error is safe. Optional `summaryMail` with all join links.

## Setup

1. Create an OAuth client in the GoTo developer center
   (<https://developer.logmeininc.com/clients>) with the GoTo Webinar scope.
2. Extension configuration `form_gotowebinar`: `clientId`, `clientSecret`
   (or env `GOTO_CLIENT_ID` / `GOTO_CLIENT_SECRET`).
3. Connect, one of:
   - **Personal access token** (no browser login): an organizer creates a
     token at myaccount.goto.com → Developer Tools; the OAuth client needs the
     grant type "Personal Access Token" switched on and exactly the scopes of
     the token (e.g. also `identity:scim.me`, which is used to find the
     organizer key). `vendor/bin/typo3 gotowebinar:connect --pat=<token>`.
     A new access token is minted from it whenever the old one ran out.
   - **Authorization code**: set `redirectUri` (any page of the site, only the
     `code` parameter is read), run `vendor/bin/typo3 gotowebinar:connect`,
     open the printed URL, log in as an organizer, paste the URL GoTo
     redirects to.

   Tokens go to sys_registry, never into a file.
4. `vendor/bin/typo3 gotowebinar:status --fields` lists the upcoming webinars,
   their keys and the fields/questions their registration requires.
5. Cron, daily: `vendor/bin/typo3 gotowebinar:status` keeps the connection in
   use and the fallback list fresh when nobody visits the page.

## Form definition

```yaml
renderables:
  - type: GoToWebinarSelection
    identifier: webinars
    label: Webinare
    properties:
      storagePid: '123'          # folder with the webinar records
      subjectFilter: 'LINEAR@WORK'
    validators:
      - identifier: NotEmpty
finishers:
  - identifier: GoToWebinarRegister
    options:
      fields:
        organization: '{company}'
      responses:                  # custom GoTo questions: questionKey => value
        '4711': '{software}'
```

Required GoTo questions must be asked in the form as well, otherwise GoTo
refuses the registration.

## Caching

The webinar list is cached for `cacheLifetime` seconds (default 600) and the
page cache of a page with the element expires after the same time. If GoTo is
unreachable the last good list is used. A webinar GoTo refuses as full or
closed is hidden for a day.

## Tests

`vendor/bin/phpunit -c packages/form_gotowebinar/phpunit.xml.dist`
