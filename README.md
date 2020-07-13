# github-security-jira

GitHub Action for mapping security alerts to Jira tickets.

## Setup

You need the following pieces set up to sync alerts with Jira:

1. Two repo secrets containing a GitHub access token and a Jira API token, respectively.
2. A workflow file which runs the action on a schedule, continually creating new tickets when necessary.

### Repo secrets

The `reload/github-security-jira` action requires you to [create two encrypted secrets](https://help.github.com/en/actions/automating-your-workflow-with-github-actions/creating-and-using-encrypted-secrets#creating-encrypted-secrets) in the repo:

1. A secret called `GitHubSecurityToken` which should contain a [Personal Access Token](https://help.github.com/en/github/authenticating-to-github/creating-a-personal-access-token-for-the-command-line) for the GitHub user under which this action should be executed. The token must include the `public_repo` scope if checking only public repos, or the `repo` scope for use on private repos. Also, the user must have [access to security alerts in the repo](https://help.github.com/en/github/managing-security-vulnerabilities/managing-alerts-for-vulnerable-dependencies-in-your-organization).
2. A secret called `JiraApiToken` containing an [API Token](https://confluence.atlassian.com/cloud/api-tokens-938839638.html) for the Jira user that should be used to create tickets.

### Workflow file setup

The [GitHub workflow file](https://help.github.com/en/actions/automating-your-workflow-with-github-actions/configuring-a-workflow#creating-a-workflow-file) should reside in any repo where you want to sync security alerts with Jira.

It has some required and some optional settings, which are passed to the action as environment variables:

- `GH_SECURITY_TOKEN`: A reference to the repo secret `GitHubSecurityToken` (**REQUIRED**)
- `JIRA_TOKEN`: A reference to the repo secret `JiraApiToken` (**REQUIRED**)
- `JIRA_HOST`: The endpoint for your Jira instance, e.g. <https://foo.atlassian.net> (**REQUIRED**)
- `JIRA_USER`: The ID of the Jira user which is associated with the 'JiraApiToken' secret, eg 'someuser@reload.dk' (**REQUIRED**)
- `JIRA_PROJECT`: The project key for the Jira project where issues should be created, eg `TEST` or `ABC`. (**REQUIRED**)
- `JIRA_ISSUE_TYPE`: Type of issue to create, e.g. `Security`. Defaults to `Bug`. (*Optional*)
- `JIRA_WATCHERS`: Jira users to add as watchers to tickets. Separate multiple watchers with comma (no spaces).
- `JIRA_RESTRICTED_COMMENT_ROLE`: A comment with restricted visibility
  to this role is posted with info about who was added as watchers to
  the issue. Defaults to `Developers`. (*Optional*)

Here is an example setup which runs this action every 6 hours.

```yaml
name: GitHub Security Alerts for Jira

on:
  schedule:
    - cron: '0 */6 * * *'

jobs:
  syncSecurityAlerts:
    runs-on: ubuntu-latest
    steps:
      - name: "Sync security alerts to Jira issues"
        uses: reload/github-security-jira@v1.x
        env:
          GH_SECURITY_TOKEN: ${{ secrets.GitHubSecurityToken }}
          JIRA_TOKEN: ${{ secrets.JiraApiToken }}
          JIRA_HOST: https://foo.atlassian.net
          JIRA_USER: someuser@reload.dk
          JIRA_PROJECT: ABC
          JIRA_ISSUE_TYPE: Security
          JIRA_WATCHERS: someuser@reload.dk,someotheruser@reload.dk
```

## Local development

Copy `docker-composer.override.example.yml` to `docker-composer.override.yml` and edit according to your settings.

After that, you can execute the Symfony console app like so:

```
docker-compose run --rm ghsec-jira --verbose --dry-run
```

Remove the `--dry-run` option to actually create issues in Jira.
