# Testing Guide - Jira API v3 Implementation

## Quick Verification Checklist

Run these commands to verify the implementation is working:

### 1. Check All Files Exist ✅

```bash
ls -la src/Jira/
# Should show: JiraV3Client.php, AdfBuilder.php, JiraIssue.php

ls -la test-*.php
# Should show: test-adf.php, test-integration.php
```

### 2. Verify Syntax ✅

```bash
# Check all new files
php -l src/Jira/JiraV3Client.php
php -l src/Jira/AdfBuilder.php
php -l src/Jira/JiraIssue.php

# Check updated files
php -l src/SecurityAlertIssue.php
php -l src/PullRequestIssue.php

# All should return: "No syntax errors detected"
```

### 3. Check Dependencies ✅

```bash
# Verify old dependencies are gone
composer show | grep -E "(jira|lesstif|reload)"
# Should return NOTHING (or only show this package itself)

# Check what's installed
composer show --installed
# Should NOT include:
#   - lesstif/php-jira-rest-client
#   - reload/jira-security-issue
```

### 4. Run ADF Conversion Test ✅

```bash
php test-adf.php
```

**Expected Output:**
```
Testing ADF Conversion...

Input Wiki Markup:
=================
- Repository: [test/repo|https://github.com/test/repo]
- Alert: [Vulnerability Summary|...]
...

Output ADF (JSON):
==================
{
    "type": "doc",
    "version": 1,
    "content": [...]
}

Validation:
===========
✅ PASS: Document type is correct
✅ PASS: Content array exists
   Found 2 content blocks
✅ PASS: Bullet list found
✅ PASS: Code block found

Test complete!
```

### 5. Run Integration Test ✅

```bash
php test-integration.php
```

**Expected Output:**
```
Integration Test - SecurityAlertIssue and PullRequestIssue
===========================================================

Test 1: SecurityAlertIssue Construction
----------------------------------------
✅ SecurityAlertIssue constructed successfully
   Unique ID: lodash:4.17.21

Test 2: PullRequestIssue Construction
--------------------------------------
✅ PullRequestIssue constructed successfully
   Unique ID: lodash:src:4.17.21

Test 3: Verify exists() method doesn't crash
---------------------------------------------
✅ Issue objects are properly structured

Test 4: Verify ensure() would work (structure check)
-----------------------------------------------------
✅ Issue has valid title and body
   Title: lodash (4.17.21) - HIGH
   Body length: 419 characters

Test 5: Verify ADF conversion for issue body
---------------------------------------------
✅ ADF conversion successful
   Content blocks: 2
   ADF structure is valid

═══════════════════════════════════════════════════════════
All tests passed! ✅
═══════════════════════════════════════════════════════════
```

---

## Testing With Real Jira Instance

### Prerequisites

You need:
1. Jira Cloud instance URL (e.g., `https://missionwired.atlassian.net`)
2. Jira user email
3. Jira API token ([Create one here](https://id.atlassian.com/manage-profile/security/api-tokens))
4. Jira project key (e.g., `ITINF`)
5. GitHub Personal Access Token with `repo` and `security_events` scopes
6. A GitHub repository with security alerts

### Step 1: Set Environment Variables

```bash
# Required - Jira
export JIRA_HOST="https://missionwired.atlassian.net"
export JIRA_USER="your-email@example.com"
export JIRA_TOKEN="your-jira-api-token"
export JIRA_PROJECT="ITINF"

# Required - GitHub
export GH_SECURITY_TOKEN="ghp_your_github_pat"
export GITHUB_REPOSITORY="your-org/your-repo"
export GITHUB_SERVER_URL="https://github.com"

# Optional - Customization
export JIRA_ISSUE_TYPE="Bug"
export JIRA_ISSUE_LABELS="ghas,vulns,SOC-2,code-scanning"
export JIRA_ISSUE_PRIORITY="High"

# Optional - Watchers
export JIRA_WATCHERS="user1@example.com,user2@example.com"
export JIRA_RESTRICTED_COMMENT_ROLE="Team Members"
```

### Step 2: Test API Connectivity

Test Jira authentication:

```bash
curl -u "${JIRA_USER}:${JIRA_TOKEN}" \
  -H "Accept: application/json" \
  "${JIRA_HOST}/rest/api/3/myself"
```

**Expected:** JSON response with your user details

Test Jira project access:

```bash
curl -u "${JIRA_USER}:${JIRA_TOKEN}" \
  -H "Accept: application/json" \
  "${JIRA_HOST}/rest/api/3/project/${JIRA_PROJECT}"
```

**Expected:** JSON response with project details

### Step 3: Dry Run (No Changes)

```bash
./bin/ghsec-jira sync --dry-run -vvv
```

**What This Does:**
- ✅ Fetches alerts from GitHub
- ✅ Constructs Jira issue objects
- ✅ Checks for existing issues
- ❌ Does NOT create any Jira tickets
- ✅ Shows what would be created

**Expected Output:**
```
[TIMESTAMP] - ITINF - No alerts found.
```

OR if alerts exist:
```
[TIMESTAMP] - ITINF - Would have created an issue for lodash:4.17.21 if not a dry run.
[TIMESTAMP] - ITINF - Existing issue ITINF-123 covers axios:0.21.1.
```

### Step 4: Create One Test Ticket

If dry-run looks good, create a real ticket:

```bash
./bin/ghsec-jira sync -vvv
```

**What This Does:**
- ✅ Fetches alerts from GitHub
- ✅ Checks for existing Jira issues
- ✅ Creates new issues for untracked alerts
- ✅ Adds watchers (if configured)
- ✅ Adds comments (if configured)

**Expected Output:**
```
[TIMESTAMP] - ITINF - Created issue ITINF-456 for lodash:4.17.21.
[TIMESTAMP] - ITINF - Existing issue ITINF-123 covers axios:0.21.1.
```

### Step 5: Verify in Jira

1. Go to your Jira project
2. Find the newly created issue (use key from output, e.g., `ITINF-456`)
3. Verify:
   - ✅ Title is correct (package name, version, severity)
   - ✅ Description is formatted (not raw JSON!)
   - ✅ Links are clickable
   - ✅ Code blocks are formatted
   - ✅ Labels are applied (repository, unique ID, custom labels)
   - ✅ Watchers were added (if configured)
   - ✅ Comment exists with correct visibility (if configured)

---

## Testing in GitHub Actions

### Step 1: Update Workflow File

Update your `.github/workflows/security-sync.yml`:

```yaml
name: "Sync Security Alerts to Jira"

on:
  schedule:
    - cron: '00 13 * * *'
  workflow_dispatch:

jobs:
  sync_code_scanning:
    runs-on: ubuntu-latest
    steps:
      # Use your fork (replace YOUR_USERNAME and YOUR_BRANCH)
      - uses: YOUR_USERNAME/github-security-jira@YOUR_BRANCH
        env:
          GH_SECURITY_TOKEN: '${{ secrets.GH2JR_PAT }}'
          JIRA_TOKEN: '${{ secrets.JIRA_TOKEN }}'
          JIRA_HOST: 'https://missionwired.atlassian.net'
          JIRA_USER: '${{ secrets.JIRA_USER }}'
          JIRA_PROJECT: 'ITINF'
          JIRA_ISSUE_LABELS: 'ghas,vulns,SOC-2,code-scanning'
          GH_SECURITY_TOOLS: 'code_scanning'
```

### Step 2: Set Repository Secrets

In your GitHub repository settings, add secrets:
- `GH2JR_PAT` - GitHub Personal Access Token
- `JIRA_TOKEN` - Jira API Token
- `JIRA_USER` - Your Jira email

### Step 3: Test Manually

1. Go to Actions tab in your repository
2. Select your workflow
3. Click "Run workflow"
4. Select branch
5. Click "Run workflow"

### Step 4: Monitor Execution

Watch the workflow run:
- ✅ Check for any errors in logs
- ✅ Look for "Created issue" messages
- ✅ Note any HTTP errors (should be 200, not 410!)
- ✅ Verify tickets created in Jira

### Step 5: Check for HTTP 410 Errors

**If you see:**
```
CURL HTTP Request Failed: Status Code : 410
```

**Then:** The v2 API is still being called somehow. This shouldn't happen with our implementation.

**If you see:**
```
CURL HTTP Request Failed: Status Code : 401
```

**Then:** Authentication failed. Check:
- JIRA_USER is your email (not username)
- JIRA_TOKEN is valid
- Secrets are set correctly in GitHub

---

## Common Test Scenarios

### Scenario 1: New Alert

**Setup:** Repository with 1 untracked security alert

**Expected:**
```bash
./bin/ghsec-jira sync -vvv
# Output:
[TIMESTAMP] - ITINF - Created issue ITINF-789 for lodash:4.17.21.
```

**Verify:**
- New Jira ticket exists
- Has correct details
- Has labels

### Scenario 2: Duplicate Alert

**Setup:** Run sync twice for same alert

**First run:**
```bash
./bin/ghsec-jira sync -vvv
# Output:
[TIMESTAMP] - ITINF - Created issue ITINF-789 for lodash:4.17.21.
```

**Second run:**
```bash
./bin/ghsec-jira sync -vvv
# Output:
[TIMESTAMP] - ITINF - Existing issue ITINF-789 covers lodash:4.17.21.
```

**Verify:**
- No duplicate ticket created
- Only one ITINF-789 exists

### Scenario 3: Multiple Alerts

**Setup:** Repository with 5 different alerts

**Expected:**
```bash
./bin/ghsec-jira sync -vvv
# Output:
[TIMESTAMP] - ITINF - Created issue ITINF-790 for lodash:4.17.21.
[TIMESTAMP] - ITINF - Created issue ITINF-791 for axios:0.21.1.
[TIMESTAMP] - ITINF - Created issue ITINF-792 for minimist:1.2.5.
[TIMESTAMP] - ITINF - Created issue ITINF-793 for node-fetch:2.6.1.
[TIMESTAMP] - ITINF - Created issue ITINF-794 for yargs-parser:18.1.3.
```

**Verify:**
- 5 separate tickets created
- Each has unique labels
- All have correct details

### Scenario 4: Dependabot PRs

**Setup:** Repository with open Dependabot security PRs

**Expected:**
```bash
./bin/ghsec-jira sync -vvv
# Output:
[TIMESTAMP] - ITINF - Created issue ITINF-795 for lodash (4.17.21).
```

**Verify:**
- PR details in description
- Link to PR works
- Labels include package name

---

## Performance Testing

### Test Large Volume

If you have many alerts (50+), test performance:

```bash
time ./bin/ghsec-jira sync -vvv
```

**Expected:**
- 0-10 alerts: ~5-15 seconds
- 11-50 alerts: ~15-60 seconds
- 51-100 alerts: ~1-3 minutes

**If slower:** May be hitting rate limits or network issues.

---

## Rollback Plan

If you need to revert:

### Option 1: Use Git

```bash
git log --oneline
# Find commit before changes
git revert <commit-hash>
```

### Option 2: Restore Dependencies

Add back to composer.json:

```json
{
  "require": {
    "reload/jira-security-issue": "^2.0.11"
  }
}
```

Then:
```bash
composer update
```

**Note:** This will bring back the HTTP 410 error!

---

## Success Metrics

After testing, you should have:

✅ All local tests passing
✅ Dry-run completes without errors
✅ At least one test ticket created successfully
✅ Ticket formatting correct in Jira
✅ Duplicate detection working
✅ No HTTP 410 errors
✅ GitHub Actions workflow succeeds
✅ Multiple alerts handled correctly

---

## Next Actions

1. **Test locally first** - Use dry-run and create one test ticket
2. **Verify in Jira** - Check formatting and functionality
3. **Test in GitHub Actions** - Run workflow manually
4. **Monitor for issues** - Watch first few automatic runs
5. **Document your setup** - Note any custom configurations
6. **Roll out gradually** - Test on one repo, then expand

---

## Support Resources

- [IMPLEMENTATION-COMPLETE.md](IMPLEMENTATION-COMPLETE.md) - Full implementation details
- [V3-Implementation-Plan.md](V3-Implementation-Plan.md) - Original plan
- [Jira API v3 Docs](https://developer.atlassian.com/cloud/jira/platform/rest/v3/)
- [ADF Playground](https://developer.atlassian.com/cloud/jira/platform/apis/document/playground/)

---

**Testing Status:** ✅ Ready for Testing
**Date:** November 19, 2025
